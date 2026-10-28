import type { Runtime } from '@forelse/runtime-contract';
import { m } from '../i18n';
import { mesurer } from '../mesure';
import type { PaneFolding } from './folding';
import type { ExerciseSession } from './session';
import type { Status } from './status';
import type { SuccessPanel } from './SuccessPanel';
import { spokenSummary, type TestResultsView } from './TestResultsView';
import type { ExercisePayload } from './types';

/** Ce dont le bouton « Exécuter » a besoin du reste du playground. */
export interface RunButtonDeps {
	exercise: ExercisePayload;
	/** Le bac à sable n'a pas de tests : « Exécuter » envoie les dernières frappes et recharge l'aperçu. */
	scratch: boolean;
	runtime: Pick<Runtime, 'runTests'>;
	session: Pick<ExerciseSession, 'flush'>;
	results: Pick<TestResultsView, 'show' | 'showError'>;
	success: Pick<SuccessPanel, 'show' | 'hide'>;
	folding: Pick<PaneFolding, 'open' | 'showResult'>;
	status: Status;
	reload: () => void;
	/** Amène l'apprenant sur un volet, sur petit écran. */
	showWorkpane: (pane: 'brief' | 'code') => void;
	/** Les durées mesurées (debug / mesures) : premier et dernier lancement des tests. */
	metrics: Record<string, number>;
	mesure: Record<string, string>;
}

/** Branche le bouton « Exécuter » : les tests de l'exercice, ou l'aperçu rechargé dans le bac à sable. */
export function bindRunButton(button: HTMLButtonElement, deps: RunButtonDeps): void {
	const { exercise, session, status } = deps;
	if (deps.scratch) {
		button.addEventListener('click', async () => {
			await session.flush().catch(() => false);
			deps.reload();
			mesurer('bac-a-sable-execute', deps.mesure);
			status(m.scratch.ran, 'ok');
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
			if (await session.flush()) deps.reload();
			const result = await deps.runtime.runTests({ ownTests: exercise.ownTests, mutants: exercise.mutants });
			deps.metrics[deps.metrics.firstTestRun ? 'lastTestRun' : 'firstTestRun'] = Math.round(result.durationMs);
			const passed = deps.results.show(result);
			const total = exercise.objectives.length;
			status(m.playground.testsDone(passed, total, Math.round(result.durationMs)), passed === total ? 'ok' : 'ko', spokenSummary(passed, total));
			// Petit écran : les consignes, où s'affichent les objectifs. Volet replié : il ne se rouvre qu'à la
			// réussite, pour ne pas reprendre la place pendant que l'apprenant corrige ; sa rail porte le résultat.
			deps.showWorkpane('brief');
			deps.folding.showResult(passed, total);
			if (passed === total) {
				deps.folding.open('brief');
				void deps.success.show();
			} else deps.success.hide();
		} catch (error) {
			status(m.playground.testsError, 'ko');
			deps.results.showError(error);
		} finally {
			button.disabled = false;
			button.removeAttribute('aria-busy');
		}
	});
}
