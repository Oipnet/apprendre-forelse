/**
 * Le projet PHP d'un exercice, sur deux instances php-wasm partageant les mêmes fichiers :
 *  - `preview` sert les requêtes HTTP de l'aperçu (APP_ENV=dev) ;
 *  - `tests` exécute PHPUnit (APP_ENV=test), préparée en arrière-plan après le boot.
 * Mélanger requêtes et runs PHPUnit sur une même instance la bloque (constaté
 * pendant le spike), et l'isolation évite que les tests polluent l'aperçu.
 *
 * Sans dépendance au navigateur : le chargement de PHP et le téléchargement sont fournis
 * (voir php-worker.ts), ce qui permet de le tester avec un PHP factice.
 */
import { HttpCookieStore, inferMimeType, PHPExecutionFailureError, type PHP, type PHPResponse, type PHPRunOptions } from '@php-wasm/universal';
import { unzipSync } from 'fflate';
import type { BootProgress, CommandResult, EnvironmentSpec, FrameworkProfile, Grading, HttpRequest, HttpResponse, Runtime, TestRunResult } from '@forelse/runtime-contract';
import { gradeOwnTests } from '@forelse/runtime-contract';
import { defaultPhpFramework, PHP_FRAMEWORKS, type PhpFrameworkAdapter, type PhpPreviewContext } from './php-frameworks';
import { phpunitSummary } from './phpunit';
import runTestsScript from './run-tests.php?raw';

const APP_DIR = '/app';
const RUNNER = '/runner/run-tests.php';
const CONSOLE = '/runner/console.php';
const CONSOLE_MARKER = '\n@@SORTIE@@';
const REPORT_MARKER = '\n@@RAPPORT@@\n';

/** Ce que le projet demande d'une instance PHP. */
export type PhpInstance = Pick<PHP, 'mkdir' | 'writeFile' | 'fileExists' | 'unlink' | 'isFile' | 'isDir' | 'readFileAsText' | 'readFileAsBuffer' | 'listFiles' | 'rmdir' | 'run'>;

export interface PhpWasmProjectDeps {
	/** Une instance PHP neuve, de cette version (le binaire n'est téléchargé et compilé qu'une fois). */
	loadPhp(version: string): Promise<PhpInstance>;
	/** L'archive du projet, en signalant la progression du téléchargement. */
	download(url: string, onProgress: (progress: BootProgress) => void): Promise<Uint8Array>;
	/** Les frameworks PHP connus, par identifiant de profil (PHP_FRAMEWORKS par défaut). */
	frameworks?: Record<string, () => PhpFrameworkAdapter>;
}

const encoder = new TextEncoder();

export class PhpWasmProject implements Runtime {
	private env!: EnvironmentSpec;
	private profile: FrameworkProfile | null = null;
	private framework: PhpFrameworkAdapter = defaultPhpFramework();
	private baseFiles = new Map<string, Uint8Array>();
	/** Modifications de l'apprenant, rejouées sur chaque instance (null = supprimé). */
	private readonly overlay = new Map<string, Uint8Array | null>();
	private preview!: PhpInstance;
	/** L'instance de tests, en préparation ou prête ; null si sa création a échoué (réessayée aux tests suivants). */
	private tests: Promise<PhpInstance> | null = null;
	/**
	 * La même, une fois prête. Les écritures ne l'attendent pas : tant qu'elle se prépare, elles ne vont que
	 * dans l'overlay, qu'elle applique à sa création. Elles l'attendaient, et au démarrage chaque fichier
	 * attendait la création complète d'une seconde instance PHP avant d'apparaître dans l'aperçu.
	 */
	private testsReady: PhpInstance | null = null;
	/**
	 * Des fichiers ont changé depuis le dernier run : on repart d'un cache de test vide.
	 * Les appels PHP asynchrones (aperçu, écritures, tests) s'entrelacent dans ce worker ; un
	 * container ou un template Twig compilé au mauvais moment resterait sinon « frais » à tort.
	 * Les tests font foi pour valider l'exercice : la justesse passe avant la vitesse.
	 */
	private testsDirty = false;
	private readonly cookies = new HttpCookieStore();

	constructor(private readonly deps: PhpWasmProjectDeps) {}

	/** Le profil du projet en cours ; boot() le pose avant tout le reste. */
	private frameworkProfile(): FrameworkProfile {
		if (!this.profile) throw new Error('Environnement non démarré.');
		return this.profile;
	}

	private phpVersion(): string {
		return this.env.options?.phpVersion || '8.4';
	}

	/**
	 * Lance la création de l'instance de tests. Elle applique l'overlay à la fin de sa création, sans rien
	 * attendre ensuite : aucune écriture ne peut s'intercaler entre ce moment et celui où elle devient
	 * `testsReady` (seules des microtâches les séparent, et les messages sont des tâches).
	 */
	private prepareTests(): Promise<PhpInstance> {
		const pending = this.createPhp(true);
		this.tests = pending;
		this.testsReady = null;
		pending.then(
			(php) => {
				if (this.tests === pending) this.testsReady = php;
			},
			() => {
				if (this.tests === pending) this.tests = null;
			},
		);
		return pending;
	}

	/**
	 * Une instance PHP complète, projet copié. `background` : l'instance de tests, préparée pendant que
	 * l'apprenant travaille déjà ; sa copie du projet rend la main au worker entre deux tranches.
	 */
	private async createPhp(background = false): Promise<PhpInstance> {
		const php = await this.deps.loadPhp(this.phpVersion());
		php.mkdir(APP_DIR);
		if (background) await writeTreeInSlices(php, this.baseFiles);
		else writeTree(php, this.baseFiles);
		// L'overlay en dernier, et plus aucun await ensuite (voir prepareTests).
		writeTree(php, this.overlay);
		php.mkdir('/runner');
		php.writeFile(RUNNER, runTestsScript);
		php.writeFile(CONSOLE, this.framework.consoleScript);
		for (const [path, content] of Object.entries(this.framework.extraFiles ?? {})) php.writeFile(path, content);
		return php;
	}

	private toHttpResponse(response: PHPResponse, start: number): HttpResponse {
		this.cookies.rememberCookiesFromResponseHeaders(response.headers);
		const headers = { ...response.headers };
		// Une redirection relative à la racine du domaine (Laravel : Route::redirect, route(…, absolute: false) ;
		// Symfony : new RedirectResponse('/x')) sortirait du bac à sable : on la ramène sous le préfixe de l'aperçu.
		const base = this.env.previewBasePath;
		const location = headers.location?.[0];
		if (location && /^\/(?!\/)/.test(location) && location !== base && !location.startsWith(`${base}/`)) {
			headers.location = [base + location];
		}
		return { status: response.httpStatusCode, headers, body: response.bytes, durationMs: performance.now() - start };
	}

	/** Exécute un script de l'aperçu ; une erreur fatale PHP rend quand même sa page (ex. page d'erreur Symfony). */
	private async runPreview(options: PHPRunOptions, start: number): Promise<HttpResponse> {
		try {
			return this.toHttpResponse(await this.preview.run(options), start);
		} catch (error) {
			if (error instanceof PHPExecutionFailureError) return this.toHttpResponse(error.response, start);
			throw error;
		}
	}

	private clearTestCache(php: PhpInstance) {
		for (const dir of this.frameworkProfile().testCaches.map((d) => `${APP_DIR}/${d}`)) {
			if (php.isDir(dir)) php.rmdir(dir, { recursive: true });
			php.mkdir(dir);
		}
	}

	private async runPhpUnit(php: PhpInstance, paths: string[] = []): Promise<Omit<TestRunResult, 'durationMs'>> {
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
		const cases = report.cases.map((c) => (c.message ? { ...c, summary: phpunitSummary(c.message) } : c));
		return { ...report, cases, output: (text.slice(0, markerAt) + report.output).trim() };
	}

	/**
	 * Note les tests de l'apprenant (voir gradeOwnTests du contrat) : ajoute les cas « own-tests » et
	 * « mutant:<id> » au résultat. Chaque mutant est appliqué à l'instance de tests seulement, puis retiré.
	 */
	private async grade(php: PhpInstance, grading: Grading, result: Omit<TestRunResult, 'durationMs'>) {
		const { cases, report } = await gradeOwnTests(grading, result.cases, async (mutant) => {
			const originals = new Map<string, string>();
			for (const change of mutant.changes) {
				const path = `${APP_DIR}/${change.file}`;
				const code = php.readFileAsText(path);
				if (!originals.has(path)) originals.set(path, code);
				php.writeFile(path, code.split(change.search).join(change.replace));
			}
			this.clearTestCache(php);
			try {
				// Si PHPUnit s'arrête sans rapport (aucun cas), l'application est cassée : le mutant est détecté.
				return { cases: (await this.runPhpUnit(php, grading.ownTests)).cases };
			} finally {
				for (const [path, code] of originals) php.writeFile(path, code);
				this.clearTestCache(php);
			}
		}, {
			noOwnTest: 'Aucun de vos tests ne s\'est exécuté : écrivez au moins une méthode test…() dans votre classe de test.',
			skipped: '(incomplet ou ignoré)',
		});
		result.cases.push(...cases);
		if (report.length) result.output += `\n\nMutants (versions cassées de l'application) :\n${report.join('\n')}`;
	}

	/** Le contenu des fichiers du projet, par chemin relatif. */
	private instantane(php: PhpInstance): Map<string, string> {
		const fichiers = new Map<string, string>();
		const parcourir = (dossier: string) => {
			for (const nom of php.listFiles(dossier)) {
				const chemin = `${dossier}/${nom}`;
				if (php.isDir(chemin)) parcourir(chemin);
				else fichiers.set(chemin.slice(APP_DIR.length + 1), php.readFileAsText(chemin));
			}
		};
		for (const dossier of this.frameworkProfile().projectDirs) if (php.isDir(`${APP_DIR}/${dossier}`)) parcourir(`${APP_DIR}/${dossier}`);
		return fichiers;
	}

	/**
	 * Ce qu'une commande a créé, modifié ou supprimé dans l'aperçu est reporté sur les modifications
	 * de l'apprenant, et donc sur l'instance de tests : un fichier généré doit pouvoir être testé.
	 */
	private propagerLesChangements(avant: Map<string, string>, apres: Map<string, string>) {
		const fichiers: Record<string, string> = {};
		const supprimes: string[] = [];
		for (const [chemin, contenu] of apres) if (avant.get(chemin) !== contenu) fichiers[chemin] = contenu;
		for (const chemin of avant.keys()) if (!apres.has(chemin)) supprimes.push(chemin);
		if (!Object.keys(fichiers).length && !supprimes.length) return { fichiers, supprimes };

		const instanceDeTests = this.testsReady;
		for (const [chemin, contenu] of Object.entries(fichiers)) {
			const octets = encoder.encode(contenu);
			this.overlay.set(chemin, octets);
			if (instanceDeTests) {
				const absolu = `${APP_DIR}/${chemin}`;
				instanceDeTests.mkdir(absolu.slice(0, absolu.lastIndexOf('/')));
				instanceDeTests.writeFile(absolu, octets);
			}
		}
		for (const chemin of supprimes) {
			this.overlay.set(chemin, null);
			if (instanceDeTests?.fileExists(`${APP_DIR}/${chemin}`)) instanceDeTests.unlink(`${APP_DIR}/${chemin}`);
		}
		this.testsDirty = true;
		return { fichiers, supprimes };
	}

	async boot(spec: EnvironmentSpec, onProgress?: (p: BootProgress) => void) {
		const progress = (p: BootProgress) => onProgress?.(p);
		this.env = spec;
		// Le garde porte sur le runtime demandé, pas sur le nom du framework : ce runtime sert tous ceux
		// qui s'exécutent en PHP, quel que soit leur nombre, et aucun autre.
		if ('php-wasm' !== spec.framework.runtime) {
			throw new Error(`Un environnement « ${spec.framework.runtime} » ne se joue pas avec PHP (voir src/runtime/registry.ts).`);
		}
		this.profile = spec.framework;
		const frameworks = this.deps.frameworks ?? PHP_FRAMEWORKS;
		this.framework = (Object.hasOwn(frameworks, spec.framework.id) ? frameworks[spec.framework.id] : defaultPhpFramework)();
		const archive = await this.deps.download(spec.archiveUrl, progress);
		progress({ step: 'unpack', ratio: null, label: spec.framework.unpackLabel });
		this.baseFiles = new Map(
			Object.entries(unzipSync(archive)).filter(([path]) => !path.endsWith('/')),
		);
		progress({ step: 'boot', ratio: null, label: `Démarrage de PHP ${spec.options?.phpVersion ?? ''}` });
		this.preview = await this.createPhp();
		// Instance de tests préparée en arrière-plan : premier run plus rapide.
		this.prepareTests().catch(() => {}); // réessayée au premier lancement des tests
	}

	async writeFile(path: string, content: string) {
		const bytes = encoder.encode(content);
		this.overlay.set(path, bytes);
		this.testsDirty = true;
		const absolute = `${APP_DIR}/${path}`;
		for (const php of [this.preview, this.testsReady]) {
			if (!php) continue;
			php.mkdir(absolute.slice(0, absolute.lastIndexOf('/')));
			php.writeFile(absolute, bytes);
		}
	}

	async writeFiles(files: Record<string, string>) {
		for (const [path, content] of Object.entries(files)) await this.writeFile(path, content);
	}

	async readFile(path: string) {
		const absolute = `${APP_DIR}/${path}`;
		return this.preview.isFile(absolute) ? this.preview.readFileAsText(absolute) : null;
	}

	async listFiles() {
		// L'apprenant explore son projet, pas les dépendances ni les caches.
		const hidden = new Set(this.frameworkProfile().hidden);
		const files: string[] = [];
		const walk = (directory: string) => {
			for (const name of this.preview.listFiles(directory)) {
				const absolute = `${directory}/${name}`;
				if (hidden.has(absolute.slice(APP_DIR.length + 1)) || files.length > 3000) continue;
				if (this.preview.isDir(absolute)) walk(absolute);
				else files.push(absolute.slice(APP_DIR.length + 1));
			}
		};
		walk(APP_DIR);

		return files.sort();
	}

	async deleteFile(path: string) {
		this.overlay.set(path, null);
		this.testsDirty = true;
		const absolute = `${APP_DIR}/${path}`;
		for (const php of [this.preview, this.testsReady]) {
			if (php?.fileExists(absolute)) php.unlink(absolute);
		}
	}

	async request(req: HttpRequest): Promise<HttpResponse> {
		const start = performance.now();
		const url = new URL(req.url, 'http://preview.local');
		const env = this.env;
		if (this.framework.request) {
			const preview: PhpPreviewContext = {
				basePath: env.previewBasePath,
				cookieHeader: this.cookies.getCookieRequestHeader(),
				run: (options) => this.runPreview(options, start),
			};
			return this.framework.request(req, url, preview);
		}
		let relativePath: string;
		try {
			relativePath = decodeURIComponent(url.pathname.slice(env.previewBasePath.length)) || '/';
		} catch {
			// « /menu% » : un vrai serveur web refuse la requête avant PHP, avec une 400.
			return { status: 400, headers: { 'content-type': ['text/plain; charset=utf-8'] }, body: encoder.encode('400 Bad Request : URL mal encodée.'), durationMs: performance.now() - start };
		}

		// Fichier statique de public/ (CSS, images…) : servi sans passer par PHP.
		const staticPath = `${APP_DIR}/public${relativePath}`;
		if (!relativePath.endsWith('.php') && this.preview.isFile(staticPath)) {
			const body = this.preview.readFileAsBuffer(staticPath);
			return { status: 200, headers: { 'content-type': [inferMimeType(staticPath)] }, body, durationMs: performance.now() - start };
		}

		const cookieHeader = this.cookies.getCookieRequestHeader();
		const frontController = `${env.previewBasePath}/index.php`;
		return this.runPreview({
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
		}, start);
	}

	async runTests(grading?: Grading) {
		const start = performance.now();
		const php = await (this.tests ?? this.prepareTests());
		if (this.testsDirty) {
			this.testsDirty = false;
			this.clearTestCache(php);
		}
		const result = await this.runPhpUnit(php);
		if (grading?.ownTests.length) await this.grade(php, grading, result);
		return { ...result, durationMs: performance.now() - start };
	}

	async runCommand(args: string[]): Promise<CommandResult> {
		const start = performance.now();
		const avant = this.instantane(this.preview);
		let text: string;
		try {
			text = (await this.preview.run({ scriptPath: CONSOLE, $_SERVER: { CONSOLE_ARGS: JSON.stringify(args) } })).text;
		} catch (error) {
			if (!(error instanceof PHPExecutionFailureError)) throw error;
			text = `${error.response.text}\n${error.response.errors}`;
		}
		const markerAt = text.lastIndexOf(CONSOLE_MARKER);
		const exitCode = markerAt === -1 ? 255 : Number(text.slice(markerAt + CONSOLE_MARKER.length)) || 0;
		const { fichiers, supprimes } = this.propagerLesChangements(avant, this.instantane(this.preview));
		return { exitCode, output: (markerAt === -1 ? text : text.slice(0, markerAt)).replace(/\s+$/, ''), durationMs: performance.now() - start, fichiers, supprimes };
	}
}

/**
 * Comme writeTree, mais par tranches d'environ 50 ms, en rendant la main au worker entre deux : copier
 * un projet et son vendor/ prend plusieurs secondes, pendant lesquelles le worker ne répondait à rien
 * (ni aux écritures de l'éditeur, ni à l'aperçu, ni au battement de cœur de WorkerRuntime).
 */
async function writeTreeInSlices(php: PhpInstance, files: Map<string, Uint8Array | null>) {
	let started = performance.now();
	for (const entry of files) {
		writeTree(php, [entry]);
		if (performance.now() - started < 50) continue;
		await new Promise((resolve) => setTimeout(resolve));
		started = performance.now();
	}
}

function writeTree(php: PhpInstance, files: Iterable<[string, Uint8Array | null]>) {
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
