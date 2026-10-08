<?php
/**
 * mu-plugin: produktové feedy pro srovnávače zboží a nákupní reklamu.
 *
 * Adresy /heureka.xml a /google.xml. Generuje se do statického
 * souboru cronem (denně ráno, po noční synchronizaci skladu) nebo přes
 * WP-CLI, nikdy za běhu požadavku — desítky tisíc položek jsou práce na
 * minuty.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('SHOP_FEEDY_DIR', __DIR__ . '/shop-feedy');

require_once SHOP_FEEDY_DIR . '/kategorie.php';
require_once SHOP_FEEDY_DIR . '/class-shop-feedy.php';

Shop_Feedy::registruj_hooky();

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::add_command(
		'shop feed',
		/**
		 * @param array<int, string>    $args
		 * @param array<string, string> $flags
		 */
		static function (array $args, array $flags): void {
			$format    = $args[0] ?? 'vse';
			$nanecisto = isset($flags['dry-run']);

			foreach ($format === 'vse' ? Shop_Feedy::FORMATY : [$format] as $jeden) {
				if (!in_array($jeden, Shop_Feedy::FORMATY, true)) {
					WP_CLI::error('Neznámý formát: ' . $jeden . '. Použij: ' . implode(', ', Shop_Feedy::FORMATY) . ', vse.');
				}

				$zacatek = microtime(true);
				$stat    = Shop_Feedy::generuj($jeden, $nanecisto);

				WP_CLI::log(sprintf(
					'%s: %d položek z %d produktů za %.1f s%s; vynecháno bez ceny %d, nedostupných %d, bez kategorie %d, bez obrázku %d; bez EANu (ale ve feedu) %d',
					$jeden,
					$stat['polozek'],
					$stat['produktu'],
					microtime(true) - $zacatek,
					$nanecisto ? ' (nanečisto, nic se nezapsalo)' : '',
					$stat['bez_ceny'],
					$stat['nedostupnych'],
					$stat['bez_kategorie'],
					$stat['bez_obrazku'],
					$stat['bez_eanu']
				));
			}
		},
		['shortdesc' => 'Vygeneruje feed. Použití: wp shop feed [heureka|google|vse] [--dry-run]']
	);
}
