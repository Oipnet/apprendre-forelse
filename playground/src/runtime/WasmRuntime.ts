import { createModuleWorker } from '../util/worker';
import type { BootProgress, CommandResult, EnvironmentSpec, Grading, HttpRequest, HttpResponse, Runtime, TestRunResult } from './Runtime';
import type { WorkerCall, WorkerMessage, WorkerMethod } from './protocol';
import phpWorkerUrl from './php-worker.ts?worker&url';

/** Côté page : délègue chaque appel à un worker (PHP, simulateur Nuxt…) par postMessage. */
export class WorkerRuntime implements Runtime {
	private readonly worker: Worker;
	private readonly pending = new Map<number, { resolve: (v: unknown) => void; reject: (e: Error) => void }>();
	private nextId = 1;
	private onProgress?: (p: BootProgress) => void;

	constructor(workerUrl: string, private readonly workerName: string) {
		this.worker = createModuleWorker(workerUrl, workerName);
		this.worker.addEventListener('message', (event: MessageEvent<WorkerMessage>) => {
			const message = event.data;
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
		this.worker.addEventListener('error', (event) => {
			const error = new Error(`Worker ${this.workerName} : ${event.message || 'échec du chargement'}`);
			console.error(error, event);
			for (const call of this.pending.values()) call.reject(error);
			this.pending.clear();
		});
	}

	private call<T>(method: WorkerMethod, ...args: unknown[]): Promise<T> {
		const id = this.nextId++;
		return new Promise<T>((resolve, reject) => {
			this.pending.set(id, { resolve: resolve as (v: unknown) => void, reject });
			this.worker.postMessage({ id, method, args } satisfies WorkerCall);
		});
	}

	boot(env: EnvironmentSpec, onProgress?: (p: BootProgress) => void) {
		this.onProgress = onProgress;
		return this.call<void>('boot', env);
	}

	writeFile(path: string, content: string) {
		return this.call<void>('writeFile', path, content);
	}

	readFile(path: string) {
		return this.call<string | null>('readFile', path);
	}

	listFiles() {
		return this.call<string[]>('listFiles');
	}

	deleteFile(path: string) {
		return this.call<void>('deleteFile', path);
	}

	request(request: HttpRequest) {
		return this.call<HttpResponse>('request', request);
	}

	runTests(grading?: Grading) {
		return this.call<TestRunResult>('runTests', grading);
	}

	runCommand(args: string[]) {
		return this.call<CommandResult>('runCommand', args);
	}
}

/** Le runtime PHP : php-wasm dans un worker. */
export class WasmRuntime extends WorkerRuntime {
	constructor() {
		super(phpWorkerUrl, 'php');
	}
}
