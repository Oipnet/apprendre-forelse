import type { EditorPanel } from '../editor/monaco';
import { mesurer } from '../mesure';
import { markdown } from './markdown';
import type { ExerciseSession } from './session';
import type { ExercisePayload, PlaygroundConfig } from './types';

export interface HintsAndSolutionDeps {
	session: ExerciseSession;
	editor: Pick<EditorPanel, 'addFile' | 'has' | 'open' | 'setContent'>;
	/** Sur petit écran : amène l'apprenant sur le volet où quelque chose vient de se passer. */
	revealPane: (pane: 'brief' | 'code') => void;
	/** Ce qui identifie l'exercice dans la mesure d'audience. */
	mesure: Record<string, string>;
}

/** Les indices, un à un et contre de l'XP, et la solution de référence (comptes seulement). */
export class HintsAndSolution {
	private readonly $ = <T extends HTMLElement>(selector: string) => this.root.querySelector<T>(selector)!;
	private readonly hintButton: HTMLButtonElement;

	constructor(
		private readonly root: HTMLElement,
		private readonly exercise: ExercisePayload,
		private readonly config: PlaygroundConfig,
		private readonly deps: HintsAndSolutionDeps,
	) {
		const { session } = deps;
		this.hintButton = this.$<HTMLButtonElement>('#hint');
		for (let i = 0; i < Math.min(session.hintsUsed, exercise.hints.length); i++) this.showHint(i);
		this.updateHintButton();
		this.hintButton.addEventListener('click', () => {
			if (session.hintsUsed >= exercise.hints.length) return;
			this.showHint(session.hintsUsed);
			deps.revealPane('brief');
			session.useHint();
			mesurer('indice-demande', { ...deps.mesure, indices: session.hintsUsed });
			this.updateHintButton();
		});
		this.bindSolution();
	}

	private showHint(index: number) {
		const hint = document.createElement('div');
		hint.className = 'hint';
		hint.innerHTML = `<strong>Indice ${index + 1}/${this.exercise.hints.length}</strong> ${markdown(this.exercise.hints[index], true)}`;
		this.$('#hints').append(hint);
	}

	private updateHintButton() {
		const cost = this.deps.session.hintCost(this.exercise.xp);
		this.hintButton.hidden = this.deps.session.hintsUsed >= this.exercise.hints.length;
		this.hintButton.textContent = cost <= 0 ? '💡 Un indice ?' : `💡 Un indice ? (−${cost} XP)`;
	}

	/** La solution de référence, à côté du code de l'apprenant (onglets en lecture seule), contre l'XP. */
	private bindSolution() {
		const { session, editor } = this.deps;
		const exercise = this.exercise;
		const solutionButton = this.$<HTMLButtonElement>('#solution');
		const solutionNote = this.$('#solution-note');
		const showSolution = (files: Record<string, string>) => {
			let first: string | undefined;
			for (const [path, content] of Object.entries(files)) {
				const tabPath = `solution/${path}`;
				if (!editor.has(tabPath)) editor.addFile(tabPath, content, true, `✓ ${path.split('/').pop()!}`);
				first ??= tabPath;
			}
			if (first) editor.open(first);
			this.deps.revealPane('code');
		};
		const noteSolution = () => {
			solutionNote.hidden = false;
			solutionNote.textContent = session.completed || exercise.xp === 0 ? 'Solution consultée.' : 'Solution consultée : cet exercice ne rapportera pas d\'XP.';
			solutionButton.querySelector('.label')!.textContent = 'Solution ✓';
			this.updateHintButton();
		};
		if (this.config.progress.mode === 'api') {
			solutionButton.hidden = false;
			solutionButton.title = exercise.xp === 0 ? 'La solution de référence.' : 'La solution de référence. La consulter ne rapporte pas l\'XP de cet exercice.';
			if (session.solutionRevealed) noteSolution();
			solutionButton.addEventListener('click', async () => {
				if (!session.completed && !session.solutionRevealed && exercise.xp > 0 && !confirm('Consulter la solution ? Cet exercice ne rapportera alors pas d\'XP, même réussi ensuite avec votre code.')) return;
				solutionButton.disabled = true;
				try {
					const files = await session.revealSolution();
					if (!files) return;
					mesurer('solution-consultee', this.deps.mesure);
					noteSolution();
					showSolution(files);
				} catch (error) {
					alert(`Solution indisponible : ${error instanceof Error ? error.message : error}`);
				} finally {
					solutionButton.disabled = false;
				}
			});
		} else if (exercise.solution) {
			// Développement de la plateforme : la solution remplace le code, pour tester vite.
			solutionButton.hidden = false;
			solutionButton.title = 'Visible en développement uniquement';
			solutionButton.addEventListener('click', () => {
				for (const [path, content] of Object.entries(exercise.solution!)) editor.setContent(path, content);
			});
		}
	}
}
