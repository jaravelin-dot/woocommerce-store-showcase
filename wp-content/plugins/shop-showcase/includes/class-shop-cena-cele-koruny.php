<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Cena s DPH vždy na celé koruny.
 *
 * Obchod ukládá ceny BEZ DPH a cena s DPH má vycházet na celou korunu
 * (999 Kč, ne 999,01 Kč). Import počítá netto = round(brutto / 1,21, 4),
 * což je matematicky správně — jenže WooCommerce počítá DPH ve DVOU
 * krocích: nejdřív zaokrouhlí daň, pak sečte s netto a zaokrouhlí znovu.
 * Druhý krok umí „ukousnout" haléř navíc: z netto 131,4050 vyjde 159,01.
 * A v košíku se chyba násobí počtem kusů (3 ks = 2 754,01 místo 2 754).
 *
 * Řešení přes filtr ceny, ne zápisem do databáze:
 * - nic se nepřepisuje v produkci ani v pokladním systému, opravuje se
 *   jen při čtení posledních pár desetitisícin koruny;
 * - funguje hned na celém webu (karta, výpis, košík, pokladna, feedy —
 *   všechno nakonec volá get_price()) i u produktů, které rozbije příští
 *   import.
 *
 * Postup: netto = cílová celá koruna ÷ (1 + sazba) na 6 desetinných míst,
 * což je přesnost, se kterou počítá košík. Když to nevyjde (víc sazeb,
 * ceny zadávané s DPH), záložně se hledá nejbližší 4místné netto v okolí
 * ±0,05 Kč. Když se nenajde ani to, vrátí se PŮVODNÍ cena a zapíše se do
 * logu — radši viditelná odchylka než tichá záměna za nesprávnou cenu.
 */
class Shop_Cena_Cele_Koruny {
	/** @var array<string, float> cache v rámci požadavku, klíč „id:puvodni" */
	private static array $cache = [];

	private const KROK      = 0.0001;
	private const MAX_KROKU = 500;
	private const TOLERANCE = 0.005;

	public static function registruj_hooky(): void {
		add_filter('woocommerce_product_get_price', [self::class, 'oprav'], 20, 2);
		add_filter('woocommerce_product_variation_get_price', [self::class, 'oprav'], 20, 2);

		// Rozpětí cen variabilního produktu („1 190 – 1 290 Kč") WooCommerce
		// počítá z ceny v kontextu „edit", který filtr výše obchází. Bez těchhle
		// dvou háků by výpis ukázal 1 490,01 Kč, zatímco košík 1 490 Kč.
		add_filter('woocommerce_variation_prices_price', [self::class, 'oprav_rozpeti'], 20, 2);
		add_filter('woocommerce_variation_prices_regular_price', [self::class, 'oprav_rozpeti'], 20, 2);
		add_filter('woocommerce_get_variation_prices_hash', static function (array $hash): array {
			$hash[] = 'shop-cele-koruny-1';

			return $hash;
		});

		// „600 Kč" místo „600,00 Kč". Zbytečné nuly se uříznou jen tam, kde za
		// oddělovačem nic jiného není — cena bez DPH „76,03" zůstane celá
		// (bez haléřů by si zákazník 76 × 1,21 nespároval s 92).
		add_filter('woocommerce_price_trim_zeros', '__return_true');
	}

	/**
	 * @param string|float $cena
	 * @return string|float
	 */
	public static function oprav($cena, WC_Product $produkt) {
		if (!is_numeric($cena) || (float) $cena <= 0.0) {
			return $cena;
		}

		$puvodni = (float) $cena;
		$klic    = $produkt->get_id() . ':' . $puvodni;

		if (!array_key_exists($klic, self::$cache)) {
			self::$cache[$klic] = self::najdi_opravenou_cenu($puvodni, $produkt);
		}

		return self::$cache[$klic];
	}

	/**
	 * @param string|float $cena
	 * @return string|float
	 */
	public static function oprav_rozpeti($cena, $varianta) {
		return $varianta instanceof WC_Product ? self::oprav($cena, $varianta) : $cena;
	}

	private static function najdi_opravenou_cenu(float $puvodni, WC_Product $produkt): float {
		$netto = self::netto_na_6_mist($puvodni, $produkt);

		if ($netto !== null) {
			return $netto;
		}

		$hruba = (float) wc_get_price_including_tax($produkt, ['price' => $puvodni]);

		if (abs($hruba - round($hruba)) < self::TOLERANCE) {
			return $puvodni; // Už teď celá koruna — beze změny.
		}

		$cil = round($hruba);

		for ($krok = 1; $krok <= self::MAX_KROKU; $krok++) {
			foreach ([$puvodni - $krok * self::KROK, $puvodni + $krok * self::KROK] as $kandidat) {
				$kandidat = round($kandidat, 4);

				if ($kandidat > 0.0 && abs((float) wc_get_price_including_tax($produkt, ['price' => $kandidat]) - $cil) < self::TOLERANCE) {
					return $kandidat;
				}
			}
		}

		error_log(sprintf('Shop_Cena_Cele_Koruny: produkt %d (cena %.4f) nemá v okolí ±0,05 Kč netto pro celou korunu. Ponecháno.', $produkt->get_id(), $puvodni));

		return $puvodni;
	}

	/**
	 * Netto odvozené z celé koruny s DPH na 6 desetinných míst. Cena s DPH se
	 * počítá jako ve WooCommerce (daň zaokrouhlená zvlášť, pak součet), ale
	 * přímo ze sazeb — nezávisí na tom, jestli je zákazník osvobozený od DPH.
	 *
	 * Null = nepoužitelné (produkt bez DPH, ceny zadávané s DPH, víc sazeb,
	 * nebo by netto nedalo přesně stejnou celou korunu).
	 */
	private static function netto_na_6_mist(float $puvodni, WC_Product $produkt): ?float {
		if (!$produkt->is_taxable() || wc_prices_include_tax()) {
			return null;
		}

		$sazby = WC_Tax::get_rates($produkt->get_tax_class());

		if (count($sazby) !== 1) {
			return null;
		}

		$s_dph = static function (float $netto) use ($sazby): float {
			$dan = array_sum(array_map('wc_round_tax_total', WC_Tax::calc_tax($netto, $sazby, false)));

			return (float) round($netto + $dan, wc_get_price_decimals());
		};

		$cil = round($s_dph($puvodni));

		if ($cil <= 0.0 || abs($s_dph($puvodni) - $cil) > 0.05) {
			return null; // Ani vzdáleně celá koruna — nehádat, co tím kdo myslel.
		}

		$sazba = reset($sazby);
		$netto = round($cil / (1 + (float) $sazba['rate'] / 100), 6);

		return abs($s_dph($netto) - $cil) < self::TOLERANCE ? $netto : null;
	}
}
