import { WorkerRuntime } from './WasmRuntime';
import nuxtWorkerUrl from './nuxt-worker.ts?worker&url';

/** Le runtime Nuxt : le simulateur de tools/nuxt-sim dans un worker (rendu serveur, routes, modules de l'aperçu). */
export class NuxtRuntime extends WorkerRuntime {
	constructor() {
		super(nuxtWorkerUrl, 'nuxt-sim');
	}
}
