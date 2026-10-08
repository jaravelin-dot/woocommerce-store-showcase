<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Velikosti na detailu produktu jako tlačítka se stavem skladu
 * (skladem / u výrobce / vyprodáno). Vlastní výběr dál dělá formulář
 * WooCommerce, viz assets/js/velikosti.js.
 */
class Shop_Velikosti {

	public static function registruj_hooky(): void {
		add_filter('woocommerce_available_variation', [self::class, 'stav_varianty'], 10, 3);
		add_action('wp_enqueue_scripts', static function (): void {
			if (!function_exists('is_product') || !is_product()) {
				return;
			}

			$cesta = SHOP_SHOWCASE_DIR . '/assets/';
			wp_enqueue_script('shop-velikosti', plugins_url('assets/js/velikosti.js', SHOP_SHOWCASE_SOUBOR), ['wc-add-to-cart-variation'], (string) filemtime($cesta . 'js/velikosti.js'), true);
			wp_enqueue_style('shop-velikosti', plugins_url('assets/css/velikosti.css', SHOP_SHOWCASE_SOUBOR), [], (string) filemtime($cesta . 'css/velikosti.css'));
		});
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function stav_varianty(array $data, $produkt, $varianta): array {
		if ($varianta instanceof WC_Product_Variation) {
			$stav = $varianta->get_stock_status();
			$data['shop_stav'] = $stav === 'instock' ? 'skladem' : ($stav === 'onbackorder' ? 'u-vyrobce' : 'vyprodano');
		}

		return $data;
	}
}
