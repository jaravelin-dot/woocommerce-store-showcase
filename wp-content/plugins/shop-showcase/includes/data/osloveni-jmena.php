<?php
/**
 * Běžná česká křestní jména v 5. pádu (oslovení).
 *
 * Klíč = 1. pád, hodnota = 5. pád. Sem patří hlavně jména, u kterých by
 * jednoduché pravidlo selhalo: souhlásková zakončení mužských jmen
 * s měkčením (Petr → Petře, Marek → Marku, Karel → Karle). Ženská jména na
 * krátké -a (Jana → Jano) a nesklonná zakončení (Marie, Jiří) řeší pravidlo
 * v Shop_Osloveni a do slovníku nepatří.
 *
 * Seznam je sestavený z obecně nejčastějších jmen; každý řádek je tvrzení,
 * které jde zkontrolovat okem.
 */
declare(strict_types=1);

return [
	// Mužská jména.
	'Adam'      => 'Adame',
	'Aleš'      => 'Aleši',
	'Alois'     => 'Aloisi',
	'Antonín'   => 'Antoníne',
	'Bohumil'   => 'Bohumile',
	'Bohuslav'  => 'Bohuslave',
	'Daniel'    => 'Danieli',
	'David'     => 'Davide',
	'Denis'     => 'Denisi',
	'Dominik'   => 'Dominiku',
	'Eduard'    => 'Eduarde',
	'Emil'      => 'Emile',
	'Erik'      => 'Eriku',
	'Filip'     => 'Filipe',
	'František' => 'Františku',
	'Hynek'     => 'Hynku',
	'Igor'      => 'Igore',
	'Jan'       => 'Jane',
	'Jaromír'   => 'Jaromíre',
	'Jaroslav'  => 'Jaroslave',
	'Jindřich'  => 'Jindřichu',
	'Kamil'     => 'Kamile',
	'Karel'     => 'Karle',
	'Ladislav'  => 'Ladislave',
	'Leoš'      => 'Leoši',
	'Libor'     => 'Libore',
	'Lubomír'   => 'Lubomíre',
	'Lukáš'     => 'Lukáši',
	'Marek'     => 'Marku',
	'Matyáš'    => 'Matyáši',
	'Michael'   => 'Michaeli',
	'Michal'    => 'Michale',
	'Milan'     => 'Milane',
	'Miloslav'  => 'Miloslave',
	'Miroslav'  => 'Miroslave',
	'Oldřich'   => 'Oldřichu',
	'Ondřej'    => 'Ondřeji',
	'Patrik'    => 'Patriku',
	'Petr'      => 'Petře',
	'Radek'     => 'Radku',
	'Robert'    => 'Roberte',
	'Roman'     => 'Romane',
	'Rostislav' => 'Rostislave',
	'Stanislav' => 'Stanislave',
	'Štěpán'    => 'Štěpáne',
	'Tadeáš'    => 'Tadeáši',
	'Tomáš'     => 'Tomáši',
	'Václav'    => 'Václave',
	'Viktor'    => 'Viktore',
	'Vít'       => 'Víte',
	'Vladimír'  => 'Vladimíre',
	'Vlastimil' => 'Vlastimile',
	'Vojtěch'   => 'Vojtěchu',
	'Zbyněk'    => 'Zbyňku',
	'Zdeněk'    => 'Zdeňku',

	// Ženská jména zakončená souhláskou — v 5. pádu se nemění.
	'Dagmar'    => 'Dagmar',
	'Ester'     => 'Ester',
	'Ingrid'    => 'Ingrid',
	'Miriam'    => 'Miriam',
];
