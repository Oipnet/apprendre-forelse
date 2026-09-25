import type { BootProgress, EnvironmentSpec, Grading, HttpRequest, Runtime, RuntimeRestart } from './runtime';
import { PING, type WorkerArgs, type WorkerCall, type WorkerMessage, type WorkerMethod, type WorkerResult } from './protocol';

/**
 * Crée un worker module à partir d'une URL obtenue par `import url from './x.ts?worker&url'`.
 *
 * En dev, le script est servi par Vite (autre origine que la page Symfony), et un Worker
 * doit être de même origine : on passe par un worker `blob:` qui importe le module.
 * En production, tout est servi par Symfony, sur une seule origine.
 */
export function createModuleWorker(url: string, name?: string): Worker {
	const absolute = new URL(url, import.meta.url);
	if (absolute.origin === location.origin) return new Worker(absolute, { type: 'module', name });
	const shim = new Blob([`import ${JSON.stringify(absolute.href)};`], { type: 'text/javascript' });
	return new Worker(URL.createObjectURL(shim), { type: 'module', name });
}

export interface WorkerRuntimeOptions {
	/** Nom donné à l'apprenant quand le runtime ne répond plus (« PHP », « Le simulateur Nuxt ») : il commence la phrase. */
	label?: string;
	/** Silence au-delà duquel un worker qui a un appel en cours est tenu pour bloqué. */
	silenceMs?: number;
	/** Intervalle du battement de cœur. */
	pingMs?: number;
	/** Fabrique du worker ; par défaut createModuleWorker (remplacée dans les tests). */
	spawn?: () => Worker;
}

/**
 * Côté page : délègue chaque appel à un worker (PHP, simulateur Nuxt…) par postMessage.
 *
 * Il surveille aussi le worker. Une boucle infinie dans le code de l'apprenant (un `while (true)` dans
 * une route) bloque le fil du worker tout entier, et `max_execution_time` n'y peut rien sous wasm (vérifié :
 * même un minuteur JS ne s'y déclenche plus). Sans surveillance, écritures, tests et commandes
 * attendraient sans fin. Tant qu'un appel est en cours, on envoie donc un PING chaque seconde : un worker
 * occupé mais vivant répond entre deux tâches, un worker bloqué se tait. Après `silenceMs` de silence,
 * on l'arrête, les appels en cours échouent avec un message qui le dit, et un worker neuf redémarre avec
 * les fichiers du projet. Les appels faits pendant ce temps attendent le nouveau worker.
 *
 * Un délai par appel ne suffirait pas : une écriture envoyée pendant une longue notation par mutants
 * attend son tour sans que rien ne soit bloqué.
 */
export class WorkerRuntime implements Runtime {
	private worker: Worker;
	private readonly pending = new Map<number, { resolve: (v: unknown) => void; reject: (e: Error) => void }>();
	private nextId = 1;
	private onProgress?: (p: BootProgress) => void;
	private readonly label: string;
	private readonly silenceMs: number;
	private readonly pingMs: number;
	private readonly spawn: () => Worker;
	/** L'environnement démarré : de quoi relancer un worker neuf. Absent tant que le premier boot n'a pas réussi. */
	private env?: EnvironmentSpec;
	/** Ce que la page a écrit (ou supprimé : null) depuis le démarrage, dans l'ordre : rejoué au redémarrage. */
	private readonly files = new Map<string, string | null>();
	/** Le redémarrage en cours : les nouveaux appels l'attendent. */
	private restarting: Promise<void> | null = null;
	private readonly restartListeners = new Set<(event: RuntimeRestart) => void>();
	/** Dernier signe de vie du worker (ou début de l'attente, si rien n'était en cours). */
	private lastSign = 0;
	private heartbeat?: ReturnType<typeof setInterval>;

	constructor(workerUrl: string, private readonly workerName: string, options: WorkerRuntimeOptions = {}) {
		this.label = options.label ?? workerName;
		this.silenceMs = options.silenceMs ?? 10_000;
		this.pingMs = options.pingMs ?? 1_000;
		this.spawn = options.spawn ?? (() => createModuleWorker(workerUrl, workerName));
		this.worker = this.start();
	}

	private start(): Worker {
		const worker = this.spawn();
		worker.addEventListener('message', (event: MessageEvent<WorkerMessage>) => {
			if (worker !== this.worker) return;
			this.lastSign = performance.now();
			const message = event.data;
			if (message.type === 'pong') return;
			if (message.type === 'progress') {
				this.onProgress?.(message.progress);
				return;
			}
			const call = this.pending.get(message.id);
			this.pending.delete(message.id);
			if (message.type === 'result') call?.resolve(message.result);
			else call?.reject(new Error(message.error));
		});
		// Échec de chargement du worker (import cassé…) : on échoue bruyamment plutôt que d'attendre.
		worker.addEventListener('error', (event) => {
			if (worker !== this.worker) return;
			const error = new Error(`Worker ${this.workerName} : ${event.message || 'échec du chargement'}`);
			console.error(error, event);
			this.rejectAll(error);
		});
		return worker;
	}

	private rejectAll(error: Error) {
		for (const call of this.pending.values()) call.reject(error);
		this.pending.clear();
	}

	/** Envoie l'appel au worker courant, sans attendre un redémarrage (réservé à celui-ci). */
	private send<M extends WorkerMethod>(method: M, args: WorkerArgs[M]): Promise<WorkerResult[M]> {
		const id = this.nextId++;
		return new Promise<WorkerResult[M]>((resolve, reject) => {
			// Rien n'était en cours : le silence se compte à partir de maintenant, pas du dernier message.
			if (this.pending.size === 0) this.lastSign = performance.now();
			this.pending.set(id, { resolve: resolve as (v: unknown) => void, reject });
			const call: WorkerCall<M> = { id, method, args };
			this.worker.postMessage(call);
		});
	}

	private async call<M extends WorkerMethod>(method: M, ...args: WorkerArgs[M]): Promise<WorkerResult[M]> {
		await this.restarting;
		return this.send(method, args);
	}

	/** Surveille le worker une fois démarré : le premier boot (téléchargement, décompression) n'est pas concerné. */
	private watch() {
		clearInterval(this.heartbeat);
		this.heartbeat = setInterval(() => {
			if (this.pending.size === 0 || this.restarting) return;
			if (performance.now() - this.lastSign > this.silenceMs) this.restart();
			else this.worker.postMessage(PING);
		}, this.pingMs);
	}

	private emit(event: RuntimeRestart) {
		for (const listener of this.restartListeners) listener(event);
	}

	private restart() {
		clearInterval(this.heartbeat);
		const seconds = Math.round(this.silenceMs / 1000);
		const reason = `${this.label} ne répond plus depuis ${seconds} s : une boucle infinie dans le code ? Il a été redémarré ; corrigez le code, puis relancez.`;
		this.worker.terminate();
		this.rejectAll(new Error(reason));
		this.worker = this.start();
		this.emit({ phase: 'restarting', reason });
		this.restarting = (async () => {
			try {
				await this.send('boot', [this.env!]);
				for (const [path, content] of this.files) {
					if (content !== null) await this.send('writeFile', [path, content]);
					// Un fichier créé puis supprimé n'existe pas dans le projet neuf : rien à retirer.
					else await this.send('deleteFile', [path]).catch(() => {});
				}
			} catch (error) {
				const message = error instanceof Error ? error.message.split('\n')[0] : String(error);
				this.emit({ phase: 'failed', error: message });
				throw new Error(`${this.label} n'a pas pu redémarrer (${message}) : rechargez la page.`);
			}
			this.restarting = null;
			this.watch();
			this.emit({ phase: 'restarted' });
		})();
		// Personne n'attend encore ce redémarrage : son échec éventuel est signalé par l'événement « failed ».
		this.restarting.catch(() => {});
	}

	/** Retient une écriture, pour la rejouer dans un worker neuf. L'ordre suit la dernière modification. */
	private remember(path: string, content: string | null) {
		this.files.delete(path);
		this.files.set(path, content);
	}

	onRestart(listener: (event: RuntimeRestart) => void) {
		this.restartListeners.add(listener);
	}

	async boot(env: EnvironmentSpec, onProgress?: (p: BootProgress) => void) {
		this.onProgress = onProgress;
		await this.call('boot', env);
		// Un redémarrage se fait en silence : la barre de progression n'est plus affichée.
		this.onProgress = undefined;
		this.env = env;
		this.watch();
	}

	writeFile(path: string, content: string) {
		this.remember(path, content);
		return this.call('writeFile', path, content);
	}

	readFile(path: string) {
		return this.call('readFile', path);
	}

	listFiles() {
		return this.call('listFiles');
	}

	deleteFile(path: string) {
		this.remember(path, null);
		return this.call('deleteFile', path);
	}

	request(request: HttpRequest) {
		return this.call('request', request);
	}

	runTests(grading?: Grading) {
		return this.call('runTests', grading);
	}

	async runCommand(args: string[]) {
		const result = await this.call('runCommand', args);
		// Les fichiers qu'une commande a générés (une migration…) doivent survivre à un redémarrage.
		for (const [path, content] of Object.entries(result.fichiers ?? {})) this.remember(path, content);
		for (const path of result.supprimes ?? []) this.remember(path, null);
		return result;
	}
}
