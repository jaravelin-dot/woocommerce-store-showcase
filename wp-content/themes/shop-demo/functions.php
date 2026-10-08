<?php
/**
 * Ukázková šablona: jen styly. Funkce obchodu jsou v pluginu shop-showcase
 * a v mu-pluginech, ať přežijí výměnu vzhledu.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

add_action('wp_enqueue_scripts', static function (): void {
	$soubor = get_stylesheet_directory() . '/assets/shop.css';
	wp_enqueue_style('shop-demo', get_stylesheet_directory_uri() . '/assets/shop.css', [], (string) filemtime($soubor));
});
