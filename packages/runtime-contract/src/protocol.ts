import type { BootProgress, Runtime } from './runtime';

/** Méthodes exposées par le worker PHP : exactement celles du Runtime (sauf l'abonnement aux redémarrages, local à la page). */
export type WorkerMethod = Exclude<keyof Runtime, 'onRestart'>;

/** Les arguments de chaque méthode, tels qu'ils voyagent (sans fonction : le suivi du boot passe par des messages). */
export type WorkerArgs = { [M in WorkerMethod]: Parameters<Runtime[M]> };

/** Le résultat de chaque méthode, une fois la promesse résolue. */
export type WorkerResult = { [M in WorkerMethod]: Awaited<ReturnType<Runtime[M]>> };

/** Un appel : la méthode et ses arguments vont ensemble, ce qui permet de l'exécuter sans transtypage. */
export type WorkerCall<M extends WorkerMethod = WorkerMethod> = { [K in M]: { id: number; method: K; args: WorkerArgs[K] } }[M];

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
