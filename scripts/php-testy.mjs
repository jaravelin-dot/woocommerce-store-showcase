// Spustí PHP testy přes WordPress Playground CLI (PHP běží jako WebAssembly,
// lokální instalace PHP není potřeba). Použití: npm run test:php
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const koren = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const skripty = ['tests/php/syntaxe.php', 'tests/php/jednotkove.php'];
let chyba = 0;

for (const skript of skripty) {
	console.log(`\n== ${skript}`);
	const argumenty = [
		'-y', '@wp-playground/cli@latest', 'php',
		'--mount-dir', koren, '/repo',
		'--wordpress-install-mode', 'do-not-attempt-installing',
		'--', `/repo/${skript}`,
	];
	// Na Windows je npx dávka (.cmd) a jde spustit jen přes shell — cesty s mezerou do uvozovek.
	const windows = process.platform === 'win32';
	const vysledek = spawnSync('npx', windows ? argumenty.map((a) => `"${a}"`) : argumenty, { stdio: 'inherit', shell: windows });
	chyba ||= vysledek.status ?? 1;
}

process.exit(chyba);
