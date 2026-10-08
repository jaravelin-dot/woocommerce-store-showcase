<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * AI poradce: smyčka rozhovoru, REST endpoint, relace, limity, denní strop
 * útraty a log.
 *
 * Tři pojistky proti sebevědomé chybě:
 *  1. Model smí ukázat JEN produkty, které mu v tomhle rozhovoru vrátil
 *     nástroj (`povolena`). Jiná ID se tiše vyhodí a zalogují.
 *  2. Kartu (cena, fotka, dostupnost) skládá Shop_Poradce_Katalog::karta()
 *     z WooCommerce, nikdy z textu modelu.
 *  3. Když cokoli selže (klíč, strop, síť, limit kol), vrací se
 *     `zaloha: true` a widget pošle dotaz do běžného hledání. Zákazník
 *     nikdy neuvidí chybovou hlášku místo výsledku.
 *
 * Pojistky provozu:
 *  - denní strop útraty v USD; útrata se sčítá z logu (INSERT je atomický),
 *    ne z jedné volby, kterou by si souběžné požadavky přepsaly;
 *  - limity na IP (hodina, den), na délku zprávy a počet zpráv v relaci;
 *  - nejvýš MAX_SOUBEZNE rozhovorů naráz (s externí object cache), ať
 *    poradce nezablokuje procesy PHP, které potřebuje pokladna;
 *  - po sérii chyb API se poradce na pár minut sám vypne.
 */
class Shop_Poradce {
	public const REST_NAMESPACE = 'shop/v1';
	public const REST_ROUTA     = '/poradce';

	public const OPTION_REZIM  = 'shop_poradce_rezim';
	public const OPTION_MOTOR  = 'shop_poradce_motor';
	public const OPTION_STROP  = 'shop_poradce_denni_strop_usd';

	public const REZIMY = ['vypnuto', 'spravci', 'vsichni'];
	public const MOTORY = ['simulace', 'claude'];

	public const STROP_VYCHOZI_USD = 5.0;
	public const MODEL_VYCHOZI     = 'claude-opus-5-5';

	/** Kola nástrojů na jednu zprávu zákazníka. */
	public const MAX_KOL       = 6;
	public const MAX_ZPRAV     = 10;
	public const MAX_ZNAKU     = 400;
	public const MAX_KARET     = 6;
	public const MAX_ZA_HODINU = 30;
	public const MAX_ZA_DEN    = 100;
	public const MAX_SOUBEZNE  = 2;
	public const RELACE_TTL    = HOUR_IN_SECONDS;

	/** Po MAX_SEKUND se nezačne další kolo; celek zůstane pod timeoutem prohlížeče. */
	public const MAX_SEKUND    = 45;
	public const CELKEM_SEKUND = 60;

	private const SLOT_TTL     = 120;
	private const MAX_CHYB_API = 5;
	private const CHYBY_OKNO   = 300;
	private const SKUPINA      = 'shop_poradce';
	private const TABULKA      = 'shop_poradce_log';
	private const VERZE_SCHEMATU = '1';

	/** Serverový záložní model při odmítnutí (Claude API, modely 5.5). */
	private const BETA_FALLBACK = 'server-side-fallback-2026-07-01';

	public static function registruj_hooky(): void {
		add_action('rest_api_init', [self::class, 'registruj_routu']);
	}

	public static function rezim(): string {
		$rezim = (string) get_option(self::OPTION_REZIM, 'vypnuto');

		return in_array($rezim, self::REZIMY, true) ? $rezim : 'vypnuto';
	}

	public static function motor(): string {
		$motor = (string) get_option(self::OPTION_MOTOR, 'simulace');

		return in_array($motor, self::MOTORY, true) ? $motor : 'simulace';
	}

	/** Vidí aktuální návštěvník poradce? */
	public static function vidi_poradce(): bool {
		$rezim = self::rezim();

		return $rezim === 'vsichni' || ($rezim === 'spravci' && current_user_can('manage_woocommerce'));
	}

	public static function model(): string {
		return (string) apply_filters('shop_poradce_model', self::MODEL_VYCHOZI);
	}

	public static function strop_usd(): float {
		$strop = (float) get_option(self::OPTION_STROP, self::STROP_VYCHOZI_USD);

		return $strop > 0 ? $strop : self::STROP_VYCHOZI_USD;
	}

	/** Dnešní útrata = součet z logu od půlnoci místního času. */
	public static function utrata_dnes(): float {
		global $wpdb;

		self::zajisti_tabulku();

		return (float) $wpdb->get_var($wpdb->prepare(
			'SELECT COALESCE(SUM(cena_usd), 0) FROM ' . self::tabulka() . ' WHERE cas >= %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			wp_date('Y-m-d 00:00:00')
		));
	}

	public static function registruj_routu(): void {
		register_rest_route(self::REST_NAMESPACE, self::REST_ROUTA, [
			'methods'             => 'POST',
			'callback'            => [self::class, 'obsluz'],
			// Veřejné (zákazník není přihlášený), ale jen v režimu, ve kterém ho smí vidět.
			'permission_callback' => static fn(): bool => self::vidi_poradce(),
			'args'                => [
				'zprava' => ['type' => 'string', 'required' => true],
				'relace' => ['type' => 'string', 'required' => false],
			],
		]);
	}

	public static function obsluz(WP_REST_Request $pozadavek): WP_REST_Response {
		// Cizí stránka by mohla nechat prohlížeče svých návštěvníků posílat
		// dotazy za nás (útrata). Prohlížeč Origin u POST posílá vždy.
		if (!self::povoleny_origin((string) $pozadavek->get_header('origin'))) {
			return self::odpoved(['zaloha' => true, 'duvod' => 'origin'], 403);
		}

		if (!self::povol_podle_ip()) {
			return self::odpoved(['zaloha' => true, 'duvod' => 'limit'], 429);
		}

		$slot = self::obsad_slot();

		if ($slot === null) {
			return self::odpoved(self::zaloha('soubeh'), 200);
		}

		try {
			$vysledek = self::odpovez_na((string) $pozadavek->get_param('relace'), (string) $pozadavek->get_param('zprava'));
		} finally {
			self::uvolni_slot($slot);
		}

		return self::odpoved($vysledek, 200);
	}

	/** @param array<string, mixed> $data */
	private static function odpoved(array $data, int $kod): WP_REST_Response {
		$odpoved = new WP_REST_Response($data, $kod);
		$odpoved->header('Cache-Control', 'no-store');

		return $odpoved;
	}

	/** Prázdný Origin (curl, starší prohlížeč) pouštíme; cizí ne. */
	public static function povoleny_origin(string $origin): bool {
		if ($origin === '') {
			return true;
		}

		$nase = array_map('strtolower', array_filter([
			(string) wp_parse_url(home_url(), PHP_URL_HOST),
			(string) wp_parse_url(site_url(), PHP_URL_HOST),
		]));

		return in_array(strtolower((string) wp_parse_url($origin, PHP_URL_HOST)), $nase, true);
	}

	/** Model podle nastavení. Null = skutečný model nejde použít (chybí klíč, výpadek). */
	private static function vyber_model(): ?Shop_Poradce_Model {
		if (self::motor() === 'simulace') {
			return new Shop_Poradce_Simulace();
		}

		return (Shop_Poradce_Claude::nastaveno() && !self::api_vypnute()) ? new Shop_Poradce_Claude() : null;
	}

	/**
	 * Jedna zpráva zákazníka → odpověď s kartami.
	 *
	 * @param Shop_Poradce_Model|null $model podstrčený model (testy); null = podle nastavení
	 * @return array<string, mixed>
	 */
	public static function odpovez_na(string $relace_id, string $zprava, ?Shop_Poradce_Model $model = null): array {
		$start  = microtime(true);
		$zprava = (string) preg_replace('/\s+/u', ' ', trim(wp_strip_all_tags($zprava)));
		$zprava = mb_substr($zprava, 0, self::MAX_ZNAKU, 'UTF-8');

		if ($zprava === '') {
			return ['zaloha' => false, 'text' => 'Napište prosím, co sháníte.', 'karty' => []];
		}

		$model = $model ?? self::vyber_model();

		if ($model === null) {
			return self::zaloha(Shop_Poradce_Claude::nastaveno() ? 'vypadek_api' : 'bez_klice');
		}

		if (self::utrata_dnes() >= self::strop_usd()) {
			self::zapis_log(['dotaz' => $zprava, 'chyba' => 'strop', 'trvani' => $start]);

			return self::zaloha('strop');
		}

		$relace = self::nacti_relaci($relace_id);

		if ($relace['pocet_zprav'] >= self::MAX_ZPRAV) {
			return ['relace' => $relace['id'], 'konec' => true, 'text' => 'Tenhle rozhovor už je dlouhý. Začněte prosím nový.', 'karty' => []];
		}

		$historie = $relace['historie'];
		self::pridej_text_zakaznika($historie, $zprava);

		$zaklad = [
			'model'         => self::model(),
			'max_tokens'    => 4000,
			'output_config' => ['effort' => 'low'],
			'system'        => [['type' => 'text', 'text' => Shop_Poradce_Prompt::system(), 'cache_control' => ['type' => 'ephemeral']]],
			'tools'         => Shop_Poradce_Prompt::nastroje(),
			'tool_choice'   => ['type' => 'auto'],
			// Druhý breakpoint posune cache na konec historie: každé další kolo
			// platí plně jen nové tokeny.
			'cache_control' => ['type' => 'ephemeral'],
		];

		// Záložní model při odmítnutí — `fallbacks: "default"` umí jen modely 5.5.
		if (str_starts_with($zaklad['model'], 'claude-opus-5-5') || str_starts_with($zaklad['model'], 'claude-sonnet-5-5')) {
			$zaklad['fallbacks'] = 'default';
			$zaklad['_beta']     = [self::BETA_FALLBACK];
		}

		$povolena     = $relace['povolena'];
		$velikosti    = $relace['velikosti'];
		$nastroje_log = [];
		$usage_soucet = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];
		$cena         = 0.0;
		$final        = null;
		$chyba        = '';
		$ulozit       = true;
		$pouzity      = $zaklad['model'];

		for ($kolo = 1; $kolo <= self::MAX_KOL; $kolo++) {
			$uplynulo = microtime(true) - $start;

			if ($kolo > 1 && $uplynulo > self::MAX_SEKUND) {
				$chyba = 'cas';
				break;
			}

			$odpoved = $model->zprava($zaklad + ['messages' => $historie, '_timeout' => (int) max(5, min(40, self::CELKEM_SEKUND - $uplynulo))]);

			if (is_wp_error($odpoved)) {
				$chyba = $odpoved->get_error_message();
				self::zapocti_chybu_api();
				break;
			}

			$pouzity = (string) ($odpoved['model'] ?? $pouzity);
			$usage   = (array) ($odpoved['usage'] ?? []);

			foreach ($usage_soucet as $klic => $hodnota) {
				$usage_soucet[$klic] = $hodnota + (int) ($usage[$klic] ?? 0);
			}

			$cena += $model->cena_usd($pouzity, $usage);
			$obsah = is_array($odpoved['content'] ?? null) ? $odpoved['content'] : [];
			$stop  = (string) ($odpoved['stop_reason'] ?? '');

			if ($stop === 'refusal') {
				$chyba  = 'refusal';
				$final  = ['text' => 'S tímhle neporadím. Rád ale pomůžu s výběrem pracovního oblečení, obuvi nebo ochranných pomůcek.', 'produkty' => []];
				// Odmítnutou zprávu do relace neukládat, jinak by ji model
				// dostával znovu a odmítal by dál.
				$ulozit = false;
				break;
			}

			if ($stop === 'max_tokens' || $obsah === []) {
				$chyba = $stop === 'max_tokens' ? 'max_tokens' : 'prazdna_odpoved';
				break;
			}

			// Do historie jde obsah BEZE ZMĚNY (bloky myšlení jsou vázané na
			// rozhovor) — z původního JSON, protože json_decode(…, true) by
			// z prázdného `input: {}` udělal pole a API by příště vrátilo 400.
			$historie[] = ['role' => 'assistant', 'content' => self::obsah_pro_historii($odpoved, $obsah)];
			$volani     = array_values(array_filter($obsah, static fn($b): bool => is_array($b) && ($b['type'] ?? '') === 'tool_use'));

			if ($volani === []) {
				// Model odpověděl textem místo nástroje odpovez — bereme text, bez karet.
				$text  = implode('', array_map(static fn($b): string => ($b['type'] ?? '') === 'text' ? (string) $b['text'] : '', $obsah));
				$final = ['text' => trim($text), 'produkty' => []];
				break;
			}

			$vysledky = [];

			foreach ($volani as $blok) {
				$jmeno          = (string) ($blok['name'] ?? '');
				$vstup          = is_array($blok['input'] ?? null) ? $blok['input'] : [];
				$nastroje_log[] = ['nastroj' => $jmeno, 'vstup' => $vstup];

				[$obsah_vysledku, $je_chyba] = self::proved_nastroj($jmeno, $vstup, $povolena, $final, $velikosti);

				$vysledek = ['type' => 'tool_result', 'tool_use_id' => (string) ($blok['id'] ?? ''), 'content' => $obsah_vysledku];

				if ($je_chyba) {
					$vysledek['is_error'] = true;
				}

				$vysledky[] = $vysledek;
			}

			// Všechny výsledky v JEDNÉ zprávě uživatele (paralelní volání nástrojů).
			$historie[] = ['role' => 'user', 'content' => $vysledky];

			if ($final !== null) {
				break;
			}
		}

		if ($final === null) {
			self::zapis_log(['relace' => $relace['id'], 'dotaz' => $zprava, 'nastroje' => $nastroje_log, 'usage' => $usage_soucet, 'cena' => $cena, 'model' => $pouzity, 'chyba' => $chyba !== '' ? $chyba : 'max_kol', 'trvani' => $start]);
			$zaloha = self::zaloha($chyba !== '' ? $chyba : 'max_kol');

			// Uprostřed rozhovoru widget nepřesměruje, ukáže hlášku v rozhovoru.
			if ($relace['pocet_zprav'] > 0) {
				$zaloha['relace'] = $relace['id'];
			}

			return $zaloha;
		}

		[$karty, $vyhozena] = self::karty((array) ($final['produkty'] ?? []), $povolena, $velikosti);

		if ($ulozit) {
			self::uloz_relaci($relace['id'], [
				'historie'    => $historie,
				'povolena'    => array_values(array_unique($povolena)),
				'velikosti'   => $velikosti,
				'pocet_zprav' => $relace['pocet_zprav'] + 1,
			]);
		}

		$text = trim((string) ($final['text'] ?? ''));

		if ($text === '') {
			$text = $karty === [] ? 'Tady vám bohužel neporadím. Zkuste to prosím popsat jinak.' : 'Tohle by vám mohlo sednout:';
		}

		$vysledek = ['relace' => $relace['id'], 'text' => $text, 'karty' => $karty];
		$dotaz    = trim((string) ($final['dotaz'] ?? ''));

		if ($dotaz !== '') {
			$vysledek['dotaz']    = $dotaz;
			$vysledek['moznosti'] = array_slice(array_values(array_filter(array_map(
				static fn($m): string => mb_substr(trim(wp_strip_all_tags((string) $m)), 0, 80, 'UTF-8'),
				(array) ($final['moznosti'] ?? [])
			))), 0, 4);
		}

		self::zapis_log([
			'relace'   => $relace['id'],
			'dotaz'    => $zprava,
			'nastroje' => $nastroje_log,
			'odpoved'  => $text . ($dotaz !== '' ? "\n? " . $dotaz : ''),
			'karty'    => array_column($karty, 'id'),
			'vyhozena' => $vyhozena,
			'usage'    => $usage_soucet,
			'cena'     => $cena,
			'model'    => $pouzity,
			'chyba'    => $chyba,
			'trvani'   => $start,
		]);

		return $vysledek;
	}

	/**
	 * Karty jen pro ID, která vrátil nástroj; pořadí podle modelu, bez duplicit.
	 *
	 * @param array<int, mixed>                                      $navrzena
	 * @param list<int>                                              $povolena
	 * @param array<int, array{velikost: string, dostupnost: string}> $velikosti
	 * @return array{0: list<array<string, mixed>>, 1: list<int>} [karty, vyhozená ID]
	 */
	public static function karty(array $navrzena, array $povolena, array $velikosti): array {
		$karty    = [];
		$vyhozena = [];

		foreach (array_slice(array_values(array_unique(array_map('intval', $navrzena))), 0, 20) as $id) {
			if (!in_array($id, $povolena, true)) {
				$vyhozena[] = $id;
				continue;
			}

			$karta = Shop_Poradce_Katalog::karta($id);

			if ($karta === null) {
				continue;
			}

			// Štítek produktu by lhal, když zákazník chce jednu velikost:
			// „Skladem" u modelu, který v jeho velikosti skladem není.
			if (isset($velikosti[$id])) {
				$karta['velikost']   = $velikosti[$id]['velikost'];
				$karta['dostupnost'] = $velikosti[$id]['dostupnost'];
			}

			$karty[] = $karta;

			if (count($karty) >= self::MAX_KARET) {
				break;
			}
		}

		return [$karty, $vyhozena];
	}

	/**
	 * @param array<string, mixed>       $odpoved
	 * @param list<array<string, mixed>> $obsah
	 * @return list<mixed>
	 */
	private static function obsah_pro_historii(array $odpoved, array $obsah): array {
		if (isset($odpoved['_surovy']) && is_string($odpoved['_surovy'])) {
			$surovy = json_decode($odpoved['_surovy']);

			if (is_object($surovy) && isset($surovy->content) && is_array($surovy->content)) {
				return $surovy->content;
			}
		}

		return array_map(static function ($blok) {
			if (is_array($blok) && ($blok['type'] ?? '') === 'tool_use') {
				$blok['input'] = (object) ($blok['input'] ?? []);
			}

			return $blok;
		}, $obsah);
	}

	/**
	 * Provede jeden nástroj. Vrací [obsah pro tool_result, je_chyba].
	 *
	 * @param array<string, mixed>     $vstup
	 * @param list<int>                $povolena  doplní se o vrácená ID
	 * @param array<string, mixed>|null $final    nastaví se při `odpovez`
	 * @param array<int, array{velikost: string, dostupnost: string}> $velikosti
	 * @return array{0: string, 1: bool}
	 */
	private static function proved_nastroj(string $jmeno, array $vstup, array &$povolena, ?array &$final, array &$velikosti): array {
		switch ($jmeno) {
			case 'hledej_produkty':
				$vysledek = Shop_Poradce_Katalog::hledej($vstup);

				foreach ($vysledek['produkty'] as $produkt) {
					$id         = (int) $produkt['id'];
					$povolena[] = $id;

					if (isset($produkt['velikost_dostupnost'])) {
						$velikosti[$id] = ['velikost' => (string) ($vysledek['pouzite_filtry']['velikost'] ?? ''), 'dostupnost' => (string) $produkt['velikost_dostupnost']];
					} else {
						unset($velikosti[$id]);
					}
				}

				return [(string) wp_json_encode($vysledek, JSON_UNESCAPED_UNICODE), false];

			case 'detail_produktu':
				$detail = Shop_Poradce_Katalog::detail((int) ($vstup['id'] ?? 0));

				if ($detail === null) {
					return ['Produkt s tímto ID v nabídce není.', true];
				}

				$povolena[] = (int) $detail['id'];

				return [(string) wp_json_encode($detail, JSON_UNESCAPED_UNICODE), false];

			case 'odpovez':
				$final = [
					'text'     => mb_substr(trim(wp_strip_all_tags((string) ($vstup['text'] ?? ''))), 0, 600, 'UTF-8'),
					'produkty' => (array) ($vstup['produkty'] ?? []),
					'dotaz'    => mb_substr(trim(wp_strip_all_tags((string) ($vstup['dotaz'] ?? ''))), 0, 200, 'UTF-8'),
					'moznosti' => (array) ($vstup['moznosti'] ?? []),
				];

				return ['Odesláno zákazníkovi.', false];
		}

		return ["Nástroj {$jmeno} neexistuje.", true];
	}

	/**
	 * Text zákazníka do historie. Když historie končí výsledky nástrojů,
	 * připojí se do téže zprávy uživatele, ať se role pravidelně střídají.
	 *
	 * @param list<array<string, mixed>> $historie
	 */
	private static function pridej_text_zakaznika(array &$historie, string $zprava): void {
		$posledni = count($historie) - 1;

		if ($posledni >= 0 && ($historie[$posledni]['role'] ?? '') === 'user' && is_array($historie[$posledni]['content'])) {
			$historie[$posledni]['content'][] = ['type' => 'text', 'text' => $zprava];

			return;
		}

		$historie[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $zprava]]];
	}

	/** @return array<string, mixed> */
	public static function zaloha(string $duvod): array {
		return ['zaloha' => true, 'duvod' => $duvod, 'text' => 'Poradce teď neodpovídá, ukážeme vám výsledky běžného hledání.', 'karty' => []];
	}

	/** @return array{id: string, historie: list<array<string, mixed>>, povolena: list<int>, velikosti: array<int, mixed>, pocet_zprav: int} */
	private static function nacti_relaci(string $id): array {
		$data = wp_is_uuid($id) ? get_transient('shop_poradce_r_' . $id) : false;

		if (is_array($data) && isset($data['historie'])) {
			return [
				'id'          => $id,
				'historie'    => (array) $data['historie'],
				'povolena'    => array_map('intval', (array) ($data['povolena'] ?? [])),
				'velikosti'   => (array) ($data['velikosti'] ?? []),
				'pocet_zprav' => (int) ($data['pocet_zprav'] ?? 0),
			];
		}

		return ['id' => wp_generate_uuid4(), 'historie' => [], 'povolena' => [], 'velikosti' => [], 'pocet_zprav' => 0];
	}

	/** @param array<string, mixed> $data */
	private static function uloz_relaci(string $id, array $data): void {
		set_transient('shop_poradce_r_' . $id, $data, self::RELACE_TTL);
	}

	/** Klíč IP pro limity: IPv4 celá, IPv6 po /64. IP se neukládá, jen její HMAC. */
	public static function klic_ip(string $ip): string {
		$binarni = @inet_pton($ip); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- neplatná IP = „neznama"

		if ($binarni === false) {
			return 'neznama';
		}

		if (strlen($binarni) === 16) {
			$binarni = substr($binarni, 0, 8);
		}

		return substr(hash_hmac('sha256', $binarni, wp_salt('nonce')), 0, 24);
	}

	private static function povol_podle_ip(): bool {
		$ip   = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
		$klic = self::klic_ip($ip);

		return self::pocitadlo('ip_h_' . $klic . '_' . gmdate('YmdH'), HOUR_IN_SECONDS) <= self::MAX_ZA_HODINU
			&& self::pocitadlo('ip_d_' . $klic . '_' . gmdate('Ymd'), DAY_IN_SECONDS) <= self::MAX_ZA_DEN;
	}

	/** Zvýší čítač: s externí cache atomicky, bez ní přes transient (vývoj). */
	private static function pocitadlo(string $klic, int $ttl): int {
		if (wp_using_ext_object_cache()) {
			wp_cache_add($klic, 0, self::SKUPINA, $ttl);
			$hodnota = wp_cache_incr($klic, 1, self::SKUPINA);

			return is_int($hodnota) ? $hodnota : PHP_INT_MAX;
		}

		$hodnota = (int) get_transient('shop_poradce_' . $klic) + 1;
		set_transient('shop_poradce_' . $klic, $hodnota, $ttl);

		return $hodnota;
	}

	/** Slot pro souběžný rozhovor (SET NX v object cache). Bez externí cache se strop neuplatní. */
	private static function obsad_slot(): ?string {
		if (!wp_using_ext_object_cache()) {
			return 'bez-cache';
		}

		for ($i = 0; $i < self::MAX_SOUBEZNE; $i++) {
			if (wp_cache_add('slot_' . $i, getmypid(), self::SKUPINA, self::SLOT_TTL)) {
				return 'slot_' . $i;
			}
		}

		return null;
	}

	private static function uvolni_slot(string $slot): void {
		if ($slot !== 'bez-cache') {
			wp_cache_delete($slot, self::SKUPINA);
		}
	}

	private static function api_vypnute(): bool {
		$klic   = 'chyby_api_' . intdiv(time(), self::CHYBY_OKNO);
		$pocet  = wp_using_ext_object_cache() ? wp_cache_get($klic, self::SKUPINA) : get_transient('shop_poradce_' . $klic);

		return (int) $pocet >= self::MAX_CHYB_API;
	}

	private static function zapocti_chybu_api(): void {
		self::pocitadlo('chyby_api_' . intdiv(time(), self::CHYBY_OKNO), self::CHYBY_OKNO);
	}

	public static function tabulka(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABULKA;
	}

	public static function zajisti_tabulku(): void {
		if (get_option('shop_poradce_log_schema') === self::VERZE_SCHEMATU) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta('CREATE TABLE ' . self::tabulka() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			cas datetime NOT NULL,
			relace char(36) NOT NULL DEFAULT '',
			dotaz text NOT NULL,
			nastroje longtext NULL,
			odpoved text NULL,
			karty varchar(255) NOT NULL DEFAULT '',
			vyhozena varchar(255) NOT NULL DEFAULT '',
			tokeny_vstup int(10) unsigned NOT NULL DEFAULT 0,
			tokeny_vystup int(10) unsigned NOT NULL DEFAULT 0,
			cena_usd decimal(10,6) NOT NULL DEFAULT 0,
			model varchar(60) NOT NULL DEFAULT '',
			trvani_ms int(10) unsigned NOT NULL DEFAULT 0,
			chyba varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY cas (cas)
		) " . $wpdb->get_charset_collate() . ';');

		update_option('shop_poradce_log_schema', self::VERZE_SCHEMATU, false);
	}

	/** @param array<string, mixed> $zaznam */
	private static function zapis_log(array $zaznam): void {
		global $wpdb;

		self::zajisti_tabulku();
		$usage = (array) ($zaznam['usage'] ?? []);
		// Sloupec má 255 znaků; delší hodnota by INSERT tiše shodila — a přišli
		// bychom právě o podezřelý dotaz.
		$seznam = static fn($ids): string => mb_substr(implode(',', array_map('intval', (array) $ids)), 0, 255);

		$wpdb->insert(self::tabulka(), [
			'cas'           => current_time('mysql'),
			'relace'        => (string) ($zaznam['relace'] ?? ''),
			'dotaz'         => mb_substr((string) ($zaznam['dotaz'] ?? ''), 0, self::MAX_ZNAKU),
			'nastroje'      => (string) wp_json_encode($zaznam['nastroje'] ?? [], JSON_UNESCAPED_UNICODE),
			'odpoved'       => (string) ($zaznam['odpoved'] ?? ''),
			'karty'         => $seznam($zaznam['karty'] ?? []),
			'vyhozena'      => $seznam($zaznam['vyhozena'] ?? []),
			'tokeny_vstup'  => (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0),
			'tokeny_vystup' => (int) ($usage['output_tokens'] ?? 0),
			'cena_usd'      => round((float) ($zaznam['cena'] ?? 0), 6),
			'model'         => mb_substr((string) ($zaznam['model'] ?? ''), 0, 60),
			'trvani_ms'     => (int) round((microtime(true) - (float) ($zaznam['trvani'] ?? microtime(true))) * 1000),
			'chyba'         => mb_substr((string) ($zaznam['chyba'] ?? ''), 0, 250),
		]);
	}
}
