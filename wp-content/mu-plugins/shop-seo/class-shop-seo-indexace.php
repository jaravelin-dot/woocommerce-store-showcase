<?php
/**
 * Indexace: co smí do vyhledávače a pod jakou adresou.
 *
 * Problém: na jedné kategorii jsou stovky odkazů typu ?filter_velikost=…,
 * ?orderby=…, jádro na archivu nedá kanonickou adresu a robots.txt na ně
 * nemá pravidlo. Při desítkách kategorií se adresy násobí do desetitisíců
 * a robot plýtvá časem na kopiích téhož výpisu.
 *
 * Tři vrstvy, každá řeší jiný případ:
 *
 *   robots.txt   … robot adresu vůbec nestáhne (šetří crawl budget)
 *   rel=nofollow … robot si ji nezařadí do fronty z odkazu na stránce
 *   noindex      … pojistka pro adresu, kterou robot najde jinudy
 *
 * Na zúžené stránky se ZÁMĚRNĚ nedává kanonická adresa mířící na čistý
 * výpis. Kanonická adresa a noindex na téže stránce jsou protichůdné
 * signály; noindex je jednoznačný, tak ať tam stojí sám.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class Shop_Seo_Indexace {

	/** Parametry, které výpis zužují. `filter_` je předpona, zbytek přesné názvy. */
	private const PREDPONY = ['filter_', 'query_type_'];
	private const PRESNE   = ['orderby', 'min_price', 'max_price', 'product_orderby', 'product_count'];

	public static function registruj_hooky(): void {
		add_filter('wp_robots', [self::class, 'robots_meta']);
		add_action('wp_head', [self::class, 'kanonicka'], 9);
		add_filter('robots_txt', [self::class, 'robots_txt'], 10, 2);
		add_action('template_redirect', [self::class, 'zacni_nofollow_buffer'], 20);
	}

	/** Nese aktuální požadavek některý ze zužujících parametrů? */
	public static function je_zuzena(): bool {
		foreach (array_keys($_GET) as $klic) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$klic = (string) $klic;

			if (in_array($klic, self::PRESNE, true)) {
				return true;
			}

			foreach (self::PREDPONY as $predpona) {
				if (str_starts_with($klic, $predpona)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Zúžené výpisy a výsledky hledání ven z indexu. `follow` zůstává, aby
	 * se robot přes ně dostal na produkty — ty indexované být mají.
	 *
	 * @param array<string, mixed> $robots
	 * @return array<string, mixed>
	 */
	public static function robots_meta(array $robots): array {
		if (is_admin()) {
			return $robots;
		}

		if (is_search() || self::je_zuzena()) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset($robots['nofollow']);
		}

		return $robots;
	}

	/**
	 * Kanonická adresa pro archivy — vždy sebe-odkazující a bez parametrů.
	 *
	 * Stránka 2 odkazuje na sebe, ne na stránku 1: má jiný obsah, a sloučit
	 * je by znamenalo, že se produkty z dalších stránek ztratí.
	 */
	public static function kanonicka(): void {
		if (is_singular() || is_404() || is_search() || self::je_zuzena()) {
			return;
		}

		$adresa = self::zakladni_adresa();

		if ($adresa === '') {
			return;
		}

		$strana = (int) get_query_var('paged');

		if ($strana > 1) {
			$adresa = trailingslashit($adresa) . 'page/' . $strana . '/';
		}

		echo '<link rel="canonical" href="' . esc_url($adresa) . '" />' . "\n";
	}

	private static function zakladni_adresa(): string {
		if (is_front_page()) {
			return home_url('/');
		}

		if (is_tax() || is_category() || is_tag()) {
			$termin = get_queried_object();
			$odkaz  = $termin instanceof WP_Term ? get_term_link($termin) : '';

			return is_string($odkaz) ? $odkaz : '';
		}

		if (is_post_type_archive()) {
			$typ = get_query_var('post_type');
			$typ = is_array($typ) ? (string) reset($typ) : (string) $typ;

			// Výpis obchodu je stránka, ne archiv typu — její permalink je to,
			// co zákazník i robot vidí v adresním řádku.
			if ($typ === 'product' && function_exists('wc_get_page_id') && wc_get_page_id('shop') > 0) {
				$odkaz = get_permalink(wc_get_page_id('shop'));

				return is_string($odkaz) ? $odkaz : '';
			}

			$odkaz = get_post_type_archive_link($typ);

			return is_string($odkaz) ? $odkaz : '';
		}

		return '';
	}

	/**
	 * Pravidla do robots.txt.
	 *
	 * Záměrně se NEZAKLÁDÁ vlastní skupina pro AI roboty. Skupina pro
	 * konkrétního robota nahrazuje skupinu `*` celou, takže „User-agent: XY /
	 * Allow: /" by mu naopak otevřela všechny filtry, které tady zavíráme.
	 *
	 * @param string $vystup
	 * @param string $verejny '1' když je web viditelný pro vyhledávače
	 */
	public static function robots_txt(string $vystup, $verejny): string {
		if ((string) $verejny !== '1') {
			return $vystup;
		}

		$radky = ['', '# Zúžené výpisy — filtry, řazení a cenové rozpětí.'];

		foreach (self::PREDPONY as $predpona) {
			$radky[] = 'Disallow: /*?' . $predpona;
			$radky[] = 'Disallow: /*&' . $predpona;
		}

		foreach (self::PRESNE as $parametr) {
			$radky[] = 'Disallow: /*?' . $parametr . '=';
			$radky[] = 'Disallow: /*&' . $parametr . '=';
		}

		$radky[] = '';
		$radky[] = '# Interní vyhledávání.';
		$radky[] = 'Disallow: /*?s=';
		$radky[] = 'Disallow: /*&s=';

		return $vystup . implode("\n", $radky) . "\n";
	}

	/**
	 * Doplní rel="nofollow" na odkazy, které vedou na zúžený výpis.
	 *
	 * Dělá se to nad hotovým HTML, ne v šabloně: odkazy filtrů vykresluje
	 * několik míst (blok filtrů, řazení, stránkování) a pravidlo má platit
	 * pro všechna, i pro ta, která přibudou.
	 */
	public static function zacni_nofollow_buffer(): void {
		if (is_admin() || is_singular() || is_feed() || wp_doing_ajax()) {
			return;
		}

		if (!is_post_type_archive('product') && !is_tax(get_object_taxonomies('product'))) {
			return;
		}

		ob_start([self::class, 'doplnit_nofollow']);
	}

	public static function doplnit_nofollow(string $html): string {
		// Zkratka: bez zužujícího odkazu se neplatí za regulární výraz nad
		// celou stránkou.
		if (!str_contains($html, 'filter_') && !str_contains($html, 'orderby=') && !str_contains($html, 'min_price=')) {
			return $html;
		}

		$vzor = '#<a\s+([^>]*href="[^"]*[?&](?:filter_|query_type_|orderby=|min_price=|max_price=)[^"]*"[^>]*)>#i';

		$vysledek = preg_replace_callback(
			$vzor,
			static function (array $shoda): string {
				// Cizí rel se nepřepisuje — mohlo by to shodit něco jiného.
				if (preg_match('#\srel\s*=#i', $shoda[1]) === 1) {
					return $shoda[0];
				}

				return '<a ' . $shoda[1] . ' rel="nofollow">';
			},
			$html
		);

		// Při chybě (backtrack limit na velké stránce) radši původní HTML
		// než prázdná stránka.
		return is_string($vysledek) ? $vysledek : $html;
	}
}
