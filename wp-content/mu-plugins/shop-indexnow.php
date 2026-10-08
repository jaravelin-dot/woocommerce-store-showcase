<?php
/**
 * mu-plugin: IndexNow — ohlašuje vyhledávačům nové a změněné stránky, aby
 * je nemusely hledat samy.
 *
 * Změny se sbírají do fronty (uložení produktu, změna skladu nebo stavu,
 * stažení z webu) a WP-Cron je jednou za 15 minut pošle najednou. Klíč se
 * vygeneruje sám a vydává se na adrese /<klíč>.txt, jak protokol vyžaduje.
 *
 * Odesílá se jen na produkci, jen po zapnutí volby `shop_indexnow_aktivni`
 * a jen když je vyplněná volba `shop_indexnow_cil` (adresa sdíleného
 * endpointu protokolu IndexNow, který ohlášení předá všem zapojeným
 * vyhledávačům). Z testovací kopie webu se nesmí ohlašovat adresy, které
 * neexistují. Fronta se plní vždy, takže jde zkontrolovat i nanečisto.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

const SHOP_INDEXNOW_KLIC    = 'shop_indexnow_klic';
const SHOP_INDEXNOW_FRONTA  = 'shop_indexnow_fronta';
const SHOP_INDEXNOW_AKTIVNI = 'shop_indexnow_aktivni';
const SHOP_INDEXNOW_HOOK    = 'shop_indexnow_odeslat';
const SHOP_INDEXNOW_DAVKA   = 10000;

function shop_indexnow_klic(): string {
	$klic = (string) get_option(SHOP_INDEXNOW_KLIC, '');

	if (!preg_match('/^[a-f0-9]{32}$/', $klic)) {
		$klic = bin2hex(random_bytes(16));
		update_option(SHOP_INDEXNOW_KLIC, $klic, true);
	}

	return $klic;
}

// Soubor s klíčem /<klíč>.txt na disku neleží, vydává ho tenhle hák.
add_action('init', static function (): void {
	$cesta = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$klic  = (string) get_option(SHOP_INDEXNOW_KLIC, '');

	if ($klic !== '' && $cesta === '/' . $klic . '.txt') {
		status_header(200);
		header('Content-Type: text/plain; charset=UTF-8');
		echo esc_html($klic);
		exit;
	}
}, 0);

/**
 * Přidá adresy do fronty. Fronta je mapa adresa => true, takže se stejná
 * adresa při deseti změnách skladu za čtvrt hodiny ohlásí jednou.
 *
 * @param array<int, string> $adresy
 */
function shop_indexnow_do_fronty(array $adresy): void {
	$host   = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
	$fronta = get_option(SHOP_INDEXNOW_FRONTA, []);
	$fronta = is_array($fronta) ? $fronta : [];

	foreach ($adresy as $adresa) {
		// Jen vlastní doména — cizí adresu by vyhledávač stejně odmítl.
		if (is_string($adresa) && $adresa !== '' && wp_parse_url($adresa, PHP_URL_HOST) === $host) {
			$fronta[$adresa] = true;
		}
	}

	update_option(SHOP_INDEXNOW_FRONTA, $fronta, false);
}

function shop_indexnow_produkt(int $id): void {
	$post = get_post($id);

	if ($post && $post->post_type === 'product_variation') {
		$post = get_post((int) $post->post_parent);
	}

	if ($post && $post->post_type === 'product') {
		// U staženého produktu se ohlásí poslední známá adresa — vyhledávač
		// zjistí, že vrací 404, a vyřadí ji.
		shop_indexnow_do_fronty([(string) get_permalink($post)]);
	}
}

add_action('woocommerce_update_product', 'shop_indexnow_produkt');
add_action('woocommerce_new_product', 'shop_indexnow_produkt');
add_action('woocommerce_product_set_stock_status', static fn($id) => shop_indexnow_produkt((int) $id));
add_action('woocommerce_variation_set_stock_status', static fn($id) => shop_indexnow_produkt((int) $id));
add_action('transition_post_status', static function (string $novy, string $stary, WP_Post $post): void {
	if ($post->post_type === 'product' && $novy !== $stary && ($novy === 'publish' || $stary === 'publish')) {
		shop_indexnow_produkt((int) $post->ID);
	}
}, 10, 3);

add_filter('cron_schedules', static function (array $plany): array {
	$plany['shop_15_minut'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Každých 15 minut'];

	return $plany;
});
add_action('init', static function (): void {
	if (!wp_next_scheduled(SHOP_INDEXNOW_HOOK)) {
		wp_schedule_event(time() + 60, 'shop_15_minut', SHOP_INDEXNOW_HOOK);
	}
});
add_action(SHOP_INDEXNOW_HOOK, 'shop_indexnow_odeslat_frontu');

function shop_indexnow_cil(): string {
	return esc_url_raw(trim((string) get_option('shop_indexnow_cil', '')));
}

function shop_indexnow_aktivni(): bool {
	return wp_get_environment_type() === 'production' && (bool) get_option(SHOP_INDEXNOW_AKTIVNI, false) && shop_indexnow_cil() !== '';
}

/**
 * Pošle frontu. Adresy se mažou jen po úspěšném odeslání; při chybě
 * zůstanou na další běh.
 *
 * @return array<string, mixed>
 */
function shop_indexnow_odeslat_frontu(): array {
	$fronta = get_option(SHOP_INDEXNOW_FRONTA, []);

	if (!is_array($fronta) || $fronta === []) {
		return ['adres' => 0];
	}

	if (!shop_indexnow_aktivni()) {
		return ['adres' => count($fronta), 'odeslano' => false, 'duvod' => 'vypnuto mimo produkci'];
	}

	$adresy   = array_keys($fronta);
	$vysledek = shop_indexnow_odeslat($adresy);

	if ($vysledek['ok']) {
		// Mezi načtením a odesláním mohly přibýt nové adresy — mažou se jen ty odeslané.
		$aktualni = (array) get_option(SHOP_INDEXNOW_FRONTA, []);

		foreach ($adresy as $adresa) {
			unset($aktualni[$adresa]);
		}

		update_option(SHOP_INDEXNOW_FRONTA, $aktualni, false);
	}

	return $vysledek;
}

/**
 * @param array<int, string> $adresy
 * @return array<string, mixed>
 */
function shop_indexnow_odeslat(array $adresy): array {
	$klic = shop_indexnow_klic();
	$kody = [];
	$ok   = true;

	foreach (array_chunk(array_values(array_unique($adresy)), SHOP_INDEXNOW_DAVKA) as $davka) {
		$odpoved = wp_remote_post(shop_indexnow_cil(), [
			'timeout' => 30,
			'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
			'body'    => (string) wp_json_encode([
				'host'        => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
				'key'         => $klic,
				'keyLocation' => home_url('/' . $klic . '.txt'),
				'urlList'     => $davka,
			]),
		]);

		$kod    = is_wp_error($odpoved) ? $odpoved->get_error_message() : wp_remote_retrieve_response_code($odpoved);
		$kody[] = $kod;

		// 200 = přijato, 202 = přijato, klíč se teprve ověřuje.
		if (!in_array($kod, [200, 202], true)) {
			$ok = false;
		}
	}

	return ['ok' => $ok, 'adres' => count($adresy), 'kody' => $kody, 'cas' => gmdate('c')];
}

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::add_command('shop indexnow', static function (array $args): void {
		if (($args[0] ?? 'fronta') === 'klic') {
			WP_CLI::log(home_url('/' . shop_indexnow_klic() . '.txt'));

			return;
		}

		WP_CLI::log((string) wp_json_encode(shop_indexnow_odeslat_frontu(), JSON_UNESCAPED_UNICODE));
	}, ['shortdesc' => 'IndexNow: wp shop indexnow [fronta|klic]']);
}
