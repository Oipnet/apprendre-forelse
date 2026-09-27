/**
 * Le runtime PHP sans navigateur ni WebAssembly : un PHP factice, qui garde ses fichiers en mémoire et
 * répond aux scripts comme le test le lui dit.
 */
import { describe, expect, it } from 'vitest';
import { PHPExecutionFailureError, PHPResponse, type PHPRunOptions } from '@php-wasm/universal';
import { strToU8, zipSync } from 'fflate';
import type { BootProgress, EnvironmentSpec, FrameworkProfile } from '@forelse/runtime-contract';
import { PhpWasmProject, type PhpInstance } from '../src/runtime/PhpWasmProject.ts';
import type { PhpFrameworkAdapter } from '../src/runtime/php-frameworks.ts';

const decoder = new TextDecoder();
const encoder = new TextEncoder();

type Script = (php: FauxPhp, options: PHPRunOptions) => string | PHPResponse;

class FauxPhp implements PhpInstance {
	readonly files = new Map<string, Uint8Array>();
	readonly dirs = new Set<string>(['/']);
	constructor(private readonly script: Script) {}

	mkdir(path: string) {
		for (let dir = path; dir && dir !== '/'; dir = dir.slice(0, dir.lastIndexOf('/')) || '/') this.dirs.add(dir);
	}
	writeFile(path: string, data: string | Uint8Array) {
		this.files.set(path, typeof data === 'string' ? encoder.encode(data) : data);
	}
	fileExists(path: string) {
		return this.files.has(path) || this.dirs.has(path);
	}
	unlink(path: string) {
		this.files.delete(path);
	}
	isFile(path: string) {
		return this.files.has(path);
	}
	isDir(path: string) {
		return this.dirs.has(path);
	}
	readFileAsText(path: string) {
		return decoder.decode(this.files.get(path));
	}
	readFileAsBuffer(path: string) {
		return this.files.get(path)!;
	}
	listFiles(path: string) {
		const children = [...this.files.keys(), ...this.dirs].filter((p) => p !== path && p.slice(0, p.lastIndexOf('/')) === path);
		return children.map((p) => p.slice(path.length + 1));
	}
	rmdir(path: string) {
		for (const p of [...this.files.keys()]) if (p.startsWith(`${path}/`)) this.files.delete(p);
		for (const p of [...this.dirs]) if (p === path || p.startsWith(`${path}/`)) this.dirs.delete(p);
	}
	async run(options: PHPRunOptions) {
		const result = this.script(this, options);
		return typeof result === 'string' ? new PHPResponse(200, {}, encoder.encode(result)) : result;
	}
	text(path: string) {
		return this.files.has(path) ? this.readFileAsText(path) : undefined;
	}
}

const profile = (overrides: Partial<FrameworkProfile> = {}): FrameworkProfile => ({
	id: 'symfony', label: 'Symfony', console: 'bin/console', consoleExample: '', bootNote: '', unpackLabel: 'Décompression du projet Symfony',
	testRunner: 'phpunit', testRunnerLabel: 'PHPUnit', runtime: 'php-wasm', snippets: ['php'], consoleAliases: {}, rawCommands: [],
	projectDirs: ['src', 'migrations'], testCaches: ['var/cache/test'], hidden: ['vendor'], namespaceRoots: {},
	...overrides,
});

const env = (framework = profile()): EnvironmentSpec => ({
	id: 'symfony-8', framework, options: { phpVersion: '8.4' }, archiveUrl: 'https://exemple/projet.zip', previewBasePath: '/preview/abc', previewSecure: false,
});

const archive = zipSync({
	'src/Menu.php': strToU8('<?php // menu'),
	'public/style.css': strToU8('body {}'),
	'vendor/autoload.php': strToU8('<?php'),
	'tests/': new Uint8Array(),
});

/** Un rapport de run-tests.php, tel qu'il suit le marqueur. */
const rapport = (cases: object[], output = '') => `sortie PHPUnit\n@@RAPPORT@@\n${JSON.stringify({ exitCode: 0, cases, output })}`;

function projet(script: Script = () => '', frameworks?: Record<string, () => PhpFrameworkAdapter>) {
	const instances: FauxPhp[] = [];
	const versions: string[] = [];
	const project = new PhpWasmProject({
		async loadPhp(version) {
			versions.push(version);
			const php = new FauxPhp(script);
			instances.push(php);
			return php;
		},
		async download(_url, onProgress) {
			onProgress({ step: 'download', ratio: 1, label: 'Téléchargement' });
			return archive;
		},
		frameworks,
	});
	return { project, instances, versions };
}

/** Le projet démarré, instance de tests prête. */
async function demarre(script?: Script, frameworks?: Record<string, () => PhpFrameworkAdapter>, framework?: FrameworkProfile) {
	const p = projet(script, frameworks);
	const progress: BootProgress[] = [];
	await p.project.boot(env(framework), (step) => progress.push(step));
	await p.project.runTests().catch(() => {});
	return { ...p, progress, preview: p.instances[0], tests: p.instances[1] };
}

describe('PhpWasmProject', () => {
	it('démarre deux instances avec le projet, le lanceur de tests et la console du framework', async () => {
		const { preview, tests, progress, versions } = await demarre(() => '', { symfony: () => ({ consoleScript: '<?php // console', extraFiles: { '/runner/en-plus.php': '<?php' } }) });
		expect(progress.map((p) => p.step)).toEqual(['download', 'unpack', 'boot']);
		expect(progress[2].label).toBe('Démarrage de PHP 8.4');
		expect(versions).toEqual(['8.4', '8.4']);
		for (const php of [preview, tests]) {
			expect(php.text('/app/src/Menu.php')).toBe('<?php // menu');
			expect(php.text('/runner/console.php')).toBe('<?php // console');
			expect(php.isFile('/runner/run-tests.php')).toBe(true);
			expect(php.isFile('/runner/en-plus.php')).toBe(true);
		}
	});

	it('refuse un environnement qui ne se joue pas en PHP', async () => {
		const { project } = projet();
		await expect(project.boot(env(profile({ runtime: 'nuxt-sim' })))).rejects.toThrow('ne se joue pas avec PHP');
	});

	it('écrit et supprime sur les deux instances, et liste le projet sans ce qui est masqué', async () => {
		const { project, preview, tests } = await demarre();
		await project.writeFiles({ 'src/Plat.php': '<?php // plat' });
		await project.deleteFile('src/Menu.php');
		for (const php of [preview, tests]) {
			expect(php.text('/app/src/Plat.php')).toBe('<?php // plat');
			expect(php.isFile('/app/src/Menu.php')).toBe(false);
		}
		expect(await project.readFile('src/Plat.php')).toBe('<?php // plat');
		expect(await project.readFile('src/Menu.php')).toBeNull();
		expect(await project.listFiles()).toEqual(['public/style.css', 'src/Plat.php']);
	});

	it('applique à l\'instance de tests les écritures faites pendant sa préparation', async () => {
		const { project, instances } = projet(() => rapport([]));
		await project.boot(env());
		await project.writeFile('src/Plat.php', '<?php // écrit pendant la préparation');
		await project.runTests();
		expect(instances[1].text('/app/src/Plat.php')).toBe('<?php // écrit pendant la préparation');
	});

	it('lit le rapport de PHPUnit, résume les échecs et vide le cache après une modification', async () => {
		const { project, tests } = await demarre(() => rapport([{ className: 'MenuTest', name: 'testPrix', status: 'failed', message: 'App\\Tests\\MenuTest::testPrix\nLes prix.\nFailed asserting that false is true.', timeMs: 3 }], ' 1 test'));
		tests.writeFile('/app/var/cache/test/vieux.php', '<?php');
		tests.mkdir('/app/var/cache/test');
		await project.writeFile('src/Menu.php', '<?php // modifié');
		const result = await project.runTests();
		expect(result.cases[0].summary).toBe('Les prix.');
		expect(result.output).toBe('sortie PHPUnit 1 test');
		expect(tests.isFile('/app/var/cache/test/vieux.php')).toBe(false);
	});

	it('rend une erreur fatale sans rapport comme un run sans cas', async () => {
		const { project } = await demarre((_php, options) => {
			if (options.scriptPath === '/runner/run-tests.php') throw new PHPExecutionFailureError('fatal', new PHPResponse(500, {}, encoder.encode('Fatal error')), 'php-error' as never);
			return '';
		});
		const result = await project.runTests();
		expect(result).toMatchObject({ exitCode: 255, cases: [] });
		expect(result.output).toContain('Fatal error');
	});

	it('note par mutants sur l\'instance de tests seulement, et restaure le code', async () => {
		const vus: string[] = [];
		const { project, preview, tests } = await demarre((php, options) => {
			if (options.scriptPath !== '/runner/run-tests.php') return '';
			const code = php.readFileAsText('/app/src/Menu.php');
			vus.push(code);
			const passe = !code.includes('cassé');
			return rapport([{ className: 'MenuTest', name: 'testPrix', file: 'tests/MenuTest.php', status: passe ? 'passed' : 'failed', timeMs: 1 }]);
		});
		const result = await project.runTests({
			ownTests: ['tests/MenuTest.php'],
			mutants: [{ id: 'prix', label: 'le prix est ignoré', changes: [{ file: 'src/Menu.php', search: 'menu', replace: 'cassé' }] }],
		});
		expect(vus.slice(-2)).toEqual(['<?php // menu', '<?php // cassé']);
		expect(result.cases.map((c) => [c.name, c.status])).toEqual([['testPrix', 'passed'], ['own-tests', 'passed'], ['mutant:prix', 'passed']]);
		expect(result.output).toContain('Mutants (versions cassées de l\'application) :\n✔ détecté — le prix est ignoré');
		expect(tests.text('/app/src/Menu.php')).toBe('<?php // menu');
		expect(preview.text('/app/src/Menu.php')).toBe('<?php // menu');
	});

	it('rapporte les fichiers qu\'une commande crée ou supprime, et les reporte sur l\'instance de tests', async () => {
		const { project, tests } = await demarre((php, options) => {
			if (options.scriptPath !== '/runner/console.php') return '';
			expect(options.$_SERVER?.CONSOLE_ARGS).toBe('["make:migration"]');
			php.mkdir('/app/migrations');
			php.writeFile('/app/migrations/Version1.php', '<?php // migration');
			php.unlink('/app/src/Menu.php');
			return 'Migration créée.\n\n@@SORTIE@@0';
		});
		const result = await project.runCommand(['make:migration']);
		expect(result).toMatchObject({ exitCode: 0, output: 'Migration créée.', fichiers: { 'migrations/Version1.php': '<?php // migration' }, supprimes: ['src/Menu.php'] });
		expect(tests.text('/app/migrations/Version1.php')).toBe('<?php // migration');
		expect(tests.isFile('/app/src/Menu.php')).toBe(false);
	});

	it('sert les fichiers statiques sans PHP, et garde les redirections sous le préfixe de l\'aperçu', async () => {
		const scripts: PHPRunOptions[] = [];
		const { project } = await demarre((_php, options) => {
			scripts.push(options);
			return new PHPResponse(302, { location: ['/menu'] }, new Uint8Array());
		});
		const style = await project.request({ method: 'GET', url: '/preview/abc/style.css', headers: {} });
		expect(style.status).toBe(200);
		expect(decoder.decode(style.body)).toBe('body {}');
		const redirect = await project.request({ method: 'GET', url: '/preview/abc/', headers: {} });
		expect(redirect.headers.location).toEqual(['/preview/abc/menu']);
		expect(scripts.at(-1)).toMatchObject({ scriptPath: '/app/public/index.php', $_SERVER: { SCRIPT_NAME: '/preview/abc/index.php' } });
		expect((await project.request({ method: 'GET', url: '/preview/abc/menu%', headers: {} })).status).toBe(400);
	});

	it('laisse un framework servir l\'aperçu à sa façon', async () => {
		const adapter = (): PhpFrameworkAdapter => ({
			consoleScript: '<?php',
			request: (_req, url, preview) => preview.run({ scriptPath: '/runner/autre.php', $_SERVER: { CHEMIN: url.pathname.slice(preview.basePath.length) } }),
		});
		const { project } = await demarre((_php, options) => `${options.scriptPath} ${options.$_SERVER?.CHEMIN}`, { autre: adapter }, profile({ id: 'autre' }));
		const response = await project.request({ method: 'GET', url: '/preview/abc/:8080/menu', headers: {} });
		expect(decoder.decode(response.body)).toBe('/runner/autre.php /:8080/menu');
	});
});
