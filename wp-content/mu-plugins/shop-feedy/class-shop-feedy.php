<?php
/**
 * Generátor produktových feedů.
 *
 * ROZHODNUTÍ: jedna položka na VELIKOST, ne na produkt. Sklad je sdílený
 * s kamennou prodejnou, takže dostupnost hlášená po produktech („aspoň
 * jedna velikost skladem") by posílala zákazníky na zboží, které v jejich
 * velikosti není. Velikosti se spojují přes ITEMGROUP_ID / item_group_id.
 *
 * Ceny: VÝHRADNĚ wc_get_price_including_tax(), tedy prodejní cena včetně
 * DPH. Nákupní cena do feedu nesmí nikdy.
 *
 * Soubor se zapisuje do *.rozdelano a pak se atomicky přejmenuje —
 * srovnávač si nikdy nestáhne rozepsaný feed.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class Shop_Feedy {

	public const FORMATY = ['heureka', 'google'];

	/** Odhad dodací lhůty u zboží na objednávku, ve dnech. */
	private const DNU_NA_OBJEDNAVKU = 7;

	public static function registruj_hooky(): void {
		add_action('init', [self::class, 'pravidla_adres']);
		add_filter('query_vars', static fn(array $p): array => array_merge($p, ['shop_feed']));

		// Priorita 0: dřív než redirect_canonical z jádra.
		add_action('template_redirect', [self::class, 'obsluz_adresu'], 0);
		add_filter('redirect_canonical', [self::class, 'bez_lomitka']);
		add_action('shop_feedy_pregeneruj', [self::class, 'cron']);
		add_action('init', static function (): void {
			if (!wp_next_scheduled('shop_feedy_pregeneruj')) {
				// Ráno po noční synchronizaci skladu, ať feed nese dnešní dostupnost.
				wp_schedule_event(strtotime('tomorrow 04:30'), 'daily', 'shop_feedy_pregeneruj');
			}
		});
	}

	public static function cron(): void {
		foreach (self::FORMATY as $format) {
			self::generuj($format);
		}
	}

	/**
	 * Soubor leží v uploads, ale ven se podává v kořeni webu — tahle adresa
	 * se zadává do administrace srovnávače a nemá se měnit.
	 */
	public static function pravidla_adres(): void {
		foreach (self::FORMATY as $format) {
			add_rewrite_rule('^' . $format . '\.xml$', 'index.php?shop_feed=' . $format, 'top');
		}
	}

	/**
	 * Trvalé odkazy končí lomítkem, takže jádro posílá /heureka.xml na
	 * /heureka.xml/ (301). Srovnávač by dostal přesměrování místo souboru.
	 *
	 * @param string|false $adresa
	 * @return string|false
	 */
	public static function bez_lomitka($adresa) {
		return in_array(get_query_var('shop_feed'), self::FORMATY, true) ? false : $adresa;
	}

	public static function obsluz_adresu(): void {
		$format = get_query_var('shop_feed');

		if (!is_string($format) || !in_array($format, self::FORMATY, true)) {
			return;
		}

		$soubor = self::cesta_souboru($format);

		if (!file_exists($soubor)) {
			status_header(503);
			header('Retry-After: 3600');
			header('Content-Type: text/plain; charset=UTF-8');
			echo 'Feed se zatim negeneroval. Spust: wp shop feed ' . esc_html($format) . "\n";
			exit;
		}

		status_header(200);
		header('Content-Type: application/xml; charset=UTF-8');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($soubor)) . ' GMT');
		readfile($soubor);
		exit;
	}

	public static function cesta_souboru(string $format): string {
		return trailingslashit(wp_upload_dir()['basedir']) . 'feedy/' . $format . '.xml';
	}

	/**
	 * Sestaví feed do souboru.
	 *
	 * @return array<string, int> statistika běhu
	 */
	public static function generuj(string $format, bool $nanecisto = false): array {
		if (!in_array($format, self::FORMATY, true)) {
			throw new InvalidArgumentException('Neznámý formát feedu: ' . $format);
		}

		$stat = ['produktu' => 0, 'polozek' => 0, 'bez_ceny' => 0, 'bez_eanu' => 0, 'bez_kategorie' => 0, 'nedostupnych' => 0, 'bez_obrazku' => 0];
		$cil  = self::cesta_souboru($format);

		if (!$nanecisto && !wp_mkdir_p(dirname($cil))) {
			throw new RuntimeException('Nejde založit složku ' . dirname($cil));
		}

		$fd = fopen($nanecisto ? 'php://memory' : $cil . '.rozdelano', 'w');

		if ($fd === false) {
			throw new RuntimeException('Nejde otevřít soubor feedu.');
		}

		fwrite($fd, '<?xml version="1.0" encoding="utf-8"?>' . "\n" . self::hlavicka($format));

		foreach (self::idcka_produktu() as $id_produktu) {
			$produkt = wc_get_product($id_produktu);

			if (!$produkt instanceof WC_Product || $produkt->get_catalog_visibility() === 'hidden') {
				continue;
			}

			++$stat['produktu'];

			$kategorie = self::kategorie($produkt, $format);

			if ($kategorie === '') {
				++$stat['bez_kategorie'];
				continue;
			}

			$spolecne = [
				'nazev'     => wp_strip_all_tags($produkt->get_name()),
				'popis'     => self::popis($produkt),
				'obrazek'   => self::obrazek($produkt),
				'znacka'    => trim((string) explode(',', (string) $produkt->get_attribute('pa_znacka'))[0]),
				'kategorie' => $kategorie,
				'skupina'   => (string) $produkt->get_id(),
			];

			foreach (self::kusy($produkt) as $kus) {
				$radek = self::polozka($kus, $produkt, $spolecne, $format, $stat);

				if ($radek !== '') {
					fwrite($fd, $radek);
					++$stat['polozek'];
				}
			}

			// Desítky tisíc variant se do paměti nevejdou, když si WooCommerce
			// nechá každý načtený objekt.
			if ($stat['produktu'] % 200 === 0) {
				wp_cache_flush_runtime();
			}
		}

		fwrite($fd, $format === 'google' ? "</channel></rss>\n" : "</SHOP>\n");
		fclose($fd);

		if (!$nanecisto) {
			rename($cil . '.rozdelano', $cil);
		}

		return $stat;
	}

	private static function hlavicka(string $format): string {
		$firma = shop_seo_firma();

		if ($format === 'google') {
			return '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>' . "\n"
				. self::tag('title', (string) $firma['nazev'])
				. self::tag('link', home_url('/'))
				. self::tag('description', (string) $firma['popis']);
		}

		// Další srovnávače používají stejnou strukturu SHOP/SHOPITEM, některé
		// vyžadují vlastní jmenný prostor v kořenovém prvku — doplní se filtrem.
		return (string) apply_filters('shop_feedy_koren', '<SHOP>' . "\n", $format);
	}

	/** @return array<int, int> */
	private static function idcka_produktu(): array {
		return array_map('intval', get_posts([
			'post_type'   => 'product',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'orderby'     => 'ID',
			'order'       => 'ASC',
		]));
	}

	/**
	 * Prodejní kusy produktu: u variabilního jeho varianty, jinak on sám.
	 *
	 * @return array<int, WC_Product>
	 */
	private static function kusy(WC_Product $produkt): array {
		if (!$produkt->is_type('variable')) {
			return [$produkt];
		}

		return array_values(array_filter(
			array_map('wc_get_product', $produkt->get_children()),
			static fn($v): bool => $v instanceof WC_Product_Variation
		));
	}

	/**
	 * @param array<string, string> $spolecne
	 * @param array<string, int>    $stat
	 */
	private static function polozka(WC_Product $kus, WC_Product $produkt, array $spolecne, string $format, array &$stat): string {
		$cena = wc_get_price_including_tax($kus);

		if (!is_numeric($cena) || (float) $cena <= 0) {
			++$stat['bez_ceny'];

			return '';
		}

		// Vyprodané zboží bez možnosti objednat do feedu nepatří — zákazník
		// by ze srovnávače přišel na kartu, kterou nelze koupit.
		if (!$kus->is_in_stock() && !$kus->is_on_backorder()) {
			++$stat['nedostupnych'];

			return '';
		}

		// Bez obrázku nabídku nákupní reklama odmítne.
		if ($format === 'google' && $spolecne['obrazek'] === '') {
			++$stat['bez_obrazku'];

			return '';
		}

		$ean = self::ean($kus);

		if ($ean === '') {
			++$stat['bez_eanu'];
		}

		$velikost = $kus->is_type('variation') ? (string) $kus->get_attribute('pa_velikost') : '';
		$nazev    = $spolecne['nazev'] . ($velikost !== '' ? ', velikost ' . $velikost : '');
		$adresa   = self::adresa($produkt, $velikost);

		if ($format === 'google') {
			return self::polozka_google($kus, $spolecne, (float) $cena, $ean, $velikost, $nazev, $adresa);
		}

		$x  = "\t<SHOPITEM>\n";
		$x .= self::tag('ITEM_ID', (string) $kus->get_id());
		$x .= self::tag('PRODUCTNAME', $nazev);
		$x .= self::tag('PRODUCT', $spolecne['nazev']);
		$x .= self::cdata('DESCRIPTION', $spolecne['popis']);
		$x .= self::tag('URL', $adresa);
		$x .= $spolecne['obrazek'] !== '' ? self::tag('IMGURL', $spolecne['obrazek']) : '';
		$x .= self::tag('PRICE_VAT', (string) round((float) $cena, 2));
		$x .= $spolecne['znacka'] !== '' ? self::tag('MANUFACTURER', $spolecne['znacka']) : '';
		$x .= self::tag('CATEGORYTEXT', $spolecne['kategorie']);
		$x .= $ean !== '' ? self::tag('EAN', $ean) : '';
		$x .= $kus->get_sku() !== '' ? self::tag('PRODUCTNO', $kus->get_sku()) : '';
		$x .= self::tag('DELIVERY_DATE', (string) ($kus->is_on_backorder() ? self::DNU_NA_OBJEDNAVKU : 0));
		$x .= self::doprava((float) $cena);
		$x .= self::tag('ITEMGROUP_ID', $spolecne['skupina']);

		if ($velikost !== '') {
			$x .= "\t\t<PARAM>\n\t" . self::tag('PARAM_NAME', 'Velikost') . "\t" . self::tag('VAL', $velikost) . "\t\t</PARAM>\n";
		}

		return $x . "\t</SHOPITEM>\n";
	}

	/**
	 * Položka pro nákupní reklamu (RSS 2.0, jmenný prostor g:). Dostupnost
	 * musí sedět se schématem na stránce, jinak se položka zamítne pro
	 * nesoulad.
	 *
	 * @param array<string, string> $spolecne
	 */
	private static function polozka_google(WC_Product $kus, array $spolecne, float $cena, string $ean, string $velikost, string $nazev, string $adresa): string {
		$x  = "\t<item>\n";
		$x .= self::tag('g:id', (string) $kus->get_id());
		$x .= self::tag('g:title', mb_substr($nazev, 0, 150, 'UTF-8'));
		$x .= self::cdata('g:description', mb_substr($spolecne['popis'] !== '' ? $spolecne['popis'] : $spolecne['nazev'], 0, 5000, 'UTF-8'));
		$x .= self::tag('g:link', $adresa);
		$x .= self::tag('g:image_link', $spolecne['obrazek']);
		$x .= self::tag('g:condition', 'new');
		$x .= self::tag('g:price', number_format($cena, 2, '.', '') . ' CZK');

		if ($kus->is_on_backorder()) {
			$x .= self::tag('g:availability', 'backorder');
			$x .= self::tag('g:availability_date', gmdate('Y-m-d\TH:i:s\Z', time() + self::DNU_NA_OBJEDNAVKU * DAY_IN_SECONDS));
		} else {
			$x .= self::tag('g:availability', 'in_stock');
		}

		$x .= $spolecne['znacka'] !== '' ? self::tag('g:brand', $spolecne['znacka']) : '';

		if ($ean !== '') {
			$x .= self::tag('g:gtin', $ean);
		} elseif ($kus->get_sku() !== '' && $spolecne['znacka'] !== '') {
			$x .= self::tag('g:mpn', $kus->get_sku());
		} else {
			$x .= self::tag('g:identifier_exists', 'no');
		}

		$x .= self::tag('g:product_type', $spolecne['kategorie']);
		$x .= self::tag('g:item_group_id', $spolecne['skupina']);
		// Kampaň neumí dělit produkty podle ceny, jen podle vlastního štítku.
		$x .= self::tag('g:custom_label_0', self::cenove_pasmo($cena));
		$x .= self::tag('g:custom_label_1', $kus->is_on_backorder() ? 'u_dodavatele' : 'skladem');
		$x .= $velikost !== '' ? self::tag('g:size', $velikost) : '';

		return $x . "\t</item>\n";
	}

	/** Cenové pásmo s DPH pro štítek kampaně. */
	public static function cenove_pasmo(float $cena): string {
		if ($cena < 500) {
			return 'p_pod500';
		}

		if ($cena < 1000) {
			return 'p_500_999';
		}

		return $cena < 2000 ? 'p_1000_1999' : 'p_2000_plus';
	}

	/**
	 * Blok <DELIVERY>. Doprava zdarma se počítá z ceny TÉTO položky —
	 * zjednodušení, ale opačný směr by lhal: slíbit dopravu zdarma u zboží,
	 * které na ni samo o sobě nedosáhne.
	 */
	private static function doprava(float $cena_polozky): string {
		$konfig = shop_feedy_doprava();

		if ($konfig['overeno'] !== true) {
			return '';
		}

		$zdarma = $cena_polozky >= (float) shop_seo_firma()['doprava_zdarma_od'];
		$x      = '';

		foreach ($konfig['sazby'] as $sazba) {
			$cena = $zdarma ? 0.0 : (float) $sazba['cena'];
			$x   .= "\t\t<DELIVERY>\n";
			$x   .= "\t" . self::tag('DELIVERY_ID', $sazba['kod']);
			$x   .= "\t" . self::tag('DELIVERY_PRICE', (string) $cena);
			$x   .= "\t" . self::tag('DELIVERY_PRICE_COD', (string) ($cena + (float) $sazba['dobirka']));
			$x   .= "\t\t</DELIVERY>\n";
		}

		return $x;
	}

	/**
	 * Adresa s předvybranou velikostí — zákazník přišel ze srovnávače pro
	 * konkrétní velikost, ne pro produkt.
	 */
	private static function adresa(WC_Product $produkt, string $velikost): string {
		$zaklad = (string) $produkt->get_permalink();

		return $velikost === '' ? $zaklad : add_query_arg('attribute_pa_velikost', rawurlencode(sanitize_title($velikost)), $zaklad);
	}

	/** Cesta v cizím stromu (formáty srovnávačů), nebo naše cesta (Google). */
	private static function kategorie(WC_Product $produkt, string $format): string {
		$terminy = wp_get_post_terms($produkt->get_id(), 'product_cat');

		if (!is_array($terminy) || $terminy === []) {
			return '';
		}

		// Nejhlubší kategorie je ta nejpřesnější.
		usort($terminy, static fn(WP_Term $a, WP_Term $b): int => count(get_ancestors($b->term_id, 'product_cat')) <=> count(get_ancestors($a->term_id, 'product_cat')));
		$termin = $terminy[0];

		if ($format === 'google') {
			$casti = array_reverse(array_map(
				static fn(int $id): string => (string) get_term($id, 'product_cat')->name,
				get_ancestors($termin->term_id, 'product_cat')
			));

			return implode(' > ', array_merge($casti, [$termin->name]));
		}

		$mapa = shop_feedy_mapa_kategorii();

		foreach (array_merge([$termin->term_id], get_ancestors($termin->term_id, 'product_cat')) as $id) {
			$slug = (string) get_term($id, 'product_cat')->slug;

			if (isset($mapa[$slug][$format])) {
				return $mapa[$slug][$format];
			}
		}

		return '';
	}

	private static function popis(WC_Product $produkt): string {
		$zdroj = trim($produkt->get_short_description()) !== '' ? $produkt->get_short_description() : $produkt->get_description();

		// Stejný převod tabulky parametrů na větu jako u meta popisků, jen
		// delší strop — srovnávače popis nezkracují na 155 znaků.
		return class_exists('Shop_Seo_Meta') ? Shop_Seo_Meta::zkrat($zdroj, 2000) : trim(wp_strip_all_tags($zdroj));
	}

	private static function obrazek(WC_Product $produkt): string {
		$id = (int) $produkt->get_image_id();

		return $id > 0 ? (string) wp_get_attachment_image_url($id, 'full') : '';
	}

	/**
	 * EAN jen s platnou kontrolní číslicí. Interní 13místný kód v poli EAN
	 * (párování skladu) srovnávač hlásí jako neplatný GTIN — ve skladu
	 * zůstává, jen se neposílá ven.
	 */
	private static function ean(WC_Product $kus): string {
		$ean = class_exists('Shop_Seo_Schema') ? Shop_Seo_Schema::ean($kus) : '';

		return ($ean !== '' && self::ean_platny($ean)) ? $ean : '';
	}

	public static function ean_platny(string $cislo): bool {
		if (preg_match('/^\d{13}$/', $cislo) !== 1) {
			return false;
		}

		$soucet = 0;

		for ($i = 0; $i < 12; $i++) {
			$soucet += (int) $cislo[$i] * ($i % 2 === 0 ? 1 : 3);
		}

		return (10 - $soucet % 10) % 10 === (int) $cislo[12];
	}

	private static function tag(string $nazev, string $hodnota): string {
		return "\t\t<" . $nazev . '>' . htmlspecialchars($hodnota, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $nazev . ">\n";
	}

	private static function cdata(string $nazev, string $hodnota): string {
		if (trim($hodnota) === '') {
			return '';
		}

		// „]]>" uvnitř popisu by CDATA předčasně ukončilo.
		return "\t\t<" . $nazev . '><![CDATA[' . str_replace(']]>', ']]&gt;', $hodnota) . ']]></' . $nazev . ">\n";
	}
}
