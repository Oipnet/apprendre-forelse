import { WorkerRuntime } from '@forelse/runtime-contract';
import phpNetworkWorkerUrl from './php-network-worker.ts?worker&url';
import phpWorkerUrl from './php-worker.ts?worker&url';

/** Le runtime PHP : php-wasm dans un worker. Il sait lire les bases SQLite du projet (l'onglet « Base »). */
export class WasmRuntime extends WorkerRuntime {
	constructor() {
		super(phpWorkerUrl, 'php', { label: 'PHP', database: true });
	}
}

/**
 * Le même, dans le worker qui a le réseau vers les API d'IA (environnements qui déclarent `network:`). Il reçoit
 * la clé d'API de l'apprenant (setSecrets), que l'autre n'accepte pas.
 */
export class NetworkWasmRuntime extends WorkerRuntime {
	constructor() {
		super(phpNetworkWorkerUrl, 'php', { label: 'PHP', database: true, secrets: true });
	}
}
