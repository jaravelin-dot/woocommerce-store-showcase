<?php
/**
 * Integrační testy uvnitř WordPressu + WooCommerce s ukázkovým katalogem.
 *
 * Spouští je `npm run test:integrace` (Playground CLI run-blueprint: stejná
 * instalace jako demo + tenhle soubor). Výsledek se zapíše do /vystup/integrace.txt
 * (pracovní složka mimo repo), runner ho vypíše; při chybě skript navíc vyhodí výjimku.
 */
declare(strict_types=1);

require_once '/wordpress/wp-load.php';

$vystup = [];
$chyb   = 0;

$over = static function (string $popis, bool $podminka, string $detail = '') use (&$vystup, &$chyb): void {
	$vystup[] = ($podminka ? '  OK    ' : '  CHYBA ') . $popis . ($podminka || $detail === '' ? '' : "\n        " . $detail);
	$chyb    += $podminka ? 0 : 1;
};

/** Falešný model: vrací předem dané tahy (odpovědi Messages API). */
final class Falesny_Model implements Shop_Poradce_Model {
	/** @param list<array<string, mixed>|WP_Error> $tahy */
	public function __construct(private array $tahy) {}

	public function zprava(array $pozadavek) {
		return array_shift($this->tahy) ?? new WP_Error('konec', 'Došly tahy.');
	}

	public function cena_usd(string $model, array $usage): float {
		return 0.01;
	}
}

$tah = static fn(string $nastroj, array $vstup): array => [
	'model'       => 'falesny',
	'stop_reason' => 'tool_use',
	'content'     => [['type' => 'tool_use', 'id' => 't_' . wp_generate_password(8, false), 'name' => $nastroj, 'input' => $vstup]],
	'usage'       => ['input_tokens' => 1000, 'output_tokens' => 100],
];

// --- AI poradce ----------------------------------------------------------------
$vystup[] = 'AI poradce';
$cizi_id  = (int) get_posts(['post_type' => 'product', 'numberposts' => 1, 'fields' => 'ids', 's' => 'rukavice'])[0];
$hledani  = Shop_Poradce_Katalog::hledej(['kategorie' => 'holinky']);
$vracene  = array_column($hledani['produkty'], 'id');

$over('hledání v kategorii holínky něco vrátí', count($vracene) > 0);

$vysledek = Shop_Poradce::odpovez_na('', 'holínky', new Falesny_Model([
	$tah('hledej_produkty', ['kategorie' => 'holinky']),
	$tah('odpovez', ['text' => 'Tady jsou.', 'produkty' => [$cizi_id, $vracene[0]]]),
]));
$karty = array_column($vysledek['karty'] ?? [], 'id');
$over('karta jen pro ID, které vrátil nástroj', $karty === [$vracene[0]], 'karty: ' . wp_json_encode($karty));

$log = $GLOBALS['wpdb']->get_row('SELECT vyhozena FROM ' . Shop_Poradce::tabulka() . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
$over('vyhozené ID je v logu', ($log['vyhozena'] ?? '') === (string) $cizi_id, 'log: ' . wp_json_encode($log));

$prazdne = Shop_Poradce_Katalog::hledej(['kategorie' => 'holinky', 'filtry' => ['pa_bezpecnostni_trida' => ['neexistuje']]]);
$over('neplatný filtr se nezahodí tiše (0 + poznámka)', $prazdne['celkem'] === 0 && isset($prazdne['poznamka']));

$karta = Shop_Poradce_Katalog::karta($vracene[0]);
$over('karta nenese nic mimo bílý seznam polí', array_keys((array) $karta) === ['id', 'nazev', 'cena_kc', 'cena_text', 'dostupnost', 'fotka', 'odkaz']);

$chyba = Shop_Poradce::odpovez_na('', 'boty', new Falesny_Model([new WP_Error('sit', 'timeout')]));
$over('chyba API → záloha na běžné hledání', ($chyba['zaloha'] ?? false) === true);

$strop = Shop_Poradce::strop_usd();
update_option(Shop_Poradce::OPTION_STROP, 0.1);
$GLOBALS['wpdb']->insert(Shop_Poradce::tabulka(), ['cas' => current_time('mysql'), 'dotaz' => 'test', 'cena_usd' => 0.2]);
$pres_strop = Shop_Poradce::odpovez_na('', 'boty', new Falesny_Model([]));
$over('po dosažení denního stropu → záloha', ($pres_strop['duvod'] ?? '') === 'strop', wp_json_encode($pres_strop));
update_option(Shop_Poradce::OPTION_STROP, $strop);
$GLOBALS['wpdb']->query("DELETE FROM " . Shop_Poradce::tabulka() . " WHERE dotaz = 'test'");

$sim = Shop_Poradce::odpovez_na('', 'boty do mokra na stavbu, velikost 43', new Shop_Poradce_Simulace());
$over('simulace: karty ve velikosti 43', ($sim['karty'] ?? []) !== [] && array_unique(array_column($sim['karty'], 'velikost')) === ['43'], wp_json_encode($sim));

$navazujici = Shop_Poradce::odpovez_na((string) ($sim['relace'] ?? ''), 'dámské', new Shop_Poradce_Simulace());
$over('simulace: navazující zpráva drží relaci', ($navazujici['relace'] ?? '') === ($sim['relace'] ?? 'x'));

// --- Ceny na celé koruny --------------------------------------------------------
$vystup[] = 'Ceny';
$necele = [];

foreach (get_posts(['post_type' => 'product_variation', 'numberposts' => -1, 'fields' => 'ids']) as $id) {
	$varianta = wc_get_product($id);
	$s_dph    = (float) wc_get_price_including_tax($varianta);
	$tri_kusy = (float) wc_get_price_including_tax($varianta, ['qty' => 3]);

	if (abs($s_dph - round($s_dph)) > 0.001 || abs($tri_kusy - round($tri_kusy)) > 0.001) {
		$necele[] = $varianta->get_sku() . ' ' . $s_dph;
	}
}

$over('všechny varianty mají cenu s DPH na celé koruny (1 i 3 ks)', $necele === [], implode(', ', array_slice($necele, 0, 5)));

$rozpeti = wc_get_product(wc_get_product_id_by_sku('UK-021'))->get_price_html();
$over('rozpětí ceny variabilního produktu bez haléřů', !str_contains(wp_strip_all_tags($rozpeti), ',01'), wp_strip_all_tags($rozpeti));

// --- Feedy -----------------------------------------------------------------------
$vystup[] = 'Feedy';
$stat = Shop_Feedy::generuj('google');
$xml  = simplexml_load_file(Shop_Feedy::cesta_souboru('google'));
$over('google feed: položka na velikost', $stat['polozek'] > $stat['produktu'], wp_json_encode($stat));
$platne = true;

foreach ($xml->channel->item as $item) {
	$g = $item->children('http://base.google.com/ns/1.0');

	if ((string) $g->gtin !== '' && !Shop_Feedy::ean_platny((string) $g->gtin)) {
		$platne = false;
	}
}

$over('google feed: všechny EAN mají platnou kontrolní číslici', $platne);
$over('heureka feed se vygeneruje', Shop_Feedy::generuj('heureka')['polozek'] > 0);

// --- Tisk výdejek ------------------------------------------------------------------
$vystup[] = 'Tisková fronta';
$admin = get_user_by('login', 'admin');
wp_set_current_user($admin ? $admin->ID : 1);
$objednavka = wc_create_order();
$objednavka->add_product(wc_get_product(wc_get_product_id_by_sku('UK-003-43')), 1);
$objednavka->set_billing_first_name('Petr');
$objednavka->calculate_totals();
$objednavka->update_status('processing');

$fronta = Shop_Tisk::fronta()->get_data()['ulohy'];
$uloha  = end($fronta);
$over('zaplacená objednávka je ve frontě tisku', $uloha && $uloha['objednavka'] === $objednavka->get_order_number(), wp_json_encode($fronta));
$over('fronta je zabraná (druhé vyzvednutí nic nevrátí)', Shop_Tisk::fronta()->get_data()['ulohy'] === []);

$html = Shop_Tisk::vydejka_html($objednavka);
$over('výdejka obsahuje čárový kód EAN', str_contains($html, '<svg') && str_contains($html, '2000003'));

$pozadavek = new WP_REST_Request('POST');
$pozadavek['id'] = $uloha['id'];
$over('potvrzení po tisku → 200', Shop_Tisk::hotovo($pozadavek)->get_status() === 200);
$znovu = Shop_Tisk::hotovo($pozadavek);
$over('opakované potvrzení → 409 jiz_vytisteno', $znovu->get_status() === 409 && $znovu->get_data()['duvod'] === 'jiz_vytisteno');
$over('oslovení z objednávky', Shop_Osloveni::z_objednavky($objednavka) === 'Petře');

// --- Ochrana registrace ---------------------------------------------------------------
$vystup[] = 'Ochrana registrace';
$ted = time();
$over('razítko staré 10 s platí', Shop_Registrace_Ochrana::razitko_plati(Shop_Registrace_Ochrana::razitko($ted - 10), $ted));
$over('odeslání za 1 s neplatí (robot)', !Shop_Registrace_Ochrana::razitko_plati(Shop_Registrace_Ochrana::razitko($ted - 1), $ted));
$over('podvržené razítko neplatí', !Shop_Registrace_Ochrana::razitko_plati(($ted - 10) . '.0123456789abcdef0123', $ted));

$text = implode("\n", $vystup) . "\n\nVýsledek: " . (substr_count(implode("\n", $vystup), '  OK') ) . " OK, {$chyb} chyb\n";
echo $text;
file_put_contents('/vystup/integrace.txt', $text);

if ($chyb > 0) {
	throw new RuntimeException("Integrační testy: {$chyb} chyb.");
}
