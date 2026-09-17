/**
 * Sauvegarde de la progression : sur le serveur pour un apprenant connecté,
 * dans le navigateur pour un invité (exercices `free` uniquement).
 */

import type { Review } from './mentor';

export interface SavedProgress {
	files: Record<string, string>;
	hintsUsed: number;
	completed: boolean;
	/** La solution a été consultée : l'exercice ne rapporte plus d'XP. */
	solutionRevealed?: boolean;
	/** Revue de code du mentor, conservée par le serveur. */
	review?: Review | null;
}

export interface CompletionResult {
	xpEarned: number;
	/** XP totale du compte (null pour un invité). */
	totalXp: number | null;
	alreadyCompleted: boolean;
}

export interface ProgressStore {
	load(): Promise<SavedProgress | null>;
	saveDraft(files: Record<string, string>, hintsUsed: number): Promise<void>;
	complete(hintsUsed: number): Promise<CompletionResult>;
	/** La solution de référence, contre l'XP de l'exercice ; null quand elle n'est pas accessible (invité). */
	revealSolution(): Promise<Record<string, string> | null>;
}

/** Même règle que App\Service\XpCalculator côté serveur : −25 % par indice, plancher à 25 %. */
export function xpFor(baseXp: number, hintsUsed: number): number {
	return Math.round(baseXp * Math.max(0.25, 1 - 0.25 * hintsUsed));
}

export class LocalProgressStore implements ProgressStore {
	constructor(
		private readonly key: string,
		private readonly baseXp: number,
	) {}

	private read(): SavedProgress | null {
		try {
			return JSON.parse(localStorage.getItem(this.key) ?? 'null') as SavedProgress | null;
		} catch {
			return null;
		}
	}

	private write(progress: SavedProgress) {
		try {
			localStorage.setItem(this.key, JSON.stringify(progress));
		} catch {
			/* stockage indisponible : on continue sans persistance */
		}
	}

	async load() {
		return this.read();
	}

	async saveDraft(files: Record<string, string>, hintsUsed: number) {
		this.write({ files, hintsUsed, completed: this.read()?.completed ?? false });
	}

	async complete(hintsUsed: number): Promise<CompletionResult> {
		const previous = this.read();
		this.write({ files: previous?.files ?? {}, hintsUsed, completed: true });
		return { xpEarned: previous?.completed ? 0 : xpFor(this.baseXp, hintsUsed), totalXp: null, alreadyCompleted: !!previous?.completed };
	}

	async revealSolution() {
		return null;
	}
}

export class ApiProgressStore implements ProgressStore {
	constructor(private readonly url: string) {}

	private async send(method: string, path = '', body?: unknown) {
		const response = await fetch(this.url + path, {
			method,
			headers: { accept: 'application/json', ...(body ? { 'content-type': 'application/json' } : {}) },
			body: body ? JSON.stringify(body) : undefined,
			credentials: 'same-origin',
		});
		if (response.status === 401) throw new Error('votre session a expiré : reconnectez-vous, puis rechargez la page (votre code reste dans l\'éditeur)');
		// Accès au parcours terminé (cohorte expirée, remboursement) : la progression reste sur le serveur.
		if (response.status === 403) throw new Error('votre accès à ce parcours a pris fin : la progression déjà enregistrée est conservée, et vous la retrouverez avec un nouvel accès');
		if (!response.ok) throw new Error(`Sauvegarde impossible (${response.status})`);
		return response.status === 204 ? null : response.json();
	}

	async load() {
		return (await this.send('GET')) as SavedProgress | null;
	}

	async saveDraft(files: Record<string, string>, hintsUsed: number) {
		await this.send('PUT', '', { files, hintsUsed });
	}

	async complete(hintsUsed: number) {
		return (await this.send('POST', '/complete', { hintsUsed })) as CompletionResult;
	}

	async revealSolution() {
		return ((await this.send('POST', '/solution')) as { files: Record<string, string> }).files;
	}
}
