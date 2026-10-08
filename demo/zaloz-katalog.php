<?php
/**
 * Založí ukázkový obchod: nastavení WooCommerce (CZK, DPH 21 %, ceny bez
 * DPH zobrazované s DPH), kategorie, atributy, 31 produktů s velikostmi,
 * obrázky, stránky, dopravu, platby a nastavení poradce (simulace).
 *
 * Spouští ho blueprint.json (krok runPHP) po instalaci WooCommerce.
 * Data: katalog.json a obrazky/ ve stejné složce (generuje generuj_katalog.py).
 * Opakované spuštění nic nezdvojí — produkty se hledají podle kódu (SKU).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	require_once '/wordpress/wp-load.php';
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$data = json_decode((string) file_get_contents(__DIR__ . '/katalog.json'), true);

if (!is_array($data) || !function_exists('WC')) {
	echo "Chybí katalog.json nebo WooCommerce.\n";

	return;
}

// --- Obchod ------------------------------------------------------------------
$volby = [
	'blogname'                             => 'Ukázkový obchod',
	'blogdescription'                      => 'Pracovní oděvy a obuv (ukázka do portfolia)',
	'timezone_string'                      => 'Europe/Prague',
	'date_format'                          => 'j. n. Y',
	'woocommerce_default_country'          => 'CZ',
	'woocommerce_allowed_countries'        => 'specific',
	'woocommerce_specific_allowed_countries' => ['CZ'],
	'woocommerce_currency'                 => 'CZK',
	'woocommerce_currency_pos'             => 'right_space',
	'woocommerce_price_thousand_sep'       => ' ',
	'woocommerce_price_decimal_sep'        => ',',
	'woocommerce_price_num_decimals'       => '2',
	'woocommerce_calc_taxes'               => 'yes',
	'woocommerce_prices_include_tax'       => 'no',
	'woocommerce_tax_display_shop'         => 'incl',
	'woocommerce_tax_display_cart'         => 'incl',
	'woocommerce_tax_round_at_subtotal'    => 'no',
	'woocommerce_tax_based_on'             => 'base',
	'woocommerce_manage_stock'             => 'yes',
	'woocommerce_hide_out_of_stock_items'  => 'no',
	'woocommerce_enable_guest_checkout'    => 'yes',
	'woocommerce_enable_myaccount_registration' => 'yes',
	'woocommerce_registration_generate_password' => 'yes',
	'woocommerce_coming_soon'              => 'no',
	'woocommerce_store_pages_only'         => 'no',
	'woocommerce_onboarding_profile'       => ['skipped' => true],
	'woocommerce_task_list_hidden'         => 'yes',
	'woocommerce_show_marketplace_suggestions' => 'no',
	'woocommerce_allow_tracking'           => 'no',
	'woocommerce_analytics_enabled'        => 'no',
	'woocommerce_feature_order_attribution_enabled' => 'yes',
	'woocommerce_checkout_privacy_policy_text' => 'Osobní údaje použijeme jen k vyřízení objednávky. Podrobnosti najdete v zásadách [privacy_policy]. (Ukázka — nic se nikam neodesílá.)',
	'woocommerce_registration_privacy_policy_text' => 'Osobní údaje použijeme ke správě vašeho účtu. Podrobnosti najdete v zásadách [privacy_policy].',
	'woocommerce_permalinks'               => ['product_base' => '/produkt', 'category_base' => 'kategorie', 'tag_base' => 'stitek', 'attribute_base' => ''],
	// Moduly z repa.
	Shop_Poradce::OPTION_REZIM             => 'vsichni',
	Shop_Poradce::OPTION_MOTOR             => 'simulace',
	Shop_Poradce::OPTION_STROP             => 5,
];

foreach ($volby as $klic => $hodnota) {
	update_option($klic, $hodnota);
}

update_option('permalink_structure', '/%postname%/');

// DPH 21 % pro Česko (jednou).
global $wpdb;

if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'CZ'") === 0) {
	WC_Tax::_insert_tax_rate([
		'tax_rate_country'  => 'CZ',
		'tax_rate'          => '21.0000',
		'tax_rate_name'     => 'DPH',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 1,
		'tax_rate_order'    => 0,
		'tax_rate_class'    => '',
	]);
}

// --- Atributy -----------------------------------------------------------------
foreach ($data['atributy'] as $taxonomie => $popisek) {
	$slug = substr($taxonomie, 3);

	if (!wc_attribute_taxonomy_id_by_name($slug)) {
		wc_create_attribute(['name' => $popisek, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false]);
	}

	// Taxonomie se běžně registrují při init; v tomhle běhu je potřeba hned.
	if (!taxonomy_exists($taxonomie)) {
		register_taxonomy($taxonomie, ['product', 'product_variation'], ['hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false]);
	}
}

$poradi_velikosti = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'];

/** Term atributu podle názvu (založí, když chybí). Vrací slug. */
$term = static function (string $taxonomie, string $nazev) use ($poradi_velikosti): string {
	$existujici = get_term_by('name', $nazev, $taxonomie);

	if ($existujici instanceof WP_Term) {
		return $existujici->slug;
	}

	$novy = wp_insert_term($nazev, $taxonomie);

	if (is_wp_error($novy)) {
		return sanitize_title($nazev);
	}

	// Velikosti v přirozeném pořadí (písmenné podle tabulky, čísla podle hodnoty).
	if ($taxonomie === 'pa_velikost') {
		$poradi = array_search($nazev, $poradi_velikosti, true);
		update_term_meta((int) $novy['term_id'], 'order', $poradi !== false ? $poradi : (int) $nazev + 100);
	}

	return (string) get_term((int) $novy['term_id'], $taxonomie)->slug;
};

// --- Kategorie ----------------------------------------------------------------
$id_kategorie = [];

foreach ($data['kategorie'] as $k) {
	$existujici = get_term_by('slug', $k['slug'], 'product_cat');
	$rodic      = $k['rodic'] !== '' ? ($id_kategorie[$k['rodic']] ?? 0) : 0;

	if ($existujici instanceof WP_Term) {
		$id_kategorie[$k['slug']] = (int) $existujici->term_id;
		continue;
	}

	$novy = wp_insert_term($k['nazev'], 'product_cat', ['slug' => $k['slug'], 'parent' => $rodic, 'description' => $k['popis']]);
	$id_kategorie[$k['slug']] = is_wp_error($novy) ? 0 : (int) $novy['term_id'];
}

// --- Obrázky ------------------------------------------------------------------
$obrazek = static function (string $soubor, string $nazev): int {
	$zdroj = __DIR__ . '/obrazky/' . $soubor;

	if (!is_readable($zdroj)) {
		return 0;
	}

	$uploads = wp_upload_dir();
	wp_mkdir_p($uploads['path']);
	$cil = trailingslashit($uploads['path']) . 'ukazka-' . $soubor;
	copy($zdroj, $cil);

	$id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $nazev, 'post_status' => 'inherit'], $cil);

	if ($id > 0) {
		wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $cil));
		update_post_meta($id, '_wp_attachment_image_alt', $nazev);
	}

	return (int) $id;
};

// --- Produkty -----------------------------------------------------------------
$zalozeno = 0;

foreach ($data['produkty'] as $p) {
	if (wc_get_product_id_by_sku($p['sku'])) {
		continue;
	}

	$produkt = new WC_Product_Variable();
	$produkt->set_name($p['nazev']);
	$produkt->set_sku($p['sku']);
	$produkt->set_status('publish');
	$produkt->set_short_description($p['kratky_popis']);
	$produkt->set_description($p['popis']);
	$produkt->set_category_ids([$id_kategorie[$p['kategorie']] ?? 0]);
	$produkt->set_image_id($obrazek($p['obrazek'], $p['nazev']));
	$produkt->set_total_sales((int) $p['prodeje']);

	$atributy = [];
	$pozice   = 0;
	$velikosti = array_map(static fn(array $v): string => $v['velikost'], $p['varianty']);

	foreach (['pa_velikost' => $velikosti] + $p['atributy'] as $taxonomie => $hodnoty) {
		$slugy = array_map(static fn(string $h): string => $term($taxonomie, $h), $hodnoty);
		$a     = new WC_Product_Attribute();
		$a->set_id(wc_attribute_taxonomy_id_by_name(substr($taxonomie, 3)));
		$a->set_name($taxonomie);
		$a->set_options(array_map(static fn(string $s): int => (int) get_term_by('slug', $s, $taxonomie)->term_id, $slugy));
		$a->set_position($pozice++);
		$a->set_visible($taxonomie !== 'pa_znacka');
		$a->set_variation($taxonomie === 'pa_velikost');
		$atributy[] = $a;
	}

	$produkt->set_attributes($atributy);
	$id = $produkt->save();

	foreach ($p['varianty'] as $v) {
		$varianta = new WC_Product_Variation();
		$varianta->set_parent_id($id);
		$varianta->set_attributes(['pa_velikost' => $term('pa_velikost', $v['velikost'])]);
		$varianta->set_sku($v['sku']);
		$varianta->set_global_unique_id($v['ean']);
		// Ceny se ukládají BEZ DPH na 4 místa, jako z importu; celou korunu
		// s DPH dopočítá Shop_Cena_Cele_Koruny.
		$varianta->set_regular_price((string) round($v['cena'] / 1.21, 4));
		$varianta->set_manage_stock(true);
		$varianta->set_stock_quantity((int) $v['kusu']);
		$varianta->set_backorders($v['stav'] === 'onbackorder' ? 'notify' : 'no');
		$varianta->set_stock_status($v['stav']);
		$varianta->save();
	}

	WC_Product_Variable::sync($id);
	wc_delete_product_transients($id);
	++$zalozeno;
}

// --- Stránky ------------------------------------------------------------------
$stranky = [
	'doprava-a-platba'  => ['Doprava a platba', '<!-- wp:paragraph --><p>Výdejní místo 89 Kč, doručení na adresu 119 Kč, osobní odběr na prodejně zdarma. Od 2 000 Kč doprava zdarma. Platba převodem nebo na dobírku. (Ukázka — objednávky se nikam neodesílají.)</p><!-- /wp:paragraph -->'],
	'obchodni-podminky' => ['Obchodní podmínky', '<!-- wp:paragraph --><p>Ukázkový obchod do portfolia. Obchodní podmínky skutečného obchodu tu nejsou.</p><!-- /wp:paragraph -->'],
	'kontakt'           => ['Kontakt', '<!-- wp:paragraph --><p>Ukázková prodejna s.r.o., Ukázková 1, 100 00 Praha, obchod@example.cz (vymyšlené údaje).</p><!-- /wp:paragraph -->'],
];

foreach ($stranky as $slug => [$titulek, $obsah]) {
	if (!get_page_by_path($slug)) {
		wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $titulek, 'post_content' => $obsah]);
	}
}

// Obchod pod /obchod/; košík a pokladna klasickými shortcody (výběr výdejního místa je pod sazbou dopravy).
wp_update_post(['ID' => wc_get_page_id('shop'), 'post_name' => 'obchod', 'post_title' => 'Obchod']);
wp_update_post(['ID' => wc_get_page_id('cart'), 'post_name' => 'kosik', 'post_title' => 'Košík', 'post_content' => '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->']);
wp_update_post(['ID' => wc_get_page_id('checkout'), 'post_name' => 'pokladna', 'post_title' => 'Pokladna', 'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->']);
wp_update_post(['ID' => wc_get_page_id('myaccount'), 'post_name' => 'muj-ucet', 'post_title' => 'Můj účet']);

// --- Doprava a platby ---------------------------------------------------------
if (WC_Shipping_Zones::get_zones() === []) {
	$zona = new WC_Shipping_Zone();
	$zona->set_zone_name('Česko');
	$zona->add_location('CZ', 'country');
	$zona->save();

	$instance = $zona->add_shipping_method(Shop_Vydejni_Mista::METODA);
	update_option('woocommerce_' . Shop_Vydejni_Mista::METODA . '_' . $instance . '_settings', ['title' => 'Výdejní místo', 'cost' => '89', 'zdarma_od' => '2000']);

	$instance = $zona->add_shipping_method('flat_rate');
	update_option('woocommerce_flat_rate_' . $instance . '_settings', ['title' => 'Doručení na adresu', 'tax_status' => 'none', 'cost' => '119']);

	$instance = $zona->add_shipping_method('local_pickup');
	update_option('woocommerce_local_pickup_' . $instance . '_settings', ['title' => 'Osobní odběr na prodejně', 'tax_status' => 'none', 'cost' => '0']);
}

update_option('woocommerce_cod_settings', ['enabled' => 'yes', 'title' => 'Dobírka', 'description' => 'Platba při převzetí.', 'enable_for_methods' => [], 'enable_for_virtual' => 'yes']);
update_option('woocommerce_bacs_settings', ['enabled' => 'yes', 'title' => 'Převodem na účet', 'description' => 'Ukázka: platební údaje se nezobrazují.']);

// --- Pravidla adres -------------------------------------------------------------
// Feedy se generují až v dalším kroku blueprintu: nové trvalé odkazy (/produkt/…)
// platí až od dalšího požadavku.
flush_rewrite_rules(false);

echo "Ukázkový obchod založen: {$zalozeno} produktů.\n";
