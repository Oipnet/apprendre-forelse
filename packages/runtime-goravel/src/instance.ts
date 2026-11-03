/**
 * Une instance du binaire Goravel (go/main.go) : le module WebAssembly compilé une fois, instancié à chaque
 * état du code de l'apprenant.
 *
 * Le Go compilé pour js/wasm lit trois objets sur globalThis à son initialisation : `fs` (le système de
 * fichiers, à l'API de Node), `process` (cwd, chdir) et `path` (resolve). On les pose le temps du démarrage
 * synchrone de l'instance, puis on remet ceux d'avant : Go a gardé ses références, et deux instances (l'aperçu
 * et les tests) peuvent vivre côte à côte, chacune avec ses fichiers. Sous Node, le vrai `process` n'est
 * donc remplacé que pendant cet instant.
 */
import './wasm_exec.js';
import { MemFS, normalize } from './memfs.ts';

/** La racine du projet : GOPATH/src/goravel (voir go/learner/learner.go). */
export const ROOT = '/go/src/goravel';

interface GoInstance {
	argv: string[];
	env: Record<string, string>;
	importObject: WebAssembly.Imports;
	run(instance: WebAssembly.Instance): Promise<void>;
}

declare const Go: new () => GoInstance;

export interface RawResponse {
	status: number;
	headers: Record<string, string[]>;
	body: Uint8Array;
	durationMs: number;
}

/** Une instance en mode « serve » : l'application démarrée, qui répond aux requêtes. */
export interface GoravelServer {
	fetch(method: string, url: string, headers: Record<string, string>, body?: Uint8Array): Promise<RawResponse>;
	/** Le code de l'apprenant ne démarre pas (erreur de compilation, panique) : chaque requête rend cette erreur. */
	error?: string;
	exit(): void;
}

export interface RawTestCase {
	name: string;
	file: string;
	status: 'passed' | 'failed' | 'skipped' | 'error';
	timeMs: number;
}

/** Une instance en mode « test » : ce qu'ont donné les fonctions TestXxx, et la sortie de `go test -v`. */
export interface RawTestRun {
	cases: RawTestCase[];
	error?: string;
	output: string;
}

interface Host {
	ready?(api: unknown): void;
	done?(result: unknown): void;
	fail(message: string): void;
}

let instances = 0;

/** Démarre l'application : rend quand Goravel est prêt à servir. */
export function startServer(module: WebAssembly.Module, fs: MemFS, env: Record<string, string> = {}, onOutput?: (text: string) => void): Promise<GoravelServer> {
	return new Promise((resolve, reject) => {
		fs.onOutput = (_fd, text) => onOutput?.(text);
		launch(module, fs, 'serve', env, {
			ready: (api) => {
				const server = api as { fetch: GoravelServer['fetch']; exit(): void; error?: string };
				resolve({ fetch: (...args) => server.fetch(...args), exit: () => server.exit(), error: server.error });
			},
			fail: (message) => reject(new Error(message)),
		}).catch(reject);
	});
}

/** Lance les tests du projet, sur ces fichiers : l'instance s'arrête ensuite. */
export function runTests(module: WebAssembly.Module, fs: MemFS, env: Record<string, string> = {}): Promise<RawTestRun> {
	return new Promise((resolve, reject) => {
		let output = '';
		fs.onOutput = (_fd, text) => {
			// Une sortie de tests qui ne finit pas (un test qui écrit en boucle) ne doit pas saturer la mémoire.
			if (output.length < 1_000_000) output += text;
		};
		launch(module, fs, 'test', env, {
			done: (result) => {
				const raw = result as { cases: RawTestCase[]; error?: string };
				resolve({ cases: [...raw.cases], error: raw.error, output });
			},
			fail: (message) => reject(new Error(message)),
		}).catch(reject);
	});
}

async function launch(module: WebAssembly.Module, fs: MemFS, mode: 'serve' | 'test', env: Record<string, string>, host: Host): Promise<void> {
	const key = `__goravelHost${++instances}`;
	const go = new Go();
	go.argv = ['goravel', mode];
	// Les variables d'environnement du processus passent avant le .env du projet (viper, AutomaticEnv).
	go.env = { ...env, GORAVEL_HOST: key, HOME: '/tmp', TMPDIR: '/tmp' };
	fs.mkdirp('/tmp');
	const instance = await WebAssembly.instantiate(module, go.importObject);

	const g = globalThis as Record<string, unknown>;
	const saved = { fs: g.fs, process: g.process, path: g.path };
	let cwd = ROOT;
	g[key] = host;
	fs.cwd = () => cwd;
	g.fs = fs;
	g.process = {
		getuid: () => -1,
		getgid: () => -1,
		geteuid: () => -1,
		getegid: () => -1,
		getgroups: () => [],
		pid: -1,
		ppid: -1,
		umask: () => 0o022,
		cwd: () => cwd,
		chdir: (path: string) => {
			cwd = normalize(path.startsWith('/') ? path : `${cwd}/${path}`);
		},
	};
	g.path = { resolve: (...segments: string[]) => normalize(segments.reduce((acc, s) => (s.startsWith('/') ? s : `${acc}/${s}`), cwd)) };
	let exited: Promise<void>;
	try {
		// La partie synchrone : initialisation des paquets Go, jusqu'à la première attente (un accès disque).
		exited = go.run(instance);
	} finally {
		g.fs = saved.fs;
		g.process = saved.process;
		g.path = saved.path;
	}
	try {
		await exited;
	} finally {
		delete g[key];
	}
	host.fail('le programme Go s\'est arrêté sans répondre');
}
