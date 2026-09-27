import type { Grading, TestCaseResult } from './runtime.ts';

/** Un mutant de la notation : l'application cassée que les tests de l'apprenant doivent détecter. */
export type Mutant = Grading['mutants'][number];

/** Ce que le runtime rapporte d'un run des tests de l'apprenant sur un mutant. */
export interface MutantRun {
	cases: TestCaseResult[];
	/** Le run n'a pas abouti (un fichier de test ne se charge plus) : l'application est cassée, donc détectée. */
	broken?: boolean;
}

/** Les phrases qui parlent du lanceur de tests : chaque runtime a les siennes. */
export interface GradingWording {
	/** Aucun test de l'apprenant ne s'est exécuté. */
	noOwnTest: string;
	/** Suffixe d'un test ignoré, dans la liste de ceux qui ne passent pas (« (ignoré) »). */
	skipped: string;
}

export interface GradingOutcome {
	/** Les cas « own-tests » et « mutant:<id> », à ajouter au résultat. */
	cases: TestCaseResult[];
	/** Une ligne par mutant essayé : « ✔ détecté — … » ou « ✘ survivant — … ». */
	report: string[];
}

const DEFAULT_WORDING: GradingWording = {
	noOwnTest: 'Aucun de vos tests ne s\'est exécuté : écrivez au moins un test.',
	skipped: '(ignoré)',
};

/**
 * Note les tests de l'apprenant (voir Grading), avec les mêmes règles que content:check :
 *  - « own-tests » : ses tests passent tous sur l'application correcte (au moins un) ;
 *  - « mutant:<id> » : ils échouent sur l'application cassée par ce mutant.
 *
 * Le runtime ne fournit que `runMutant` : appliquer le mutant, lancer les tests de l'apprenant, et
 * rendre l'application correcte ensuite. Les mutants sont essayés un par un, dans l'ordre.
 */
export async function gradeOwnTests(
	grading: Grading,
	baseCases: TestCaseResult[],
	runMutant: (mutant: Mutant) => Promise<MutantRun>,
	wording: GradingWording = DEFAULT_WORDING,
): Promise<GradingOutcome> {
	const synthetic = (name: string, passed: boolean, message?: string): TestCaseResult => ({ className: 'Notation', name, status: passed ? 'passed' : 'failed', message, timeMs: 0 });
	const own = baseCases.filter((c) => c.file && grading.ownTests.includes(c.file));
	const failing = own.filter((c) => c.status !== 'passed');
	const ownPassing = own.length > 0 && failing.length === 0;
	const cases = [
		synthetic(
			'own-tests',
			ownPassing,
			own.length === 0
				? wording.noOwnTest
				: `${failing.length === 1 ? 'Un de vos tests ne passe' : `${failing.length} de vos tests ne passent`} pas sur l'application correcte : ${failing
						.map((c) => (c.status === 'skipped' ? `${c.name} ${wording.skipped}` : c.name))
						.join(', ')}.`,
		),
	];
	const report: string[] = [];
	for (const mutant of grading.mutants) {
		if (!ownPassing) {
			cases.push(synthetic(`mutant:${mutant.id}`, false, 'Vos tests doivent d\'abord tous passer sur l\'application correcte.'));
			continue;
		}
		const run = await runMutant(mutant);
		// Détecté si un de vos tests échoue, ou si le run n'aboutit pas : l'application est cassée.
		const detected = Boolean(run.broken) || run.cases.length === 0 || run.cases.some((c) => c.status !== 'passed');
		cases.push(synthetic(`mutant:${mutant.id}`, detected, `Vos tests passent encore quand ${mutant.label} : il manque un test.`));
		report.push(`${detected ? '✔ détecté' : '✘ survivant'} — ${mutant.label}`);
	}
	return { cases, report };
}
