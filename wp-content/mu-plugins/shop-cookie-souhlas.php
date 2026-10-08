<?php
/**
 * mu-plugin: cookie lišta se třemi kategoriemi (nezbytné / analytika /
 * reklama) a měření konverzí, které běží až po souhlasu.
 *
 * Do udělení souhlasu se nenačte žádný externí skript. ID měřicích nástrojů
 * se NIKDE v kódu nevyskytují — doplňují se v administraci (Nastavení →
 * Cookie souhlas). Dokud jsou prázdná, nic se nenačte, i kdyby souhlas byl.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('SHOP_COOKIE_SOUHLAS_DIR', __DIR__ . '/shop-cookie-souhlas');
define('SHOP_COOKIE_SOUHLAS_URL', content_url('mu-plugins/shop-cookie-souhlas'));

require_once SHOP_COOKIE_SOUHLAS_DIR . '/class-shop-cookie-souhlas.php';
require_once SHOP_COOKIE_SOUHLAS_DIR . '/class-shop-konverze.php';

Shop_Cookie_Souhlas::registruj_hooky();
Shop_Konverze::registruj_hooky();
