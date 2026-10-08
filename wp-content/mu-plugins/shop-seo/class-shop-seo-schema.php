<?php
/**
 * Strukturovaná data (JSON-LD).
 *
 * 1. Úvodka: Organization + Store + WebSite. Vyhledávač ani jazykový model
 *    jinak nemá odkud vzít, kdo obchod provozuje, kde je prodejna a kdy má
 *    otevřeno.
 *
 * 2. Produktové schéma z jádra WooCommerce je ploché a má tři vady: cenu
 *    BEZ DPH (zákazník vidí 999 Kč, schéma tvrdí 825,62), žádnou značku
 *    a žádný EAN. Obohacuje se přes filtr woocommerce_structured_data_product.
 *
 * U variabilního produktu se plochý Product mění na ProductGroup
 * s hasVariant, kde každá velikost nese vlastní EAN, cenu a dostupnost.
 * Je to tatáž datová vrstva, ze které se skládají feedy pro srovnávače.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class Shop_Seo_Schema {

	public static function registruj_hooky(): void {
		add_action('wp_head', [self::class, 'entita_firmy'], 8);
		add_filter('woocommerce_structured_data_product', [self::class, 'obohat_produkt'], 10, 2);
		add_filter('woocommerce_structured_data_type_for_page', [self::class, 'povol_typ_skupiny']);
	}

	/**
	 * Propustí typ ProductGroup ven.
	 *
	 * WooCommerce na konci prosívá data podle seznamu typů povolených pro
	 * stránku. „productgroup" v něm není — bez tohohle řádku se celý blok
	 * u variabilních produktů TIŠE zahodí.
	 *
	 * @param array<int, string> $typy
	 * @return array<int, string>
	 */
	public static function povol_typ_skupiny(array $typy): array {
		if (function_exists('is_product') && is_product()) {
			$typy[] = 'productgroup';
		}

		return $typy;
	}

	/**
	 * Entita firmy jen na úvodce: patří na jedno místo, ostatní stránky na
	 * ni odkazují přes @id. Opakovat ji na tisících produktů by jen nafouklo
	 * každou stránku.
	 */
	public static function entita_firmy(): void {
		if (!is_front_page()) {
			return;
		}

		$firma  = shop_seo_firma();
		$zaklad = untrailingslashit(home_url('/'));

		$organizace = array_filter([
			'@type'        => 'Organization',
			'@id'          => $zaklad . '/#organizace',
			'name'         => $firma['nazev'],
			'legalName'    => $firma['pravni_nazev'],
			'url'          => trailingslashit($zaklad),
			'description'  => $firma['popis'],
			'taxID'        => $firma['ico'],
			'vatID'        => $firma['dic'],
			'foundingDate' => $firma['zalozeno'],
			'email'        => $firma['email'],
			'telephone'    => $firma['telefon_eshop'],
			'address'      => self::postovni_adresa($firma['prodejna']),
			'sameAs'       => array_values((array) $firma['profily']),
		], static fn($hodnota): bool => $hodnota !== '' && $hodnota !== []);

		$logo = (string) get_site_icon_url(512);

		if ($logo !== '') {
			$organizace['logo'] = $logo;
		}

		$prodejna = array_filter([
			'@type'                     => 'Store',
			'@id'                       => $zaklad . '/#prodejna',
			'name'                      => $firma['nazev'] . ' — prodejna',
			'url'                       => trailingslashit($zaklad),
			'telephone'                 => $firma['telefon_prodejna'],
			'address'                   => self::postovni_adresa($firma['prodejna']),
			'parentOrganization'        => ['@id' => $zaklad . '/#organizace'],
			'openingHoursSpecification' => self::oteviraci_doba($firma['oteviraci_doba']),
			'currenciesAccepted'        => 'CZK',
		], static fn($hodnota): bool => $hodnota !== '');

		$web = [
			'@type'           => 'WebSite',
			'@id'             => $zaklad . '/#web',
			'url'             => trailingslashit($zaklad),
			'name'            => $firma['nazev'],
			'inLanguage'      => 'cs-CZ',
			'publisher'       => ['@id' => $zaklad . '/#organizace'],
			'potentialAction' => [
				'@type'       => 'SearchAction',
				'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => trailingslashit($zaklad) . '?s={search_term_string}&post_type=product'],
				'query-input' => 'required name=search_term_string',
			],
		];

		self::vypis_json(['@context' => 'https://schema.org', '@graph' => [$organizace, $prodejna, $web]]);
	}

	/**
	 * @param array<string, mixed> $data
	 * @param WC_Product           $produkt
	 * @return array<string, mixed>
	 */
	public static function obohat_produkt(array $data, $produkt): array {
		if (!$produkt instanceof WC_Product) {
			return $data;
		}

		// Bez fotky produkt nehlásíme vůbec: nákupní výsledky vyžadují image
		// a jediný takový produkt umí zablokovat ověření opravy pro celý web.
		// Jakmile fotku dostane, schéma se vrátí samo.
		if ((int) $produkt->get_image_id() <= 0) {
			return [];
		}

		$data['itemCondition'] = 'https://schema.org/NewCondition';

		$znacka = self::znacka($produkt);

		if ($znacka !== '') {
			$data['brand'] = ['@type' => 'Brand', 'name' => $znacka];
		}

		if ($produkt->get_sku() !== '') {
			$data['mpn'] = $produkt->get_sku();
		}

		if ($produkt->is_type('variable')) {
			return self::na_skupinu_variant($data, $produkt);
		}

		$ean = self::ean($produkt);

		if ($ean !== '') {
			$data['gtin13'] = $ean;
		}

		$nabidka = self::nabidka($produkt);

		if ($nabidka !== []) {
			$data['offers'] = [$nabidka];
		}

		return $data;
	}

	/**
	 * Plochý Product → ProductGroup s hasVariant.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function na_skupinu_variant(array $data, WC_Product $produkt): array {
		$varianty = [];
		$ceny     = [];

		foreach ($produkt->get_children() as $id_varianty) {
			$varianta = wc_get_product($id_varianty);

			if (!$varianta instanceof WC_Product_Variation || !$varianta->is_purchasable()) {
				continue;
			}

			$nabidka = self::nabidka($varianta);

			if ($nabidka === []) {
				continue;
			}

			$ceny[] = (float) $nabidka['price'];

			$kus = [
				'@type'         => 'Product',
				'@id'           => $varianta->get_permalink() . '#varianta-' . $varianta->get_id(),
				'name'          => wp_strip_all_tags($varianta->get_name()),
				'sku'           => $varianta->get_sku() !== '' ? $varianta->get_sku() : (string) $varianta->get_id(),
				'itemCondition' => 'https://schema.org/NewCondition',
				'offers'        => [$nabidka],
			];

			$ean = self::ean($varianta);

			if ($ean !== '') {
				$kus['gtin13'] = $ean;
			}

			$velikost = (string) $varianta->get_attribute('pa_velikost');

			if ($velikost !== '') {
				$kus['size'] = $velikost;
			}

			$varianty[] = $kus;
		}

		if ($varianty === []) {
			// Nic koupitelného — necháme plochý Product z jádra.
			return $data;
		}

		$data['@type']          = 'ProductGroup';
		$data['productGroupID'] = $produkt->get_sku() !== '' ? $produkt->get_sku() : (string) $produkt->get_id();
		$data['variesBy']       = ['https://schema.org/size'];
		$data['hasVariant']     = $varianty;
		$data['offers']         = [[
			'@type'         => 'AggregateOffer',
			'priceCurrency' => get_woocommerce_currency(),
			'lowPrice'      => (string) round(min($ceny), 2),
			'highPrice'     => (string) round(max($ceny), 2),
			'offerCount'    => count($varianty),
			'availability'  => $produkt->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			'url'           => $produkt->get_permalink(),
		]];

		return $data;
	}

	/**
	 * Jedna nabídka. VŽDY cena s DPH — jádro sem dává cenu bez DPH, což by
	 * ve výsledcích hledání ukázalo částku, za kterou se koupit nedá.
	 *
	 * @return array<string, mixed>
	 */
	private static function nabidka(WC_Product $produkt): array {
		$cena = wc_get_price_including_tax($produkt);

		if (!is_numeric($cena) || (float) $cena <= 0) {
			return [];
		}

		$firma = shop_seo_firma();

		if ($produkt->is_on_backorder()) {
			$dostupnost = 'https://schema.org/BackOrder';
		} elseif ($produkt->is_in_stock()) {
			$dostupnost = 'https://schema.org/InStock';
		} else {
			$dostupnost = 'https://schema.org/OutOfStock';
		}

		return [
			'@type'                   => 'Offer',
			'price'                   => (string) round((float) $cena, 2),
			'priceCurrency'           => get_woocommerce_currency(),
			'availability'            => $dostupnost,
			'itemCondition'           => 'https://schema.org/NewCondition',
			'url'                     => $produkt->get_permalink(),
			'priceValidUntil'         => gmdate('Y-m-d', strtotime('+1 year')),
			'shippingDetails'         => self::doprava_nabidky((float) $cena, $firma),
			'hasMerchantReturnPolicy' => [
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => 'CZ',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
				'merchantReturnDays'   => (int) $firma['lhuta_vraceni_dnu'],
				'returnMethod'         => 'https://schema.org/ReturnByMail',
				'returnFees'           => 'https://schema.org/ReturnShippingFees',
			],
		];
	}

	/**
	 * Doprava k nabídce. Nad prahem 0 Kč, pod prahem každá metoda zvlášť —
	 * jinak by nákupní výpis ukázal nejdražší variantu i tam, kde je levnější
	 * výdejní místo.
	 *
	 * @param array<string, mixed> $firma
	 * @return array<int, array<string, mixed>>
	 */
	private static function doprava_nabidky(float $cena, array $firma): array {
		$sazby = $cena >= (float) $firma['doprava_zdarma_od'] ? [0.0] : (array) $firma['sazby_dopravy'];

		return array_map(
			static fn(float $sazba): array => [
				'@type'               => 'OfferShippingDetails',
				'shippingRate'        => ['@type' => 'MonetaryAmount', 'value' => (string) $sazba, 'currency' => get_woocommerce_currency()],
				'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'CZ'],
				'deliveryTime'        => [
					'@type'        => 'ShippingDeliveryTime',
					'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY'],
					'transitTime'  => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 2, 'unitCode' => 'DAY'],
				],
			],
			array_map('floatval', $sazby)
		);
	}

	/** Značka z atributu pa_znacka (get_attribute vrací víc hodnot přes čárku). */
	private static function znacka(WC_Product $produkt): string {
		$casti = explode(',', (string) $produkt->get_attribute('pa_znacka'));

		return trim((string) reset($casti));
	}

	/**
	 * EAN z pole GTIN (WooCommerce 9.2+), záložně z meta _ean. Vrací jen
	 * třináctimístné číslo — raději nic než neplatný gtin13.
	 */
	public static function ean(WC_Product $produkt): string {
		$hodnota = method_exists($produkt, 'get_global_unique_id') ? (string) $produkt->get_global_unique_id() : '';

		if ($hodnota === '') {
			$hodnota = (string) get_post_meta($produkt->get_id(), '_ean', true);
		}

		$hodnota = (string) preg_replace('/\D+/', '', $hodnota);

		return strlen($hodnota) === 13 ? $hodnota : '';
	}

	/**
	 * @param array<string, string> $adresa
	 * @return array<string, string>
	 */
	private static function postovni_adresa(array $adresa): array {
		return [
			'@type'           => 'PostalAddress',
			'streetAddress'   => $adresa['ulice'],
			'postalCode'      => $adresa['psc'],
			'addressLocality' => $adresa['mesto'],
			'addressRegion'   => $adresa['kraj'] ?? '',
			'addressCountry'  => $adresa['zeme'],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $doba
	 * @return array<int, array<string, mixed>>
	 */
	private static function oteviraci_doba(array $doba): array {
		return array_map(
			static fn(array $blok): array => [
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $blok['dny'],
				'opens'     => $blok['od'],
				'closes'    => $blok['do'],
			],
			$doba
		);
	}

	/** @param array<string, mixed> $data */
	private static function vypis_json(array $data): void {
		$json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

		if (is_string($json)) {
			echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON z wp_json_encode s JSON_HEX_TAG
		}
	}
}
