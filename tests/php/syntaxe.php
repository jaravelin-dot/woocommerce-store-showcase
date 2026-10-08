<?php
/**
 * Kontrola syntaxe všech PHP souborů repa (obdoba `php -l` pro celý strom).
 * token_get_all() s TOKEN_PARSE soubor celý rozparsuje a při chybě vyhodí ParseError.
 */
declare(strict_types=1);

$koren  = dirname(__DIR__, 2);
$chyby  = 0;
$pocet  = 0;
$soubory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($koren . '/wp-content', FilesystemIterator::SKIP_DOTS));

foreach (array_merge(iterator_to_array($soubory, false), glob($koren . '/demo/*.php') ?: [], glob(__DIR__ . '/*.php') ?: []) as $soubor) {
	$cesta = (string) $soubor;

	if (!str_ends_with($cesta, '.php')) {
		continue;
	}

	++$pocet;

	try {
		token_get_all((string) file_get_contents($cesta), TOKEN_PARSE);
	} catch (ParseError $e) {
		++$chyby;
		echo 'CHYBA ' . substr($cesta, strlen($koren) + 1) . ':' . $e->getLine() . ' ' . $e->getMessage() . "\n";
	}
}

echo "Syntaxe: {$pocet} souborů, {$chyby} chyb\n";
exit($chyby === 0 ? 0 : 1);
