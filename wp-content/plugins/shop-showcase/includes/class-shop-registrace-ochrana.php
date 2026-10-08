<?php
/**
 * Ochrana registrace proti robotům — bez captchy a bez služby třetí strany.
 *
 * Registrace se ptá jen na e-mail, takže ji robot vyplní snadno. Captcha by
 * znamenala cizí skript, cookies a souhlas, a obtěžovala by lidi. Místo ní
 * tři levné pojistky:
 *
 * - Skryté pole `shop_web`: člověk ho nevidí, robot vyplní každé pole.
 * - Podepsaný čas načtení formuláře: odeslání dřív než za MIN_SEKUND
 *   (robot), bez razítka nebo s razítkem starším než MAX_SEKUND se odmítne.
 *   Podpis (HMAC se solí webu) brání razítko si vyrobit.
 * - Strop MAX_ZA_HODINU úspěšných registrací z jedné IP adresy.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

class Shop_Registrace_Ochrana {

	public const MIN_SEKUND    = 2;
	public const MAX_SEKUND    = 86400;
	public const MAX_ZA_HODINU = 5;

	private const CHYBA = 'Registraci se nepodařilo odeslat. Načtěte prosím stránku a zkuste to znovu.';

	public static function registruj_hooky(): void {
		add_action('woocommerce_register_form', [self::class, 'pole']);
		add_filter('woocommerce_process_registration_errors', [self::class, 'over'], 10, 4);
		add_action('woocommerce_created_customer', [self::class, 'zapocitej']);
	}

	public static function razitko(int $cas): string {
		return $cas . '.' . substr(wp_hash('shop_registrace|' . $cas, 'nonce'), 0, 20);
	}

	public static function pole(): void {
		?>
		<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">
			<label for="shop_web">Nevyplňujte</label>
			<input type="text" name="shop_web" id="shop_web" value="" tabindex="-1" autocomplete="off">
		</div>
		<input type="hidden" name="shop_cas" value="<?php echo esc_attr(self::razitko(time())); ?>">
		<?php
	}

	/**
	 * @param WP_Error $chyby
	 * @return WP_Error
	 */
	public static function over($chyby, $uzivatel = '', $heslo = '', $email = '') {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce ověřuje WooCommerce před tímhle filtrem.
		$web     = isset($_POST['shop_web']) ? (string) wp_unslash($_POST['shop_web']) : '';
		$razitko = isset($_POST['shop_cas']) ? sanitize_text_field(wp_unslash($_POST['shop_cas'])) : '';
		// phpcs:enable

		// Jedna společná hláška: robot se nedozví, která pojistka ho chytila.
		if ($web !== '' || !self::razitko_plati($razitko, time()) || self::ip_vycerpana()) {
			$chyby->add('shop_registrace', self::CHYBA);
		}

		return $chyby;
	}

	public static function razitko_plati(string $razitko, int $ted): bool {
		if (preg_match('/^(\d{9,11})\.[0-9a-f]{20}$/', $razitko, $m) !== 1) {
			return false;
		}

		$cas = (int) $m[1];

		if (!hash_equals(self::razitko($cas), $razitko)) {
			return false;
		}

		$stari = $ted - $cas;

		return $stari >= self::MIN_SEKUND && $stari <= self::MAX_SEKUND;
	}

	public static function zapocitej(): void {
		$klic = self::klic_ip();
		set_transient($klic, (int) get_transient($klic) + 1, HOUR_IN_SECONDS);
	}

	private static function ip_vycerpana(): bool {
		return (int) get_transient(self::klic_ip()) >= self::MAX_ZA_HODINU;
	}

	/** IP se neukládá, jen její otisk v klíči. */
	private static function klic_ip(): string {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return 'shop_registrace_ip_' . md5($ip);
	}
}
