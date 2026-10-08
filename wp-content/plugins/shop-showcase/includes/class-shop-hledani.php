<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Hledání podle kódu zboží a EAN.
 *
 * Řemeslník často přijde s kódem z faktury nebo s čárovým kódem ze staré
 * krabice. Běžné hledání WordPressu prohledá jen název a popis, kód
 * varianty nenajde. Když hledaný výraz přesně odpovídá kódu nebo EAN,
 * zákazník jde rovnou na produkt — u varianty s předvybranou velikostí.
 *
 * V ostrém nasazení je navíc fulltextový index s překlepy a našeptávačem;
 * bez něj (jako v ukázce) zůstává záloha na běžné hledání WordPressu.
 */
class Shop_Hledani {

	public static function registruj_hooky(): void {
		add_action('template_redirect', [self::class, 'presmeruj_na_kod'], 5);
	}

	public static function presmeruj_na_kod(): void {
		if (!is_search() || is_admin()) {
			return;
		}

		$dotaz = trim((string) get_search_query(false));

		// Kód = jedno „slovo" s číslicí; běžné dotazy („boty 43") se nechávají být.
		if ($dotaz === '' || preg_match('/\s/u', $dotaz) === 1 || preg_match('/\d/', $dotaz) !== 1) {
			return;
		}

		$adresa = self::adresa_pro_kod($dotaz);

		if ($adresa !== '') {
			wp_safe_redirect($adresa, 302);
			exit;
		}
	}

	public static function adresa_pro_kod(string $kod): string {
		$id = wc_get_product_id_by_sku($kod);

		if ($id === 0 && function_exists('wc_get_product_id_by_global_unique_id')) {
			$id = wc_get_product_id_by_global_unique_id(preg_replace('/\D+/', '', $kod) ?? '');
		}

		$produkt = $id > 0 ? wc_get_product($id) : null;

		if (!$produkt instanceof WC_Product || $produkt->get_status() !== 'publish') {
			return '';
		}

		if ($produkt instanceof WC_Product_Variation) {
			$rodic    = wc_get_product($produkt->get_parent_id());
			$velikost = $produkt->get_attributes()['pa_velikost'] ?? '';

			return $rodic ? add_query_arg('attribute_pa_velikost', rawurlencode((string) $velikost), $rodic->get_permalink()) : '';
		}

		return (string) $produkt->get_permalink();
	}
}
