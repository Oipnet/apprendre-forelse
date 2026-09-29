import type { Runtime } from '@forelse/runtime-contract';
import { xpFor, type CompletionResult, type ProgressStore } from './progress';

export interface SessionState {
	/** Les fichiers modifiables, contenu à jour, par chemin. */
	files: Record<string, string>;
	hintsUsed: number;
	solutionRevealed: boolean;
	completed: boolean;
}

export interface SessionOptions {
	/** Délai avant d'écrire les frappes dans le runtime. */
	writeDelayMs?: number;
	/** Délai avant d'enregistrer le brouillon. */
	saveDelayMs?: number;
	/** Des frappes viennent d'être écrites dans le runtime (l'aperçu se recharge). */
	onWritten?: () => void;
	/** L'enregistrement différé du brouillon a échoué (session expirée, accès terminé…) : à dire à l'apprenant. */
	onDraftError?: (error: Error) => void;
}

/**
 * La session d'exercice, sans DOM : le code de l'apprenant, ses indices, la solution consultée, la
 * réussite. Elle écrit les frappes dans le runtime et le brouillon dans le ProgressStore, chacun avec
 * son délai.
 */
export class ExerciseSession {
	/** Le code de l'apprenant : tous les fichiers modifiables, ouverts ou non. */
	readonly current: Record<string, string>;
	hintsUsed: number;
	solutionRevealed: boolean;
	completed: boolean;
	/**
	 * Écritures vers le runtime en attente, par fichier : un debounce global perdrait la
	 * modification d'un fichier quand un autre change juste après.
	 */
	private readonly pending = new Map<string, string>();
	/** L'écriture en cours vers le runtime : un flush qui arrive pendant ce temps l'attend. */
	private flushing: Promise<void> | undefined;
	private writeTimer: ReturnType<typeof setTimeout> | undefined;
	private saveTimer: ReturnType<typeof setTimeout> | undefined;
	private readonly writeDelayMs: number;
	private readonly saveDelayMs: number;

	constructor(
		private readonly runtime: Pick<Runtime, 'writeFiles'>,
		private readonly progress: ProgressStore,
		state: SessionState,
		private readonly options: SessionOptions = {},
	) {
		this.current = state.files;
		this.hintsUsed = state.hintsUsed;
		this.solutionRevealed = state.solutionRevealed;
		this.completed = state.completed;
		this.writeDelayMs = options.writeDelayMs ?? 400;
		this.saveDelayMs = options.saveDelayMs ?? 1500;
	}

	/** Une frappe dans l'éditeur : écrite dans le runtime et dans le brouillon après leur délai. */
	edit(path: string, content: string): void {
		this.current[path] = content;
		this.pending.set(path, content);
		clearTimeout(this.writeTimer);
		this.writeTimer = setTimeout(() => {
			this.flush()
				.then((changed) => changed && this.options.onWritten?.())
				// Runtime redémarré en cours d'écriture : il a retenu le lot et le rejoue dans le worker neuf. Autre échec :
				// le lot reste en attente, et part avec l'écriture suivante.
				.catch((error) => console.warn('Écriture différée vers le runtime', error));
		}, this.writeDelayMs);
		this.saveDraft();
	}

	/**
	 * Écrit tout de suite les frappes en attente ; vrai si quelque chose a été écrit. Une écriture déjà en cours est
	 * attendue d'abord : sans cela, « Lancer les tests » partirait avant elle, sur l'ancien code.
	 */
	async flush(): Promise<boolean> {
		clearTimeout(this.writeTimer);
		let written = false;
		while (this.flushing) written = (await this.flushing.then(() => true, () => false)) || written;
		if (this.pending.size === 0) return written;

		// Tout le lot en un appel : le runtime le retient en entier, et le rejoue en entier après un redémarrage.
		const batch = Object.fromEntries(this.pending);
		this.pending.clear();
		this.flushing = this.runtime
			.writeFiles(batch)
			.catch((error: unknown) => {
				// Refusé : le lot reste en attente, sauf un fichier modifié depuis (sa version plus récente l'emporte).
				for (const [path, content] of Object.entries(batch)) if (!this.pending.has(path)) this.pending.set(path, content);
				throw error;
			})
			.finally(() => {
				this.flushing = undefined;
			});
		await this.flushing;
		return true;
	}

	/** Enregistre le brouillon tout de suite (Ctrl+S), sans attendre son délai ; l'échec remonte à l'appelant. */
	async saveNow(): Promise<void> {
		clearTimeout(this.saveTimer);
		this.saveTimer = undefined;
		await this.progress.saveDraft(this.current, this.hintsUsed);
	}

	/** Enregistre le brouillon après son délai (les appels rapprochés n'en font qu'un) ; un échec est signalé. */
	saveDraft(): void {
		clearTimeout(this.saveTimer);
		this.saveTimer = setTimeout(() => {
			this.saveTimer = undefined;
			this.progress.saveDraft(this.current, this.hintsUsed).catch((error: unknown) => {
				console.warn(error);
				this.options.onDraftError?.(error instanceof Error ? error : new Error(String(error)));
			});
		}, this.saveDelayMs);
	}

	/**
	 * La page se ferme (pagehide) : le brouillon qui attendait son délai part tout de suite, sans quoi les dernières
	 * frappes seraient perdues. La requête doit survivre à la page (voir ProgressStore.saveDraftOnExit).
	 */
	saveOnExit(): void {
		if (this.saveTimer === undefined) return;
		clearTimeout(this.saveTimer);
		this.saveTimer = undefined;
		if (this.progress.saveDraftOnExit) this.progress.saveDraftOnExit(this.current, this.hintsUsed);
		else void this.progress.saveDraft(this.current, this.hintsUsed).catch(() => {});
	}

	/** Ce qu'un indice de plus coûterait en XP (0 une fois réussi ou la solution consultée). */
	hintCost(baseXp: number): number {
		if (this.completed || this.solutionRevealed) return 0;
		return xpFor(baseXp, this.hintsUsed) - xpFor(baseXp, this.hintsUsed + 1);
	}

	/** Un indice de plus : retenu dans le brouillon. */
	useHint(): void {
		this.hintsUsed++;
		this.saveDraft();
	}

	/** La réussite : le code qui a réussi est enregistré d'abord (le brouillon attendrait sinon son délai). */
	async complete(): Promise<CompletionResult> {
		clearTimeout(this.saveTimer);
		this.saveTimer = undefined;
		await this.progress.saveDraft(this.current, this.hintsUsed);
		const result = await this.progress.complete(this.hintsUsed);
		this.completed = true;
		return result;
	}

	/** La solution de référence ; null quand elle n'est pas accessible. */
	async revealSolution(): Promise<Record<string, string> | null> {
		const files = await this.progress.revealSolution();
		if (files) this.solutionRevealed = true;
		return files;
	}
}
