/**
 * Le répartiteur des workers (serveRuntime) : un appel à la fois, dans l'ordre d'arrivée, sauf le
 * battement de cœur, qui répond aussitôt.
 */
import { describe, expect, it } from 'vitest';
import { PING, serveRuntime, type HttpResponse, type WorkerApi, type WorkerCall, type WorkerEndpoint, type WorkerMessage } from '@forelse/runtime-contract';

const attendre = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/** Le côté worker de postMessage, branché sur un tableau de messages. */
function endpoint() {
	const target = new EventTarget();
	const messages: { message: WorkerMessage; transfer: Transferable[] }[] = [];
	const e: WorkerEndpoint = {
		addEventListener: (type, listener) => target.addEventListener(type, listener as EventListener),
		postMessage: (message, transfer) => void messages.push({ message, transfer }),
	};
	let id = 1;
	const envoyer = (data: Omit<WorkerCall, 'id'> | typeof PING) => {
		target.dispatchEvent(new MessageEvent('message', { data: data === PING ? PING : { ...data, id: id++ } }));
	};
	const reponses = () => messages.map((m) => m.message).filter((m) => m.type === 'result' || m.type === 'error');
	return { e, messages, envoyer, reponses };
}

/** Un runtime minimal : des fichiers, et une notation par mutants qui modifie puis restaure le code. */
function runtime(body: () => Uint8Array = () => new Uint8Array([1, 2, 3])): WorkerApi {
	const fichiers = new Map<string, string>();
	const ok = async () => {};
	return {
		boot: ok,
		async writeFile(path, content) {
			fichiers.set(path, content);
		},
		async writeFiles(files) {
			for (const [path, content] of Object.entries(files)) fichiers.set(path, content);
		},
		async readFile(path) {
			return fichiers.get(path) ?? null;
		},
		async listFiles() {
			return [...fichiers.keys()];
		},
		async deleteFile(path) {
			fichiers.delete(path);
		},
		async request(): Promise<HttpResponse> {
			return { status: 200, headers: {}, body: body(), durationMs: 1 };
		},
		// Comme php-worker : le code d'origine est capturé, un mutant appliqué, puis l'original restauré.
		async runTests() {
			const original = fichiers.get('src/Menu.php');
			fichiers.set('src/Menu.php', 'mutant');
			await attendre(30);
			fichiers.set('src/Menu.php', original!);
			return { exitCode: 0, cases: [], output: '', durationMs: 30 };
		},
		async runCommand() {
			return { exitCode: 0, output: '', durationMs: 0 };
		},
	};
}

describe('serveRuntime', () => {
	it('traite les appels un par un : une frappe pendant les mutants n\'est pas écrasée', async () => {
		const { e, envoyer, reponses } = endpoint();
		serveRuntime(runtime(), {}, e);
		envoyer({ method: 'writeFile', args: ['src/Menu.php', 'v1'] });
		envoyer({ method: 'runTests', args: [] });
		// L'apprenant tape pendant la notation.
		envoyer({ method: 'writeFile', args: ['src/Menu.php', 'v2'] });
		envoyer({ method: 'readFile', args: ['src/Menu.php'] });
		await attendre(80);
		expect(reponses().map((m) => m.type === 'result' && m.id)).toEqual([1, 2, 3, 4]);
		expect(reponses()[3]).toMatchObject({ type: 'result', result: 'v2' });
	});

	it('répond au battement de cœur pendant un appel long', async () => {
		const { e, envoyer, messages } = endpoint();
		serveRuntime(runtime(), {}, e);
		envoyer({ method: 'writeFile', args: ['src/Menu.php', 'v1'] });
		envoyer({ method: 'runTests', args: [] });
		await attendre(5);
		envoyer(PING);
		expect(messages.at(-1)?.message).toEqual({ type: 'pong' });
		expect(messages.some((m) => m.message.type === 'result' && m.message.id === 2)).toBe(false);
	});

	it('refuse une méthode inconnue, sans bloquer la suite', async () => {
		const { e, envoyer, reponses } = endpoint();
		serveRuntime(runtime(), {}, e);
		envoyer({ method: 'toString', args: [] } as never);
		envoyer({ method: 'listFiles', args: [] });
		await attendre(10);
		expect(reponses()[0]).toMatchObject({ type: 'error', error: expect.stringMatching(/^Méthode inconnue du runtime : toString/) });
		expect(reponses()[1]).toMatchObject({ type: 'result', result: [] });
	});

	it('transfère le corps d\'une réponse seulement si le runtime le cède, et s\'il occupe tout son tampon', async () => {
		const corps = async (options: Parameters<typeof serveRuntime>[1], body?: () => Uint8Array) => {
			const { e, envoyer, messages } = endpoint();
			serveRuntime(runtime(body), options, e);
			envoyer({ method: 'request', args: [{ method: 'GET', url: '/', headers: {} }] });
			await attendre(5);
			return messages[0].transfer;
		};
		expect(await corps({ transferResponseBodies: true })).toHaveLength(1);
		expect(await corps({})).toEqual([]);
		// Une vue sur un tampon plus grand (la mémoire de WebAssembly, par exemple) est copiée.
		expect(await corps({ transferResponseBodies: true }, () => new Uint8Array(64).subarray(8, 16))).toEqual([]);
	});
});
