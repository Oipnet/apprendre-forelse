// @vitest-environment happy-dom
/** Les exercices à étapes : la frise, les fichiers verrouillés selon la phase, la notation de l'étape en cours. */
import { describe, expect, it, vi } from 'vitest';
import { StepsController, startingStep } from '../src/app/steps';
import type { ExercisePayload, ExerciseStep } from '../src/app/types';

const etape = (id: string, phase: ExerciseStep['phase'], editable: string[], objectives: string[], mutants: string[] = []): ExerciseStep => ({
	id,
	phase,
	title: `Titre ${id}`,
	instructionsHtml: `<p>Consigne ${id}</p>`,
	editable,
	open: editable[0],
	objectives: objectives.map((test) => ({ test, label: `Objectif ${test}` })),
	mutants,
	reference: phase === 'red' ? { 'src/Paie.php': '<?php // attendu' } : null,
});

const exercise = {
	ownTests: ['tests/PaieTest.php'],
	mutants: [{ id: 'prix', label: 'le prix est oublié', changes: [] }, { id: 'autre', label: 'autre', changes: [] }],
	steps: [
		etape('rouge', 'red', ['tests/PaieTest.php'], ['own-tests-fail', 'own-tests-reference']),
		etape('vert', 'green', ['src/Paie.php'], ['own-tests', 'testVerifie']),
		etape('refacto', 'refactor', ['src/Paie.php'], ['own-tests', 'mutant:prix'], ['prix']),
	],
} as unknown as ExercisePayload;

function monter(step?: number) {
	document.body.innerHTML = '<div id="root"><div id="steps" hidden></div><ul class="objectives"></ul></div>';
	const root = document.getElementById('root')!;
	const locks = new Map<string, boolean>();
	const opened: string[] = [];
	const session = { step, current: { 'tests/PaieTest.php': '', 'src/Paie.php': '' }, goToStep: vi.fn(async (n: number) => void (session.step = n)) };
	const status = vi.fn();
	const steps = new StepsController(root, exercise, {
		session: session as never,
		editor: { setReadonly: (path, readonly) => void locks.set(path, readonly), has: () => true, open: (path) => void opened.push(path) },
		status,
	});
	return { root, steps, locks, opened, session, status };
}

describe('StepsController', () => {
	it('reprend à l\'étape enregistrée, bornée aux étapes de l\'exercice', () => {
		expect(startingStep(undefined, 3)).toBe(0);
		expect(startingStep(7, 3)).toBe(2);
		expect(startingStep(-1, 3)).toBe(0);
		expect(monter(1).steps.current.id).toBe('vert');
	});

	it('montre la frise, l\'étape en cours et ses objectifs', () => {
		const { root } = monter();
		expect(root.querySelector<HTMLElement>('#steps')!.hidden).toBe(false);
		expect([...root.querySelectorAll('.step-dot')].map((li) => li.getAttribute('data-state'))).toEqual(['current', 'upcoming', 'upcoming']);
		expect(root.querySelector('.step-title')!.textContent).toBe('Titre rouge');
		expect([...root.querySelectorAll('.objectives li')].map((li) => li.getAttribute('data-test'))).toEqual(['own-tests-fail', 'own-tests-reference']);
	});

	it('verrouille le code pendant le rouge, les tests pendant le vert', async () => {
		const { steps, locks, opened } = monter();
		expect(Object.fromEntries(locks)).toEqual({ 'tests/PaieTest.php': false, 'src/Paie.php': true });
		expect(opened.at(-1)).toBe('tests/PaieTest.php');
		await steps.goTo(1);
		expect(Object.fromEntries(locks)).toEqual({ 'tests/PaieTest.php': true, 'src/Paie.php': false });
		expect(opened.at(-1)).toBe('src/Paie.php');
	});

	it('note l\'étape en cours : le code attendu au rouge, les mutants qu\'elle cite', async () => {
		const { steps } = monter();
		expect(steps.grading()).toEqual({ ownTests: ['tests/PaieTest.php'], mutants: [], red: { reference: { 'src/Paie.php': '<?php // attendu' } } });
		await steps.goTo(2);
		expect(steps.grading()).toEqual({ ownTests: ['tests/PaieTest.php'], mutants: [exercise.mutants[0]] });
	});

	it('propose l\'étape suivante une fois l\'étape réussie, et l\'enregistre', async () => {
		const { root, steps, session } = monter();
		const next = root.querySelector<HTMLButtonElement>('#step-next')!;
		expect(next.hidden).toBe(true);
		steps.passed();
		expect(next.hidden).toBe(false);
		steps.failed();
		expect(next.hidden).toBe(true);
		steps.passed();
		next.click();
		await vi.waitFor(() => expect(session.goToStep).toHaveBeenCalledWith(1));
		expect(steps.current.id).toBe('vert');
		expect([...root.querySelectorAll('.step-dot')].map((li) => li.getAttribute('data-state'))).toEqual(['done', 'current', 'upcoming']);
	});

	it('n\'offre pas d\'étape après la dernière : c\'est la réussite de l\'exercice', async () => {
		const { root, steps } = monter(2);
		expect(steps.isLast).toBe(true);
		steps.passed();
		expect(root.querySelector<HTMLButtonElement>('#step-next')!.hidden).toBe(true);
	});
});
