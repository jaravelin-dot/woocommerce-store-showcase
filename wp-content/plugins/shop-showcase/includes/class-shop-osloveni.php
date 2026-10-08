<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Oslovení zákazníka jménem v 5. pádu („Dobrý den, Petře,").
 *
 * Vrací buď tvar jména, nebo prázdný řetězec. Prázdno znamená, že si
 * tvarem nejsme jistí — pozdrav pak zní neutrálně „Dobrý den,".
 *
 * Zásada: **radši žádné jméno než špatně skloňované.** „Dobrý den, Ondřeju"
 * praští do očí víc, než když zákazníka neoslovíme vůbec. Souhlásková
 * zakončení mužských jmen se proto nehádají pravidlem — sedí ve slovníku.
 *
 * Třída nesahá na WordPress kromě slovníku, takže jde ověřit samostatně
 * (tests/php/test-osloveni.php).
 */
class Shop_Osloveni {
	/** Kratší ani delší už není jméno, ale překlep nebo firma. */
	private const DELKA_MIN = 2;
	private const DELKA_MAX = 20;

	/**
	 * Zakončení, která se v 5. pádu nemění (Marie, Jiří, René, Ivo).
	 * `-á` kvůli ženskému příjmení zapsanému do pole pro křestní jméno.
	 */
	private const NEMENNA_ZAKONCENI = ['á', 'e', 'é', 'í', 'o', 'ó'];

	/** @var array<string, string>|null zploštělý slovník, klíč bez diakritiky a malými */
	private static ?array $slovnik = null;

	/**
	 * Fakturační křestní jméno: e-mail chodí na fakturační adresu, takže
	 * oslovujeme toho, kdo ho otevře. Doručovací je záloha.
	 */
	public static function z_objednavky(WC_Order $objednavka): string {
		$jmeno = trim((string) $objednavka->get_billing_first_name());

		if ($jmeno === '') {
			$jmeno = trim((string) $objednavka->get_shipping_first_name());
		}

		return self::ze_jmena($jmeno);
	}

	/** Celý pozdrav i s čárkou — šablony nemusí řešit, kam patří čárka. */
	public static function pozdrav(string $jmeno): string {
		$paty = self::ze_jmena($jmeno);

		return $paty === '' ? 'Dobrý den,' : 'Dobrý den, ' . $paty . ',';
	}

	/** Jádro převodu, oddělené od objednávky. */
	public static function ze_jmena(string $jmeno): string {
		$jmeno = self::projde_brankou($jmeno);

		if ($jmeno === '') {
			return '';
		}

		return self::slovnik()[self::klic($jmeno)] ?? self::pravidlem($jmeno);
	}

	/**
	 * Vstupní branka. Odpadá „Jan Novák" (dvě slova), „Firma s.r.o." (tečky),
	 * jiné písmo než latinka i prázdno. Velikost písmen se srovná, ne
	 * odmítne: kdo napíše „honza" nebo „JAN", je pořád zákazník se jménem.
	 */
	private static function projde_brankou(string $jmeno): string {
		$jmeno = trim($jmeno);

		if ($jmeno === '' || preg_match('/^\p{Latin}+$/u', $jmeno) !== 1) {
			return '';
		}

		$delka = mb_strlen($jmeno, 'UTF-8');

		if ($delka < self::DELKA_MIN || $delka > self::DELKA_MAX) {
			return '';
		}

		return mb_convert_case($jmeno, MB_CASE_TITLE, 'UTF-8');
	}

	/** Dvě zakončení, u kterých je pravidlo spolehlivé. Nic jiného. */
	private static function pravidlem(string $jmeno): string {
		$posledni = mb_substr($jmeno, -1, null, 'UTF-8');

		// Krátké -a → -o: ženská (Hana → Hano) i mužské domácké podoby (Honza → Honzo).
		if ($posledni === 'a') {
			return mb_substr($jmeno, 0, -1, 'UTF-8') . 'o';
		}

		return in_array($posledni, self::NEMENNA_ZAKONCENI, true) ? $jmeno : '';
	}

	/**
	 * Slovník zploštělý do mapy bez diakritiky, takže „Tomas" najde „Tomáš"
	 * a vrátí správné „Tomáši". Chybějící soubor web neshodí, jen zúží pokrytí.
	 *
	 * @return array<string, string>
	 */
	private static function slovnik(): array {
		if (self::$slovnik !== null) {
			return self::$slovnik;
		}

		self::$slovnik = [];
		$cesta         = __DIR__ . '/data/osloveni-jmena.php';
		$data          = is_readable($cesta) ? require $cesta : [];

		foreach (is_array($data) ? $data : [] as $prvni => $paty) {
			self::$slovnik[self::klic((string) $prvni)] = (string) $paty;
		}

		return self::$slovnik;
	}

	/**
	 * Klíč: malá písmena bez diakritiky. Vlastní tabulka místo iconv() —
	 * //TRANSLIT závisí na locale serveru a bez českého vrací otazníky.
	 */
	private static function klic(string $jmeno): string {
		return strtr(mb_strtolower($jmeno, 'UTF-8'), [
			'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n',
			'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
		]);
	}
}
