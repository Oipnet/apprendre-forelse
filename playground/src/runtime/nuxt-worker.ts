/**
 * Worker Nuxt : le « serveur » de l'aperçu pour les parcours Nuxt. Il tient les fichiers du projet et
 * répond aux requêtes de l'aperçu avec le simulateur (tools/nuxt-sim) : pages rendues côté serveur,
 * modules qui les hydratent dans l'iframe, routes de server/ ; et lance les tests Vitest de l'exercice.
 */
import { unzipSync } from 'fflate';
import clientBundle from 'virtual:nuxt-sim-client';
import { NuxtSimulator } from '../../../tools/nuxt-sim/src/index.ts';
import type { BootProgress, CommandResult, EnvironmentSpec, Grading, HttpRequest, HttpResponse, Runtime, TestRunResult } from './Runtime';
import type { WorkerCall, WorkerMessage } from './protocol';

let simulator: NuxtSimulator | undefined;

const progress = (p: BootProgress) => postMessage({ type: 'progress', progress: p } satisfies WorkerMessage);

function ready(): NuxtSimulator {
	if (!simulator) throw new Error('Simulateur Nuxt non démarré.');
	return simulator;
}

const api: Runtime = {
	async boot(env: EnvironmentSpec) {
		progress({ step: 'download', ratio: null, label: 'Téléchargement du projet Nuxt…' });
		const response = await fetch(env.archiveUrl);
		if (!response.ok) throw new Error(`Archive de l'environnement introuvable (${response.status}).`);
		progress({ step: 'unpack', ratio: null, label: 'Ouverture du projet…' });
		const decoder = new TextDecoder();
		const files = Object.fromEntries(
			Object.entries(unzipSync(new Uint8Array(await response.arrayBuffer())))
				.filter(([path]) => !path.endsWith('/'))
				.map(([path, content]) => [path, decoder.decode(content)]),
		);
		progress({ step: 'boot', ratio: null, label: 'Démarrage du simulateur Nuxt…' });
		simulator = new NuxtSimulator(files, { clientBundle, baseURL: `${env.previewBasePath}/` });
	},

	async writeFile(path, content) {
		ready().writeFile(path, content);
	},

	async readFile(path) {
		return ready().readFile(path);
	},

	async listFiles() {
		return ready().listFiles();
	},

	async deleteFile(path) {
		ready().deleteFile(path);
	},

	async request(request: HttpRequest): Promise<HttpResponse> {
		const response = await ready().request(request);
		return { status: response.status, headers: response.headers, body: response.body, durationMs: response.durationMs };
	},

	async runTests(grading?: Grading): Promise<TestRunResult> {
		return ready().runTests(grading);
	},

	async runCommand(args: string[]): Promise<CommandResult> {
		return { exitCode: 1, output: `nuxi ${args.join(' ')} : la console n'est pas encore disponible dans le simulateur Nuxt.`, durationMs: 0 };
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
