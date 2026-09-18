/**
 * Diagnostic : rejoue dans php-wasm les tests d'exercices Laravel déjà assemblés par
 * `content:check --keep`, pour attraper ce que la vérification de contenu ne peut pas voir —
 * elle exécute PHPUnit en PHP natif, où PHP_SAPI vaut « cli », alors qu'il vaut « wasm » ici.
 *
 * Usage : node spike/wasm-check.mjs <« exercice|workdir », ou un fichier d'une telle ligne par exercice>
 *   RUNNER_PATHS='["tests/X.php"]'  restreint les tests exécutés
 *   --sans-console                  retire APP_RUNNING_IN_CONSOLE de phpunit.xml (état d'avant la 1.2.3)
 *
 * Un exercice par processus : plusieurs instances php-wasm dans le même processus finissent par
 * faire tomber le runtime. Limite connue : RefreshDatabase fait tomber le runtime Node de php-wasm
 * (« RuntimeError: unreachable »), alors qu'il fonctionne dans le navigateur — les exercices à base
 * de données ne se vérifient donc pas ici.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { unzipSync } from 'fflate';

// fileURLToPath, pas URL.pathname : le chemin du dépôt contient une espace.
const RACINE = fileURLToPath(new URL('../../', import.meta.url));
const ZIP = `${RACINE}platform/public/envs/laravel-13.zip`;
const APP = '/app';
const RUNNER = readFileSync(`${RACINE}playground/src/runtime/run-tests.php`);
const sansConsole = process.argv.includes('--sans-console');
// Un exercice par processus : une instance php-wasm par exercice finit par faire tomber le runtime.
const arg = process.argv[2];
const lignes = arg.includes('|') ? [arg] : readFileSync(arg, 'utf8').trim().split('\n').filter(Boolean);
const zip = Object.entries(unzipSync(new Uint8Array(readFileSync(ZIP))));
// Ce que l'exercice peut modifier : le reste (vendor, storage…) vient du zip de l'environnement.
const PROJET = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'tests'];

for (const ligne of lignes) {
	const [exercice, workdir] = ligne.split('|');
	const php = new PHP(await loadNodeRuntime('8.4', { emscriptenOptions: { processId: 1 } }));
	php.mkdir(APP);
	for (const [name, bytes] of zip) {
		if (name.endsWith('/')) continue;
		const path = `${APP}/${name}`;
		php.mkdir(path.slice(0, path.lastIndexOf('/')));
		php.writeFile(path, bytes);
	}
	// bootstrap/cache et storage : caches produits par la passe native de content:check, avec des chemins
	// absolus de l'hôte. Les recopier dans le wasm fait tomber le runtime ; le zip fournit les bons.
	const IGNORE = new Set(['cache', 'storage']);
	const copier = (dir, cible) => {
		for (const name of readdirSync(dir)) {
			if (dir.endsWith('/bootstrap') && IGNORE.has(name)) continue;
			const src = join(dir, name), dst = join(cible, name);
			if (statSync(src).isDirectory()) { if (!php.isDir(dst)) php.mkdir(dst); copier(src, dst); }
			else php.writeFile(dst, readFileSync(src));
		}
	};
	for (const dir of PROJET) {
		try { statSync(join(workdir, dir)); } catch { continue; }
		if (!php.isDir(`${APP}/${dir}`)) php.mkdir(`${APP}/${dir}`);
		copier(join(workdir, dir), `${APP}/${dir}`);
	}
	// SQLite « :memory: » + RefreshDatabase fait tomber le runtime wasm sous Node (pas dans le navigateur) :
	// un fichier contourne le problème et laisse vérifier ce qui nous intéresse.
	if (process.argv.includes('--base-fichier')) {
		php.writeFile('/tmp/test.sqlite', new Uint8Array(0));
		php.writeFile(`${APP}/phpunit.xml`, php.readFileAsText(`${APP}/phpunit.xml`)
			.replace('<env name="DB_DATABASE" value=":memory:"/>', '<env name="DB_DATABASE" value="/tmp/test.sqlite" force="true"/>'));
	}
	if (sansConsole) {
		const xml = php.readFileAsText(`${APP}/phpunit.xml`)
			.replace(/\s*<(server|env) name="APP_RUNNING_IN_CONSOLE"[^>]*\/>/g, '');
		php.writeFile(`${APP}/phpunit.xml`, xml);
	}
	php.mkdir('/runner');
	php.writeFile('/runner/run-tests.php', RUNNER);

	let ligneResultat;
	try {
		const paths = process.env.RUNNER_PATHS ?? '[]';
		const res = await php.run({ scriptPath: '/runner/run-tests.php', $_SERVER: { RUNNER_PATHS: paths } });
		const marker = '\n@@RAPPORT@@\n';
		const at = res.text.lastIndexOf(marker);
		if (at === -1) ligneResultat = 'PAS DE RAPPORT : ' + res.text.slice(-300).replace(/\n/g, ' ');
		else {
			const r = JSON.parse(res.text.slice(at + marker.length));
			const ko = r.cases.filter((c) => c.status !== 'passed');
			ligneResultat = ko.length === 0
				? `OK   ${r.cases.length}/${r.cases.length} tests verts`
				: `KO   ${r.cases.length - ko.length}/${r.cases.length} verts — ${ko[0].name} : ${(ko[0].message ?? '').split('\n').slice(1, 2).join(' ').trim().slice(0, 110)}`;
		}
	} catch (e) {
		ligneResultat = 'ERREUR ' + String(e).split('\n')[0].slice(0, 160);
	}
	console.log(`${exercice.padEnd(38)} ${ligneResultat}`);
}
process.exit(0);
