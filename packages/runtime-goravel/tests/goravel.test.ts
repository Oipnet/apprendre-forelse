/**
 * Le runtime de bout en bout, sous Node, avec le vrai binaire (go/build.sh) et l'environnement goravel-1.
 * Sans binaire (Go absent), ces tests sont ignorés : la CI le construit avant (npm run build:wasm).
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeAll, describe, expect, it } from 'vitest';
import { GoravelProject } from '../src/project.ts';

const binary = fileURLToPath(new URL('../dist/goravel.wasm', import.meta.url));
const environment = fileURLToPath(new URL('../../../environments/goravel-1', import.meta.url));

function projectFiles(): Record<string, Uint8Array> {
	const files: Record<string, Uint8Array> = {};
	(function walk(dir: string) {
		for (const name of readdirSync(dir)) {
			const path = join(dir, name);
			if (statSync(path).isDirectory()) walk(path);
			else if (name !== 'environment.yaml') files[relative(environment, path)] = readFileSync(path);
		}
	})(environment);
	return files;
}

const text = (body: Uint8Array) => new TextDecoder().decode(body);

describe.skipIf(!existsSync(binary))('Goravel dans le navigateur', () => {
	let module: WebAssembly.Module;
	const project = () => new GoravelProject(module, projectFiles(), { basePath: '/preview/abc' });

	beforeAll(async () => {
		module = await WebAssembly.compile(readFileSync(binary));
	});

	it('sert les routes de l\'apprenant sous le préfixe de l\'aperçu, et un vrai 404', async () => {
		const p = project();
		const accueil = await p.request({ method: 'GET', url: '/preview/abc/', headers: {} });
		expect(accueil.status).toBe(200);
		expect(text(accueil.body)).toContain('<h1>Goravel</h1>');
		const json = await p.request({ method: 'GET', url: '/preview/abc/bonjour/Ada', headers: {} });
		expect(JSON.parse(text(json.body))).toEqual({ message: 'Bonjour Ada' });
		expect((await p.request({ method: 'GET', url: '/preview/abc/rien', headers: {} })).status).toBe(404);
	});

	it('relit le code après une modification', async () => {
		const p = project();
		await p.request({ method: 'GET', url: '/preview/abc/', headers: {} });
		p.writeFile('app/http/controllers/accueil_controller.go', p.readFile('app/http/controllers/accueil_controller.go')!.replace('"Bonjour "', '"Salut "'));
		const json = await p.request({ method: 'GET', url: '/preview/abc/bonjour/Ada', headers: {} });
		expect(JSON.parse(text(json.body))).toEqual({ message: 'Salut Ada' });
	});

	it('montre l\'erreur de compilation, avec le fichier et la ligne', async () => {
		const p = project();
		p.writeFile('routes/web.go', 'package routes\n\nfunc Web() {\n\tinconnu()\n}\n');
		const page = await p.request({ method: 'GET', url: '/preview/abc/', headers: {} });
		expect(page.status).toBe(500);
		expect(text(page.body)).toContain('routes/web.go:4:');
		expect(text(page.body)).toContain('undefined: inconnu');
	});

	it('lance les tests Go de l\'apprenant, et donne le message de l\'assertion qui échoue', async () => {
		const p = project();
		const ok = await p.runTests();
		expect(ok.exitCode).toBe(0);
		expect(ok.cases.map((c) => [c.name, c.status, c.file])).toEqual([
			['TestAccueilRepond', 'passed', 'tests/feature/accueil_test.go'],
			['TestBonjourSaluePersonne', 'passed', 'tests/feature/accueil_test.go'],
		]);

		p.writeFile('routes/web.go', p.readFile('routes/web.go')!.replace('"name": "Goravel"', '"name": "Autre"'));
		const ko = await p.runTests();
		expect(ko.exitCode).toBe(1);
		const failed = ko.cases.find((c) => c.name === 'TestAccueilRepond')!;
		expect(failed.status).toBe('failed');
		expect(failed.summary).toBe('Expected to see \'Goravel\' in response, but it was not found.');
	});

	it('note les tests de l\'apprenant par mutants', async () => {
		const p = project();
		const result = await p.runTests({
			ownTests: ['tests/feature/accueil_test.go'],
			mutants: [{ id: 'salut', label: 'le message change', changes: [{ file: 'app/http/controllers/accueil_controller.go', search: '"Bonjour "', replace: '"Salut "' }] }],
		});
		expect(result.cases.find((c) => c.name === 'own-tests')?.status).toBe('passed');
		expect(result.cases.find((c) => c.name === 'mutant:salut')?.status).toBe('passed');
	});
});
