<?php
/**
 * Mapování našich kategorií do PEVNÝCH stromů srovnávačů.
 *
 * Pozor na past se stejným názvem pole: CATEGORYTEXT u srovnávače je cesta
 * v JEHO stromu (vlastní cestu nepozná a nabídku nezařadí), kdežto Google
 * product_type je cesta NAŠE.
 *
 * Klíč = slug naší kategorie; hledá se od nejhlubší kategorie produktu
 * směrem ke kořeni, takže stačí mapovat hlavní větve. V reálném nasazení je
 * mapa vygenerovaná z číselníku srovnávače a má stovky řádků; tady je
 * ilustrativní výřez pro ukázkový katalog (cesty je před nasazením nutné
 * ověřit proti aktuálnímu číselníku srovnávače).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * @return array<string, array{heureka: string}>
 */
function shop_feedy_mapa_kategorii(): array {
	$mapa = [
		'bezpecnostni-obuv' => [
			'heureka' => 'Heureka.cz | Oblečení a módní doplňky | Pracovní oblečení | Pracovní obuv',
		],
		'pracovni-obuv' => [
			'heureka' => 'Heureka.cz | Oblečení a módní doplňky | Pracovní oblečení | Pracovní obuv',
		],
		'kalhoty' => [
			'heureka' => 'Heureka.cz | Oblečení a módní doplňky | Pracovní oblečení | Pracovní kalhoty',
		],
		'bundy-a-vesty' => [
			'heureka' => 'Heureka.cz | Oblečení a módní doplňky | Pracovní oblečení | Pracovní bundy',
		],
		'trika-a-mikiny' => [
			'heureka' => 'Heureka.cz | Oblečení a módní doplňky | Pracovní oblečení | Pracovní trička',
		],
		'rukavice' => [
			'heureka' => 'Heureka.cz | Dům a zahrada | Dílna | Ochranné pomůcky | Pracovní rukavice',
		],
	];

	/** @param array<string, array{heureka: string}> $mapa */
	return (array) apply_filters('shop_feedy_mapa_kategorii', $mapa);
}

/**
 * Doprava do bloku <DELIVERY>. Kódy dopravců musí přesně sedět s číselníkem
 * srovnávače — neověřený kód znamená varování u KAŽDÉ položky feedu. Dokud
 * nejsou ověřené (`overeno`), blok se nevydává vůbec.
 *
 * @return array{overeno: bool, sazby: array<int, array{kod: string, cena: float, dobirka: float}>}
 */
function shop_feedy_doprava(): array {
	return (array) apply_filters('shop_feedy_doprava', [
		'overeno' => false,
		'sazby'   => [
			['kod' => 'VYDEJNI_MISTO', 'cena' => 89.0, 'dobirka' => 30.0],
			['kod' => 'KURYR', 'cena' => 119.0, 'dobirka' => 30.0],
		],
	]);
}
