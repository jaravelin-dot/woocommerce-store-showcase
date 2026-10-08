<?php
/**
 * mu-plugin: SEO vrstva e-shopu.
 *
 * Řeší tři věci, které WordPress ani WooCommerce samy nedělají:
 *
 *   1. Indexace — kanonická adresa na archivech (jádro ji dává jen na
 *      jednotlivé stránky), noindex na zúžených výpisech, nofollow na
 *      odkazech filtrů a pravidla do robots.txt. Bez toho má jedna kategorie
 *      stovky filtrovaných adres, které nic nového nenesou.
 *   2. Meta popisky a Open Graph.
 *   3. Strukturovaná data — entita firmy a prodejny na úvodce a obohacení
 *      produktového schématu z WooCommerce o značku, EAN, cenu S DPH
 *      a velikosti (ProductGroup).
 *
 * Navíc /llms.txt — stručná mapa obchodu pro jazykové modely.
 *
 * Proč mu-plugin, ne šablona: SEO nesmí zmizet s výměnou vzhledu a nesmí
 * záviset na tom, jestli někdo plugin v administraci omylem vypne.
 *
 * Fakta o firmě jsou na jednom místě v shop-seo/firma.php.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('SHOP_SEO_DIR', __DIR__ . '/shop-seo');

require_once SHOP_SEO_DIR . '/firma.php';
require_once SHOP_SEO_DIR . '/class-shop-seo-indexace.php';
require_once SHOP_SEO_DIR . '/class-shop-seo-meta.php';
require_once SHOP_SEO_DIR . '/class-shop-seo-schema.php';
require_once SHOP_SEO_DIR . '/class-shop-seo-llms.php';

Shop_Seo_Indexace::registruj_hooky();
Shop_Seo_Meta::registruj_hooky();
Shop_Seo_Schema::registruj_hooky();
Shop_Seo_Llms::registruj_hooky();
