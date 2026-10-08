<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Čárový kód EAN-13 jako SVG (výdejka pro sklad, štítky).
 *
 * Napsané ručně: kvůli jednomu čárovému kódu nemá smysl tahat do pluginu
 * knihovnu a správce balíčků. Kódování EAN-13 je pevně dané normou.
 *
 * Levá polovina se kóduje dvěma tabulkami (L a G) podle PRVNÍ číslice,
 * pravá tabulkou R. Implementace, která první číslici ignoruje a použije
 * jen L, vygeneruje kód, který čtečka přečte jako jiné číslo.
 */
class Shop_Ean13 {

	private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
	private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
	private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

	/** Která tabulka (L/G) se použije na kterou pozici levé poloviny, podle první číslice. */
	private const PARITA = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

	/** Kontrolní číslice pro prvních 12 číslic. */
	public static function kontrolni_cislice(string $dvanact): int {
		$soucet = 0;

		for ($i = 0; $i < 12; $i++) {
			$soucet += (int) $dvanact[$i] * ($i % 2 === 0 ? 1 : 3);
		}

		return (10 - $soucet % 10) % 10;
	}

	public static function platny(string $ean): bool {
		return preg_match('/^\d{13}$/', $ean) === 1 && self::kontrolni_cislice($ean) === (int) $ean[12];
	}

	/** 95 modulů: 1 = čára, 0 = mezera. Prázdný řetězec u neplatného kódu. */
	public static function pruhy(string $ean): string {
		if (!self::platny($ean)) {
			return '';
		}

		$parita = self::PARITA[(int) $ean[0]];
		$pruhy  = '101';

		for ($i = 1; $i <= 6; $i++) {
			$pruhy .= $parita[$i - 1] === 'L' ? self::L[(int) $ean[$i]] : self::G[(int) $ean[$i]];
		}

		$pruhy .= '01010';

		for ($i = 7; $i <= 12; $i++) {
			$pruhy .= self::R[(int) $ean[$i]];
		}

		return $pruhy . '101';
	}

	/**
	 * SVG bez textu — číslo se tiskne zvlášť pod kódem, aby šlo přečíst
	 * i u rozmazaného tisku. Sousední čáry se slévají do jednoho obdélníku.
	 */
	public static function svg(string $ean, int $vyska = 40, int $modul = 2): string {
		$pruhy = self::pruhy($ean);

		if ($pruhy === '') {
			return '';
		}

		$sirka     = strlen($pruhy) * $modul;
		$obdelniky = '';

		if (preg_match_all('/1+/', $pruhy, $useky, PREG_OFFSET_CAPTURE) > 0) {
			foreach ($useky[0] as [$usek, $pozice]) {
				$obdelniky .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $pozice * $modul, strlen($usek) * $modul, $vyska);
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="EAN %3$s"><g fill="#000">%4$s</g></svg>',
			$sirka,
			$vyska,
			$ean,
			$obdelniky
		);
	}
}
