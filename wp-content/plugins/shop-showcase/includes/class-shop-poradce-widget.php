<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Widget poradce: blok `shop/poradce` (úvodka) a panel ve výsledcích
 * hledání, když běžné hledání nic nenašlo.
 *
 * Bez JavaScriptu je widget obyčejný vyhledávací formulář — funguje vždy.
 */
class Shop_Poradce_Widget {
	private const HANDLE = 'shop-poradce';

	private static bool $panel_vykreslen = false;

	public static function registruj_hooky(): void {
		add_action('init', static function (): void {
			register_block_type('shop/poradce', ['render_callback' => static fn(): string => Shop_Poradce::vidi_poradce() ? self::html() : '']);
		});
		add_filter('render_block_core/query-title', [self::class, 'za_nadpis_hledani'], 10, 1);
	}

	/** Panel pod nadpisem výsledků produktového hledání, které nic nenašlo. */
	public static function za_nadpis_hledani(string $obsah): string {
		if (self::$panel_vykreslen || !is_search() || !Shop_Poradce::vidi_poradce()) {
			return $obsah;
		}

		$dotaz = trim((string) get_search_query(false));

		if ($dotaz === '' || (int) ($GLOBALS['wp_query']->found_posts ?? 0) > 0) {
			return $obsah;
		}

		self::$panel_vykreslen = true;

		return $obsah . self::html('hledani', mb_substr($dotaz, 0, Shop_Poradce::MAX_ZNAKU, 'UTF-8'));
	}

	private static function zarad_skripty(): void {
		$url   = plugins_url('assets/', SHOP_SHOWCASE_SOUBOR);
		$cesta = SHOP_SHOWCASE_DIR . '/assets/';

		wp_enqueue_style(self::HANDLE, $url . 'css/poradce.css', [], (string) filemtime($cesta . 'css/poradce.css'));
		wp_enqueue_script(self::HANDLE, $url . 'js/poradce.js', [], (string) filemtime($cesta . 'js/poradce.js'), ['in_footer' => true, 'strategy' => 'defer']);
		wp_add_inline_script(self::HANDLE, 'var shopPoradce = ' . wp_json_encode([
			'url'      => rest_url(Shop_Poradce::REST_NAMESPACE . Shop_Poradce::REST_ROUTA),
			// Nonce jen přihlášeným: nepřihlášený ho nepotřebuje, a zestárlý
			// nonce (stránka otevřená přes noc) by vrátil 403.
			'nonce'    => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
			// WordPress u prázdné hodnoty zahodí "=", proto "&s=" ručně.
			'hledani'  => add_query_arg(['post_type' => 'product'], home_url('/')) . '&s=',
			'maxZnaku' => Shop_Poradce::MAX_ZNAKU,
		]) . ';', 'before');
	}

	/** Příklady dotazů na klepnutí — ukazují, že stačí popsat práci. */
	private const PRIKLADY = [
		'boty do mokra na stavbu, velikost 43',
		'zimní bundu na sklad',
		'rukavice na montáž, velikost 9',
	];

	public static function html(string $rezim = 'uvodka', string $dotaz = ''): string {
		self::zarad_skripty();
		$uvodka = $rezim === 'uvodka';

		ob_start();
		?>
<section class="shop-poradce" data-shop-poradce data-shop-poradce-rezim="<?php echo esc_attr($rezim); ?>" aria-labelledby="shop-poradce-nadpis">
	<form class="shop-poradce__form" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
		<label class="shop-poradce__nadpis" id="shop-poradce-nadpis" for="shop-poradce-pole"><?php echo esc_html($uvodka ? 'Co sháníte?' : 'Nevíte, jak se to jmenuje? Zeptejte se Poradce.'); ?></label>
		<div class="shop-poradce__radek">
			<input type="hidden" name="post_type" value="product">
			<input class="shop-poradce__pole" id="shop-poradce-pole" type="text" name="s" maxlength="<?php echo (int) Shop_Poradce::MAX_ZNAKU; ?>" autocomplete="off" enterkeyhint="send"
				value="<?php echo esc_attr($dotaz); ?>" placeholder="<?php echo esc_attr(self::PRIKLADY[0]); ?>" aria-describedby="shop-poradce-napoveda">
			<button class="shop-poradce__poslat" type="submit"><?php esc_html_e('Zeptat se', 'shop'); ?></button>
		</div>
	</form>
	<p class="shop-poradce__napoveda" id="shop-poradce-napoveda"><?php esc_html_e('Popište, na jakou práci zboží potřebujete. Poradce je umělá inteligence: vybírá jen z toho, co máme skladem nebo u výrobce, a řekne, co máme ve vaší velikosti.', 'shop'); ?></p>
	<?php if ($uvodka) : ?>
		<div class="shop-poradce__priklady" data-shop-poradce-priklady>
			<?php foreach (self::PRIKLADY as $priklad) : ?>
				<button type="button" class="shop-poradce__priklad" data-shop-poradce-text="<?php echo esc_attr($priklad); ?>"><?php echo esc_html($priklad); ?></button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<div class="shop-poradce__rozhovor" data-shop-poradce-rozhovor aria-live="polite" aria-busy="false"></div>
	<div class="shop-poradce__pata" data-shop-poradce-pata hidden>
		<button type="button" class="shop-poradce__znovu" data-shop-poradce-znovu><?php esc_html_e('Začít znovu', 'shop'); ?></button>
		<p class="shop-poradce__ai">
			<?php
			echo esc_html(Shop_Poradce::motor() === 'simulace'
				? 'Ukázkový režim: odpovědi skládá simulace bez jazykového modelu, produkty jsou ze skutečného katalogu ukázky.'
				: 'Poradce je umělá inteligence. Parametry si prosím ověřte na kartě produktu.');
			?>
		</p>
	</div>
</section>
		<?php
		return (string) ob_get_clean();
	}
}
