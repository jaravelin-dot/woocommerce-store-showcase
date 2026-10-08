<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Tisk výdejek na prodejně: fronta úloh, REST pro tiskového agenta
 * a podklad (HTML výdejka s čárovými kódy).
 *
 * Web tiskárnu nevidí (server je v datacentru, tiskárna za routerem
 * prodejny), proto si práci vyzvedává počítač u tiskárny sám
 * (tisk-agent/, Python). Když je vypnutý, nic se neztratí — po zapnutí
 * vyjede i to, co přišlo v noci.
 *
 * Protokol:
 *   GET  /shop/v1/tisk/fronta             zabere čekající úlohy (na 10 minut) a vrátí je
 *   GET  /shop/v1/tisk/uloha/{id}         HTML výdejky
 *   POST /shop/v1/tisk/uloha/{id}/hotovo  potvrzení po vytištění
 *        409 {duvod: "jiz_vytisteno"}  = neškodná duplicita (opakované potvrzení)
 *        409 {duvod: "nevyzvednuto"}   = úloha mezitím propadla zpět do fronty
 *
 * Úloha, kterou agent zabral a nepotvrdil, se po 10 minutách sama vrátí
 * do fronty. V nejhorším se tedy výdejka vytiskne dvakrát — nikdy nezmizí.
 */
class Shop_Tisk {
	private const TABULKA       = 'shop_tisk_fronta';
	private const VERZE         = '1';
	public const ZABRANO_SEKUND = 600;
	public const OPRAVNENI      = 'shop_tisk';

	public static function registruj_hooky(): void {
		add_action('rest_api_init', [self::class, 'registruj_routy']);
		// Výdejka vzniká, když je objednávka zaplacená nebo na dobírku přijatá.
		add_action('woocommerce_order_status_processing', [self::class, 'zarad']);
	}

	public static function tabulka(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABULKA;
	}

	public static function zajisti_tabulku(): void {
		if (get_option('shop_tisk_schema') === self::VERZE) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta('CREATE TABLE ' . self::tabulka() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			objednavka bigint(20) unsigned NOT NULL,
			stav varchar(20) NOT NULL DEFAULT 'ceka',
			zabrano_do bigint(20) unsigned NOT NULL DEFAULT 0,
			zabral varchar(40) NOT NULL DEFAULT '',
			vytvoreno datetime NOT NULL,
			vytisteno datetime NULL,
			PRIMARY KEY  (id),
			KEY stav (stav)
		) " . $wpdb->get_charset_collate() . ';');

		update_option('shop_tisk_schema', self::VERZE, false);
	}

	/** Zařadí výdejku objednávky do fronty (jednou; dotisk jde přes administraci). */
	public static function zarad(int $id_objednavky): void {
		$objednavka = wc_get_order($id_objednavky);

		if (!$objednavka instanceof WC_Order || $objednavka->get_meta('_shop_tisk_zarazeno') === 'ano') {
			return;
		}

		global $wpdb;
		self::zajisti_tabulku();
		$wpdb->insert(self::tabulka(), ['objednavka' => $id_objednavky, 'stav' => 'ceka', 'vytvoreno' => current_time('mysql')]);
		$objednavka->update_meta_data('_shop_tisk_zarazeno', 'ano');
		$objednavka->save();
	}

	public static function registruj_routy(): void {
		$smi = static fn(): bool => current_user_can(self::OPRAVNENI) || current_user_can('manage_woocommerce');

		register_rest_route('shop/v1', '/tisk/fronta', ['methods' => 'GET', 'callback' => [self::class, 'fronta'], 'permission_callback' => $smi]);
		register_rest_route('shop/v1', '/tisk/uloha/(?P<id>\d+)', ['methods' => 'GET', 'callback' => [self::class, 'uloha'], 'permission_callback' => $smi]);
		register_rest_route('shop/v1', '/tisk/uloha/(?P<id>\d+)/hotovo', ['methods' => 'POST', 'callback' => [self::class, 'hotovo'], 'permission_callback' => $smi]);
	}

	/**
	 * Zabere čekající úlohy a vrátí je. POZOR: GET tady MĚNÍ STAV — agent ho
	 * volá jen tehdy, když úlohy opravdu zpracuje.
	 *
	 * Zabrání je jeden UPDATE s náhodným tokenem, takže dva souběžné agenty
	 * nikdy nedostanou tutéž úlohu.
	 */
	public static function fronta(): WP_REST_Response {
		global $wpdb;
		self::zajisti_tabulku();

		$ted   = time();
		$token = wp_generate_uuid4();

		$wpdb->query($wpdb->prepare(
			'UPDATE ' . self::tabulka() . " SET stav = 'vyzvednuto', zabrano_do = %d, zabral = %s
			 WHERE stav = 'ceka' OR (stav = 'vyzvednuto' AND zabrano_do < %d)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ted + self::ZABRANO_SEKUND,
			$token,
			$ted
		));

		$radky = $wpdb->get_results($wpdb->prepare('SELECT id, objednavka FROM ' . self::tabulka() . ' WHERE zabral = %s ORDER BY id', $token), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$ulohy = array_map(static function (array $r): array {
			$objednavka = wc_get_order((int) $r['objednavka']);

			return ['id' => (int) $r['id'], 'objednavka' => $objednavka ? $objednavka->get_order_number() : (string) $r['objednavka'], 'druh' => 'vydejka'];
		}, (array) $radky);

		return new WP_REST_Response(['ulohy' => $ulohy], 200);
	}

	/** HTML výdejky jako text/html — agent z něj udělá PDF a pošle na tiskárnu. */
	public static function uloha(WP_REST_Request $pozadavek) {
		global $wpdb;
		self::zajisti_tabulku();

		$radek = $wpdb->get_row($wpdb->prepare('SELECT objednavka FROM ' . self::tabulka() . ' WHERE id = %d', (int) $pozadavek['id']), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$objednavka = $radek ? wc_get_order((int) $radek['objednavka']) : null;

		if (!$objednavka instanceof WC_Order) {
			return new WP_Error('shop_tisk_nenalezeno', 'Úloha neexistuje.', ['status' => 404]);
		}

		// Vlastní výstup místo JSON obálky REST API.
		add_filter('rest_pre_serve_request', static function () use ($objednavka): bool {
			header('Content-Type: text/html; charset=UTF-8');
			echo self::vydejka_html($objednavka); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno ve vydejka_html()

			return true;
		});

		return new WP_REST_Response(null, 200);
	}

	public static function hotovo(WP_REST_Request $pozadavek): WP_REST_Response {
		global $wpdb;
		self::zajisti_tabulku();

		$id    = (int) $pozadavek['id'];
		$radek = $wpdb->get_row($wpdb->prepare('SELECT stav, objednavka FROM ' . self::tabulka() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if (!$radek) {
			return new WP_REST_Response(['duvod' => 'neexistuje'], 404);
		}

		if ($radek['stav'] === 'vytisteno') {
			return new WP_REST_Response(['duvod' => 'jiz_vytisteno'], 409);
		}

		$zmeneno = $wpdb->query($wpdb->prepare(
			'UPDATE ' . self::tabulka() . " SET stav = 'vytisteno', vytisteno = %s WHERE id = %d AND stav = 'vyzvednuto'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			current_time('mysql'),
			$id
		));

		if ((int) $zmeneno !== 1) {
			return new WP_REST_Response(['duvod' => 'nevyzvednuto'], 409);
		}

		$objednavka = wc_get_order((int) $radek['objednavka']);

		if ($objednavka instanceof WC_Order) {
			$objednavka->add_order_note('Výdejka vytištěna na prodejně.');
		}

		return new WP_REST_Response(['ok' => true], 200);
	}

	/**
	 * Výdejka pro sklad: co vychystat a podle čeho to poznat. Fotka je hlavní
	 * vodítko (barva), EAN jako čárový kód pro kontrolu čtečkou.
	 */
	public static function vydejka_html(WC_Order $objednavka): string {
		$radky = '';

		foreach ($objednavka->get_items() as $polozka) {
			if (!$polozka instanceof WC_Order_Item_Product) {
				continue;
			}

			$produkt = $polozka->get_product();
			$ean     = $produkt && class_exists('Shop_Seo_Schema') ? Shop_Seo_Schema::ean($produkt) : '';
			$fotka   = $produkt ? (string) wp_get_attachment_image_url((int) $produkt->get_image_id(), 'thumbnail') : '';

			$radky .= '<tr>'
				. '<td class="foto">' . ($fotka !== '' ? '<img src="' . esc_url($fotka) . '" alt="">' : '') . '</td>'
				. '<td><strong>' . esc_html($polozka->get_name()) . '</strong><br><span class="kod">' . esc_html($produkt ? $produkt->get_sku() : '') . '</span></td>'
				. '<td class="kusy">' . (int) $polozka->get_quantity() . '&nbsp;ks</td>'
				. '<td class="ean">' . Shop_Ean13::svg($ean, 36) . '<br>' . esc_html($ean) . '</td>'
				. '</tr>';
		}

		return '<!doctype html><html lang="cs"><head><meta charset="utf-8"><title>Výdejka ' . esc_html($objednavka->get_order_number()) . '</title>'
			. '<style>body{font:14px/1.4 sans-serif;margin:24px}h1{font-size:22px;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin-top:16px}'
			. 'td{border-top:1px solid #999;padding:8px;vertical-align:middle}.foto img{width:72px;height:72px;object-fit:contain}.kusy{font-size:20px;font-weight:700;white-space:nowrap}.ean{text-align:center;font-size:11px}.kod{color:#555}</style>'
			. '</head><body>'
			. '<h1>Výdejka – objednávka ' . esc_html($objednavka->get_order_number()) . '</h1>'
			. '<p>' . esc_html(wc_format_datetime($objednavka->get_date_created(), 'j. n. Y H:i')) . ' · ' . esc_html($objednavka->get_shipping_method()) . ' · ' . esc_html($objednavka->get_payment_method_title()) . '</p>'
			. '<table>' . $radky . '</table></body></html>';
	}
}
