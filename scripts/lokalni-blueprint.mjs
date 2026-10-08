// Společné pro `npm run demo` a `npm run test:integrace`.
//
// blueprint.json je psaný pro zveřejněné repo: moduly a data si stahuje z GitHubu
// (resource „git:directory“). Lokálně se tytéž kroky přepíšou na kopírování
// z připojené složky repa (/repo), zbytek blueprintu zůstává beze změny.
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import path from 'node:path';

export const koren = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Dočasná složka mimo repo: lokální blueprinty a výstup testů (v Playgroundu jako /vystup). */
export const pracovni = path.join(tmpdir(), 'woocommerce-store-showcase');

const zGitu = (zdroj) => zdroj && zdroj.resource === 'git:directory';

const kopiruj = (z, kam) => ({
	step: 'runPHP',
	code: `<?php $z = ${JSON.stringify(`/repo/${z}`)}; $kam = ${JSON.stringify(kam)};
if (!is_dir($kam)) { mkdir($kam, 0777, true); }
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($z, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) { $cil = $kam . '/' . $it->getSubPathName(); $f->isDir() ? (is_dir($cil) || mkdir($cil, 0777, true)) : copy($f->getPathname(), $cil); }`,
});

/** Zapíše lokální blueprint (+ volitelné kroky navíc) do dočasné složky systému a vrátí cestu. */
export function lokalniBlueprint(nazev, krokyNavic = []) {
	const blueprint = JSON.parse(readFileSync(path.join(koren, 'blueprint.json'), 'utf8'));
	const kroky = [];

	for (const krok of blueprint.steps) {
		if (krok.step === 'writeFiles' && zGitu(krok.filesTree)) {
			kroky.push(kopiruj(krok.filesTree.path, krok.writeToPath));
		} else if (krok.step === 'installPlugin' && zGitu(krok.pluginData)) {
			const slozka = krok.options?.targetFolderName || path.basename(krok.pluginData.path);
			kroky.push(kopiruj(krok.pluginData.path, `/wordpress/wp-content/plugins/${slozka}`));
			if (krok.options?.activate) {
				kroky.push({ step: 'activatePlugin', pluginPath: `${slozka}/${slozka}.php` });
			}
		} else if (krok.step === 'installTheme' && zGitu(krok.themeData)) {
			const slozka = krok.options?.targetFolderName || path.basename(krok.themeData.path);
			kroky.push(kopiruj(krok.themeData.path, `/wordpress/wp-content/themes/${slozka}`));
			if (krok.options?.activate) {
				kroky.push({ step: 'activateTheme', themeFolderName: slozka });
			}
		} else {
			kroky.push(krok);
		}
	}

	mkdirSync(pracovni, { recursive: true });
	const cesta = path.join(pracovni, nazev);
	writeFileSync(cesta, JSON.stringify({ ...blueprint, steps: [...kroky, ...krokyNavic] }, null, '\t'));

	return cesta;
}

/** Spustí Playground CLI s připojeným repem (/repo) a pracovní složkou (/vystup). */
export function spustCli(prikaz, argumenty, poSkonceni = (kod) => process.exit(kod ?? 0)) {
	mkdirSync(pracovni, { recursive: true });
	const vse = ['-y', '@wp-playground/cli@latest', prikaz, ...argumenty, '--mount-dir', koren, '/repo', '--mount-dir', pracovni, '/vystup'];
	// Na Windows je npx dávka (.cmd) a jde spustit jen přes shell — argumenty s mezerou do uvozovek.
	const windows = process.platform === 'win32';
	const proces = spawn('npx', windows ? vse.map((a) => `"${a}"`) : vse, { stdio: 'inherit', shell: windows });
	proces.on('exit', poSkonceni);
}
