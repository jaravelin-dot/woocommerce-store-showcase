<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Nástroje AI poradce nad katalogem. Jen čtou.
 *
 * BÍLÝ SEZNAM POLÍ: výstup se skládá ručně z veřejných getterů (název, cena
 * s DPH, termy atributů, stav skladu). Nikdy get_meta_data() ani celé meta —
 * nákupní cena ani interní údaj nesmí mít odkud vzniknout.
 *
 * Filtr, na který nic nesedí, se NEZAHAZUJE: vrátí se celkem 0 a poznámka.
 * Tiché zahození filtru by z „S3" udělalo „cokoli podobného" a model by to
 * sebejistě podal jako S3.
 *
 * Volný text hledá přes běžné hledání WordPressu + kód zboží a EAN.
 * V ostrém nasazení je za tím fulltextový index (překlepy, kódy); tady
 * stačí databáze, rozhraní nástroje je stejné.
 */
class Shop_Poradce_Katalog {
	/** Atributy, podle kterých smí model filtrovat. Velikost má vlastní parametr. */
	public const KLICOVE_ATRIBUTY = [
		'pa_bezpecnostni_trida',
		'pa_pohlavi',
		'pa_barva',
		'pa_znacka',
		'pa_prostredi',
		'pa_material',
		'pa_vlastnosti',
	];

	/** Atributy, které se vrací u každého produktu ve výsledku hledání (krátce). */
	private const ATRIBUTY_VE_VYSLEDKU = ['pa_bezpecnostni_trida', 'pa_pohlavi', 'pa_vlastnosti', 'pa_material'];

	public const LIMIT_VYCHOZI = 8;
	public const LIMIT_MAX     = 12;

	/** Kolik kandidátů se nejvýš vytáhne před dofiltrováním velikosti a ceny. */
	private const KANDIDATU_MAX = 300;

	/** Velikosti, které katalog vede pod dvěma jmény. */
	private const VELIKOST_SYNONYMA = ['xxl' => ['xxl', '2xl'], '2xl' => ['xxl', '2xl'], 'xxxl' => ['3xl', 'xxxl'], '3xl' => ['3xl', 'xxxl']];

	/**
	 * Hledání produktů.
	 *
	 * @param array<string, mixed> $vstup kategorie, filtry, velikost, text, cena_do, limit, razeni
	 * @return array<string, mixed>
	 */
	public static function hledej(array $vstup): array {
		$poznamky  = [];
		$pouzite   = [];
		$nic       = false;
		$tax_query = [
			'relation' => 'AND',
			// Vyprodané a skryté zboží poradce neukáže.
			['taxonomy' => 'product_visibility', 'field' => 'slug', 'terms' => ['outofstock', 'exclude-from-catalog', 'exclude-from-search'], 'operator' => 'NOT IN'],
		];

		$kategorie = isset($vstup['kategorie']) ? sanitize_title((string) $vstup['kategorie']) : '';

		if ($kategorie !== '') {
			if (get_term_by('slug', $kategorie, 'product_cat') instanceof WP_Term) {
				$tax_query[]          = ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => [$kategorie], 'include_children' => true];
				$pouzite['kategorie'] = $kategorie;
			} else {
				$poznamky[] = "Kategorie „{$kategorie}\" neexistuje.";
				$nic        = true;
			}
		}

		// Atributy: uvnitř jednoho NEBO, mezi atributy A (jako filtry výpisu).
		foreach ((array) ($vstup['filtry'] ?? []) as $taxonomie => $hodnoty) {
			$taxonomie = (string) $taxonomie;

			if (!in_array($taxonomie, self::KLICOVE_ATRIBUTY, true) || !taxonomy_exists($taxonomie)) {
				$poznamky[] = "Podle „{$taxonomie}\" filtrovat nejde.";
				$nic        = true;
				continue;
			}

			$platne = [];

			foreach ((array) $hodnoty as $hodnota) {
				$slug = sanitize_title((string) $hodnota);

				if (get_term_by('slug', $slug, $taxonomie) instanceof WP_Term) {
					$platne[] = $slug;
				} else {
					$poznamky[] = "Hodnota „{$slug}\" u {$taxonomie} neexistuje.";
				}
			}

			if ($platne === []) {
				$nic = true;
				continue;
			}

			$tax_query[]                   = ['taxonomy' => $taxonomie, 'field' => 'slug', 'terms' => $platne, 'operator' => 'IN'];
			$pouzite['filtry'][$taxonomie] = $platne;
		}

		$text = isset($vstup['text']) ? trim(sanitize_text_field((string) $vstup['text'])) : '';
		$ids_textu = null;

		if ($text !== '') {
			$pouzite['text'] = $text;
			$ids_textu       = self::hledej_text($text);

			if ($ids_textu === []) {
				$nic = true;
			}
		}

		$velikost = isset($vstup['velikost']) ? trim(sanitize_text_field((string) $vstup['velikost'])) : '';
		$cena_do  = isset($vstup['cena_do']) && is_numeric($vstup['cena_do']) ? (float) $vstup['cena_do'] : 0.0;
		$limit    = max(1, min(self::LIMIT_MAX, (int) ($vstup['limit'] ?? self::LIMIT_VYCHOZI)));
		$podle_ceny = ($vstup['razeni'] ?? '') === 'cena';

		if ($velikost !== '') {
			$pouzite['velikost'] = $velikost;
		}

		if ($cena_do > 0) {
			$pouzite['cena_do'] = $cena_do;
		}

		if ($podle_ceny) {
			$pouzite['razeni'] = 'cena';
		}

		if ($pouzite === [] && !$nic) {
			return ['celkem' => 0, 'zobrazeno' => 0, 'pouzite_filtry' => [], 'produkty' => [], 'poznamka' => 'Zadejte aspoň kategorii, parametr, velikost nebo text.'];
		}

		if ($nic) {
			return ['celkem' => 0, 'zobrazeno' => 0, 'pouzite_filtry' => $pouzite, 'produkty' => [], 'poznamka' => implode(' ', array_unique($poznamky)) ?: 'Nic neodpovídá.'];
		}

		$dotaz = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => self::KANDIDATU_MAX,
			'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'no_found_rows'  => true,
		];

		if (is_array($ids_textu)) {
			$dotaz['post__in'] = $ids_textu;
		}

		$kandidati = array_map('intval', (new WP_Query($dotaz))->posts);

		// Velikost: produkt, který ji nemá (nebo je v ní vyprodaný), vypadne.
		$velikost_stav = [];

		if ($velikost !== '') {
			$velikost_stav = self::dostupnost_velikosti($kandidati, $velikost);
			$kandidati     = array_values(array_filter($kandidati, static fn(int $id): bool => isset($velikost_stav[$id])));

			if ($kandidati === [] && self::slugy_velikosti($velikost) === []) {
				$poznamky[] = "Velikost „{$velikost}\" v katalogu nevedeme.";
			}
		}

		$zaznamy = [];

		foreach ($kandidati as $id) {
			$produkt = wc_get_product($id);

			if (!$produkt instanceof WC_Product) {
				continue;
			}

			// S velikostí platí cena té velikosti — větší velikosti bývají dražší.
			$cena = isset($velikost_stav[$id]) ? $velikost_stav[$id]['cena'] : self::cena_s_dph($produkt);

			if ($cena_do > 0 && $cena > $cena_do) {
				continue;
			}

			$zaznam = [
				'id'         => $id,
				'nazev'      => $produkt->get_name(),
				'kategorie'  => self::kategorie_nazev($id),
				'cena_kc'    => $cena,
				'dostupnost' => isset($velikost_stav[$id]) ? $velikost_stav[$id]['dostupnost'] : self::dostupnost_ze_stavu($produkt->get_stock_status()),
				'_prodeje'   => (int) $produkt->get_total_sales(),
			];

			if ($velikost !== '') {
				$zaznam['velikost_dostupnost'] = $velikost_stav[$id]['dostupnost'];
			}

			$parametry = self::parametry($id, self::ATRIBUTY_VE_VYSLEDKU);

			if ($parametry !== []) {
				$zaznam['parametry'] = $parametry;
			}

			$zaznamy[] = $zaznam;
		}

		// „Doporučené" = skladem první, pak podle prodejů; „cena" = od nejlevnějšího.
		usort($zaznamy, static fn(array $a, array $b): int => $podle_ceny
			? [$a['cena_kc'], $b['_prodeje']] <=> [$b['cena_kc'], $a['_prodeje']]
			: [$a['dostupnost'] !== 'skladem', $b['_prodeje'], $b['id']] <=> [$b['dostupnost'] !== 'skladem', $a['_prodeje'], $a['id']]);

		$produkty = array_map(static function (array $z): array {
			unset($z['_prodeje']);

			return $z;
		}, array_slice($zaznamy, 0, $limit));

		$vysledek = ['celkem' => count($zaznamy), 'zobrazeno' => count($produkty), 'pouzite_filtry' => $pouzite, 'produkty' => $produkty];

		if ($poznamky !== []) {
			$vysledek['poznamka'] = implode(' ', array_unique($poznamky));
		}

		return $vysledek;
	}

	/**
	 * Úplné parametry, popis a velikosti jednoho produktu.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function detail(int $id): ?array {
		$produkt = wc_get_product($id);

		if (!self::je_verejny($produkt)) {
			return null;
		}

		$popis = trim($produkt->get_short_description() . "\n" . $produkt->get_description());
		$popis = trim(html_entity_decode(wp_strip_all_tags((string) preg_replace('#<(br|/p|/li|/tr)[^>]*>#i', "\n", $popis)), ENT_QUOTES, 'UTF-8'));

		$detail = [
			'id'         => $id,
			'nazev'      => $produkt->get_name(),
			'kategorie'  => self::kategorie_nazev($id),
			'cena_kc'    => self::cena_s_dph($produkt),
			'dostupnost' => self::dostupnost_ze_stavu($produkt->get_stock_status()),
			'parametry'  => self::parametry($id, self::KLICOVE_ATRIBUTY),
			'popis'      => mb_substr($popis, 0, 1500, 'UTF-8'),
			'odkaz'      => (string) $produkt->get_permalink(),
		];

		if ($produkt->is_type('variable')) {
			$detail['velikosti'] = [];

			foreach ($produkt->get_children() as $id_varianty) {
				$varianta = wc_get_product($id_varianty);

				if ($varianta instanceof WC_Product_Variation && $varianta->get_attribute('pa_velikost') !== '') {
					// Bez počtu kusů — ten do odpovědi nepatří.
					$detail['velikosti'][] = ['velikost' => $varianta->get_attribute('pa_velikost'), 'dostupnost' => self::dostupnost_ze_stavu($varianta->get_stock_status())];
				}
			}
		}

		return $detail;
	}

	/**
	 * Data karty pro widget. Cena, fotka a dostupnost VŽDY z WooCommerce,
	 * nikdy z textu modelu.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function karta(int $id): ?array {
		$produkt = wc_get_product($id);

		if (!self::je_verejny($produkt) || $produkt->get_stock_status() === 'outofstock') {
			return null;
		}

		$cena = self::cena_s_dph($produkt);
		$text = number_format($cena, 0, ',', "\u{00A0}") . "\u{00A0}Kč";

		if ($produkt->is_type('variable')) {
			$max = (float) wc_get_price_including_tax($produkt, ['price' => (float) $produkt->get_variation_price('max')]);
			$text = round($max) > round($cena) ? 'od ' . $text : $text;
		}

		$obrazek = (int) $produkt->get_image_id();

		return [
			'id'         => $id,
			'nazev'      => $produkt->get_name(),
			'cena_kc'    => $cena,
			'cena_text'  => $text,
			'dostupnost' => self::dostupnost_ze_stavu($produkt->get_stock_status()),
			'fotka'      => $obrazek > 0 ? (string) wp_get_attachment_image_url($obrazek, 'woocommerce_thumbnail') : '',
			'odkaz'      => (string) $produkt->get_permalink(),
		];
	}

	/** @param mixed $produkt */
	private static function je_verejny($produkt): bool {
		return $produkt instanceof WC_Product
			&& $produkt->get_status() === 'publish'
			&& !$produkt->is_type('variation')
			&& $produkt->get_catalog_visibility() !== 'hidden';
	}

	private static function dostupnost_ze_stavu(string $stav): string {
		if ($stav === 'instock') {
			return 'skladem';
		}

		return $stav === 'onbackorder' ? 'u-vyrobce' : 'vyprodano';
	}

	/** Cena s DPH na celé koruny. U variabilního produktu nejnižší. */
	private static function cena_s_dph(WC_Product $produkt): float {
		return (float) round((float) wc_get_price_including_tax($produkt));
	}

	/**
	 * ID produktů podle textu: běžné hledání WordPressu (název, popis)
	 * + přesná shoda kódu zboží nebo EAN u produktu i varianty.
	 *
	 * @return list<int>
	 */
	private static function hledej_text(string $text): array {
		$ids = (new WP_Query([
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => $text,
			'fields'         => 'ids',
			'posts_per_page' => self::KANDIDATU_MAX,
			'no_found_rows'  => true,
		]))->posts;

		$podle_kodu = wc_get_product_id_by_sku($text);

		if ($podle_kodu === 0 && function_exists('wc_get_product_id_by_global_unique_id')) {
			$podle_kodu = wc_get_product_id_by_global_unique_id($text);
		}

		if ($podle_kodu > 0) {
			$nalezeny = wc_get_product($podle_kodu);
			$ids[]    = $nalezeny instanceof WC_Product_Variation ? $nalezeny->get_parent_id() : $podle_kodu;
		}

		return array_values(array_unique(array_map('intval', $ids)));
	}

	/**
	 * Slugy termů pa_velikost pro to, co napsal zákazník: „43" = 43,
	 * „XL" = XL, „XXL" = 2XL.
	 *
	 * @return list<string>
	 */
	public static function slugy_velikosti(string $velikost): array {
		$hledane = mb_strtolower(trim($velikost), 'UTF-8');

		if ($hledane === '') {
			return [];
		}

		$varianty = self::VELIKOST_SYNONYMA[$hledane] ?? [$hledane];
		$termy    = get_terms(['taxonomy' => 'pa_velikost', 'hide_empty' => false]);
		$slugy    = [];

		foreach (is_array($termy) ? $termy : [] as $term) {
			if (in_array(mb_strtolower(trim($term->name), 'UTF-8'), $varianty, true)) {
				$slugy[] = (string) $term->slug;
			}
		}

		return $slugy;
	}

	/**
	 * Dostupnost a cena dané velikosti u produktů. Vrací jen ty, které ji
	 * mají skladem nebo u výrobce.
	 *
	 * @param list<int> $rodice
	 * @return array<int, array{dostupnost: string, cena: float}>
	 */
	private static function dostupnost_velikosti(array $rodice, string $velikost): array {
		$slugy    = self::slugy_velikosti($velikost);
		$vysledek = [];

		if ($slugy === []) {
			return [];
		}

		foreach ($rodice as $id) {
			$produkt = wc_get_product($id);

			if (!$produkt instanceof WC_Product_Variable) {
				continue;
			}

			foreach ($produkt->get_children() as $id_varianty) {
				$varianta = wc_get_product($id_varianty);

				if (!$varianta instanceof WC_Product_Variation || !in_array($varianta->get_attributes()['pa_velikost'] ?? '', $slugy, true)) {
					continue;
				}

				$stav = self::dostupnost_ze_stavu($varianta->get_stock_status());

				if ($stav === 'vyprodano') {
					continue;
				}

				// Skladem má přednost před „u výrobce", když jsou dvě varianty stejné velikosti.
				if (!isset($vysledek[$id]) || $stav === 'skladem') {
					$vysledek[$id] = ['dostupnost' => $stav, 'cena' => self::cena_s_dph($varianta)];
				}
			}
		}

		return $vysledek;
	}

	/**
	 * Názvy termů zvolených atributů. Klíč = popisek atributu.
	 *
	 * @param list<string> $taxonomie
	 * @return array<string, string>
	 */
	private static function parametry(int $id, array $taxonomie): array {
		$vysledek = [];

		foreach ($taxonomie as $tax) {
			$termy = taxonomy_exists($tax) ? wp_get_post_terms($id, $tax, ['fields' => 'names']) : [];

			if (is_array($termy) && $termy !== []) {
				sort($termy);
				$vysledek[wc_attribute_label($tax)] = implode(', ', $termy);
			}
		}

		return $vysledek;
	}

	/** Nejhlubší (nejkonkrétnější) kategorie produktu. */
	private static function kategorie_nazev(int $id): string {
		$termy = wp_get_post_terms($id, 'product_cat');

		if (!is_array($termy) || $termy === []) {
			return '';
		}

		usort($termy, static fn(WP_Term $a, WP_Term $b): int => count(get_ancestors($b->term_id, 'product_cat')) <=> count(get_ancestors($a->term_id, 'product_cat')));

		return $termy[0]->name;
	}
}
