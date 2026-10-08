<?php
/**
 * Fakta o firmě na jednom místě.
 *
 * Používá je meta popisek, JSON-LD, /llms.txt i feedy — adresa nebo
 * otevírací doba se tak mění jen tady. Hodnoty jsou ukázkové; v reálném
 * nasazení se drží v souladu s patičkou webu (ta je to, co zákazník vidí).
 *
 * Pozor na dvě adresy: PRODEJNA je místo, kam zákazník přijde, a patří do
 * schématu Store. Fakturační sídlo do Store nepatří — mapy by posílaly
 * zákazníky jinam.
 *
 * Prázdná hodnota (IČO, telefon, profily) = do výstupu se nepošle nic.
 * Radši nic než vymyšlený údaj.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Číslo s oddělenými tisíci pro místa, kde HTML entita škodí.
 *
 * `number_format_i18n()` vrací v české lokalizaci oddělovač tisíců jako
 * entitu `&nbsp;`. V meta popisku ji esc_attr() zakóduje podruhé a ve
 * výsledcích vyhledávání se ukáže doslova „2&nbsp;000 Kč". Vrací proto
 * pevnou mezeru jako skutečný znak (U+00A0).
 */
function shop_seo_cislo(float $cislo): string {
	return html_entity_decode(number_format_i18n($cislo), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * @return array<string, mixed>
 */
function shop_seo_firma(): array {
	$firma = [
		'nazev'            => 'Ukázkový obchod',
		'pravni_nazev'     => 'Ukázková prodejna s.r.o.',
		'ico'              => '',
		'dic'              => '',
		'zalozeno'         => '2000',
		'popis'            => 'Pracovní oděvy, pracovní obuv a ochranné pomůcky. '
			. 'Kamenná prodejna a e-shop s dopravou po celé ČR.',
		'email'            => 'obchod@example.cz',
		'telefon_eshop'    => '',
		'telefon_prodejna' => '',

		// Prodejna — sem zákazník fyzicky chodí.
		'prodejna' => [
			'ulice'  => 'Ukázková 1',
			'psc'    => '100 00',
			'mesto'  => 'Praha',
			'zeme'   => 'CZ',
			'kraj'   => 'Hlavní město Praha',
			'plocha' => 'zboží k vyzkoušení na místě',
		],

		'oteviraci_doba' => [
			['dny' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'od' => '08:00', 'do' => '17:00'],
			['dny' => ['Saturday'], 'od' => '08:00', 'do' => '12:00'],
		],

		// Doprava zdarma od této částky včetně DPH.
		'doprava_zdarma_od' => 2000,

		// Sazby dopravy pod prahem: výdejní místo, doručení na adresu.
		'sazby_dopravy' => [89.0, 119.0],

		'lhuta_vraceni_dnu' => 30,

		// Profily na sítích. Prázdné = do sameAs se nic nepošle.
		'profily' => [],
	];

	/**
	 * Nasazení si fakta přepíše filtrem, nemusí sahat do kódu.
	 *
	 * @param array<string, mixed> $firma
	 */
	return (array) apply_filters('shop_seo_firma', $firma);
}
