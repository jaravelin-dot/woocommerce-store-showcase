<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Doprava na výdejní místo — statická ukázka.
 *
 * V ostrém nasazení vybírá zákazník místo na mapě dopravce (widget
 * dopravce + ověření v jeho API). Tady je místo mapy pevný seznam
 * vymyšlených míst; zbytek toku je stejný: výběr pod sazbou dopravy,
 * kontrola v pokladně, uložení k objednávce a zobrazení na výdejce
 * i v administraci.
 */
class Shop_Vydejni_Mista {
	public const METODA = 'shop_vydejni_misto';
	private const META  = '_shop_vydejni_misto';

	public static function registruj_hooky(): void {
		add_action('woocommerce_shipping_init', static function (): void {
			require_once __DIR__ . '/class-shop-doprava-vydejni-misto.php';
		});
		add_filter('woocommerce_shipping_methods', static function (array $metody): array {
			$metody[self::METODA] = 'Shop_Doprava_Vydejni_Misto';

			return $metody;
		});
		add_action('woocommerce_after_shipping_rate', [self::class, 'vyber'], 10, 2);
		add_action('woocommerce_checkout_update_order_review', [self::class, 'zapamatuj']);
		// Po výběru místa hned přepočítat pokladnu: místo se uloží do session dřív,
		// než ho stihne přepsat přepočet vyvolaný změnou adresy.
		add_action('wp_enqueue_scripts', static function (): void {
			if (function_exists('is_checkout') && is_checkout()) {
				wp_add_inline_script('wc-checkout', "jQuery(function ($) { $(document.body).on('change', '#shop_vydejni_misto', function () { $(document.body).trigger('update_checkout'); }); });");
			}
		});
		add_action('woocommerce_after_checkout_validation', [self::class, 'over'], 10, 2);
		add_action('woocommerce_checkout_create_order', [self::class, 'uloz'], 10, 2);
		add_action('woocommerce_admin_order_data_after_shipping_address', [self::class, 'zobraz']);
		add_action('woocommerce_order_details_after_order_table', [self::class, 'zobraz']);
	}

	/** @return array<string, string> kód => popis */
	public static function mista(): array {
		return (array) apply_filters('shop_vydejni_mista', [
			'VM-001' => 'Ukázkové výdejní místo 1, Ukázková 1, Město A',
			'VM-002' => 'Ukázkové výdejní místo 2, Ukázková 2, Město B',
			'VM-003' => 'Ukázkový výdejní box 3, Ukázková 3, Město C',
			'VM-004' => 'Ukázkové výdejní místo 4, Ukázková 4, Město D',
			'VM-005' => 'Ukázkový výdejní box 5, Ukázková 5, Město E',
		]);
	}

	/** Výběr místa přímo pod zvolenou sazbou (klasická pokladna). */
	public static function vyber(WC_Shipping_Rate $sazba, int $index): void {
		if ($sazba->get_method_id() !== self::METODA || !is_checkout()) {
			return;
		}

		$zvolene = WC()->session ? (array) WC()->session->get('chosen_shipping_methods') : [];

		if (($zvolene[$index] ?? '') !== $sazba->get_id()) {
			return;
		}

		$aktualni = WC()->session ? (string) WC()->session->get('shop_vydejni_misto', '') : '';
		echo '<div class="shop-vydejni-misto"><label for="shop_vydejni_misto">' . esc_html__('Výdejní místo', 'shop') . '</label><select name="shop_vydejni_misto" id="shop_vydejni_misto">';
		echo '<option value="">' . esc_html__('— vyberte —', 'shop') . '</option>';

		foreach (self::mista() as $kod => $popis) {
			echo '<option value="' . esc_attr($kod) . '"' . selected($aktualni, $kod, false) . '>' . esc_html($popis) . '</option>';
		}

		echo '</select><small>' . esc_html__('Ukázka: v ostrém provozu je tu mapa dopravce.', 'shop') . '</small></div>';
	}

	/**
	 * Pokladna se po každé změně (adresa, způsob dopravy) překreslí AJAXem a
	 * select se vykreslí znovu. Bez zapamatování v session by zvolené místo
	 * zmizelo a objednávka by neprošla kontrolou.
	 *
	 * @param string $post_data serializovaný formulář pokladny
	 */
	public static function zapamatuj($post_data): void {
		parse_str((string) $post_data, $data);
		$kod = isset($data['shop_vydejni_misto']) ? sanitize_text_field((string) $data['shop_vydejni_misto']) : '';

		if (WC()->session && ($kod === '' || isset(self::mista()[$kod]))) {
			WC()->session->set('shop_vydejni_misto', $kod);
		}
	}

	/** @param array<string, mixed> $data */
	public static function over(array $data, WP_Error $chyby): void {
		if (!self::je_zvolena()) {
			return;
		}

		$kod = isset($_POST['shop_vydejni_misto']) ? sanitize_text_field(wp_unslash((string) $_POST['shop_vydejni_misto'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ověřuje pokladna WooCommerce

		if (!isset(self::mista()[$kod])) {
			$chyby->add('shipping', __('Vyberte prosím výdejní místo.', 'shop'));
		}
	}

	/** @param array<string, mixed> $data */
	public static function uloz(WC_Order $objednavka, array $data): void {
		$kod = isset($_POST['shop_vydejni_misto']) ? sanitize_text_field(wp_unslash((string) $_POST['shop_vydejni_misto'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if (self::je_zvolena() && isset(self::mista()[$kod])) {
			$objednavka->update_meta_data(self::META, $kod . ' — ' . self::mista()[$kod]);
		}
	}

	public static function zobraz(WC_Order $objednavka): void {
		$misto = (string) $objednavka->get_meta(self::META);

		if ($misto !== '') {
			echo '<p><strong>' . esc_html__('Výdejní místo:', 'shop') . '</strong> ' . esc_html($misto) . '</p>';
		}
	}

	private static function je_zvolena(): bool {
		foreach ((array) (WC()->session ? WC()->session->get('chosen_shipping_methods') : []) as $metoda) {
			if (str_starts_with((string) $metoda, self::METODA)) {
				return true;
			}
		}

		return false;
	}
}
