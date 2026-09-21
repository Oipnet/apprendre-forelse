import { WorkerRuntime } from '@forelse/runtime-contract';
import phpWorkerUrl from './php-worker.ts?worker&url';

/** Le runtime PHP : php-wasm dans un worker. */
export class WasmRuntime extends WorkerRuntime {
	constructor() {
		super(phpWorkerUrl, 'php');
	}
}
