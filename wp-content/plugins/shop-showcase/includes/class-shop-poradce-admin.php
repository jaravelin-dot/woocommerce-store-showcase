<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WooCommerce → Poradce: režim, motor (simulace / Claude), denní strop,
 * dnešní útrata a poslední dotazy z logu.
 */
class Shop_Poradce_Admin {
	private const SLUG    = 'shop-poradce';
	private const SKUPINA = 'shop_poradce';

	public static function registruj_hooky(): void {
		add_action('admin_menu', static function (): void {
			add_submenu_page('woocommerce', 'Poradce', 'Poradce', 'manage_woocommerce', self::SLUG, [self::class, 'stranka']);
		}, 60);
		add_action('admin_init', [self::class, 'registruj_pole']);
	}

	public static function registruj_pole(): void {
		register_setting(self::SKUPINA, Shop_Poradce::OPTION_REZIM, [
			'type'              => 'string',
			'sanitize_callback' => static fn($v): string => in_array($v, Shop_Poradce::REZIMY, true) ? (string) $v : 'vypnuto',
		]);
		register_setting(self::SKUPINA, Shop_Poradce::OPTION_MOTOR, [
			'type'              => 'string',
			'sanitize_callback' => static fn($v): string => in_array($v, Shop_Poradce::MOTORY, true) ? (string) $v : 'simulace',
		]);
		register_setting(self::SKUPINA, Shop_Poradce::OPTION_STROP, [
			'type'              => 'number',
			'sanitize_callback' => static fn($v): float => max(0.1, (float) $v),
		]);
		// Klíč v databázi je pohodlí pro vyzkoušení; na ostrém webu patří do
		// konstanty SHOP_ANTHROPIC_KLIC ve wp-config.php (má přednost).
		register_setting(self::SKUPINA, 'shop_poradce_api_klic', [
			'type'              => 'string',
			'sanitize_callback' => static function ($v): string {
				$v = trim((string) $v);

				// Prázdné pole ve formuláři = klíč neměnit (hodnota se zpět nevypisuje).
				return $v === '' ? (string) get_option('shop_poradce_api_klic', '') : sanitize_text_field($v);
			},
		]);
	}

	public static function stranka(): void {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		global $wpdb;
		Shop_Poradce::zajisti_tabulku();
		$posledni = $wpdb->get_results('SELECT cas, dotaz, odpoved, karty, vyhozena, model, cena_usd, trvani_ms, chyba FROM ' . Shop_Poradce::tabulka() . ' ORDER BY id DESC LIMIT 20', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$klic_v_konstante = defined('SHOP_ANTHROPIC_KLIC') && SHOP_ANTHROPIC_KLIC !== '';
		?>
		<div class="wrap">
			<h1>Poradce</h1>
			<p>Dnešní útrata: <strong><?php echo esc_html(number_format(Shop_Poradce::utrata_dnes(), 4, ',', ' ')); ?> USD</strong> z denního stropu <?php echo esc_html(number_format(Shop_Poradce::strop_usd(), 2, ',', ' ')); ?> USD. Po dosažení stropu widget posílá dotazy do běžného hledání.</p>
			<form method="post" action="options.php">
				<?php settings_fields(self::SKUPINA); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Režim</th>
						<td>
							<select name="<?php echo esc_attr(Shop_Poradce::OPTION_REZIM); ?>">
								<?php foreach (['vypnuto' => 'Vypnuto', 'spravci' => 'Jen správci (zkoušení)', 'vsichni' => 'Všichni návštěvníci'] as $hodnota => $popis) : ?>
									<option value="<?php echo esc_attr($hodnota); ?>" <?php selected(Shop_Poradce::rezim(), $hodnota); ?>><?php echo esc_html($popis); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Motor</th>
						<td>
							<select name="<?php echo esc_attr(Shop_Poradce::OPTION_MOTOR); ?>">
								<option value="simulace" <?php selected(Shop_Poradce::motor(), 'simulace'); ?>>Simulace (bez API, deterministická)</option>
								<option value="claude" <?php selected(Shop_Poradce::motor(), 'claude'); ?>>Claude (<?php echo esc_html(Shop_Poradce::model()); ?>)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="shop-poradce-klic">API klíč</label></th>
						<td>
							<?php if ($klic_v_konstante) : ?>
								<p>Klíč je nastavený v konstantě SHOP_ANTHROPIC_KLIC.</p>
							<?php else : ?>
								<input type="password" id="shop-poradce-klic" name="shop_poradce_api_klic" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr(Shop_Poradce_Claude::nastaveno() ? 'uložený (prázdné = neměnit)' : 'VLOZ_SVUJ_KLIC'); ?>">
								<p class="description">Jen pro režim Claude. Bez klíče poradce tiše přesměruje na běžné hledání.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="shop-poradce-strop">Denní strop (USD)</label></th>
						<td><input type="number" step="0.1" min="0.1" id="shop-poradce-strop" name="<?php echo esc_attr(Shop_Poradce::OPTION_STROP); ?>" value="<?php echo esc_attr((string) Shop_Poradce::strop_usd()); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Posledních 20 dotazů</h2>
			<table class="widefat striped">
				<thead><tr><th>Čas</th><th>Dotaz</th><th>Odpověď</th><th>Karty</th><th>Vyhozená ID</th><th>Model</th><th>USD</th><th>ms</th><th>Chyba</th></tr></thead>
				<tbody>
				<?php foreach ((array) $posledni as $r) : ?>
					<tr>
						<td><?php echo esc_html($r['cas']); ?></td>
						<td><?php echo esc_html($r['dotaz']); ?></td>
						<td><?php echo esc_html((string) $r['odpoved']); ?></td>
						<td><?php echo esc_html($r['karty']); ?></td>
						<td><?php echo esc_html($r['vyhozena']); ?></td>
						<td><?php echo esc_html($r['model']); ?></td>
						<td><?php echo esc_html((string) $r['cena_usd']); ?></td>
						<td><?php echo esc_html((string) $r['trvani_ms']); ?></td>
						<td><?php echo esc_html($r['chyba']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
