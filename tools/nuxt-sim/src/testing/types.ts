/** Résultats de tests : même forme que le contrat `Runtime` du playground (playground/src/runtime/Runtime.ts). */

export type TestStatus = 'passed' | 'failed' | 'error' | 'skipped';

export interface TestCaseResult {
	/** Suites englobantes (`describe`), séparées par « > ». */
	className: string;
	/** Titre du test (`it`, `test`) : c'est lui que désigne un objectif d'exercice. */
	name: string;
	/** Fichier du test, relatif au projet. */
	file?: string;
	status: TestStatus;
	message?: string;
	timeMs: number;
}

export interface TestRunResult {
	exitCode: number;
	cases: TestCaseResult[];
	/** Sortie texte, à la manière du rapporteur par défaut de Vitest. */
	output: string;
	durationMs: number;
}

/** Notation des tests écrits par l'apprenant (voir `Grading` dans Runtime.ts). */
export interface Grading {
	ownTests: string[];
	mutants: { id: string; label: string; changes: { file: string; search: string; replace: string }[] }[];
}
