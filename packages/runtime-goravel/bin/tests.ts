/**
 * Lance les tests Go d'un projet Goravel avec le binaire du navigateur, pour content:check (NodeModuleRunner) :
 * même binaire, même interpréteur, même verdict que dans le playground. Sortie : JSON sur la sortie standard.
 *
 *     node --experimental-transform-types bin/tests.ts <dossier du projet> [fichiers de test…]
 *
 * Les tests Go se lancent par dossier : avec des fichiers en argument, tous les tests tournent, et seuls ceux
 * de ces fichiers sont rapportés.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { GoravelProject } from '../src/project.ts';

const [directory, ...only] = process.argv.slice(2);
if (!directory) {
	process.stderr.write('usage : bin/tests.ts <dossier du projet> [fichiers de test…]\n');
	process.exit(2);
}

const binary = fileURLToPath(new URL('../dist/goravel.wasm', import.meta.url));
if (!existsSync(binary)) {
	process.stderr.write(`Binaire Goravel absent (${binary}) : npm run build:wasm, dans packages/runtime-goravel.\n`);
	process.exit(1);
}

const root = resolve(directory);
const files: Record<string, Uint8Array> = {};
(function walk(dir: string) {
	for (const name of readdirSync(dir)) {
		const path = join(dir, name);
		const rel = relative(root, path);
		if (statSync(path).isDirectory()) {
			if (!['storage', 'vendor', '.git', 'node_modules'].includes(rel)) walk(path);
		} else if (rel !== 'environment.yaml') {
			files[rel] = readFileSync(path);
		}
	}
})(root);

const wanted = new Set(only.map((file) => relative(root, resolve(root, file))));
const project = new GoravelProject(await WebAssembly.compile(readFileSync(binary)), files);
const result = await project.runTests();
const cases = result.cases.filter((c) => wanted.size === 0 || (c.file && wanted.has(c.file)));
process.stdout.write(`${JSON.stringify({
	exitCode: result.exitCode,
	output: result.output,
	cases: cases.map((c) => ({ ...c, file: c.file ? join(root, c.file) : undefined })),
})}\n`);
process.exit(0);
