import { mesurer } from '../mesure';
import { escapeHtml } from './html';
import type { Review } from './mentor';
import type { MentorPanel } from './MentorPanel';
import type { CompletionResult } from './progress';
import type { ExerciseSession } from './session';
import type { ExercisePayload, PlaygroundConfig } from './types';

type Context = Pick<PlaygroundConfig, 'context' | 'progress' | 'back' | 'registerUrl' | 'waitlistUrl'>;

/** Lien vers la fiche de cours débloquée par cet exercice (comptes seulement : l'invité n'y a pas accès). */
export function lessonLinkHtml(exercise: Pick<ExercisePayload, 'lesson'>, config: Pick<PlaygroundConfig, 'progress'>, before = ''): string {
	return exercise.lesson && config.progress.mode === 'api'
		? `${before}<a class="lesson-unlocked" href="${escapeHtml(exercise.lesson.url)}">📜 Fiche de cours du chapitre « ${escapeHtml(exercise.lesson.title)} » →</a>`
		: '';
}

/** Le rappel, au retour sur un exercice déjà réussi : la suite du parcours, s'il y en a une. */
export function alreadyDoneHtml(exercise: Pick<ExercisePayload, 'next' | 'nextTrack'>, config: Pick<PlaygroundConfig, 'context'>): string {
	if (config.context === 'practice') return '✓ Vous avez déjà réussi cet exercice.';
	if (exercise.next) return `✓ Vous avez déjà réussi cet exercice. <a href="${escapeHtml(exercise.next.url)}">Exercice suivant : ${escapeHtml(exercise.next.title)} →</a>`;
	if (exercise.nextTrack) return `✓ Vous avez déjà réussi cet exercice, le dernier du parcours. <a href="${escapeHtml(exercise.nextTrack.url)}">Continuer avec « ${escapeHtml(exercise.nextTrack.title)} » →</a>`;
	return '✓ Vous avez déjà réussi cet exercice.';
}

/** Ce que la réussite rapporte, tel qu'on l'annonce (« +30 XP · 130 XP au total »). */
export function gainText(result: CompletionResult, config: Pick<PlaygroundConfig, 'context'>, solutionRevealed: boolean): string {
	const practice = config.context === 'practice';
	const gain = result.alreadyCompleted ? 'Exercice déjà validé.' : practice ? '' : solutionRevealed && result.xpEarned === 0 ? 'Sans XP : la solution a été consultée.' : `+${result.xpEarned} XP`;
	const total = result.totalXp !== null && !practice ? ` · ${result.totalXp} XP au total` : '';
	return `${gain}${total}`;
}

/** Après la réussite, où aller : la Pratique, un compte à créer, l'exercice ou le parcours suivant. */
export function nextStepHtml(exercise: Pick<ExercisePayload, 'next' | 'nextTrack' | 'practice'>, config: Context): string {
	if (config.context === 'practice') {
		const source = exercise.practice?.pullRequest
			? `<p>Envie de voir comment la fonctionnalité est faite ? <a href="${escapeHtml(exercise.practice.pullRequest)}" target="_blank" rel="noopener noreferrer">La pull request d'origine</a>.</p>`
			: '';
		return `${source}<a class="button" href="${escapeHtml(config.back.url)}">Retour à la Pratique</a>`;
	}
	if (exercise.next && config.progress.mode === 'local' && config.registerUrl) {
		return config.waitlistUrl
			? `<p>La suite du parcours est en bêta fermée. Vous avez un code d'invitation ? Créez votre compte pour continuer. Sinon, laissez votre adresse : vous serez prévenu à l'ouverture.</p><a class="button primary" href="${escapeHtml(config.registerUrl)}">J'ai un code d'invitation</a> <a class="button ghost small" href="${escapeHtml(config.waitlistUrl)}">Rejoindre la liste d'attente</a>`
			: `<p>Créez un compte gratuit pour sauvegarder votre progression et continuer.</p><a class="button primary" href="${escapeHtml(config.registerUrl)}">Créer mon compte</a>`;
	}
	if (exercise.next) {
		return `<a class="button primary" href="${escapeHtml(exercise.next.url)}">Exercice suivant : ${escapeHtml(exercise.next.title)} →</a>`;
	}
	if (exercise.nextTrack) {
		const track = exercise.nextTrack;
		return `<p>C'était le dernier exercice de « ${escapeHtml(config.back.title)} ». Et maintenant ? Continuez avec <strong>${escapeHtml(track.title)}</strong>${track.description ? ` : ${escapeHtml(track.description)}` : '.'}</p>
					<a class="button primary" href="${escapeHtml(track.url)}">Commencer « ${escapeHtml(track.title)} » →</a>
					<a class="button ghost small" href="${escapeHtml(config.back.url)}">Retour au parcours</a>`;
	}
	return `<p>C'était le dernier exercice de « ${escapeHtml(config.back.title)} ».</p><a class="button" href="${escapeHtml(config.back.url)}">Retour au parcours</a>`;
}

export interface SuccessPanelDeps {
	session: ExerciseSession;
	mentor: MentorPanel;
	/** Pratique : le code de départ face au code de l'apprenant ; null ailleurs. */
	beforeAfter: { open(): void } | null;
	/** La revue de code déjà faite, conservée par le serveur. */
	savedReview: Review | null | undefined;
	/** Ce qui identifie l'exercice dans la mesure d'audience. */
	mesure: Record<string, string>;
}

/** La réussite : « ✓ Réussi » dans la barre, le panneau de félicitations et la suite, le rappel au retour. */
export class SuccessPanel {
	private readonly $ = <T extends HTMLElement>(selector: string) => this.root.querySelector<T>(selector)!;

	constructor(
		private readonly root: HTMLElement,
		private readonly exercise: ExercisePayload,
		private readonly config: PlaygroundConfig,
		private readonly deps: SuccessPanelDeps,
	) {}

	private beforeAfterButton() {
		const button = document.createElement('button');
		button.className = 'ghost small';
		button.textContent = '↔ Avant / après';
		button.addEventListener('click', () => this.deps.beforeAfter?.open());
		return button;
	}

	/** « ✓ Réussi » dans la barre ; au retour sur un exercice déjà réussi, un rappel dans les consignes. */
	markCompleted(onReturn = false): void {
		this.$('#done-chip').hidden = false;
		if (!onReturn) return;
		// Les objectifs restent cochés : ils ont été validés lors de la réussite.
		for (const li of this.root.querySelectorAll<HTMLLIElement>('.objectives li')) li.dataset.state = 'passed';
		const back = this.$('#already-done');
		back.hidden = false;
		back.innerHTML = alreadyDoneHtml(this.exercise, this.config) + lessonLinkHtml(this.exercise, this.config, ' ');
		if (this.config.context === 'practice') back.append(' ', this.beforeAfterButton());
		if (this.deps.savedReview) this.deps.mentor.showReview(this.deps.savedReview);
	}

	hide(): void {
		this.$('#success').hidden = true;
	}

	/** Tous les objectifs sont atteints : la réussite est enregistrée, puis annoncée. */
	async show(): Promise<void> {
		const { session } = this.deps;
		const practice = this.config.context === 'practice';
		const panel = this.$('#success');
		panel.hidden = false;
		try {
			const result = await session.complete();
			if (!result.alreadyCompleted) mesurer('exercice-reussi', { ...this.deps.mesure, indices: session.hintsUsed, solution: session.solutionRevealed });
			this.markCompleted();
			if (result.totalXp !== null) this.root.querySelector('#user-xp')?.replaceChildren(`⭐ ${result.totalXp} XP`);
			panel.innerHTML = `<strong>🎉 Exercice réussi ! ${gainText(result, this.config, session.solutionRevealed)}</strong>${lessonLinkHtml(this.exercise, this.config, '<p>Vous avez terminé le chapitre.</p>')}${nextStepHtml(this.exercise, this.config)}`;
			if (practice) panel.querySelector('.button')?.before(this.beforeAfterButton(), ' ');
			const ask = this.deps.savedReview ? null : this.deps.mentor.reviewButton();
			if (ask) panel.append(ask);
		} catch (error) {
			panel.innerHTML = `<strong>🎉 Exercice réussi !</strong><p>La progression n'a pas pu être enregistrée : ${escapeHtml(String(error))}</p>`;
		}
		// Le focus y va : un lecteur d'écran lit la réussite, et le clavier trouve tout de suite l'exercice suivant.
		panel.tabIndex = -1;
		panel.focus();
	}
}
