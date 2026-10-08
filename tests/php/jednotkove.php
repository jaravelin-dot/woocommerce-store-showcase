<?php
/**
 * Jednotkové testy čistých funkcí bez WordPressu (malé náhrady funkcí jádra níže).
 *
 * Spuštění (PHP přes WordPress Playground, bez lokální instalace PHP):
 *   npm run test:php
 * nebo s lokálním PHP 8.1+:
 *   php tests/php/jednotkove.php
 *
 * Výstup: řádek na test, na konci souhrn; návratový kód 1 při chybě.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$koren = dirname(__DIR__, 2);

// --- náhrady funkcí WordPressu (jen to, co testované funkce volají) -------
function remove_accents(string $text): string {
	return strtr($text, ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
		'Á' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'Í' => 'I', 'Ň' => 'N', 'Ó' => 'O', 'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z']);
}
function wp_parse_url(string $url, int $slozka = -1) { return parse_url($url, $slozka); }
function wp_json_encode($data, int $priznaky = 0) { return json_encode($data, $priznaky); }
function apply_filters(string $hak, $hodnota) { return $hodnota; }
function add_action(...$a): void {}
function add_filter(...$a): void {}
function strip_shortcodes(string $t): string { return $t; }
function wp_strip_all_tags(string $t): string { return trim(strip_tags($t)); }

require $koren . '/wp-content/plugins/shop-showcase/includes/class-shop-osloveni.php';
require $koren . '/wp-content/plugins/shop-showcase/includes/class-shop-ean13.php';
require $koren . '/wp-content/plugins/shop-showcase/includes/class-shop-poradce-model.php';
require $koren . '/wp-content/mu-plugins/shop-chyba-404.php';
require $koren . '/wp-content/mu-plugins/shop-seo/class-shop-seo-meta.php';

// --- mini-rámec ----------------------------------------------------------
$vysledky = ['ok' => 0, 'chyba' => 0];

function over(string $popis, $ocekavano, $skutecnost): void {
	global $vysledky;

	if ($ocekavano === $skutecnost) {
		++$vysledky['ok'];
		echo "  OK    {$popis}\n";

		return;
	}

	++$vysledky['chyba'];
	echo "  CHYBA {$popis}\n        čekáno:  " . var_export($ocekavano, true) . "\n        dostal:  " . var_export($skutecnost, true) . "\n";
}

// --- oslovení v 5. pádě ------------------------------------------------------
echo "Oslovení\n";
over('Petr → Petře (slovník, měkčení)', 'Petře', Shop_Osloveni::ze_jmena('Petr'));
over('Marek → Marku', 'Marku', Shop_Osloveni::ze_jmena('Marek'));
over('Tomas bez diakritiky → Tomáši', 'Tomáši', Shop_Osloveni::ze_jmena('Tomas'));
over('jana malými → Jano (pravidlo -a)', 'Jano', Shop_Osloveni::ze_jmena('jana'));
over('Honza → Honzo', 'Honzo', Shop_Osloveni::ze_jmena('Honza'));
over('Marie se nemění', 'Marie', Shop_Osloveni::ze_jmena('Marie'));
over('Jiří se nemění', 'Jiří', Shop_Osloveni::ze_jmena('Jiří'));
over('Dagmar (ženské na souhlásku) se nemění', 'Dagmar', Shop_Osloveni::ze_jmena('Dagmar'));
over('neznámé mužské na souhlásku → nehádat', '', Shop_Osloveni::ze_jmena('Drahomil'));
over('dvě slova → prázdno', '', Shop_Osloveni::ze_jmena('Jan Novák'));
over('firma s tečkou → prázdno', '', Shop_Osloveni::ze_jmena('Firma s.r.o.'));
over('azbuka → prázdno', '', Shop_Osloveni::ze_jmena('Олена'));
over('pozdrav se jménem', 'Dobrý den, Karle,', Shop_Osloveni::pozdrav('Karel'));
over('pozdrav bez jména', 'Dobrý den,', Shop_Osloveni::pozdrav(''));

// --- EAN-13 ------------------------------------------------------------------
echo "EAN-13\n";
over('kontrolní číslice 200000000001?', 5, Shop_Ean13::kontrolni_cislice('200000000001'));
over('platný interní EAN', true, Shop_Ean13::platny('2000000000015'));
over('špatná kontrolní číslice', false, Shop_Ean13::platny('2000000000016'));
over('krátký kód', false, Shop_Ean13::platny('123'));
over('95 modulů', 95, strlen(Shop_Ean13::pruhy('2000000000015')));
over('okrajové znaky 101 … 01010 … 101', ['101', '01010', '101'], [substr(Shop_Ean13::pruhy('2000000000015'), 0, 3), substr(Shop_Ean13::pruhy('2000000000015'), 45, 5), substr(Shop_Ean13::pruhy('2000000000015'), -3)]);
// První číslice 2 → parita LLGGLG: třetí číslice levé poloviny (pozice 3) se kóduje tabulkou G.
over('parita podle první číslice (G pro 3. znak)', '0100111', substr(Shop_Ean13::pruhy('2000000000015'), 3 + 2 * 7, 7));
over('SVG obsahuje obdélníky', true, str_contains(Shop_Ean13::svg('2000000000015'), '<rect'));
over('neplatný EAN → prázdné SVG', '', Shop_Ean13::svg('2000000000016'));

// --- AI poradce: rozbor dotazu v simulaci -----------------------------------
echo "Poradce (simulace, rozbor dotazu)\n";
$r = Shop_Poradce_Simulace::rozbor('boty do mokra na stavbu, velikost 43');
over('boty → kategorie obuv', 'obuv', $r['kategorie'] ?? null);
over('do mokra → nepromokavé', ['nepromokave'], $r['filtry']['pa_vlastnosti'] ?? null);
over('stavba → prostředí', ['stavba'], $r['filtry']['pa_prostredi'] ?? null);
over('velikost 43', '43', $r['velikost'] ?? null);

$r = Shop_Poradce_Simulace::rozbor('dámské polobotky S3 do 1 500 Kč');
over('polobotky → bezpečnostní obuv', 'bezpecnostni-obuv', $r['kategorie'] ?? null);
over('S3 → třída', ['s3'], $r['filtry']['pa_bezpecnostni_trida'] ?? null);
over('dámské → dámské + unisex', ['damske', 'unisex'], $r['filtry']['pa_pohlavi'] ?? null);
over('do 1 500 Kč → cena_do', 1500, $r['cena_do'] ?? null);
over('cena není velikost', null, $r['velikost'] ?? null);

$r = Shop_Poradce_Simulace::rozbor('zimní bundu na sklad, XL');
over('bunda → bundy a vesty', 'bundy-a-vesty', $r['kategorie'] ?? null);
over('zimní → zateplené', ['zateplene'], $r['filtry']['pa_vlastnosti'] ?? null);
over('XL', 'XL', $r['velikost'] ?? null);

$r = Shop_Poradce_Simulace::rozbor('rukavice na montáž, velikost 9');
over('rukavice', 'rukavice', $r['kategorie'] ?? null);
over('velikost rukavic 9', '9', $r['velikost'] ?? null);

$r = Shop_Poradce_Simulace::rozbor('QUORVAN');
over('neznámé slovo → volný text', 'QUORVAN', $r['text'] ?? null);

// --- chytrá 404 --------------------------------------------------------------
echo "Chytrá 404\n";
over('slova ze staré adresy', ['panska', 'softshellova', 'bunda', 'vitr', 'modra'], shop_404_slova('/panska-softshellova-bunda-vitr-modra-1230-d1159.htm'));
over('velikosti a výplň se zahodí', ['polobotky', 'quorvan'], shop_404_slova('/polobotky-quorvan-vel-xl-cena-d77.htm'));
over('skóre: koncovka se nepočítá', 3, shop_404_skore(['panska', 'softshellova', 'bunda'], 'Pánská softshell bunda VÍTR'));
over('skóre: krátká předpona nesedí', 0, shop_404_skore(['az'], 'Azurová vesta'));

// --- meta popisek: zkrácení a tabulka parametrů -----------------------------
echo "SEO: zkrácení popisu\n";
over('tabulka → věta', 'Svršek: kůže. Podešev: PU/TPU.', Shop_Seo_Meta::zkrat('<table><tr><th>Svršek</th><td>kůže</td></tr><tr><th>Podešev</th><td>PU/TPU</td></tr></table>'));
$dlouhy = Shop_Seo_Meta::zkrat(str_repeat('slovo ', 30) . 'a konec', 40);
over('ořez na hranici slova s výpustkou', true, str_ends_with($dlouhy, 'slovo…') && mb_strlen($dlouhy) <= 41);
over('osamocená předložka se strhne', 'Odolná obuv pro práci…', Shop_Seo_Meta::zkrat('Odolná obuv pro práci v dílně i venku', 24));

echo "\nVýsledek: {$vysledky['ok']} OK, {$vysledky['chyba']} chyb\n";
exit($vysledky['chyba'] === 0 ? 0 : 1);
