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
	private writeTimer: ReturnType<typeof setTimeout> | undefined;
	private saveTimer: ReturnType<typeof setTimeout> | undefined;
	private readonly writeDelayMs: number;
	private readonly saveDelayMs: number;

	constructor(
		private readonly runtime: Pick<Runtime, 'writeFile'>,
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
				// Runtime redémarré en cours d'écriture : l'écriture est rejouée par le runtime neuf, rien n'est perdu.
				.catch((error) => console.warn('Écriture différée vers le runtime', error));
		}, this.writeDelayMs);
		this.saveDraft();
	}

	/** Écrit tout de suite les frappes en attente ; vrai s'il y en avait. */
	async flush(): Promise<boolean> {
		clearTimeout(this.writeTimer);
		const changes = [...this.pending];
		this.pending.clear();
		for (const [path, content] of changes) await this.runtime.writeFile(path, content);
		return changes.length > 0;
	}

	/** Enregistre le brouillon après son délai (les appels rapprochés n'en font qu'un). */
	saveDraft(): void {
		clearTimeout(this.saveTimer);
		this.saveTimer = setTimeout(() => this.progress.saveDraft(this.current, this.hintsUsed).catch((e) => console.warn(e)), this.saveDelayMs);
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
