<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Cokoli, co umí odpovědět na požadavek Messages API. Smyčka poradce
 * (Shop_Poradce) neví, jestli mluví se skutečným modelem, nebo se simulací —
 * obě implementace vracejí odpověď stejného tvaru.
 */
interface Shop_Poradce_Model {
	/**
	 * @param array<string, mixed> $pozadavek tělo POST /v1/messages; `_beta` = hlavička anthropic-beta,
	 *                                        `_timeout` = strop v sekundách (neposílá se)
	 * @return array<string, mixed>|WP_Error dekódovaná odpověď; `_surovy` = původní JSON
	 */
	public function zprava(array $pozadavek);

	/** Cena odpovědi v USD podle `usage`. */
	public function cena_usd(string $model, array $usage): float;
}

/**
 * Tenký klient Claude Messages API. O e-shopu nic neví.
 *
 * Přes wp_remote_post, ne přes SDK: plugin se nasazuje kopírováním souborů
 * bez správce balíčků a ostatní napojení jdou také přes wp_remote_*. Balík
 * s vlastními HTTP závislostmi by ve WordPressu riskoval kolize verzí
 * s jinými pluginy. Rozhraní je jeden endpoint.
 *
 * Klíč: konstanta SHOP_ANTHROPIC_KLIC ve wp-config.php (doporučeno — do
 * databáze se nedostane), nebo pro vyzkoušení volba v administraci.
 * Nikdy do logu.
 */
class Shop_Poradce_Claude implements Shop_Poradce_Model {
	private const URL       = 'https://api.anthropic.com/v1/messages';
	private const VERZE_API = '2023-06-01';

	/** Ceník v USD za milion tokenů: vstup, výstup, čtení z cache. Zápis do cache = 1,25 × vstup. */
	private const CENIK = [
		'claude-opus-5-5'   => [4.00, 20.00, 0.20],
		'claude-sonnet-5-5' => [2.00, 10.00, 0.20],
		'claude-haiku-4-5'  => [1.00, 5.00, 0.10],
	];

	public static function klic(): string {
		if (defined('SHOP_ANTHROPIC_KLIC') && is_string(SHOP_ANTHROPIC_KLIC) && SHOP_ANTHROPIC_KLIC !== '') {
			return SHOP_ANTHROPIC_KLIC;
		}

		return trim((string) get_option('shop_poradce_api_klic', ''));
	}

	public static function nastaveno(): bool {
		return self::klic() !== '';
	}

	public function zprava(array $pozadavek) {
		$klic = self::klic();

		if ($klic === '') {
			return new WP_Error('shop_poradce_bez_klice', 'Chybí API klíč.');
		}

		$hlavicky = ['content-type' => 'application/json', 'x-api-key' => $klic, 'anthropic-version' => self::VERZE_API];

		if (!empty($pozadavek['_beta'])) {
			$hlavicky['anthropic-beta'] = implode(',', (array) $pozadavek['_beta']);
		}

		$timeout = max(5, (int) ($pozadavek['_timeout'] ?? 40));
		unset($pozadavek['_beta'], $pozadavek['_timeout']);

		$telo = wp_json_encode($pozadavek, JSON_UNESCAPED_UNICODE);

		if (!is_string($telo)) {
			return new WP_Error('shop_poradce_json', 'Požadavek nejde zakódovat do JSON.');
		}

		// Jeden opakovaný pokus jen na rychlou chybu (přetížení, 5xx) a jen
		// když zbývá čas. Timeout se NEopakuje: zákazník už čekal celý.
		$konec = microtime(true) + $timeout;

		for ($pokus = 1; $pokus <= 2; $pokus++) {
			$zbyva = (int) floor($konec - microtime(true));

			if ($pokus > 1 && $zbyva < 5) {
				break;
			}

			$odpoved = wp_remote_post(self::URL, ['timeout' => $pokus === 1 ? $timeout : $zbyva, 'headers' => $hlavicky, 'body' => $telo]);

			if (is_wp_error($odpoved)) {
				return new WP_Error('shop_poradce_sit', $odpoved->get_error_message());
			}

			$kod    = (int) wp_remote_retrieve_response_code($odpoved);
			$surovy = (string) wp_remote_retrieve_body($odpoved);
			$data   = json_decode($surovy, true);

			if ($kod === 200 && is_array($data)) {
				$data['_surovy'] = $surovy;

				return $data;
			}

			if ($pokus === 1 && in_array($kod, [429, 500, 502, 503, 529], true)) {
				sleep(1);
				continue;
			}

			$typ = is_array($data) ? (string) ($data['error']['type'] ?? '') : '';

			return new WP_Error('shop_poradce_api', "HTTP {$kod} {$typ}", ['status' => $kod]);
		}

		return new WP_Error('shop_poradce_api', 'Došel čas na opakování.');
	}

	/**
	 * Cena podle modelu, který odpověď SKUTEČNĚ vyrobil (při záložním
	 * přepnutí to může být jiný než požadovaný).
	 *
	 * @param array<string, mixed> $usage
	 */
	public function cena_usd(string $model, array $usage): float {
		$cenik = self::CENIK['claude-opus-5-5'];

		foreach (self::CENIK as $klic => $ceny) {
			if (str_starts_with($model, $klic)) {
				$cenik = $ceny;
				break;
			}
		}

		[$vstup, $vystup, $cteni] = $cenik;

		return ((int) ($usage['input_tokens'] ?? 0) * $vstup
			+ (int) ($usage['output_tokens'] ?? 0) * $vystup
			+ (int) ($usage['cache_creation_input_tokens'] ?? 0) * $vstup * 1.25
			+ (int) ($usage['cache_read_input_tokens'] ?? 0) * $cteni) / 1_000_000;
	}
}

/**
 * Simulace modelu pro ukázku bez API klíče.
 *
 * Deterministicky rozebere dotaz klíčovými slovy (druh zboží, třída obuvi,
 * velikost, pro koho, cena, vlastnosti), zavolá STEJNÉ nástroje jako
 * skutečný model a odpoví šablonou. Prochází tedy celou smyčkou poradce
 * včetně pojistek (karty jen z výsledků nástrojů, strop útraty, log).
 *
 * Navazující zpráva („dámské", „43") doplní parametry z předchozího hledání
 * v historii rozhovoru — stejně jako by to udělal model.
 */
class Shop_Poradce_Simulace implements Shop_Poradce_Model {
	public const MODEL = 'simulace';

	/** Kořen slova (bez diakritiky) → kategorie ukázkového katalogu. Pořadí = priorita. */
	private const KATEGORIE = [
		'holin'     => 'holinky',
		'polobot'   => 'bezpecnostni-obuv',
		'kotnik'    => 'bezpecnostni-obuv',
		'sandal'    => 'pracovni-obuv',
		'pantof'    => 'pracovni-obuv',
		'obuv'      => 'obuv',
		'boty'      => 'obuv',
		'bota'      => 'obuv',
		'monterk'   => 'kalhoty',
		'kalhot'    => 'kalhoty',
		'kras'      => 'kalhoty',
		'bund'      => 'bundy-a-vesty',
		'vest'      => 'bundy-a-vesty',
		'softshell' => 'bundy-a-vesty',
		'mikin'     => 'trika-a-mikiny',
		'trick'     => 'trika-a-mikiny',
		'trik'      => 'trika-a-mikiny',
		'rukavic'   => 'rukavice',
	];

	/** Kořen slova → hodnota atributu pa_vlastnosti. */
	private const VLASTNOSTI = [
		'zimn'     => 'zateplene',
		'teplou'   => 'zateplene',
		'tepl'     => 'zateplene',
		'zateplen' => 'zateplene',
		'mokr'     => 'nepromokave',
		'dest'     => 'nepromokave',
		'nepromok' => 'nepromokave',
		'reflex'   => 'reflexni',
		'lehk'     => 'lehke',
	];

	/** Kořen slova → pracovní prostředí (pa_prostredi). */
	private const PROSTREDI = [
		'stavb'   => 'stavba',
		'zednik'  => 'stavba',
		'lesen'   => 'stavba',
		'sklad'   => 'sklad-a-logistika',
		'kuchyn'  => 'gastro',
		'gastro'  => 'gastro',
		'dilna'   => 'dilna',
		'montaz'  => 'dilna',
	];

	public function zprava(array $pozadavek) {
		$historie = (array) ($pozadavek['messages'] ?? []);
		$posledni = end($historie);
		$obsah    = is_array($posledni) ? (array) ($posledni['content'] ?? []) : [];

		$vysledky = array_values(array_filter($obsah, static fn($b): bool => is_array($b) && ($b['type'] ?? '') === 'tool_result'));
		$texty    = array_values(array_filter($obsah, static fn($b): bool => is_array($b) && ($b['type'] ?? '') === 'text'));

		// Nová zpráva zákazníka → hledej. Výsledek nástroje → odpověz (nebo uvolni podmínku).
		if ($texty !== []) {
			$parametry = self::sluc(self::predchozi_hledani($historie), self::rozbor((string) end($texty)['text']));

			return self::tah_nastroje('hledej_produkty', $parametry, $historie);
		}

		$vysledek = $vysledky !== [] ? json_decode((string) ($vysledky[0]['content'] ?? ''), true) : null;
		$hledani  = self::predchozi_hledani($historie);

		if (is_array($vysledek) && (int) ($vysledek['celkem'] ?? 0) === 0 && !isset($hledani['_uvolneno'])) {
			$uvolnene = self::uvolni($hledani);

			if ($uvolnene !== null) {
				return self::tah_nastroje('hledej_produkty', $uvolnene, $historie);
			}
		}

		return self::tah_nastroje('odpovez', self::odpoved(is_array($vysledek) ? $vysledek : [], $hledani), $historie);
	}

	public function cena_usd(string $model, array $usage): float {
		return 0.0;
	}

	/**
	 * Rozbor dotazu na parametry nástroje hledej_produkty.
	 *
	 * @return array<string, mixed>
	 */
	public static function rozbor(string $dotaz): array {
		$puvodni = $dotaz;
		$dotaz   = self::bez_diakritiky($dotaz);
		$slova   = preg_split('/[^a-z0-9]+/', $dotaz, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$p       = [];

		foreach (self::KATEGORIE as $koren => $slug) {
			if (self::obsahuje_koren($slova, $koren)) {
				$p['kategorie'] = $slug;
				break;
			}
		}

		if (preg_match('/\b(s1p|s1|s2|s3|s5|o1|o2)\b/', $dotaz, $m) === 1) {
			$p['filtry']['pa_bezpecnostni_trida'] = [$m[1]];
			$p['kategorie'] = $p['kategorie'] ?? 'obuv';
		}

		if (self::obsahuje_koren($slova, 'dams') || self::obsahuje_koren($slova, 'zen')) {
			$p['filtry']['pa_pohlavi'] = ['damske', 'unisex'];
		} elseif (self::obsahuje_koren($slova, 'pans') || self::obsahuje_koren($slova, 'muz')) {
			$p['filtry']['pa_pohlavi'] = ['panske', 'unisex'];
		}

		foreach (self::VLASTNOSTI as $koren => $hodnota) {
			if (self::obsahuje_koren($slova, $koren)) {
				$p['filtry']['pa_vlastnosti'] = array_values(array_unique(array_merge($p['filtry']['pa_vlastnosti'] ?? [], [$hodnota])));
			}
		}

		foreach (self::PROSTREDI as $koren => $hodnota) {
			if (self::obsahuje_koren($slova, $koren)) {
				$p['filtry']['pa_prostredi'] = [$hodnota];
				break;
			}
		}

		// Cena: „do 1500", „do 1 500 Kč", „max 900".
		if (preg_match('/\b(?:do|max|maximalne|nejvys)\s+(\d[\d ]{1,6})\s*(?:kc|korun)?/', $dotaz, $m) === 1) {
			$p['cena_do'] = (int) str_replace(' ', '', $m[1]);
			$dotaz        = str_replace($m[0], ' ', $dotaz);
		}

		// Velikost: písmenná (XS–3XL), nebo číslo 35–50 (obuv) či 6–11 u rukavic.
		if (preg_match('/\b(xs|s|m|l|xl|xxl|2xl|3xl)\b/', $dotaz, $m) === 1 && ($m[1] !== 's' || preg_match('/\bvel\w*\s+s\b/', $dotaz) === 1)) {
			$p['velikost'] = strtoupper($m[1]);
		} elseif (preg_match('/\b(3[5-9]|4[0-9]|50)\b/', $dotaz, $m) === 1) {
			$p['velikost'] = $m[1];
		} elseif (($p['kategorie'] ?? '') === 'rukavice' && preg_match('/\b([6-9]|1[01])\b/', $dotaz, $m) === 1) {
			$p['velikost'] = $m[1];
		}

		// Nic známého → krátký volný text (název modelu nebo značky).
		if (!isset($p['kategorie']) && !isset($p['filtry']) && !isset($p['velikost'])) {
			$p['text'] = mb_substr(trim($puvodni), 0, 40, 'UTF-8');
		}

		return $p;
	}

	/** @param list<string> $slova */
	private static function obsahuje_koren(array $slova, string $koren): bool {
		foreach ($slova as $slovo) {
			if (str_starts_with($slovo, $koren)) {
				return true;
			}
		}

		return false;
	}

	private static function bez_diakritiky(string $text): string {
		return strtr(mb_strtolower($text, 'UTF-8'), [
			'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n',
			'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
		]);
	}

	/**
	 * Parametry posledního hledání v rozhovoru (pro navazující zprávu).
	 *
	 * @param list<array<string, mixed>> $historie
	 * @return array<string, mixed>
	 */
	private static function predchozi_hledani(array $historie): array {
		for ($i = count($historie) - 1; $i >= 0; $i--) {
			if (($historie[$i]['role'] ?? '') !== 'assistant') {
				continue;
			}

			foreach (array_reverse((array) $historie[$i]['content']) as $blok) {
				$blok = (array) $blok;

				if (($blok['type'] ?? '') === 'tool_use' && ($blok['name'] ?? '') === 'hledej_produkty') {
					return json_decode((string) wp_json_encode($blok['input']), true) ?: [];
				}
			}
		}

		return [];
	}

	/**
	 * Nová zpráva doplní nebo přepíše předchozí parametry. Volný text se
	 * nepřenáší — navazující „a dámské?" nemá hledat staré slovo.
	 *
	 * @param array<string, mixed> $stare
	 * @param array<string, mixed> $nove
	 * @return array<string, mixed>
	 */
	private static function sluc(array $stare, array $nove): array {
		unset($stare['_uvolneno'], $stare['text']);

		$obuv         = ['obuv', 'bezpecnostni-obuv', 'pracovni-obuv', 'holinky'];
		$jiny_druh    = isset($nove['kategorie']) && ($stare['kategorie'] ?? '') !== $nove['kategorie']
			// „S3" samo nastaví obecnou obuv — to není nový druh, když už hledáme polobotky.
			&& !($nove['kategorie'] === 'obuv' && in_array($stare['kategorie'] ?? '', $obuv, true));

		if ($stare === [] || isset($nove['text']) || $jiny_druh) {
			return $nove;
		}

		$vysledek           = array_merge($stare, array_diff_key($nove, ['filtry' => 1, 'kategorie' => 1]));
		$vysledek['filtry'] = array_merge((array) ($stare['filtry'] ?? []), (array) ($nove['filtry'] ?? []));

		if ($vysledek['filtry'] === []) {
			unset($vysledek['filtry']);
		}

		return $vysledek;
	}

	/**
	 * Uvolní jednu podmínku: nejdřív velikost, pak vlastnosti, pak prostředí.
	 *
	 * @param array<string, mixed> $p
	 * @return array<string, mixed>|null
	 */
	private static function uvolni(array $p): ?array {
		foreach (['velikost' => 'velikost', 'pa_vlastnosti' => 'vlastnosti', 'pa_prostredi' => 'prostředí', 'cena_do' => 'cenu'] as $klic => $popis) {
			if (isset($p[$klic])) {
				unset($p[$klic]);
				$p['_uvolneno'] = $popis;

				return $p;
			}

			if (isset($p['filtry'][$klic])) {
				unset($p['filtry'][$klic]);
				$p['_uvolneno'] = $popis;

				return $p;
			}
		}

		return null;
	}

	/**
	 * Závěrečná odpověď ze šablony.
	 *
	 * @param array<string, mixed> $vysledek výsledek posledního hledání
	 * @param array<string, mixed> $hledani  jeho parametry
	 * @return array<string, mixed>
	 */
	private static function odpoved(array $vysledek, array $hledani): array {
		$produkty = array_slice((array) ($vysledek['produkty'] ?? []), 0, 4);

		if ($produkty === []) {
			return [
				'text'     => 'Podle zadání jsem v nabídce nic nenašel. Zkuste to popsat jinak, nebo se zeptejte na prodejně.',
				'produkty' => [],
			];
		}

		$skladem = count(array_filter($produkty, static fn(array $p): bool => ($p['dostupnost'] ?? '') === 'skladem'));
		$text    = 'Vybral jsem ' . count($produkty) . ' ' . (count($produkty) === 1 ? 'model' : (count($produkty) < 5 ? 'modely' : 'modelů'));

		if (isset($hledani['filtry']['pa_bezpecnostni_trida'])) {
			$text .= ' ve třídě ' . strtoupper(implode('/', $hledani['filtry']['pa_bezpecnostni_trida']));
		}

		if (isset($hledani['velikost'])) {
			$text .= ', které máme ve velikosti ' . $hledani['velikost'];
		}

		$text .= '.';

		if (isset($hledani['_uvolneno'])) {
			$text = 'Přesnou shodu nemáme, proto jsem vynechal ' . $hledani['_uvolneno'] . '. ' . $text;
		}

		$text .= $skladem === count($produkty) ? ' Všechny jsou u nás skladem.' : ' Některé objednáváme u výrobce, na kartě to uvidíte.';

		$odpoved = ['text' => $text, 'produkty' => array_map(static fn(array $p): int => (int) $p['id'], $produkty)];

		// Jedna doplňující otázka, jen když výběr zásadně mění.
		if (!isset($hledani['velikost']) && in_array($hledani['kategorie'] ?? '', ['obuv', 'bezpecnostni-obuv', 'pracovni-obuv', 'holinky'], true)) {
			$odpoved['dotaz']    = 'Jakou velikost nosíte? Ukážu jen to, co v ní máme.';
			$odpoved['moznosti'] = ['41', '42', '43', '44'];
		} elseif (!isset($hledani['filtry']['pa_pohlavi']) && in_array($hledani['kategorie'] ?? '', ['kalhoty', 'bundy-a-vesty', 'trika-a-mikiny'], true)) {
			$odpoved['dotaz']    = 'Hledáte pánský, nebo dámský střih?';
			$odpoved['moznosti'] = ['Pánský', 'Dámský'];
		}

		return $odpoved;
	}

	/**
	 * Odpověď ve tvaru Messages API: jeden blok tool_use.
	 *
	 * @param array<string, mixed>       $vstup
	 * @param list<array<string, mixed>> $historie
	 * @return array<string, mixed>
	 */
	private static function tah_nastroje(string $nastroj, array $vstup, array $historie): array {
		$znaku = strlen((string) wp_json_encode($historie));

		return [
			'model'       => self::MODEL,
			'stop_reason' => 'tool_use',
			'content'     => [[
				'type'  => 'tool_use',
				'id'    => 'sim_' . substr(md5($nastroj . count($historie) . wp_json_encode($vstup)), 0, 16),
				'name'  => $nastroj,
				'input' => $vstup,
			]],
			// Odhad tokenů (≈ 4 znaky na token), aby log a přehled útraty ukazovaly realistická čísla.
			'usage'       => ['input_tokens' => intdiv($znaku, 4), 'output_tokens' => 60],
		];
	}
}
