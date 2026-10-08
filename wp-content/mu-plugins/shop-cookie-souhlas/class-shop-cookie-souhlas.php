<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Cookie lišta a mechanismus, který podle souhlasu spouští (nebo
 * nespouští) měřicí skripty.
 *
 * Rozhodnutí návštěvníka se čte a zapisuje v JavaScriptu
 * (assets/cookie-souhlas.js). PHP jen vykreslí lištu a předá konfiguraci
 * vyplněných nástrojů. Nic externího se z PHP samo netiskne do <head>.
 *
 * Kategorie: nezbytné (vždy zapnuté), analytika, reklama. Analytika =
 * měření návštěvnosti, reklama = konverze reklamních systémů a retargeting.
 */
class Shop_Cookie_Souhlas {
	/** Cookie s volbou návštěvníka. Je nezbytná — bez ní by lišta naskakovala pořád. */
	public const COOKIE_NAZEV = 'shop_cookie_souhlas';

	public const OPCE_GA_ID       = 'shop_cookie_ga_id';
	public const OPCE_GADS_ID     = 'shop_cookie_gads_id';
	public const OPCE_REKLAMA_KOD = 'shop_cookie_reklama_kod';
	public const OPCE_GTAG_URL    = 'shop_cookie_gtag_url';

	private const SKUPINA = 'shop_cookie_souhlas';

	public static function registruj_hooky(): void {
		add_action('wp_enqueue_scripts', [self::class, 'zaregistruj_assety']);
		// Priorita 5: markup lišty musí být v DOM dřív, než se vytiskne skript.
		add_action('wp_footer', [self::class, 'vykresli_listu'], 5);
		add_action('admin_menu', [self::class, 'registruj_stranku']);
		add_action('admin_init', [self::class, 'registruj_pole']);

		// WooCommerce sám nastavuje cookies atribuce objednávky (sbjs_*), a to
		// bez souhlasu, dokud na webu není další plugin pro správu souhlasu.
		// WooCommerce ale nabízí vlastní filtr — napojí se na naši cookie bez
		// závislosti navíc. Kategorie: reklama (marketingová atribuce).
		add_filter('wc_order_attribution_allow_tracking', [self::class, 'ma_souhlas_reklama']);
	}

	/** Bez platné cookie (chybí, poškozená, kategorie není true) = false. Bezpečný výchozí stav. */
	public static function ma_souhlas(string $kategorie): bool {
		if (!isset($_COOKIE[self::COOKIE_NAZEV])) {
			return false;
		}

		$data = json_decode((string) wp_unslash($_COOKIE[self::COOKIE_NAZEV]), true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- jen JSON s booleany

		return is_array($data) && !empty($data[$kategorie]);
	}

	public static function ma_souhlas_reklama(): bool {
		return self::ma_souhlas('reklama');
	}

	public static function ma_souhlas_analytika(): bool {
		return self::ma_souhlas('analytika');
	}

	/**
	 * Nakonfigurované nástroje. Prázdná hodnota = nástroj se nenačte.
	 *
	 * @return array<string, array{kategorie: string, typ: string, hodnota: string}>
	 */
	private static function nastroje(): array {
		$nastroje = [
			'analytika' => ['kategorie' => 'analytika', 'typ' => 'gtag', 'hodnota' => trim((string) get_option(self::OPCE_GA_ID, ''))],
			'reklama'   => ['kategorie' => 'reklama', 'typ' => 'gtag', 'hodnota' => trim((string) get_option(self::OPCE_GADS_ID, ''))],
			'kod'       => ['kategorie' => 'reklama', 'typ' => 'snippet', 'hodnota' => (string) get_option(self::OPCE_REKLAMA_KOD, '')],
		];

		return (array) apply_filters('shop_cookie_souhlas_nastroje', $nastroje);
	}

	public static function zaregistruj_assety(): void {
		$verze = static fn(string $soubor): string => file_exists(SHOP_COOKIE_SOUHLAS_DIR . '/assets/' . $soubor)
			? (string) filemtime(SHOP_COOKIE_SOUHLAS_DIR . '/assets/' . $soubor)
			: '1';

		wp_enqueue_style('shop-cookie-souhlas', SHOP_COOKIE_SOUHLAS_URL . '/assets/cookie-souhlas.css', [], $verze('cookie-souhlas.css'));
		wp_enqueue_script('shop-cookie-souhlas', SHOP_COOKIE_SOUHLAS_URL . '/assets/cookie-souhlas.js', [], $verze('cookie-souhlas.js'), true);

		$konfigurace = [];

		foreach (self::nastroje() as $klic => $nastroj) {
			if ($nastroj['hodnota'] === '') {
				continue;
			}

			$konfigurace[$klic] = [
				'kategorie' => $nastroj['kategorie'],
				'typ'       => $nastroj['typ'],
				// Úryvek kódu z administrace je libovolné HTML/JS — base64 kvůli
				// bezpečnému vložení do <script> bez souboje s uvozovkami a </script>.
				'hodnota'   => $nastroj['typ'] === 'snippet' ? base64_encode($nastroj['hodnota']) : $nastroj['hodnota'],
			];
		}

		// Adresa načítacího skriptu gtag z návodu měřicího nástroje; bez ní
		// se gtag nenačte vůbec.
		$gtag_url = esc_url_raw(trim((string) get_option(self::OPCE_GTAG_URL, '')));

		if ($gtag_url !== '') {
			$konfigurace['gtag_url'] = $gtag_url;
		}

		$json = str_replace('</script', '<\/script', (string) wp_json_encode($konfigurace));
		wp_add_inline_script('shop-cookie-souhlas', 'window.shopCookieKonfigurace = ' . $json . ';', 'before');
	}

	/**
	 * Lišta je v HTML s atributem `hidden`: ukáže ji jen skript. Bez JS se
	 * lišta neukáže — ale bez JS se ani žádný měřicí skript nespustí.
	 */
	public static function vykresli_listu(): void {
		?>
		<div id="shop-cookie-lista" class="shop-cookie-lista" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e('Nastavení souhlasu s cookies', 'shop'); ?>" hidden>
			<p class="shop-cookie-lista__nadpis"><?php esc_html_e('Souhlas s cookies', 'shop'); ?></p>
			<p class="shop-cookie-lista__text"><?php esc_html_e('Nezbytné cookies používáme vždy, aby fungoval košík a pokladna. Analytiku a reklamu spustíme jen s vaším souhlasem.', 'shop'); ?></p>
			<div class="shop-cookie-lista__kategorie" id="shop-cookie-kategorie">
				<label><input type="checkbox" checked disabled> <span><strong><?php esc_html_e('Nezbytné', 'shop'); ?></strong> <?php esc_html_e('košík, pokladna, tato volba', 'shop'); ?></span></label>
				<label><input type="checkbox" id="shop-cookie-analytika"> <span><strong><?php esc_html_e('Analytika', 'shop'); ?></strong> <?php esc_html_e('měření návštěvnosti', 'shop'); ?></span></label>
				<label><input type="checkbox" id="shop-cookie-reklama"> <span><strong><?php esc_html_e('Reklama', 'shop'); ?></strong> <?php esc_html_e('měření účinnosti reklamy', 'shop'); ?></span></label>
			</div>
			<div class="shop-cookie-lista__tlacitka">
				<button type="button" id="shop-cookie-podrobnosti" aria-controls="shop-cookie-kategorie" aria-expanded="false"><?php esc_html_e('Nastavení', 'shop'); ?></button>
				<button type="button" id="shop-cookie-odmitnout"><?php esc_html_e('Odmítnout', 'shop'); ?></button>
				<button type="button" id="shop-cookie-ulozit"><?php esc_html_e('Uložit volbu', 'shop'); ?></button>
				<button type="button" id="shop-cookie-prijmout"><?php esc_html_e('Přijmout vše', 'shop'); ?></button>
			</div>
		</div>
		<a href="#" id="shop-cookie-znovu" class="shop-cookie-znovu"><?php esc_html_e('Nastavení cookies', 'shop'); ?></a>
		<?php
	}

	public static function registruj_stranku(): void {
		add_options_page(__('Cookie souhlas', 'shop'), __('Cookie souhlas', 'shop'), 'manage_options', 'shop-cookie-souhlas', [self::class, 'vykresli_nastaveni']);
	}

	public static function registruj_pole(): void {
		register_setting(self::SKUPINA, self::OPCE_GA_ID, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '']);
		register_setting(self::SKUPINA, self::OPCE_GADS_ID, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '']);
		register_setting(self::SKUPINA, self::OPCE_GTAG_URL, ['type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '']);
		register_setting(self::SKUPINA, Shop_Konverze::OPCE_SEND_TO, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '']);
		// Reklamní systémy dávají celý úryvek kódu, pole proto ukládá syrový
		// obsah. Přístup je omezený na manage_options — stejná úroveň důvěry
		// jako úprava šablony, kam se také zapisuje spustitelný kód.
		register_setting(self::SKUPINA, self::OPCE_REKLAMA_KOD, [
			'type'              => 'string',
			'sanitize_callback' => static fn($hodnota): string => trim((string) wp_unslash($hodnota)),
			'default'           => '',
		]);
	}

	public static function vykresli_nastaveni(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		$pole = [
			[self::OPCE_GTAG_URL, __('Adresa skriptu gtag.js', 'shop'), 'https://…/gtag/js', __('Načítací skript z návodu měřicího nástroje. Bez něj se analytika ani konverze nenačtou.', 'shop')],
			[self::OPCE_GA_ID, __('Měřicí ID analytiky', 'shop'), 'G-XXXXXXXXXX', __('Kategorie: Analytika.', 'shop')],
			[self::OPCE_GADS_ID, __('Měřicí ID reklamního účtu', 'shop'), 'AW-XXXXXXXXX', __('Kategorie: Reklama.', 'shop')],
			[Shop_Konverze::OPCE_SEND_TO, __('Konverzní akce (send_to)', 'shop'), 'AW-XXXXXXXXX/XXXXXXXXXXXX', __('Kategorie: Reklama. Měří se na děkovací stránce.', 'shop')],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Cookie souhlas — měřicí nástroje', 'shop'); ?></h1>
			<p><?php esc_html_e('Dokud je pole prázdné, nástroj se nenačte, i kdyby návštěvník souhlas udělil.', 'shop'); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields(self::SKUPINA); ?>
				<table class="form-table" role="presentation">
					<?php foreach ($pole as [$opce, $popisek, $vzor, $poznamka]) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr($opce); ?>"><?php echo esc_html($popisek); ?></label></th>
							<td>
								<input type="text" class="regular-text" name="<?php echo esc_attr($opce); ?>" id="<?php echo esc_attr($opce); ?>" placeholder="<?php echo esc_attr($vzor); ?>" value="<?php echo esc_attr((string) get_option($opce, '')); ?>">
								<p class="description"><?php echo esc_html($poznamka); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr(self::OPCE_REKLAMA_KOD); ?>"><?php esc_html_e('Další reklamní kód', 'shop'); ?></label></th>
						<td>
							<textarea class="large-text code" rows="5" name="<?php echo esc_attr(self::OPCE_REKLAMA_KOD); ?>" id="<?php echo esc_attr(self::OPCE_REKLAMA_KOD); ?>"><?php echo esc_textarea((string) get_option(self::OPCE_REKLAMA_KOD, '')); ?></textarea>
							<p class="description"><?php esc_html_e('Celý úryvek kódu retargetingu nebo konverzí jiného reklamního systému. Kategorie: Reklama.', 'shop'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
