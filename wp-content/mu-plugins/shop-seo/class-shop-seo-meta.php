<?php
/**
 * Meta popisky, titulky a Open Graph.
 *
 * Popis se bere z toho, co na stránce SKUTEČNĚ je, nikdy se nevymýšlí:
 * u produktu z krátkého popisu, a když není, ze zkráceného dlouhého popisu.
 * Když není ani ten, popis se nevydá vůbec — prázdný <meta> je horší než
 * žádný, protože vyhledávači bere možnost složit si výtah ze stránky sám.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class Shop_Seo_Meta {

	/** Kolik znaků popisu. Vyhledávače zobrazují kolem 155–160. */
	private const DELKA_POPISU = 155;

	/** Kolik znaků titulku, než ho vyhledávač usekne. */
	private const DELKA_TITULKU = 60;

	public static function registruj_hooky(): void {
		add_action('wp_head', [self::class, 'vypis'], 5);
		add_filter('document_title_parts', [self::class, 'titulek_stranky']);
	}

	/**
	 * Titulek: číslo strany u stránkovaných výpisů a bez názvu webu tam,
	 * kde by se titulek nevešel.
	 *
	 * @param array<string, string> $casti
	 * @return array<string, string>
	 */
	public static function titulek_stranky(array $casti): array {
		$strana = (int) get_query_var('paged');

		if ($strana > 1 && isset($casti['title'])) {
			/* translators: %d: číslo stránky výpisu */
			$casti['title'] .= sprintf(__(' – strana %d', 'shop'), $strana);

			// Jádro si přidává vlastní „2. stránka"; bez tohohle by v titulku
			// stálo obojí naráz.
			unset($casti['page']);
		}

		// Když se do limitu nevejde text sám, název webu na konci nepřidá nic —
		// jen se usekne a zabere znaky, které mohly nést název zboží.
		if (isset($casti['title'], $casti['site'])) {
			$delka = mb_strlen($casti['title'], 'UTF-8') + mb_strlen((string) $casti['site'], 'UTF-8') + 3;

			if ($delka > self::DELKA_TITULKU) {
				unset($casti['site']);
			}
		}

		return $casti;
	}

	public static function vypis(): void {
		if (is_404()) {
			return;
		}

		$popis   = self::popis();
		$titul   = self::titul();
		$adresa  = self::adresa();
		$obrazek = self::obrazek();
		$firma   = shop_seo_firma();

		if ($popis !== '') {
			echo '<meta name="description" content="' . esc_attr($popis) . '" />' . "\n";
		}

		echo '<meta property="og:locale" content="cs_CZ" />' . "\n";
		echo '<meta property="og:type" content="' . esc_attr(self::je_produkt() ? 'product' : 'website') . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr((string) $firma['nazev']) . '" />' . "\n";

		if ($titul !== '') {
			echo '<meta property="og:title" content="' . esc_attr($titul) . '" />' . "\n";
		}

		if ($popis !== '') {
			echo '<meta property="og:description" content="' . esc_attr($popis) . '" />' . "\n";
		}

		if ($adresa !== '') {
			echo '<meta property="og:url" content="' . esc_url($adresa) . '" />' . "\n";
		}

		if ($obrazek !== '') {
			echo '<meta property="og:image" content="' . esc_url($obrazek) . '" />' . "\n";
			echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		} else {
			echo '<meta name="twitter:card" content="summary" />' . "\n";
		}

		self::vypis_cenu_do_og();
	}

	/**
	 * Cena do Open Graphu u produktu — sociální sítě ji ukážou v náhledu.
	 *
	 * Vždy cena S DPH, tedy částka, kterou zákazník zaplatí. Zdrojem je
	 * wc_get_price_including_tax() nad prodejní cenou; nákupní cena se do
	 * výstupu nesmí dostat za žádných okolností.
	 */
	private static function vypis_cenu_do_og(): void {
		if (!self::je_produkt()) {
			return;
		}

		$produkt = wc_get_product(get_queried_object_id());

		if (!$produkt instanceof WC_Product) {
			return;
		}

		$cena = wc_get_price_including_tax($produkt);

		if (!is_numeric($cena) || (float) $cena <= 0) {
			return;
		}

		echo '<meta property="product:price:amount" content="' . esc_attr((string) round((float) $cena, 2)) . '" />' . "\n";
		echo '<meta property="product:price:currency" content="' . esc_attr(get_woocommerce_currency()) . '" />' . "\n";
		echo '<meta property="product:availability" content="' . esc_attr($produkt->is_in_stock() ? 'in stock' : 'out of stock') . '" />' . "\n";
	}

	/**
	 * Přes function_exists: mu-pluginy se načítají dřív než pluginy, a kdyby
	 * se WooCommerce vypnula, tenhle soubor nesmí shodit celý web.
	 */
	private static function je_produkt(): bool {
		return function_exists('is_product') && is_product();
	}

	/** Popis stránky. Prázdný řetězec = nevydávat žádný. */
	public static function popis(): string {
		$firma = shop_seo_firma();

		if (is_front_page()) {
			return 'Pracovní oděvy, obuv a ochranné pomůcky skladem. '
				. 'Kamenná prodejna se zbožím k vyzkoušení, '
				. 'doprava zdarma od ' . shop_seo_cislo((float) $firma['doprava_zdarma_od']) . ' Kč.';
		}

		if (is_singular()) {
			$prispevek = get_queried_object();

			if (!$prispevek instanceof WP_Post) {
				return '';
			}

			$zdroj = $prispevek->post_excerpt !== '' ? $prispevek->post_excerpt : $prispevek->post_content;

			// Barevné varianty mívají jednu kartu na barvu a sdílejí popis
			// výrobce. Název se karta od karty liší (obsahuje barvu) a je to
			// skutečný text stránky (H1), takže se smí předřadit — jinak by
			// několik stránek mělo bajtově stejný popisek.
			if (self::je_produkt()) {
				$nazev = get_the_title($prispevek);

				if ($nazev !== '') {
					$zdroj = $nazev . '. ' . $zdroj;
				}
			}

			return self::zkrat($zdroj);
		}

		if (function_exists('is_shop') && is_shop()) {
			$stranka = get_post((int) wc_get_page_id('shop'));

			return ($stranka instanceof WP_Post && trim($stranka->post_excerpt) !== '') ? self::zkrat($stranka->post_excerpt) : '';
		}

		if (is_tax() || is_category() || is_tag()) {
			$termin = get_queried_object();

			return ($termin instanceof WP_Term && trim($termin->description) !== '') ? self::zkrat($termin->description) : '';
		}

		return '';
	}

	private static function titul(): string {
		if (is_front_page()) {
			return wp_get_document_title();
		}

		if (is_singular()) {
			return (string) get_the_title(get_queried_object_id());
		}

		if (is_tax() || is_category() || is_tag()) {
			$termin = get_queried_object();

			return $termin instanceof WP_Term ? $termin->name : '';
		}

		return '';
	}

	private static function adresa(): string {
		if (is_singular()) {
			$odkaz = get_permalink(get_queried_object_id());

			return is_string($odkaz) ? $odkaz : '';
		}

		if (is_front_page()) {
			return home_url('/');
		}

		if (is_tax() || is_category() || is_tag()) {
			$termin = get_queried_object();
			$odkaz  = $termin instanceof WP_Term ? get_term_link($termin) : '';

			return is_string($odkaz) ? $odkaz : '';
		}

		return '';
	}

	private static function obrazek(): string {
		$id = 0;

		if (is_singular()) {
			$id = (int) get_post_thumbnail_id(get_queried_object_id());
		} elseif (is_tax() || is_category() || is_tag()) {
			$termin = get_queried_object();
			$id     = $termin instanceof WP_Term ? (int) get_term_meta($termin->term_id, 'thumbnail_id', true) : 0;
		}

		if ($id > 0) {
			$adresa = wp_get_attachment_image_url($id, 'large');

			if (is_string($adresa) && $adresa !== '') {
				return $adresa;
			}
		}

		// Záloha: ikona webu. Je to rastr, takže ji sociální sítě přijmou.
		return (string) get_site_icon_url(512);
	}

	/**
	 * Ořízne text na délku popisu, vždy na hranici slova, a odstraní HTML.
	 *
	 * Popis produktu bývá TABULKA PARAMETRŮ, ne souvislý text:
	 * <th>Svršek</th><td>kůže</td>. Kdyby se značky jen odstranily, slepí se
	 * to na „Svršekkůže". Buňky se proto nejdřív převedou na interpunkci:
	 * „Svršek: kůže. Podešev: PU/TPU."
	 */
	public static function zkrat(string $text, int $delka = self::DELKA_POPISU): string {
		$text = strip_shortcodes($text);
		$text = (string) preg_replace('#</th>\s*#i', ': ', $text);
		$text = (string) preg_replace('#</tr>\s*#i', '. ', $text);
		$text = (string) preg_replace('#</(td|p|li|div|h[1-6])>\s*#i', ' ', $text);
		$text = (string) preg_replace('#<br\s*/?>#i', ' ', $text);
		$text = (string) preg_replace('#<[^>]*>#', ' ', $text);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Uklidit interpunkci, kterou převod tabulky zdvojil.
		$text = (string) preg_replace('/\s+([.,:;])/u', '$1', $text);
		$text = (string) preg_replace('/([.:])\s*\1+/u', '$1', $text);
		$text = trim((string) preg_replace('/\s+/u', ' ', $text));

		if ($text === '' || mb_strlen($text, 'UTF-8') <= $delka) {
			return $text;
		}

		$oriznuty = mb_substr($text, 0, $delka, 'UTF-8');
		$mezera   = mb_strrpos($oriznuty, ' ', 0, 'UTF-8');

		if ($mezera !== false && $mezera > (int) ($delka * 0.6)) {
			$oriznuty = mb_substr($oriznuty, 0, $mezera, 'UTF-8');
		}

		// Čeština netrhá osamocenou jednopísmennou předložku na konci řádku
		// („…citlivost; u…"), ořez ji proto strhne s sebou.
		$oriznuty = (string) preg_replace('/\s+[aiksuvzAIKSUVZ]$/u', '', $oriznuty);

		return rtrim($oriznuty, " \t\n\r\0\x0B,.;:-") . '…';
	}
}
