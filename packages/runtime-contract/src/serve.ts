import { PING, type WorkerArgs, type WorkerCall, type WorkerMessage, type WorkerMethod, type WorkerResult } from './protocol';

/** Ce que le worker expose : chaque méthode du Runtime, avec ses arguments tels qu'ils voyagent. */
export type WorkerApi = { [M in WorkerMethod]: (...args: WorkerArgs[M]) => Promise<WorkerResult[M]> };

/** Le côté worker de postMessage : `self` dans un worker, une doublure dans les tests. */
export interface WorkerEndpoint {
	addEventListener(type: 'message', listener: (event: MessageEvent) => void): void;
	postMessage(message: WorkerMessage, transfer: Transferable[]): void;
}

export interface ServeOptions {
	/**
	 * Les corps de réponse que renvoie `request` sont des copies neuves, que le runtime ne garde pas :
	 * ils sont alors transférés à la page plutôt que copiés. À ne pas activer si une réponse peut porter un
	 * tableau que le runtime (ou le code de l'apprenant) conserve : le transfert le viderait.
	 */
	transferResponseBodies?: boolean;
}

/**
 * Sert un runtime dans un worker : le répartiteur que les workers PHP et Nuxt partagent.
 *
 * Les appels s'exécutent un par un, dans l'ordre d'arrivée. Traités en parallèle, ils se croisaient :
 * une écriture arrivée pendant la notation par mutants était écrasée par la restauration du code d'origine,
 * une frappe arrivée pendant une commande console passait pour une modification de la commande.
 * Le battement de cœur (PING) ne passe pas par cette file : il répond aussitôt, y compris pendant un long
 * run de tests, et c'est ce qui dit à la page que le worker est occupé plutôt que bloqué.
 */
export function serveRuntime(api: WorkerApi, options: ServeOptions = {}, endpoint: WorkerEndpoint = self as unknown as WorkerEndpoint): void {
	let queue = Promise.resolve();
	endpoint.addEventListener('message', (event: MessageEvent<WorkerCall | typeof PING>) => {
		const data = event.data;
		if (data === PING) return endpoint.postMessage({ type: 'pong' }, []);
		queue = queue.then(() => answer(data));
	});

	async function answer(call: WorkerCall) {
		try {
			const result = await invoke(call);
			const transfer = options.transferResponseBodies && call.method === 'request' ? transferable((result as WorkerResult['request']).body) : [];
			endpoint.postMessage({ type: 'result', id: call.id, result }, transfer);
		} catch (error) {
			endpoint.postMessage({ type: 'error', id: call.id, error: error instanceof Error ? `${error.message}\n${error.stack ?? ''}` : String(error) }, []);
		}
	}

	function invoke<M extends WorkerMethod>(call: WorkerCall<M>): Promise<WorkerResult[M]> {
		// Le message vient d'une autre page du même site, mais rien ne garantit qu'il nomme une vraie méthode.
		if (!Object.hasOwn(api, call.method) || typeof api[call.method] !== 'function') {
			return Promise.reject(new Error(`Méthode inconnue du runtime : ${String(call.method)}.`));
		}
		return api[call.method](...call.args);
	}
}

/**
 * Le tampon d'un corps, s'il peut être cédé : il doit lui appartenir en entier. Une vue sur la mémoire de
 * WebAssembly (ou sur un tampon plus grand) ne se transfère pas ; elle est copiée, comme avant.
 */
function transferable(body: Uint8Array): Transferable[] {
	const buffer = body.buffer;
	return buffer instanceof ArrayBuffer && body.byteOffset === 0 && body.byteLength === buffer.byteLength ? [buffer] : [];
}
