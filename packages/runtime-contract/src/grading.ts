import { m } from './i18n/index.ts';
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
	/** Les cas « own-tests », « mutant:<id> » (et, à une étape rouge, « own-tests-fail », « own-tests-reference »), à ajouter au résultat. */
	cases: TestCaseResult[];
	/** Une ligne par mutant essayé : « ✔ détecté — … » ou « ✘ survivant — … ». */
	report: string[];
}

const DEFAULT_WORDING: GradingWording = {
	noOwnTest: m.grading.noOwnTest,
	skipped: m.grading.skipped,
};

/**
 * Note les tests de l'apprenant (voir Grading), avec les mêmes règles que content:check :
 *  - « own-tests » : ses tests passent tous sur l'application correcte (au moins un) ;
 *  - « mutant:<id> » : ils échouent sur l'application cassée par ce mutant.
 *
 * Le runtime ne fournit que `runMutant` : appliquer le mutant, lancer les tests de l'apprenant, et
 * rendre l'application correcte ensuite. Les mutants sont essayés un par un, dans l'ordre.
 *
 * Étape rouge (`grading.red`) : « own-tests-fail » (un de ses tests au moins échoue, et c'est son message qu'on
 * montre) et « own-tests-reference » (ils passent tous sur le code attendu, que `runWith` pose le temps d'un run).
 * Un runtime sans `runWith` ne sait pas noter une étape rouge : l'objectif échoue.
 */
export async function gradeOwnTests(
	grading: Grading,
	baseCases: TestCaseResult[],
	runMutant: (mutant: Mutant) => Promise<MutantRun>,
	wording: GradingWording = DEFAULT_WORDING,
	runWith?: (files: Record<string, string>) => Promise<MutantRun>,
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
				: m.grading.ownTestsFailing(failing.length, failing.map((c) => (c.status === 'skipped' ? `${c.name} ${wording.skipped}` : c.name)).join(', ')),
		),
	];
	if (grading.red) {
		// Rouge pour la bonne raison : un test qui échoue (une assertion, une classe absente), pas un fichier qui ne se
		// charge pas, et que le code attendu fait passer.
		const red = own.find((c) => c.status === 'failed' || c.status === 'error');
		// Réussi, le message dit quand même pourquoi c'est rouge : lire l'échec fait partie de l'étape.
		cases.push(synthetic('own-tests-fail', red !== undefined, red ? m.grading.redFor(red.name, red.summary ?? firstLine(red.message)) : own.length === 0 ? wording.noOwnTest : m.grading.notRed));
		const run = runWith ? await runWith(grading.red.reference) : null;
		const failingOnReference = run?.cases.filter((c) => c.status !== 'passed') ?? [];
		const passesOnReference = run !== null && !run.broken && run.cases.length > 0 && failingOnReference.length === 0;
		cases.push(synthetic('own-tests-reference', passesOnReference, run === null || run.broken || run.cases.length === 0 ? m.grading.referenceBroken : m.grading.referenceFailing(failingOnReference.map((c) => c.name).join(', '))));
	}
	const report: string[] = [];
	for (const mutant of grading.mutants) {
		if (!ownPassing) {
			cases.push(synthetic(`mutant:${mutant.id}`, false, m.grading.ownTestsFirst));
			continue;
		}
		const run = await runMutant(mutant);
		// Détecté si un de vos tests échoue, ou si le run n'aboutit pas : l'application est cassée.
		const detected = Boolean(run.broken) || run.cases.length === 0 || run.cases.some((c) => c.status !== 'passed');
		cases.push(synthetic(`mutant:${mutant.id}`, detected, m.grading.mutantSurvived(mutant.label)));
		report.push(detected ? m.grading.reportDetected(mutant.label) : m.grading.reportSurvived(mutant.label));
	}
	return { cases, report };
}

/** La première ligne utile d'un message d'échec (sans les chemins de la trace). */
function firstLine(message: string | undefined): string {
	return (message ?? '').split('\n').find((line) => line.trim() && !line.startsWith('/'))?.trim() ?? '';
}
