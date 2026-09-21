import type { BootProgress, Runtime } from './runtime';

/** Méthodes exposées par le worker PHP : exactement celles du Runtime. */
export type WorkerMethod = keyof Runtime;

export interface WorkerCall {
	id: number;
	method: WorkerMethod;
	args: unknown[];
}

export type WorkerMessage =
	| { type: 'result'; id: number; result: unknown }
	| { type: 'error'; id: number; error: string }
	| { type: 'progress'; progress: BootProgress };
