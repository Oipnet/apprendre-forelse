import { WorkerRuntime } from '@forelse/runtime-contract';
import phpWorkerUrl from './php-worker.ts?worker&url';

/** Le runtime PHP : php-wasm dans un worker. Il sait lire les bases SQLite du projet (l'onglet « Base »). */
export class WasmRuntime extends WorkerRuntime {
	constructor() {
		super(phpWorkerUrl, 'php', { label: 'PHP', database: true });
	}
}
