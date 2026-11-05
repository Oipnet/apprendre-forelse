import type { Grading } from '@forelse/runtime-contract';
import { m } from '../i18n';
import { estModifiable } from './editable';
import { escapeHtml } from './html';
import { sanitize } from './markdown';
import type { ExerciseSession } from './session';
import type { ExercisePayload, ExerciseStep } from './types';

export interface StepsDeps {
	session: ExerciseSession;
	editor: { setReadonly(path: string, readonly: boolean, message?: string): void; has(path: string): boolean; open(path: string): void };
	status: (text: string, state: 'ok' | 'ko' | 'busy' | 'idle') => void;
}

/** Les objectifs d'une liste, tels que TestResultsView les coche (data-test). */
export function objectivesHtml(objectives: { test: string; label: string }[]): string {
	return objectives.map((o) => `<li data-test="${escapeHtml(o.test)}"><span class="dot" aria-hidden="true"></span><span class="sr-only objective-state"></span><span>${escapeHtml(o.label)}</span></li>`).join('');
}

/** L'étape où reprendre : celle enregistrée, bornée aux étapes de l'exercice. */
export function startingStep(saved: number | null | undefined, total: number): number {
	return Math.min(Math.max(0, Math.trunc(saved ?? 0)), Math.max(0, total - 1));
}

/**
 * Un exercice à étapes (rouge, vert, refacto) : la frise des étapes, la consigne et les objectifs de celle en cours,
 * les fichiers verrouillés selon la phase, et le passage à la suivante quand ses objectifs sont atteints. Le code de
 * l'apprenant ne change pas d'une étape à l'autre.
 */
export class StepsController {
	private readonly $ = <T extends HTMLElement>(selector: string) => this.root.querySelector<T>(selector)!;
	private readonly steps: ExerciseStep[];
	private index: number;

	constructor(
		private readonly root: HTMLElement,
		private readonly exercise: ExercisePayload,
		private readonly deps: StepsDeps,
	) {
		this.steps = exercise.steps ?? [];
		this.index = startingStep(deps.session.step, this.steps.length);
		deps.session.step = this.index;
		this.$('#steps').hidden = false;
		this.render();
	}

	get current(): ExerciseStep {
		return this.steps[this.index];
	}

	get isLast(): boolean {
		return this.index === this.steps.length - 1;
	}

	/** Ce que le runtime note à cette étape : les mutants qu'elle cite, et le code attendu d'une étape rouge. */
	grading(): Grading {
		const step = this.current;
		return {
			ownTests: this.exercise.ownTests,
			mutants: this.exercise.mutants.filter((mutant) => step.mutants.includes(mutant.id)),
			...(step.phase === 'red' && step.reference ? { red: { reference: step.reference } } : {}),
		};
	}

	/** Les fichiers de l'apprenant verrouillés ou rouverts selon l'étape ; le fichier de l'étape ouvert. */
	applyLocks(openFile = true): void {
		const step = this.current;
		for (const path of Object.keys(this.deps.session.current)) {
			const editable = estModifiable(path, step.editable);
			this.deps.editor.setReadonly(path, !editable, editable ? undefined : m.steps.locked[step.phase]);
		}
		if (openFile && this.deps.editor.has(step.open)) this.deps.editor.open(step.open);
	}

	/** Tous les objectifs de l'étape sont atteints : le bouton de la suivante (sauf à la dernière, qui réussit l'exercice). */
	passed(): void {
		this.deps.status(m.steps.passed(this.current.title), 'ok');
		if (this.isLast) return;
		this.$<HTMLButtonElement>('#step-next').hidden = false;
		this.$<HTMLButtonElement>('#step-next').focus();
	}

	/** Un run qui n'atteint pas tout : le bouton de la suite disparaît (le code a pu changer depuis). */
	failed(): void {
		const next = this.root.querySelector<HTMLButtonElement>('#step-next');
		if (next) next.hidden = true;
	}

	async goTo(index: number): Promise<void> {
		this.index = startingStep(index, this.steps.length);
		this.render();
		this.deps.status(m.steps.started(this.index + 1, this.current.title), 'idle');
		await this.deps.session.goToStep(this.index).catch((error: unknown) => this.deps.status(error instanceof Error ? error.message : String(error), 'ko'));
	}

	private render(): void {
		const step = this.current;
		const total = this.steps.length;
		this.$('#steps').innerHTML = `
			<ol class="steps-timeline" aria-label="${m.steps.timeline}">
				${this.steps
					.map((s, i) => {
						const state = i < this.index ? 'done' : i === this.index ? 'current' : 'upcoming';
						const spoken = state === 'done' ? m.steps.stateDone : state === 'current' ? m.steps.stateCurrent : '';
						return `<li class="step-dot phase-${s.phase}" data-state="${state}"${state === 'current' ? ' aria-current="step"' : ''} title="${escapeHtml(`${i + 1}. ${m.steps.phase[s.phase]} · ${s.title}`)}"><span class="sr-only">${escapeHtml(`${i + 1}. ${m.steps.phase[s.phase]} · ${s.title}${spoken ? ` (${spoken})` : ''}`)}</span></li>`;
					})
					.join('')}
			</ol>
			<section class="step phase-${step.phase}" aria-live="polite">
				<p class="step-position"><span class="chip step-phase phase-${step.phase}">${m.steps.phase[step.phase]}</span> ${m.steps.position(this.index + 1, total)}</p>
				<h3 class="step-title">${escapeHtml(step.title)}</h3>
				<p class="step-rule">${m.steps.phaseHint[step.phase]}</p>
				<div class="step-instructions instructions">${sanitize(step.instructionsHtml)}</div>
				<button type="button" id="step-next" class="primary" hidden>${m.steps.next} →</button>
			</section>`;
		this.$<HTMLButtonElement>('#step-next').addEventListener('click', () => void this.goTo(this.index + 1));
		// Les objectifs de l'étape, à cocher par le prochain run.
		this.$('.objectives').innerHTML = objectivesHtml(step.objectives);
		this.applyLocks();
	}
}
