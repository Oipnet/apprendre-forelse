/// <reference lib="webworker" />
/**
 * Worker PHP : héberge deux instances php-wasm partageant les mêmes fichiers.
 *  - `preview` sert les requêtes HTTP de l'aperçu (APP_ENV=dev) ;
 *  - `tests` exécute PHPUnit (APP_ENV=test), préparée en arrière-plan après le boot.
 * Mélanger requêtes et runs PHPUnit sur une même instance la bloque (constaté
 * pendant le spike), et l'isolation évite que les tests polluent l'aperçu.
 */
import { HttpCookieStore, inferMimeType, PHP, PHPExecutionFailureError, type PHPResponse } from '@php-wasm/universal';
import { createSpawnHandler } from '@php-wasm/util';
import { getPHPLoaderModule, loadWebRuntime } from '@php-wasm/web';
import { unzipSync } from 'fflate';
import type { BootProgress, CommandResult, EnvironmentSpec, Grading, HttpRequest, HttpResponse, Runtime, TestCaseResult, TestRunResult } from './Runtime';
import type { WorkerCall, WorkerMessage } from './protocol';
import consoleScript from './console.php?raw';
import artisanScript from './artisan.php?raw';
import runTestsScript from './run-tests.php?raw';
import dockerScript from './docker.php?raw';
import dockerHttpScript from './docker-http.php?raw';

const APP_DIR = '/app';
const RUNNER = '/runner/run-tests.php';
const CONSOLE = '/runner/console.php';
const DOCKER_HTTP = '/runner/docker-http.php';
const CONSOLE_MARKER = '\n@@SORTIE@@';
const REPORT_MARKER = '\n@@RAPPORT@@\n';

let env: EnvironmentSpec;
let baseFiles = new Map<string, Uint8Array>();
/** Modifications de l'apprenant, rejouées sur chaque instance (null = supprimé). */
const overlay = new Map<string, Uint8Array | null>();
let preview: PHP;
let tests: Promise<PHP> | null = null;
let nextProcessId = 1;
/**
 * Des fichiers ont changé depuis le dernier run : on repart d'un cache de test vide.
 * Les appels PHP asynchrones (aperçu, écritures, tests) s'entrelacent dans ce worker ; un
 * container ou un template Twig compilé au mauvais moment resterait sinon « frais » à tort.
 * Les tests font foi pour valider l'exercice : la justesse passe avant la vitesse.
 */
let testsDirty = false;

/** Ce qui change d'un framework à l'autre : la console, où vit le code, où vivent les caches. */
interface FrameworkProfile {
	/** Script exécutant une commande de la console du projet (bin/console, artisan). */
	consoleScript: string;
	/**
	 * Dossiers où une commande console peut écrire du code (doctrine:migrations:diff, make:*…).
	 * Caches et dépendances n'en font pas partie : ils ne regardent pas l'apprenant.
	 */
	projectDirs: string[];
	/** Caches de l'instance de tests, vidés quand des fichiers ont changé (relatifs au projet). */
	testCaches: string[];
	/** Ce que l'explorateur de fichiers ne montre pas (relatif au projet). */
	hidden: string[];
	unpackLabel: string;
}
/** Nuxt n'a pas de PHP : son runtime est le worker du simulateur (nuxt-worker.ts). */
const PROFILES: Record<Exclude<NonNullable<EnvironmentSpec['framework']>, 'nuxt'>, FrameworkProfile> = {
	symfony: {
		consoleScript,
		projectDirs: ['src', 'migrations', 'templates', 'config', 'tests', 'translations'],
		testCaches: ['var/cache/test'],
		hidden: ['vendor', 'var', '.git', 'node_modules', '.phpunit.cache'],
		unpackLabel: 'Décompression du projet Symfony',
	},
	laravel: {
		consoleScript: artisanScript,
		projectDirs: ['app', 'config', 'database', 'resources', 'routes', 'tests'],
		// Blade compare les dates de modification à la seconde : une vue réécrite dans la seconde resterait « fraîche ».
		testCaches: ['storage/framework/views'],
		hidden: ['vendor', 'storage', 'bootstrap/cache', 'database/database.sqlite', '.git', 'node_modules', '.phpunit.cache'],
		unpackLabel: 'Décompression du projet Laravel',
	},
	docker: {
		consoleScript: dockerScript,
		// docker cp, un volume monté sur le projet : ce qu'un conteneur écrit chez l'hôte reste visible.
		projectDirs: ['public', 'src', 'docker', 'config', 'templates', 'data'],
		testCaches: [],
		hidden: ['vendor', '.git', 'node_modules', '.phpunit.cache'],
		unpackLabel: 'Décompression du projet',
	},
};
let profile: FrameworkProfile = PROFILES.symfony;
const cookies = new HttpCookieStore();
const encoder = new TextEncoder();

const progress = (p: BootProgress) => postMessage({ type: 'progress', progress: p } satisfies WorkerMessage);

async function download(url: string): Promise<Uint8Array> {
	const response = await fetch(url);
	if (!response.ok || !response.body) throw new Error(`Téléchargement impossible : ${url} (${response.status})`);
	const total = Number(response.headers.get('content-length')) || null;
	const chunks: Uint8Array[] = [];
	let received = 0;
	const reader = response.body.getReader();
	for (;;) {
		const { done, value } = await reader.read();
		if (done) break;
		chunks.push(value);
		received += value.length;
		progress({ step: 'download', ratio: total ? received / total : null, label: `Téléchargement de l'environnement (${(received / 1e6).toFixed(1)} Mo)` });
	}
	const bytes = new Uint8Array(received);
	let offset = 0;
	for (const chunk of chunks) {
		bytes.set(chunk, offset);
		offset += chunk.length;
	}
	return bytes;
}

function writeTree(php: PHP, files: Map<string, Uint8Array | null>) {
	for (const [path, content] of files) {
		const absolute = `${APP_DIR}/${path}`;
		if (content === null) {
			if (php.fileExists(absolute)) php.unlink(absolute);
			continue;
		}
		php.mkdir(absolute.slice(0, absolute.lastIndexOf('/')));
		php.writeFile(absolute, content);
	}
}

/**
 * Le binaire PHP (19 Mo) est téléchargé et compilé une seule fois, puis instancié
 * pour chaque instance via le hook Emscripten `instantiateWasm`. Sans cela, l'instance
 * de tests re-télécharge le .wasm et échoue si le serveur n'est plus joignable.
 */
let compiledPhp: Promise<WebAssembly.Module> | undefined;
const phpVersionOf = (spec: EnvironmentSpec) => (spec.phpVersion || '8.4') as '8.4';
function compilePhpWasm(): Promise<WebAssembly.Module> {
	compiledPhp ??= getPHPLoaderModule(phpVersionOf(env)).then(async ({ dependencyFilename }) => {
		try {
			return await WebAssembly.compileStreaming(fetch(dependencyFilename));
		} catch {
			// Serveur qui ne renvoie pas application/wasm : compilation depuis le buffer.
			const response = await fetch(dependencyFilename);
			if (!response.ok) throw new Error(`Binaire PHP introuvable : ${dependencyFilename} (${response.status})`);
			return WebAssembly.compile(await response.arrayBuffer());
		}
	});
	return compiledPhp;
}

async function createPhp(): Promise<PHP> {
	const wasm = await compilePhpWasm();
	const emscriptenOptions = {
		processId: nextProcessId++,
		instantiateWasm(imports: WebAssembly.Imports, receive: (instance: WebAssembly.Instance, module: WebAssembly.Module) => void) {
			WebAssembly.instantiate(wasm, imports).then((instance) => receive(instance, wasm));
			return {};
		},
	};
	const php = new PHP(await loadWebRuntime(phpVersionOf(env), { emscriptenOptions: emscriptenOptions as never }));
	// Pas de processus dans le navigateur : une commande externe (le php-cs-fixer que lance MakerBundle
	// après make:entity, par exemple) se termine sans rien faire, au lieu de faire échouer la commande.
	await php.setSpawnHandler(createSpawnHandler((_command, processApi) => processApi.exit(0)));
	php.mkdir(APP_DIR);
	writeTree(php, baseFiles);
	writeTree(php, overlay);
	php.mkdir('/runner');
	php.writeFile(RUNNER, runTestsScript);
	php.writeFile(CONSOLE, profile.consoleScript);
	if (env.framework === 'docker') php.writeFile(DOCKER_HTTP, dockerHttpScript);
	return php;
}

function toHttpResponse(response: PHPResponse, start: number): HttpResponse {
	cookies.rememberCookiesFromResponseHeaders(response.headers);
	const headers = { ...response.headers };
	// Une redirection relative à la racine du domaine (Laravel : Route::redirect, route(…, absolute: false) ;
	// Symfony : new RedirectResponse('/x')) sortirait du bac à sable : on la ramène sous le préfixe de l'aperçu.
	const location = headers.location?.[0];
	if (location && /^\/(?!\/)/.test(location) && location !== env.previewBasePath && !location.startsWith(`${env.previewBasePath}/`)) {
		headers.location = [env.previewBasePath + location];
	}
	return { status: response.httpStatusCode, headers, body: response.bytes, durationMs: performance.now() - start };
}

function clearTestCache(php: PHP) {
	for (const dir of profile.testCaches.map((d) => `${APP_DIR}/${d}`)) {
		if (php.isDir(dir)) php.rmdir(dir, { recursive: true });
		php.mkdir(dir);
	}
}

async function runPhpUnit(php: PHP, paths: string[] = []): Promise<Omit<TestRunResult, 'durationMs'>> {
	let text: string;
	try {
		text = (await php.run({ scriptPath: RUNNER, $_SERVER: { RUNNER_PATHS: JSON.stringify(paths) } })).text;
	} catch (error) {
		if (!(error instanceof PHPExecutionFailureError)) throw error;
		text = error.response.text + '\n' + error.response.errors;
	}
	const markerAt = text.lastIndexOf(REPORT_MARKER);
	if (markerAt === -1) return { exitCode: 255, cases: [], output: text.trim() || 'PHPUnit s\'est arrêté sans rapport.' };
	const report = JSON.parse(text.slice(markerAt + REPORT_MARKER.length)) as Omit<TestRunResult, 'durationMs'>;
	return { ...report, output: (text.slice(0, markerAt) + report.output).trim() };
}

/**
 * Note les tests de l'apprenant (voir Grading) : ajoute les cas « own-tests » et « mutant:<id> »
 * au résultat. Chaque mutant est appliqué à l'instance de tests seulement, puis retiré.
 */
async function gradeOwnTests(php: PHP, grading: Grading, result: Omit<TestRunResult, 'durationMs'>) {
	const synthetic = (name: string, passed: boolean, message?: string): TestCaseResult => ({ className: 'Notation', name, status: passed ? 'passed' : 'failed', message, timeMs: 0 });
	const own = result.cases.filter((c) => c.file && grading.ownTests.includes(c.file));
	const failing = own.filter((c) => c.status !== 'passed');
	const ownPassing = own.length > 0 && failing.length === 0;
	result.cases.push(
		synthetic(
			'own-tests',
			ownPassing,
			own.length === 0
				? 'Aucun de vos tests ne s\'est exécuté : écrivez au moins une méthode test…() dans votre classe de test.'
				: `${failing.length === 1 ? 'Un de vos tests ne passe' : `${failing.length} de vos tests ne passent`} pas sur l'application correcte : ${failing
						.map((c) => (c.status === 'skipped' ? `${c.name} (incomplet ou ignoré)` : c.name))
						.join(', ')}.`,
		),
	);
	const report: string[] = [];
	for (const mutant of grading.mutants) {
		if (!ownPassing) {
			result.cases.push(synthetic(`mutant:${mutant.id}`, false, 'Vos tests doivent d\'abord tous passer sur l\'application correcte.'));
			continue;
		}
		const originals = new Map<string, string>();
		for (const change of mutant.changes) {
			const path = `${APP_DIR}/${change.file}`;
			const code = php.readFileAsText(path);
			if (!originals.has(path)) originals.set(path, code);
			php.writeFile(path, code.split(change.search).join(change.replace));
		}
		clearTestCache(php);
		let mutantRun: Omit<TestRunResult, 'durationMs'>;
		try {
			mutantRun = await runPhpUnit(php, grading.ownTests);
		} finally {
			for (const [path, code] of originals) php.writeFile(path, code);
			clearTestCache(php);
		}
		// Détecté si un de vos tests échoue (ou si PHPUnit s'arrête : l'application est cassée).
		const detected = mutantRun.cases.length === 0 || mutantRun.cases.some((c) => c.status !== 'passed');
		result.cases.push(synthetic(`mutant:${mutant.id}`, detected, `Vos tests passent encore quand ${mutant.label} : il manque un test.`));
		report.push(`${detected ? '✔ détecté' : '✘ survivant'} — ${mutant.label}`);
	}
	if (report.length) result.output += `\n\nMutants (versions cassées de l'application) :\n${report.join('\n')}`;
}

/** Le contenu des fichiers du projet, par chemin relatif. */
function instantane(php: PHP): Map<string, string> {
	const fichiers = new Map<string, string>();
	const parcourir = (dossier: string) => {
		for (const nom of php.listFiles(dossier)) {
			const chemin = `${dossier}/${nom}`;
			if (php.isDir(chemin)) parcourir(chemin);
			else fichiers.set(chemin.slice(APP_DIR.length + 1), php.readFileAsText(chemin));
		}
	};
	for (const dossier of profile.projectDirs) if (php.isDir(`${APP_DIR}/${dossier}`)) parcourir(`${APP_DIR}/${dossier}`);
	return fichiers;
}

/**
 * Ce qu'une commande a créé, modifié ou supprimé dans l'aperçu est reporté sur les modifications
 * de l'apprenant, et donc sur l'instance de tests : un fichier généré doit pouvoir être testé.
 */
async function propagerLesChangements(avant: Map<string, string>, apres: Map<string, string>) {
	const fichiers: Record<string, string> = {};
	const supprimes: string[] = [];
	for (const [chemin, contenu] of apres) if (avant.get(chemin) !== contenu) fichiers[chemin] = contenu;
	for (const chemin of avant.keys()) if (!apres.has(chemin)) supprimes.push(chemin);
	if (!Object.keys(fichiers).length && !supprimes.length) return { fichiers, supprimes };

	const instanceDeTests = await tests;
	for (const [chemin, contenu] of Object.entries(fichiers)) {
		const octets = encoder.encode(contenu);
		overlay.set(chemin, octets);
		if (instanceDeTests) {
			const absolu = `${APP_DIR}/${chemin}`;
			instanceDeTests.mkdir(absolu.slice(0, absolu.lastIndexOf('/')));
			instanceDeTests.writeFile(absolu, octets);
		}
	}
	for (const chemin of supprimes) {
		overlay.set(chemin, null);
		if (instanceDeTests?.fileExists(`${APP_DIR}/${chemin}`)) instanceDeTests.unlink(`${APP_DIR}/${chemin}`);
	}
	testsDirty = true;
	return { fichiers, supprimes };
}

/**
 * Aperçu d'un environnement Docker : l'adresse visitée est « /localhost:8080/chemin ». Le port est retenu
 * pour les liens suivants (/menu), et le simulateur fait répondre le conteneur qui publie ce port.
 */
let dockerPort = 80;
async function dockerRequest(req: HttpRequest, url: URL, start: number): Promise<HttpResponse> {
	let path = url.pathname.slice(env.previewBasePath.length) || '/';
	const visited = path.match(/^\/(?:localhost|127\.0\.0\.1)?:(\d+)(\/.*)?$/);
	if (visited) {
		dockerPort = Number(visited[1]);
		path = visited[2] ?? '/';
	}
	const cookieHeader = cookies.getCookieRequestHeader();
	try {
		const response = await preview.run({
			scriptPath: DOCKER_HTTP,
			method: req.method as never,
			body: req.body,
			$_SERVER: {
				DOCKER_HTTP: JSON.stringify({
					method: req.method,
					port: dockerPort,
					uri: path + url.search,
					base: env.previewBasePath,
					headers: { ...req.headers, host: `localhost:${dockerPort}`, ...(cookieHeader ? { cookie: cookieHeader } : {}) },
				}),
			},
		});
		return toHttpResponse(response, start);
	} catch (error) {
		if (error instanceof PHPExecutionFailureError) return toHttpResponse(error.response, start);
		throw error;
	}
}

const api: Runtime = {
	async boot(spec) {
		env = spec;
		if (spec.framework === 'nuxt') throw new Error('Un environnement Nuxt se joue avec NuxtRuntime, pas avec PHP.');
		profile = PROFILES[spec.framework ?? 'symfony'];
		const archive = await download(spec.archiveUrl);
		progress({ step: 'unpack', ratio: null, label: profile.unpackLabel });
		baseFiles = new Map(
			Object.entries(unzipSync(archive)).filter(([path]) => !path.endsWith('/')),
		);
		progress({ step: 'boot', ratio: null, label: `Démarrage de PHP ${spec.phpVersion}` });
		preview = await createPhp();
		// Instance de tests préparée en arrière-plan : premier run plus rapide.
		tests = createPhp();
		tests.catch(() => (tests = null)); // réessayée au premier lancement des tests
	},

	async writeFile(path, content) {
		const bytes = encoder.encode(content);
		overlay.set(path, bytes);
		testsDirty = true;
		const absolute = `${APP_DIR}/${path}`;
		for (const php of [preview, await tests].filter(Boolean) as PHP[]) {
			php.mkdir(absolute.slice(0, absolute.lastIndexOf('/')));
			php.writeFile(absolute, bytes);
		}
	},

	async readFile(path) {
		const absolute = `${APP_DIR}/${path}`;
		return preview.isFile(absolute) ? preview.readFileAsText(absolute) : null;
	},

	async listFiles() {
		// L'apprenant explore son projet, pas les dépendances ni les caches.
		const hidden = new Set(profile.hidden);
		const files: string[] = [];
		const walk = (directory: string) => {
			for (const name of preview.listFiles(directory)) {
				const absolute = `${directory}/${name}`;
				if (hidden.has(absolute.slice(APP_DIR.length + 1)) || files.length > 3000) continue;
				if (preview.isDir(absolute)) walk(absolute);
				else files.push(absolute.slice(APP_DIR.length + 1));
			}
		};
		walk(APP_DIR);

		return files.sort();
	},

	async deleteFile(path) {
		overlay.set(path, null);
		testsDirty = true;
		const absolute = `${APP_DIR}/${path}`;
		for (const php of [preview, await tests].filter(Boolean) as PHP[]) {
			if (php.fileExists(absolute)) php.unlink(absolute);
		}
	},

	async request(req: HttpRequest) {
		const start = performance.now();
		const url = new URL(req.url, 'http://preview.local');
		if (env.framework === 'docker') return dockerRequest(req, url, start);
		const relativePath = decodeURIComponent(url.pathname.slice(env.previewBasePath.length)) || '/';

		// Fichier statique de public/ (CSS, images…) : servi sans passer par PHP.
		const staticPath = `${APP_DIR}/public${relativePath}`;
		if (!relativePath.endsWith('.php') && preview.isFile(staticPath)) {
			const body = preview.readFileAsBuffer(staticPath);
			return { status: 200, headers: { 'content-type': [inferMimeType(staticPath)] }, body, durationMs: performance.now() - start };
		}

		const cookieHeader = cookies.getCookieRequestHeader();
		const frontController = `${env.previewBasePath}/index.php`;
		try {
			const response = await preview.run({
				scriptPath: `${APP_DIR}/public/index.php`,
				relativeUri: url.pathname + url.search,
				method: req.method as never,
				// Host est fourni par le Service Worker (en-tête interdit, donc absent de la requête d'origine).
				headers: { host: 'localhost', ...req.headers, ...(cookieHeader ? { cookie: cookieHeader } : {}) },
				body: req.body,
				$_SERVER: {
					// En prod l'aperçu est servi en https : sans cela PHP se croit en http et les URL absolues
					// qu'il produit (Laravel `asset()`, `route()`) sont bloquées par le navigateur (contenu mixte).
					// La clé doit rester absente quand l'aperçu est en http (php-wasm traite « off » comme « on »).
					...(env.previewSecure ? { HTTPS: 'on', SERVER_PORT: '443' } : {}),
					// Symfony déduit sa base URL de SCRIPT_NAME : les liens générés gardent le préfixe.
					SCRIPT_NAME: frontController,
					PHP_SELF: frontController,
					SCRIPT_FILENAME: `${APP_DIR}/public/index.php`,
					DOCUMENT_ROOT: `${APP_DIR}/public`,
				},
			});
			return toHttpResponse(response, start);
		} catch (error) {
			// Erreur fatale PHP : on renvoie quand même la page (ex. page d'erreur Symfony).
			if (error instanceof PHPExecutionFailureError) return toHttpResponse(error.response, start);
			throw error;
		}
	},

	async runTests(grading?: Grading) {
		const start = performance.now();
		tests ??= createPhp();
		const php = await tests;
		if (testsDirty) {
			testsDirty = false;
			clearTestCache(php);
		}
		const result = await runPhpUnit(php);
		if (grading?.ownTests.length) await gradeOwnTests(php, grading, result);
		return { ...result, durationMs: performance.now() - start };
	},

	async runCommand(args): Promise<CommandResult> {
		const start = performance.now();
		const avant = instantane(preview);
		let text: string;
		try {
			text = (await preview.run({ scriptPath: CONSOLE, $_SERVER: { CONSOLE_ARGS: JSON.stringify(args) } })).text;
		} catch (error) {
			if (!(error instanceof PHPExecutionFailureError)) throw error;
			text = `${error.response.text}\n${error.response.errors}`;
		}
		const markerAt = text.lastIndexOf(CONSOLE_MARKER);
		const exitCode = markerAt === -1 ? 255 : Number(text.slice(markerAt + CONSOLE_MARKER.length)) || 0;
		const { fichiers, supprimes } = await propagerLesChangements(avant, instantane(preview));
		return { exitCode, output: (markerAt === -1 ? text : text.slice(0, markerAt)).replace(/\s+$/, ''), durationMs: performance.now() - start, fichiers, supprimes };
	},
};

self.addEventListener('message', async (event: MessageEvent<WorkerCall>) => {
	const { id, method, args } = event.data;
	try {
		const result = await (api[method] as (...a: unknown[]) => Promise<unknown>)(...args);
		postMessage({ type: 'result', id, result } satisfies WorkerMessage);
	} catch (error) {
		postMessage({ type: 'error', id, error: error instanceof Error ? `${error.message}\n${error.stack ?? ''}` : String(error) } satisfies WorkerMessage);
	}
});
