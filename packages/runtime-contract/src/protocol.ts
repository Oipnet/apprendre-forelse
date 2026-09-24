import type { BootProgress, Runtime } from './runtime';

/** Méthodes exposées par le worker PHP : exactement celles du Runtime (sauf l'abonnement aux redémarrages, local à la page). */
export type WorkerMethod = Exclude<keyof Runtime, 'onRestart'>;

export interface WorkerCall {
	id: number;
	method: WorkerMethod;
	args: unknown[];
}

/**
 * Le battement de cœur : tant qu'un appel attend, la page l'envoie chaque seconde, et le worker répond
 * `pong` aussitôt, avant tout `await`. Un worker coincé dans une boucle infinie ne répond plus : c'est
 * ainsi que WorkerRuntime le distingue d'un worker simplement occupé.
 */
export const PING = 'forelse:ping';

export type WorkerMessage =
	| { type: 'result'; id: number; result: unknown }
	| { type: 'error'; id: number; error: string }
	| { type: 'progress'; progress: BootProgress }
	| { type: 'pong' };
