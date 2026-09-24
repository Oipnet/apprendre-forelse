import { WorkerRuntime } from '@forelse/runtime-contract';
import workerUrl from './nuxt-worker.ts?worker&url';

/** Le runtime Nuxt : le simulateur dans un worker (rendu serveur, routes de server/, modules de l'aperçu). */
export class NuxtRuntime extends WorkerRuntime {
	constructor() {
		super(workerUrl, 'nuxt-sim', { label: 'Le simulateur Nuxt' });
	}
}
