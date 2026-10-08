<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Filtry výpisu: blok `shop/filtry` (velikost, třída obuvi, barva, …).
 *
 * Nabízí jen hodnoty, které v aktuální kategorii opravdu jsou, s počtem
 * produktů — žádná velikost 50 u rukavic. Odkazy používají standardní
 * parametry WooCommerce (?filter_velikost=43&query_type_velikost=or),
 * takže filtrování dělá jádro a SEO vrstva je umí poznat (noindex,
 * nofollow, robots.txt — viz mu-plugin shop-seo).
 */
class Shop_Filtry {
	/** Atribut → popisek. Pořadí = pořadí v panelu. */
	private const ATRIBUTY = [
		'pa_velikost'           => 'Velikost',
		'pa_bezpecnostni_trida' => 'Třída ochrany',
		'pa_pohlavi'            => 'Pro koho',
		'pa_vlastnosti'         => 'Vlastnosti',
		'pa_barva'              => 'Barva',
	];

	public static function registruj_hooky(): void {
		add_action('init', static function (): void {
			register_block_type('shop/filtry', ['render_callback' => [self::class, 'vykresli']]);
		});

		// Blok výpisu (Product Collection) čte filtry přes get_query_var(), které
		// WooCommerce registruje jen se svým vlastním blokem filtrů. Bez toho by
		// odkazy filtrů měnily adresu, ale ne výsledky.
		add_filter('query_vars', static function (array $promenne): array {
			foreach (array_keys(self::ATRIBUTY) as $taxonomie) {
				$promenne[] = 'filter_' . substr($taxonomie, 3);
				$promenne[] = 'query_type_' . substr($taxonomie, 3);
			}

			return $promenne;
		});
	}

	public static function vykresli(): string {
		if (!function_exists('is_shop') || (!is_shop() && !is_product_taxonomy())) {
			return '';
		}

		$ids = self::produkty_vypisu();

		if ($ids === []) {
			return '';
		}

		$zaklad = self::adresa_bez_filtru();
		$html   = '';

		foreach (self::ATRIBUTY as $taxonomie => $popisek) {
			if (!taxonomy_exists($taxonomie)) {
				continue;
			}

			$pocty = self::pocty($ids, $taxonomie);

			if ($pocty === []) {
				continue;
			}

			$nazev   = substr($taxonomie, 3);
			$zvolene = array_filter(explode(',', isset($_GET['filter_' . $nazev]) ? sanitize_text_field(wp_unslash((string) $_GET['filter_' . $nazev])) : '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$polozky = '';

			foreach ($pocty as $slug => [$jmeno, $pocet]) {
				$slug    = (string) $slug; // Číselný klíč pole („43") PHP převede na int.
				$aktivni = in_array($slug, $zvolene, true);
				$nove    = $aktivni ? array_diff($zvolene, [$slug]) : array_merge($zvolene, [$slug]);
				$odkaz   = $nove === []
					? remove_query_arg(['filter_' . $nazev, 'query_type_' . $nazev], $zaklad)
					: add_query_arg(['filter_' . $nazev => implode(',', $nove), 'query_type_' . $nazev => 'or'], $zaklad);

				$polozky .= sprintf(
					'<li><a class="shop-filtry__volba%s" href="%s" aria-pressed="%s">%s <span class="shop-filtry__pocet">%d</span></a></li>',
					$aktivni ? ' je-aktivni' : '',
					esc_url($odkaz),
					$aktivni ? 'true' : 'false',
					esc_html($jmeno),
					$pocet
				);
			}

			$html .= '<div class="shop-filtry__skupina"><h3 class="shop-filtry__nadpis">' . esc_html($popisek) . '</h3><ul class="shop-filtry__volby shop-filtry__volby--' . esc_attr($nazev) . '">' . $polozky . '</ul></div>';
		}

		return $html === '' ? '' : '<nav class="shop-filtry" aria-label="' . esc_attr__('Filtry', 'shop') . '">' . $html . '</nav>';
	}

	/**
	 * ID produktů aktuálního výpisu BEZ filtrů (aby šlo filtr i zrušit nebo
	 * přidat další hodnotu stejného atributu).
	 *
	 * @return list<int>
	 */
	private static function produkty_vypisu(): array {
		$dotaz = ['post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true];

		if (is_product_taxonomy()) {
			$termin             = get_queried_object();
			$dotaz['tax_query'] = [['taxonomy' => $termin->taxonomy, 'field' => 'term_id', 'terms' => [$termin->term_id], 'include_children' => true]]; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		return array_map('intval', get_posts($dotaz));
	}

	/**
	 * Hodnoty atributu u produktů výpisu s počty (velikosti podle hodnoty, ostatní podle abecedy).
	 *
	 * @param list<int> $ids
	 * @return array<string, array{0: string, 1: int}> slug => [název, počet]
	 */
	private static function pocty(array $ids, string $taxonomie): array {
		$pocty = [];

		foreach ($ids as $id) {
			foreach (wc_get_product_terms($id, $taxonomie, ['orderby' => 'menu_order']) as $term) {
				$pocty[$term->slug] = [$term->name, ($pocty[$term->slug][1] ?? 0) + 1];
			}
		}

		// Velikosti: čísla podle hodnoty (38 < 40), písmenné podle konfekční řady (S < M < XL < 2XL).
		if ($taxonomie === 'pa_velikost') {
			$rada  = ['xs', 's', 'm', 'l', 'xl', '2xl', '3xl', '4xl', '5xl'];
			$klic  = static function ($slug) use ($rada): float {
				if (is_numeric($slug)) {
					return (float) $slug;
				}

				$poradi = array_search(strtolower((string) $slug), $rada, true);

				return 1000 + ($poradi === false ? 99 : $poradi);
			};
			uksort($pocty, static fn($a, $b): int => $klic($a) <=> $klic($b));
		} else {
			uasort($pocty, static fn(array $a, array $b): int => strnatcasecmp($a[0], $b[0]));
		}

		return $pocty;
	}

	private static function adresa_bez_filtru(): string {
		$adresa = is_product_taxonomy() ? get_term_link(get_queried_object()) : get_permalink(wc_get_page_id('shop'));
		$adresa = is_string($adresa) ? $adresa : home_url('/');

		// Ostatní zvolené filtry zůstanou, stránkování se zahodí.
		$parametry = [];

		foreach ($_GET as $klic => $hodnota) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if (is_string($hodnota) && (str_starts_with((string) $klic, 'filter_') || str_starts_with((string) $klic, 'query_type_') || $klic === 'orderby')) {
				$parametry[(string) $klic] = sanitize_text_field(wp_unslash($hodnota));
			}
		}

		return add_query_arg($parametry, $adresa);
	}
}
