/**
 * Un projet Goravel : ses fichiers, et l'instance du binaire qui les sert.
 *
 * Le framework est compilé ; le code de l'apprenant (routes, contrôleurs, modèles, tests) est interprété au
 * démarrage d'une instance (go/learner). Modifier un fichier marque donc l'instance comme périmée : la requête
 * suivante en démarre une neuve, à partir du module WebAssembly déjà compilé (environ une seconde). La base
 * SQLite de l'aperçu vit dans les fichiers du projet : elle survit à ces redémarrages.
 *
 * Partagé par le worker du playground et par le lanceur de content:check (bin/tests.ts) : même code, même verdict.
 */
import { gradeOwnTests, type Grading, type HttpRequest, type HttpResponse, type TestCaseResult, type TestRunResult } from '@forelse/runtime-contract';
import { MemFS } from './memfs.ts';
import { ROOT, runTests, startServer, type GoravelServer, type RawTestRun } from './instance.ts';

/** Ce que l'explorateur ne montre pas : les journaux, les bases, le cache de l'interpréteur. */
const HIDDEN = /^(storage\/|vendor\/|\.git\/)/;

export interface ProjectOptions {
	/** Préfixe d'URL de l'aperçu (/preview/abc) : retiré avant le routeur, remis devant les redirections. */
	basePath?: string;
	/** Journaux de l'application (Gin, fmt.Println), pour le débogage. */
	onOutput?: (text: string) => void;
}

export class GoravelProject {
	readonly fs = new MemFS();
	private server: Promise<GoravelServer> | null = null;
	private readonly basePath: string;
	/**
	 * La clé de chiffrement de Goravel (APP_KEY), tirée au hasard pour ce projet : aucune clé n'est écrite dans
	 * le dépôt, et elle reste la même d'une instance à l'autre (cookies et sessions survivent aux redémarrages).
	 */
	private readonly env: Record<string, string>;

	constructor(
		private readonly module: WebAssembly.Module,
		files: Record<string, string | Uint8Array>,
		private readonly options: ProjectOptions = {},
	) {
		this.basePath = (options.basePath ?? '').replace(/\/+$/, '');
		const key = crypto.getRandomValues(new Uint8Array(16));
		this.env = { APP_KEY: Array.from(key, (b) => b.toString(16).padStart(2, '0')).join('') };
		for (const [path, content] of Object.entries(files)) this.fs.writeFile(`${ROOT}/${path}`, content);
		this.fs.mkdirp(`${ROOT}/storage/logs`);
	}

	writeFile(path: string, content: string): void {
		this.fs.writeFile(`${ROOT}/${path}`, content);
		this.stale();
	}

	readFile(path: string): string | null {
		const data = this.fs.readFile(`${ROOT}/${path}`);
		return data ? new TextDecoder().decode(data) : null;
	}

	deleteFile(path: string): void {
		this.fs.remove(`${ROOT}/${path}`);
		this.stale();
	}

	listFiles(): string[] {
		return this.fs.files(ROOT).filter((path) => !HIDDEN.test(path));
	}

	/** Démarre l'instance si besoin (première requête, ou fichiers modifiés depuis). */
	async start(): Promise<GoravelServer> {
		this.server ??= startServer(this.module, this.fs, this.env, this.options.onOutput);
		try {
			return await this.server;
		} catch (error) {
			this.server = null;
			throw error;
		}
	}

	async request(request: HttpRequest): Promise<HttpResponse> {
		const server = await this.start();
		let url = request.url;
		if (this.basePath && (url === this.basePath || url.startsWith(`${this.basePath}/`) || url.startsWith(`${this.basePath}?`))) {
			url = url.slice(this.basePath.length) || '/';
			if (url.startsWith('?')) url = `/${url}`;
		}
		const response = await server.fetch(request.method, url, request.headers, request.body);
		const headers = { ...response.headers };
		// Une redirection vers /x quitterait l'aperçu : on la ramène sous son préfixe.
		for (const name of Object.keys(headers).filter((h) => h.toLowerCase() === 'location')) {
			headers[name] = headers[name].map((location) => (location.startsWith('/') && !location.startsWith('//') && this.basePath && !location.startsWith(`${this.basePath}/`) ? this.basePath + location : location));
		}
		return { status: response.status, headers, body: response.body, durationMs: response.durationMs };
	}

	/** Les tests du projet, sur une copie de ses fichiers : ils ne touchent ni la base ni l'instance de l'aperçu. */
	async runTests(grading?: Grading): Promise<TestRunResult> {
		const started = performance.now();
		const run = await this.testRun(this.fs.clone());
		const result = toResult(run);
		if (grading?.ownTests.length) {
			const outcome = await gradeOwnTests(grading, result.cases, async (mutant) => {
				const fs = this.fs.clone();
				for (const change of mutant.changes) {
					const file = `${ROOT}/${change.file}`;
					const current = fs.readFile(file);
					fs.writeFile(file, (current ? new TextDecoder().decode(current) : '').split(change.search).join(change.replace));
				}
				const mutated = await this.testRun(fs);
				const own = toResult(mutated).cases.filter((c) => c.file && grading.ownTests.includes(c.file));
				return { cases: own, broken: Boolean(mutated.error) };
			}, { noOwnTest: 'Aucun de vos tests ne s\'est exécuté.', skipped: '(ignoré)' });
			result.cases.push(...outcome.cases);
			if (outcome.report.length) result.output += `\n\n${outcome.report.join('\n')}`;
		}
		result.durationMs = performance.now() - started;
		return result;
	}

	/** Arrête l'instance courante : la prochaine requête relira les fichiers. */
	stale(): void {
		const previous = this.server;
		this.server = null;
		previous?.then((server) => server.exit(), () => undefined);
	}

	private testRun(fs: MemFS): Promise<RawTestRun> {
		return runTests(this.module, fs, this.env);
	}
}

/** Le résultat du contrat, à partir de la sortie de `go test -v` : un message par test en échec. */
export function toResult(run: RawTestRun): TestRunResult {
	const lines = run.output.split('\n').filter((line) => !line.startsWith('[HTTP]') && !/^\s*\[GIN/.test(line));
	const messages = new Map<string, string[]>();
	let current: string | null = null;
	for (const line of lines) {
		const start = /^=== (?:RUN|CONT|PAUSE)\s+(\S+)/.exec(line);
		if (start) {
			current = start[1].split('/')[0];
			continue;
		}
		if (/^\s*--- (PASS|FAIL|SKIP):/.test(line)) continue;
		if (current && /^\s{4,}\S/.test(line)) {
			const list = messages.get(current) ?? [];
			list.push(line.trim());
			messages.set(current, list);
		}
	}
	const cases: TestCaseResult[] = run.cases.map((c) => {
		const message = messages.get(c.name)?.join('\n');
		return {
			className: c.file ? c.file.replace(/\/[^/]+$/, '') : '',
			name: c.name,
			file: c.file || undefined,
			status: c.status,
			message: c.status === 'passed' ? undefined : (message || (c.status === 'error' ? 'Le test ne s\'est pas terminé.' : undefined)),
			summary: c.status === 'passed' || !message ? undefined : summarize(message),
			timeMs: Math.round(c.timeMs),
		};
	});
	const failed = cases.filter((c) => c.status === 'failed' || c.status === 'error').length;
	const passed = cases.filter((c) => c.status === 'passed').length;
	let output = lines.join('\n').trimEnd();
	if (run.error) output = `${output ? `${output}\n` : ''}${run.error}\nFAIL`;
	else output += `\n${failed || cases.length === 0 ? 'FAIL' : 'PASS'}  (${passed} réussi${passed > 1 ? 's' : ''}, ${failed} en échec)`;
	return {
		exitCode: run.error || failed || cases.length === 0 ? 1 : 0,
		cases,
		output: output.trim(),
		durationMs: 0,
	};
}

/**
 * Ce qu'on montre sous un objectif manqué : le message de l'assertion, sans la trace de testify.
 * testify écrit « Error Trace: … / Error: … / Messages: … ». Les assertions de Goravel (AssertSee, AssertJson…)
 * mettent leur phrase dans Messages, et dans Error la réponse entière : on préfère Messages.
 */
function summarize(message: string): string {
	// Plusieurs assertions peuvent échouer dans le même test : la première dit ce qui ne va pas.
	message = message.split(/\n(?=\S+\.go:\d+:)/)[0];
	const messages = /Messages:\s+([\s\S]*)$/.exec(message);
	const error = /Error:\s+([\s\S]*?)(?:\n\s*(?:Test|Messages):|$)/.exec(message);
	const text = messages?.[1] ?? error?.[1] ?? message.replace(/^\S+_test\.go:\d+:\s*/gm, '');
	const clean = text.trim();
	return clean.length > 400 ? `${clean.slice(0, 400)}…` : clean;
}
