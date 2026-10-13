/**
 * Les textes du contrat des runtimes montrés à l'apprenant, en français : la langue de référence.
 *
 * Un objet par module. Une valeur est une chaîne, ou une fonction quand le texte a des paramètres. Une phrase
 * reste entière dans un seul message.
 *
 * Une autre langue s'écrit `export const en: Messages = { … }` (voir index.ts) : tsc vérifie qu'elle est complète.
 */
export const fr = {
	/** La notation des tests de l'apprenant (gradeOwnTests). */
	grading: {
		/** Formulation par défaut, quand le runtime ne donne pas la sienne (GradingWording). */
		noOwnTest: 'Aucun de vos tests ne s\'est exécuté : écrivez au moins un test.',
		skipped: '(ignoré)',
		/** Les tests de l'apprenant qui ne passent pas sur l'application correcte (`tests` : leurs noms, déjà joints). */
		ownTestsFailing: (count: number, tests: string) => `${count === 1 ? 'Un de vos tests ne passe' : `${count} de vos tests ne passent`} pas sur l'application correcte : ${tests}.`,
		ownTestsFirst: 'Vos tests doivent d\'abord tous passer sur l\'application correcte.',
		/** `mutant` : le libellé du mutant (« le prix est ignoré »). */
		mutantSurvived: (mutant: string) => `Vos tests passent encore quand ${mutant} : il manque un test.`,
		/** Une ligne du rapport des mutants, dans la sortie des tests. */
		reportDetected: (mutant: string) => `✔ détecté — ${mutant}`,
		reportSurvived: (mutant: string) => `✘ survivant — ${mutant}`,
	},

	/** La surveillance du worker (WorkerRuntime). `runtime` : son libellé, qui commence la phrase (« Le simulateur Nuxt »). */
	worker: {
		/** Le worker a levé une erreur avant la fin de son démarrage (`detail` : le message du navigateur, ou loadFailed). */
		error: (worker: string, detail: string) => `Worker ${worker} : ${detail}`,
		loadFailed: 'échec du chargement',
		bootStalled: (runtime: string, seconds: number) => `${runtime} ne démarre pas (aucun signe depuis ${seconds} s) : rechargez la page.`,
		frozen: (runtime: string, seconds: number) => `${runtime} ne répond plus depuis ${seconds} s : une boucle infinie dans le code ? Il a été redémarré ; corrigez le code, puis relancez.`,
		restartFailed: (runtime: string, message: string) => `${runtime} n'a pas pu redémarrer (${message}) : rechargez la page.`,
	},
};
