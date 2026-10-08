<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Měření nákupu na děkovací stránce: konverze reklamního účtu (kategorie
 * „reklama") a událost `purchase` pro analytiku (kategorie „analytika").
 *
 * Nic nenačítá samo. Vytiskne malý skript, který počká na událost
 * `shop:gtag-pripraven` z cookie lišty (gtag se načítá až po souhlasu)
 * a teprve pak zavolá `gtag('event', …)`. Bez souhlasu s danou kategorií
 * se pro ni nevytiskne nic.
 *
 * Co se odesílá: číslo objednávky, hodnota bez DPH a bez dopravy, měna
 * a (jen analytika) položky s kódem, názvem, cenou a množstvím. Žádné
 * jméno, e-mail, telefon ani adresa — rozšířené konverze jsou záměrně
 * vypnuté.
 */
class Shop_Konverze {
	public const OPCE_SEND_TO = 'shop_reklama_konverze';

	/** Zrušená nebo vrácená objednávka konverzi neposílá. */
	private const STAVY_BEZ_KONVERZE = ['failed', 'cancelled', 'refunded'];

	public static function registruj_hooky(): void {
		// Priorita 30: skript lišty se tiskne na výchozích 20.
		add_action('wp_footer', [self::class, 'vykresli'], 30);
	}

	/** Hodnota objednávky bez DPH a bez dopravy (zboží po slevách). */
	public static function hodnota(WC_Order $objednavka): float {
		$hodnota = (float) $objednavka->get_total() - (float) $objednavka->get_total_tax() - (float) $objednavka->get_shipping_total();

		return round(max(0.0, $hodnota), 2);
	}

	/** @return array<string, mixed> */
	public static function data_reklama(WC_Order $objednavka, string $send_to): array {
		return [
			'send_to'        => $send_to,
			'value'          => self::hodnota($objednavka),
			'currency'       => $objednavka->get_currency(),
			'transaction_id' => (string) $objednavka->get_order_number(),
		];
	}

	/** @return array<string, mixed> */
	public static function data_analytika(WC_Order $objednavka, string $ga_id): array {
		$polozky = [];

		foreach ($objednavka->get_items() as $polozka) {
			if (!$polozka instanceof WC_Order_Item_Product) {
				continue;
			}

			$produkt = $polozka->get_product();
			$pocet   = max(1, (int) $polozka->get_quantity());
			$sku     = $produkt ? (string) $produkt->get_sku() : '';

			$polozky[] = [
				'item_id'   => $sku !== '' ? $sku : (string) $polozka->get_product_id(),
				'item_name' => $polozka->get_name(),
				'price'     => round((float) $polozka->get_total() / $pocet, 2),
				'quantity'  => $pocet,
			];
		}

		return [
			'send_to'        => $ga_id,
			'transaction_id' => (string) $objednavka->get_order_number(),
			'value'          => self::hodnota($objednavka),
			'currency'       => $objednavka->get_currency(),
			'tax'            => round((float) $objednavka->get_total_tax(), 2),
			'shipping'       => round((float) $objednavka->get_shipping_total(), 2),
			'items'          => $polozky,
		];
	}

	/**
	 * Skript pro děkovací stránku. Prázdný, když není co poslat.
	 *
	 * @param array<string, mixed>|null $reklama
	 * @param array<string, mixed>|null $analytika
	 */
	public static function html(?array $reklama, ?array $analytika): string {
		if ($reklama === null && $analytika === null) {
			return '';
		}

		// Značky < a & v názvech produktů nesmí rozbít <script>.
		$priznaky = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
		$cislo    = (string) ($reklama['transaction_id'] ?? $analytika['transaction_id'] ?? '');

		return '<script>(function () {'
			. 'var reklama = ' . wp_json_encode($reklama, $priznaky) . ', analytika = ' . wp_json_encode($analytika, $priznaky)
			. ', klic = ' . wp_json_encode('shop_konverze_' . $cislo, $priznaky) . ';'
			// Obnovení stránky nesmí poslat nákup znovu.
			. 'try { if (sessionStorage.getItem(klic)) { return; } } catch (e) {}'
			. 'function posli() {'
			. 'try { sessionStorage.setItem(klic, "1"); } catch (e) {}'
			. 'if (reklama) { window.gtag("event", "conversion", reklama); }'
			. 'if (analytika) { window.gtag("event", "purchase", analytika); }'
			. '}'
			. 'if (window.shopGtagPripraven) { posli(); } else { document.addEventListener("shop:gtag-pripraven", posli, { once: true }); }'
			. '})();</script>' . "\n";
	}

	/** Objednávka z děkovací stránky — jen když sedí klíč objednávky z adresy. */
	private static function objednavka_dekovaci_stranky(): ?WC_Order {
		if (!function_exists('is_order_received_page') || !is_order_received_page()) {
			return null;
		}

		$objednavka = wc_get_order(absint(get_query_var('order-received')));
		$klic       = isset($_GET['key']) ? wc_clean(wp_unslash((string) $_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if (!$objednavka instanceof WC_Order || $klic === '' || !hash_equals($objednavka->get_order_key(), $klic)) {
			return null;
		}

		return $objednavka;
	}

	public static function vykresli(): void {
		try {
			$objednavka = self::objednavka_dekovaci_stranky();

			if ($objednavka === null || in_array($objednavka->get_status(), self::STAVY_BEZ_KONVERZE, true)) {
				return;
			}

			$send_to = trim((string) get_option(self::OPCE_SEND_TO, ''));
			$ga_id   = trim((string) get_option(Shop_Cookie_Souhlas::OPCE_GA_ID, ''));

			$reklama   = ($send_to !== '' && Shop_Cookie_Souhlas::ma_souhlas_reklama()) ? self::data_reklama($objednavka, $send_to) : null;
			$analytika = ($ga_id !== '' && Shop_Cookie_Souhlas::ma_souhlas_analytika()) ? self::data_analytika($objednavka, $ga_id) : null;

			echo self::html($reklama, $analytika); // phpcs:ignore WordPress.Security.EscapeOutput -- jen JSON z wp_json_encode s JSON_HEX_TAG
		} catch (\Throwable $chyba) {
			// Měření nesmí shodit děkovací stránku.
			error_log('[shop] konverze na děkovací stránce: ' . $chyba->getMessage());
		}
	}
}
