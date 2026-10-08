<?php
/**
 * Plugin Name: Shop – chytrá chybová stránka
 * Description: Na 404 vytáhne z adresy slova a nabídne odpovídající produkty,
 *              hledání a kategorie. Blok shop/chyba-404 (šablona 404.html).
 *
 * Proč: po přechodu na nový e-shop končila zhruba pětina příchodů
 * z vyhledávačů na 404 — staré odkazy na modely, které na webu pořád jsou,
 * jen pod jinou adresou. Holé „Stránka nenalezena" posílalo zákazníky pryč.
 *
 * Postup:
 *   1. ze staré adresy se vezmou slova (bez koncovek starého e-shopu, čísel,
 *      velikostí a výplňových slov),
 *   2. slova, která v katalogu nejsou vůbec, se zahodí,
 *   3. kandidáti se seřadí podle toho, kolik slov z adresy sdílejí s názvem.
 *      Jedno společné slovo je náhoda, ne návrh — chce se aspoň dvě.
 *
 * Zdroj kandidátů je zaměnitelný filtrem `shop_404_kandidati`: v ostrém
 * nasazení je za ním fulltextový index (překlepy, kódy, EAN), tady jednoduchý
 * index názvů z databáze. Když zdroj selže, stránka ukáže hledání
 * a kategorie — nikdy chybu.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/** Slova, která z adresy nic neříkají o produktu. */
const SHOP_404_ZAHOD = [
	'a', 's', 'z', 'za', 'na', 'do', 'pro', 'se', 've', 'v', 'k', 'od', 'po', 'bez',
	'cena', 'ks', 'vel', 'velikost', 'velikosti', 'mix', 'barva', 'new', 'htm', 'html',
	'php', 'xml', 'api', 'page', 'produkt', 'eshop', 'stary',
	'xxs', 'xs', 'xl', 'xxl', 'xxxl', '3xl', '4xl', '5xl',
];

/** Nejvýš tolik slov se z adresy bere — delší adresy nesou barvy a kódy. */
const SHOP_404_MAX_SLOV = 8;

/**
 * Slova z cesty starého odkazu.
 *
 * `/panska-softshellova-bunda-vitr-modra-1230-d1159.htm`
 * → panska softshellova bunda vitr modra
 *
 * @return list<string>
 */
function shop_404_slova(string $cesta): array {
	$cesta = rawurldecode((string) wp_parse_url($cesta, PHP_URL_PATH));
	// Typické koncovky starých e-shopových systémů: -d123.htm (produkt), -c1_2_3.htm (kategorie).
	$cesta = (string) preg_replace('~-(?:d\d+|c\d+(?:_\d+)*|a\d+_\d+)\.html?$~i', '', $cesta);
	$cesta = mb_strtolower(remove_accents($cesta), 'UTF-8');

	$slova = [];

	foreach (preg_split('~[^a-z0-9]+~', $cesta, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $slovo) {
		if (strlen($slovo) < 2 || preg_match('~\d~', $slovo) || in_array($slovo, SHOP_404_ZAHOD, true)) {
			continue;
		}

		$slova[$slovo] = true;
	}

	return array_slice(array_keys($slova), 0, SHOP_404_MAX_SLOV);
}

/** Normalizovaná slova názvu (malá písmena, bez diakritiky). @return list<string> */
function shop_404_slova_nazvu(string $nazev): array {
	return preg_split('~[^a-z0-9]+~', mb_strtolower(remove_accents($nazev), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/**
 * Kolik slov z adresy se objeví v názvu produktu. Česká koncovka se
 * nepočítá: `panske` z adresy sedí na „pánská", `softshellova` na „softshell".
 *
 * @param list<string> $slova
 */
function shop_404_skore(array $slova, string $nazev): int {
	$v_nazvu = shop_404_slova_nazvu($nazev);
	$skore   = 0;

	foreach ($slova as $slovo) {
		foreach ($v_nazvu as $jine) {
			$kratsi   = min(strlen($slovo), strlen($jine));
			$spolecne = strspn($slovo ^ $jine, "\0");

			// Předpona až od tří znaků: „az" nesmí sednout na „azurová".
			if ($slovo === $jine || ($spolecne === $kratsi && $kratsi >= 3) || $spolecne >= max(4, $kratsi - 2)) {
				++$skore;
				break;
			}
		}
	}

	return $skore;
}

/**
 * Výchozí zdroj kandidátů: názvy publikovaných produktů z databáze,
 * v transientu na hodinu.
 *
 * @return array<int, string> ID => název
 */
function shop_404_index_nazvu(): array {
	$index = get_transient('shop_404_index');

	if (is_array($index)) {
		return $index;
	}

	global $wpdb;
	$radky = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'", ARRAY_A);
	$index = [];

	foreach ((array) $radky as $radek) {
		$index[(int) $radek['ID']] = (string) $radek['post_title'];
	}

	set_transient('shop_404_index', $index, HOUR_IN_SECONDS);

	return $index;
}

add_action('save_post_product', static fn() => delete_transient('shop_404_index'));

/**
 * Produkty pro mrtvou adresu, nejlepší první.
 *
 * @return list<int> ID produktů
 */
function shop_404_navrhy(string $cesta, int $pocet = 8): array {
	$slova = shop_404_slova($cesta);

	if ($slova === []) {
		return [];
	}

	/**
	 * Zdroj kandidátů (ID => název). Null = zdroj nedostupný.
	 *
	 * @param array<int, string>|null $kandidati
	 * @param list<string>            $slova
	 */
	$kandidati = apply_filters('shop_404_kandidati', null, $slova);

	if (!is_array($kandidati)) {
		$kandidati = shop_404_index_nazvu();
	}

	// Slova, která v katalogu nejsou vůbec (barvy starého e-shopu, názvy
	// akcí), se zahodí — jinak by žádný produkt nedosáhl potřebného skóre.
	$vsechna = [];

	foreach ($kandidati as $nazev) {
		foreach (shop_404_slova_nazvu($nazev) as $s) {
			$vsechna[$s] = true;
		}
	}

	$znama = array_values(array_filter($slova, static function (string $slovo) use ($vsechna): bool {
		foreach (array_keys($vsechna) as $s) {
			if (shop_404_skore([$slovo], (string) $s) > 0) {
				return true;
			}
		}

		return false;
	}));

	$skore = [];

	foreach ($kandidati as $id => $nazev) {
		$s = shop_404_skore($znama, $nazev);

		// Jedno společné slovo je náhoda („bunda" sedí na každou bundu), ne návrh.
		if ($s >= 2) {
			$skore[(int) $id] = $s;
		}
	}

	arsort($skore);

	return array_slice(array_keys($skore), 0, $pocet);
}

/** Obsah chybové stránky. Volá se jen z šablony 404.html. */
function shop_404_vykresli(): string {
	$cesta  = (string) ($_SERVER['REQUEST_URI'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- jen se z ní berou slova
	$karty  = [];

	foreach (is_404() ? shop_404_navrhy($cesta) : [] as $id) {
		$produkt = wc_get_product($id);

		if ($produkt instanceof WC_Product && $produkt->get_status() === 'publish' && $produkt->get_catalog_visibility() !== 'hidden') {
			$karty[] = $produkt;
		}
	}

	$kategorie = get_terms(['taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'exclude' => [(int) get_option('default_product_cat')]]);
	$kategorie = is_array($kategorie) ? $kategorie : [];

	ob_start();
	?>
	<section class="shop-chyba">
		<h1 class="shop-chyba__nadpis"><?php esc_html_e('Tahle stránka už neexistuje', 'shop'); ?></h1>
		<?php if ($karty !== []) : ?>
			<p class="shop-chyba__text"><?php esc_html_e('Odkaz je nejspíš ze starého e-shopu. Podle něj jsme našli tohle zboží:', 'shop'); ?></p>
			<ul class="shop-chyba__mrizka">
				<?php foreach ($karty as $produkt) : ?>
					<li>
						<a class="shop-chyba__karta" href="<?php echo esc_url($produkt->get_permalink()); ?>">
							<span class="shop-chyba__foto"><?php echo wp_kses_post($produkt->get_image('woocommerce_thumbnail', ['alt' => '', 'loading' => 'lazy'])); ?></span>
							<span class="shop-chyba__nazev"><?php echo esc_html($produkt->get_name()); ?></span>
							<span class="shop-chyba__cena"><?php echo wp_kses_post($produkt->get_price_html()); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="shop-chyba__text"><?php esc_html_e('Odkaz je zastaralý nebo neplatný. Zkuste hledání, nebo vyberte kategorii.', 'shop'); ?></p>
		<?php endif; ?>

		<form class="shop-chyba__hledani" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
			<label for="shop-chyba-pole"><?php esc_html_e('Hledat v obchodě', 'shop'); ?></label>
			<div class="shop-chyba__radek">
				<input id="shop-chyba-pole" type="search" name="s" placeholder="<?php esc_attr_e('Název, model nebo kód', 'shop'); ?>">
				<input type="hidden" name="post_type" value="product">
				<button type="submit"><?php esc_html_e('Hledat', 'shop'); ?></button>
			</div>
		</form>

		<?php if ($kategorie !== []) : ?>
			<nav class="shop-chyba__kategorie" aria-label="<?php esc_attr_e('Kategorie', 'shop'); ?>">
				<?php foreach ($kategorie as $term) : ?>
					<a href="<?php echo esc_url((string) get_term_link($term)); ?>"><?php echo esc_html($term->name); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	</section>
	<?php
	return (string) ob_get_clean();
}

const SHOP_404_CSS = <<<'CSS'
.shop-chyba{max-width:1200px;margin:0 auto;padding:40px 20px 64px}
.shop-chyba__nadpis{margin:0 0 12px}
.shop-chyba__text{max-width:65ch;margin:0 0 28px;color:var(--wp--preset--color--sediva,#4b5563)}
.shop-chyba__mrizka{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px;list-style:none;margin:0 0 40px;padding:0}
.shop-chyba__karta{display:flex;flex-direction:column;gap:6px;height:100%;padding:14px;border:1px solid var(--wp--preset--color--linka,#dcdfe3);border-radius:6px;text-decoration:none;color:inherit}
.shop-chyba__karta:hover{border-color:currentColor}
.shop-chyba__foto img{width:100%;height:auto;aspect-ratio:1;object-fit:contain}
.shop-chyba__cena{font-weight:700;margin-top:auto}
.shop-chyba__hledani{max-width:640px;margin:0 0 28px}
.shop-chyba__hledani label{display:block;font-weight:600;margin-bottom:8px}
.shop-chyba__radek{display:flex}
.shop-chyba__radek input[type=search]{flex:1;min-width:0;font:inherit;padding:12px 14px;border:2px solid currentColor;border-right:0}
.shop-chyba__radek button{font:inherit;font-weight:700;padding:0 24px;border:0;background:var(--wp--preset--color--akcent,#c2410c);color:#fff;cursor:pointer}
.shop-chyba__kategorie{display:flex;flex-wrap:wrap;gap:10px}
.shop-chyba__kategorie a{padding:8px 14px;border:1px solid var(--wp--preset--color--linka,#dcdfe3);border-radius:4px;text-decoration:none;color:inherit}
@media (max-width:640px){.shop-chyba__mrizka{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}}
CSS;

add_action('init', static function (): void {
	register_block_type('shop/chyba-404', [
		'render_callback' => static function (): string {
			// Bloková šablona se vykresluje dřív než wp_head, styl se tedy
			// ještě dostane do hlavičky.
			wp_register_style('shop-chyba-404', false, [], '1');
			wp_add_inline_style('shop-chyba-404', SHOP_404_CSS);
			wp_enqueue_style('shop-chyba-404');

			return function_exists('wc_get_product') ? shop_404_vykresli() : '';
		},
	]);
});
