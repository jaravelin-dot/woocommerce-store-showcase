// Integrační testy uvnitř WordPressu + WooCommerce: stejná instalace jako demo,
// navíc krok, který spustí tests/php/integrace.php. Bez serveru (run-blueprint).
// Výsledek zapíše PHP do /vystup/integrace.txt; tady se vypíše celý a podle něj
// se určí návratový kód (výstup kroku runPHP Playground CLI nevypisuje).
// Použití: npm run test:integrace
import { existsSync, readFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import { lokalniBlueprint, pracovni, spustCli } from './lokalni-blueprint.mjs';

const vysledky = path.join(pracovni, 'integrace.txt');
rmSync(vysledky, { force: true });

const blueprint = lokalniBlueprint('blueprint.integrace.json', [
	{ step: 'runPHP', code: "<?php require '/repo/tests/php/integrace.php';" },
]);

spustCli('run-blueprint', [`--blueprint=${blueprint}`], (kod) => {
	if (!existsSync(vysledky)) {
		console.error('Integrační testy nedoběhly: chybí soubor s výsledky.');
		process.exit(1);
	}

	const text = readFileSync(vysledky, 'utf8');
	console.log(text);
	process.exit(kod === 0 && /, 0 chyb/.test(text) ? 0 : 1);
});
