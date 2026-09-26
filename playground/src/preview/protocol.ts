import type { HttpRequest } from '@forelse/runtime-contract';

/** Messages du relais (bac à sable) vers la plateforme. */
export type RelayToHost =
	| { type: 'ready'; base: string }
	| { type: 'navigated'; path: string }
	| { type: 'preview-request'; request: HttpRequest }
	/** Avertissement ou erreur affiché dans la console de la page de l'aperçu (hydratation, [Vue warn]…). */
	| { type: 'preview-console'; level: 'warn' | 'error'; message: string }
	| { type: 'error'; message: string }
	/** Réponse au battement de cœur : le relais, donc la page de l'aperçu qui partage son fil, répond encore. */
	| { type: 'pong'; seq: number };

/** Messages de la plateforme vers le relais. */
export type HostToRelay = { type: 'navigate'; path: string } | { type: 'ping'; seq: number };
