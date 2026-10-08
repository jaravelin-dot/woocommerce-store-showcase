<?php
/**
 * /llms.txt — přehled obchodu pro jazykové modely.
 *
 * Když se někdo zeptá chatovacího asistenta „kde koupím pracovní boty S3",
 * model si odpověď skládá z toho, co na webu najde. Tenhle soubor je
 * stručná, pravdivá mapa: kdo jsme, co prodáváme, jaké jsou podmínky
 * nákupu a kam vede která část katalogu. Skládá se ŽIVĚ z databáze, takže
 * nezastará, když přibude kategorie.
 *
 * Formát llms.txt je konvence, ne standard; stojí skoro nic a nic nerozbije.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class Shop_Seo_Llms {

	public static function registruj_hooky(): void {
		add_action('init', static fn() => add_rewrite_rule('^llms\.txt$', 'index.php?shop_llms=1', 'top'));
		add_filter('query_vars', static fn(array $promenne): array => array_merge($promenne, ['shop_llms']));
		add_action('template_redirect', [self::class, 'obsluz'], 0);
		// Jádro by /llms.txt přesměrovalo na /llms.txt/ (trvalé odkazy končí lomítkem).
		add_filter('redirect_canonical', static fn($adresa) => get_query_var('shop_llms') ? false : $adresa);
	}

	public static function obsluz(): void {
		if (!get_query_var('shop_llms')) {
			return;
		}

		status_header(200);
		header('Content-Type: text/plain; charset=UTF-8');
		echo self::obsah(); // phpcs:ignore WordPress.Security.EscapeOutput -- prostý text
		exit;
	}

	public static function obsah(): string {
		$firma = shop_seo_firma();
		$p     = $firma['prodejna'];

		$t  = '# ' . $firma['nazev'] . "\n\n";
		$t .= '> ' . $firma['popis'] . "\n\n";

		$t .= "## Kdo jsme\n\n";
		$t .= '- Kamenná prodejna: ' . $p['ulice'] . ', ' . $p['psc'] . ' ' . $p['mesto'] . ' (' . $p['plocha'] . ")\n";
		$t .= '- E-mail: ' . $firma['email'] . "\n";
		$t .= "- Zboží jde na prodejně vyzkoušet před nákupem.\n\n";

		$t .= "## Podmínky nákupu\n\n";
		$t .= '- Doprava zdarma od ' . shop_seo_cislo((float) $firma['doprava_zdarma_od']) . " Kč\n";
		$t .= '- Vrácení zboží do ' . $firma['lhuta_vraceni_dnu'] . " dnů\n";

		// Odkazy se nepíšou natvrdo: stránka se dá zrušit a llms.txt by
		// odkazoval do prázdna. Vypíše se jen to, co existuje a je publikované.
		$t .= self::odkazy_na_stranky([
			'doprava-a-platba'  => 'Doprava a platba',
			'obchodni-podminky' => 'Obchodní podmínky',
			'kontakt'           => 'Kontakt',
		]);

		$t .= "\n## Sortiment\n\n" . self::strom();
		$t .= "\n## Poznámka\n\nDostupnost a ceny se mění denně (sklad je společný s kamennou prodejnou).\n";
		$t .= "Aktuální stav je vždy na konkrétní stránce produktu, ne v tomhle souboru.\n";

		return $t;
	}

	/** @param array<string, string> $stranky slug => popisek */
	private static function odkazy_na_stranky(array $stranky): string {
		$t = '';

		foreach ($stranky as $slug => $popisek) {
			$stranka = get_page_by_path($slug);

			if ($stranka instanceof WP_Post && $stranka->post_status === 'publish') {
				$t .= '- ' . $popisek . ': ' . get_permalink($stranka) . "\n";
			}
		}

		return $t;
	}

	/** Hlavní kategorie a jejich přímé podkategorie, každá s první větou popisu. */
	private static function strom(): string {
		$koreny = get_terms(['taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC']);

		if (!is_array($koreny)) {
			return '';
		}

		$t = '';

		foreach ($koreny as $koren) {
			if ($koren->slug === 'uncategorized') {
				continue;
			}

			$t   .= '### ' . $koren->name . "\n\n";
			$veta = self::prvni_veta($koren->description);
			$t   .= $veta !== '' ? $veta . "\n\n" : '';

			$deti = get_terms(['taxonomy' => 'product_cat', 'parent' => $koren->term_id, 'hide_empty' => true, 'orderby' => 'name']);

			foreach (is_array($deti) ? $deti : [] as $dite) {
				$odkaz = get_term_link($dite);
				$vetaD = self::prvni_veta($dite->description);
				$t    .= '- [' . $dite->name . '](' . (is_string($odkaz) ? $odkaz : '') . ')' . ($vetaD !== '' ? ': ' . $vetaD : '') . "\n";
			}

			$t .= "\n";
		}

		return $t;
	}

	private static function prvni_veta(string $text): string {
		$text = trim(wp_strip_all_tags($text));

		return preg_match('/^(.+?[.!?])(\s|$)/u', $text, $shoda) === 1 ? trim($shoda[1]) : $text;
	}
}
