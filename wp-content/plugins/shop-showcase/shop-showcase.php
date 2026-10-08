<?php
/**
 * Plugin Name: Shop Showcase
 * Description: Vybrané moduly e-shopu na WooCommerce (AI poradce, ceny na celé koruny, oslovení v 5. pádě, ochrana registrace, hledání podle kódu, filtry, velikosti, výdejní místa, tisk výdejek). Ukázka do portfolia.
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Jaroslav Velek
 * Text Domain: shop
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('SHOP_SHOWCASE_SOUBOR', __FILE__);
define('SHOP_SHOWCASE_DIR', __DIR__);

require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-osloveni.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-ean13.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-cena-cele-koruny.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-registrace-ochrana.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-hledani.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-filtry.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-velikosti.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-vydejni-mista.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-tisk.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-poradce-model.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-poradce-katalog.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-poradce-prompt.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-poradce.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-poradce-widget.php';
require_once SHOP_SHOWCASE_DIR . '/includes/class-shop-poradce-admin.php';

add_action('plugins_loaded', static function (): void {
	if (!class_exists('WooCommerce')) {
		return; // Bez WooCommerce nic z toho nedává smysl a nesmí to shodit web.
	}

	Shop_Cena_Cele_Koruny::registruj_hooky();
	Shop_Registrace_Ochrana::registruj_hooky();
	Shop_Hledani::registruj_hooky();
	Shop_Filtry::registruj_hooky();
	Shop_Velikosti::registruj_hooky();
	Shop_Vydejni_Mista::registruj_hooky();
	Shop_Tisk::registruj_hooky();
	Shop_Poradce::registruj_hooky();
	Shop_Poradce_Widget::registruj_hooky();
	Shop_Poradce_Admin::registruj_hooky();

	// Oslovení v 5. pádě na děkovací stránce („Děkujeme, Petře.").
	add_filter('woocommerce_thankyou_order_received_text', static function (string $text, $objednavka): string {
		if (!$objednavka instanceof WC_Order) {
			return $text;
		}

		$jmeno = Shop_Osloveni::z_objednavky($objednavka);

		return $jmeno === ''
			? 'Děkujeme, objednávku jsme přijali.'
			: sprintf('Děkujeme, %s, objednávku jsme přijali.', esc_html($jmeno));
	}, 10, 2);
});
