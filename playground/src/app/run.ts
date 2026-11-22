import type { Runtime } from '@forelse/runtime-contract';
import { m } from '../i18n';
import { mesurer } from '../mesure';
import type { PaneFolding } from './folding';
import type { ExerciseSession } from './session';
import type { Status } from './status';
import type { SuccessPanel } from './SuccessPanel';
import { spokenSummary, type TestResultsView } from './TestResultsView';
import type { StepsController } from './steps';
import type { ExercisePayload } from './types';

/** Ce dont le bouton « Exécuter » a besoin du reste du playground. */
export interface RunButtonDeps {
	exercise: ExercisePayload;
	/** Le bac à sable n'a pas de tests : « Exécuter » envoie les dernières frappes et recharge l'aperçu. */
	scratch: boolean;
	runtime: Pick<Runtime, 'runTests'>;
	session: Pick<ExerciseSession, 'flush'>;
	results: Pick<TestResultsView, 'show' | 'showError' | 'revealObjectivesAndHint'>;
	success: Pick<SuccessPanel, 'show' | 'hide'>;
	folding: Pick<PaneFolding, 'open' | 'showResult'>;
	status: Status;
	/** Recharge l'aperçu ; la promesse se résout quand la page est affichée (ou au bout d'un délai). */
	reload: () => Promise<void>;
	/** Bac à sable : montre (ou retire) l'indicateur de chargement sur l'aperçu. */
	running?: (enCours: boolean) => void;
	/** Amène l'apprenant sur un volet, sur petit écran. */
	showWorkpane: (pane: 'brief' | 'code') => void;
	/** Les durées mesurées (debug / mesures) : premier et dernier lancement des tests. */
	metrics: Record<string, number>;
	mesure: Record<string, string>;
	/** Exercice à étapes : la notation et la réussite sont celles de l'étape en cours. */
	steps?: Pick<StepsController, 'grading' | 'current' | 'isLast' | 'passed' | 'failed'> | null;
	/** Bac à sable : prévenu de chaque exécution (l'encart du parcours compte celles qui réussissent). */
	onScratchRun?: () => void;
}

/** Branche le bouton « Exécuter » : les tests de l'exercice, ou l'aperçu rechargé dans le bac à sable. */
export function bindRunButton(button: HTMLButtonElement, deps: RunButtonDeps): void {
	const { exercise, session, status } = deps;
	if (deps.scratch) {
		// Sans retour visible, une page qui ne change pas laissait croire que rien ne se passait : des visiteurs
		// recliquaient 9 fois en 8 secondes. Le bouton attend maintenant que l'aperçu soit affiché.
		let enCours = false;
		button.addEventListener('click', async () => {
			if (enCours) return;
			enCours = true;
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
			deps.running?.(true);
			status(m.scratch.running, 'busy');
			try {
				await session.flush().catch(() => false);
				deps.onScratchRun?.();
				mesurer('bac-a-sable-execute', deps.mesure);
				await deps.reload();
				status(m.scratch.ran(heure()), 'ok');
			} finally {
				enCours = false;
				button.disabled = false;
				button.removeAttribute('aria-busy');
				deps.running?.(false);
			}
		});
		return;
	}
	button.addEventListener('click', async () => {
		button.disabled = true;
		button.setAttribute('aria-busy', 'true');
		mesurer('tests-lances', deps.mesure);
		status(m.playground.testsRunning, 'busy');
		try {
			// Les dernières frappes doivent être testées, pas perdues.
			if (await session.flush()) void deps.reload();
			const steps = deps.steps ?? null;
			const result = await deps.runtime.runTests(steps ? steps.grading() : { ownTests: exercise.ownTests, mutants: exercise.mutants });
			deps.metrics[deps.metrics.firstTestRun ? 'lastTestRun' : 'firstTestRun'] = Math.round(result.durationMs);
			const passed = deps.results.show(result);
			const total = steps ? steps.current.objectives.length : exercise.objectives.length;
			status(m.playground.testsDone(passed, total, Math.round(result.durationMs)), passed === total ? 'ok' : 'ko', spokenSummary(passed, total));
			// Petit écran : les consignes, où s'affichent les objectifs. Volet replié : il ne se rouvre qu'à la
			// réussite, pour ne pas reprendre la place pendant que l'apprenant corrige ; sa rail porte le résultat.
			deps.showWorkpane('brief');
			deps.folding.showResult(passed, total);
			if (passed === total && steps && !steps.isLast) {
				// Une étape de plus : pas de réussite de l'exercice, le bouton de l'étape suivante.
				deps.folding.open('brief');
				steps.passed();
			} else if (passed === total) {
				steps?.passed();
				deps.folding.open('brief');
				void deps.success.show();
			} else {
				steps?.failed();
				deps.success.hide();
				deps.results.revealObjectivesAndHint();
			}
		} catch (error) {
			status(m.playground.testsError, 'ko');
			deps.results.showError(error);
		} finally {
			button.disabled = false;
			button.removeAttribute('aria-busy');
		}
	});
}

/** L'heure de l'exécution, à la seconde : deux exécutions de suite affichent deux messages différents. */
function heure(): string {
	return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
