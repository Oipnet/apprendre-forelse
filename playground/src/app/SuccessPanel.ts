import { m } from '../i18n';
import { mesurer } from '../mesure';
import { xpBadge } from './layout';
import { escapeHtml } from './html';
import { mountShare, shareHtml } from './share';
import type { Review } from './mentor';
import type { MentorPanel } from './MentorPanel';
import type { CompletionResult } from './progress';
import type { ExerciseSession } from './session';
import type { ExercisePayload, PlaygroundConfig } from './types';

type Context = Pick<PlaygroundConfig, 'context' | 'progress' | 'back' | 'registerUrl' | 'waitlistUrl'>;

/** Lien vers la fiche de cours débloquée par cet exercice (comptes seulement : l'invité n'y a pas accès). */
export function lessonLinkHtml(exercise: Pick<ExercisePayload, 'lesson'>, config: Pick<PlaygroundConfig, 'progress'>, before = ''): string {
	return exercise.lesson && config.progress.mode === 'api'
		? `${before}<a class="lesson-unlocked" href="${escapeHtml(exercise.lesson.url)}">${m.success.lessonLink(escapeHtml(exercise.lesson.title))}</a>`
		: '';
}

/** Le rappel, au retour sur un exercice déjà réussi : la suite du parcours, s'il y en a une. */
export function alreadyDoneHtml(exercise: Pick<ExercisePayload, 'next' | 'nextTrack'>, config: Pick<PlaygroundConfig, 'context'>): string {
	if (config.context === 'practice') return m.success.alreadyDone;
	if (exercise.next) return `${m.success.alreadyDone} <a href="${escapeHtml(exercise.next.url)}">${m.success.nextExercise(escapeHtml(exercise.next.title))}</a>`;
	if (exercise.nextTrack) return `${m.success.alreadyDoneLast} <a href="${escapeHtml(exercise.nextTrack.url)}">${m.success.continueWith(escapeHtml(exercise.nextTrack.title))}</a>`;
	return m.success.alreadyDone;
}

/** Ce que la réussite rapporte, tel qu'on l'annonce (« +30 XP · 130 XP au total »). */
export function gainText(result: CompletionResult, config: Pick<PlaygroundConfig, 'context'>, solutionRevealed: boolean): string {
	const practice = config.context === 'practice';
	const gain = result.alreadyCompleted ? m.success.alreadyValidated : practice ? '' : solutionRevealed && result.xpEarned === 0 ? m.success.noXpSolution : m.success.xpGain(result.xpEarned);
	const total = result.totalXp !== null && !practice ? ` · ${m.success.totalXp(result.totalXp)}` : '';
	return `${gain}${total}`;
}

/** Après la réussite, où aller : la Pratique, un compte à créer, l'exercice ou le parcours suivant. */
export function nextStepHtml(exercise: Pick<ExercisePayload, 'next' | 'nextTrack' | 'practice'>, config: Context): string {
	if (config.context === 'practice') {
		const pullRequest = exercise.practice?.pullRequest;
		const source = pullRequest
			? `<p>${m.success.pullRequest((texte) => `<a href="${escapeHtml(pullRequest)}" target="_blank" rel="noopener noreferrer">${texte}</a>`)}</p>`
			: '';
		const track = exercise.practice?.track;
		if (track) {
			return `<p>${m.success.practiceTrack(`<strong>${escapeHtml(track.title)}</strong>`, track.description ? escapeHtml(track.description) : null)}</p>${source}
					<a class="button primary" href="${escapeHtml(track.url)}">${m.success.discover(escapeHtml(track.title))}</a>
					<a class="button ghost small" href="${escapeHtml(config.back.url)}">${m.success.backToPractice}</a>`;
		}
		return `${source}<a class="button" href="${escapeHtml(config.back.url)}">${m.success.backToPractice}</a>`;
	}
	if (exercise.next && config.progress.mode === 'local' && config.registerUrl) {
		return config.waitlistUrl
			? `<p>${m.success.closedBeta}</p><a class="button primary" href="${escapeHtml(config.registerUrl)}">${m.success.haveInvitation}</a> <a class="button ghost small" href="${escapeHtml(config.waitlistUrl)}">${m.success.joinWaitlist}</a>`
			: `<p>${m.success.createAccountPitch}</p><a class="button primary" href="${escapeHtml(config.registerUrl)}">${m.success.createAccount}</a>`;
	}
	if (exercise.next) {
		return `<a class="button primary" href="${escapeHtml(exercise.next.url)}">${m.success.nextExercise(escapeHtml(exercise.next.title))}</a>`;
	}
	if (exercise.nextTrack) {
		const track = exercise.nextTrack;
		return `<p>${m.success.lastThenNext(escapeHtml(config.back.title), `<strong>${escapeHtml(track.title)}</strong>`, track.description ? escapeHtml(track.description) : null)}</p>
					<a class="button primary" href="${escapeHtml(track.url)}">${m.success.start(escapeHtml(track.title))}</a>
					<a class="button ghost small" href="${escapeHtml(config.back.url)}">${m.success.backToTrack}</a>`;
	}
	return `<p>${m.success.last(escapeHtml(config.back.title))}</p><a class="button" href="${escapeHtml(config.back.url)}">${m.success.backToTrack}</a>`;
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
		button.textContent = `↔ ${m.diff.title}`;
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
			if (result.totalXp !== null) {
				const badge = this.root.querySelector('#user-xp');
				if (badge) badge.innerHTML = xpBadge(result.totalXp);
			}
			panel.innerHTML = `<strong>🎉 ${m.success.succeeded} ${gainText(result, this.config, session.solutionRevealed)}</strong>${lessonLinkHtml(this.exercise, this.config, `<p>${m.success.chapterDone}</p>`)}${nextStepHtml(this.exercise, this.config)}`;
			if (practice) panel.querySelector('.button')?.before(this.beforeAfterButton(), ' ');
			// Annoncer la réussite : le texte proposé le dit (« J'ai réussi l'exercice… »).
			if (this.config.share) {
				panel.insertAdjacentHTML('beforeend', shareHtml(this.config.share.success, m.success.shareSuccess));
				mountShare(panel.querySelector<HTMLElement>('[data-share]')!, this.config.share.success, this.deps.mesure);
			}
			const ask = this.deps.savedReview ? null : this.deps.mentor.reviewButton();
			if (ask) panel.append(ask);
		} catch (error) {
			panel.innerHTML = `<strong>🎉 ${m.success.succeeded}</strong><p>${m.success.saveFailed(escapeHtml(String(error)))}</p>`;
		}
		// Le focus y va : un lecteur d'écran lit la réussite, et le clavier trouve tout de suite l'exercice suivant.
		panel.tabIndex = -1;
		panel.focus();
	}
}
