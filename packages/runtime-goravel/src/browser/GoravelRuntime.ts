import { WorkerRuntime } from '@forelse/runtime-contract';
import workerUrl from './goravel-worker.ts?worker&url';

/** Le runtime Goravel : le binaire Go dans un worker. Sans console : artisan n'est pas compilé dans le navigateur. */
export class GoravelRuntime extends WorkerRuntime {
	constructor() {
		super(workerUrl, 'goravel-wasm', { label: 'Goravel', commands: false });
	}
}
