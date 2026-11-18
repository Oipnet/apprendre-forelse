/** Les textes du runtime Goravel en français (la référence) : voir index.ts. */
export const fr = {
	boot: {
		downloading: 'Téléchargement de Goravel',
		downloadingSize: (mb: string) => `Téléchargement de Goravel (${mb} Mo)`,
		unpacking: 'Décompression du projet Goravel',
		starting: 'Démarrage de Goravel',
		notStarted: 'Le runtime Goravel n\'a pas démarré.',
		binaryMissing: (status: number) => `Binaire Goravel introuvable (HTTP ${status}).`,
		binaryNotBuilt: 'Le binaire Goravel n\'a pas été construit (npm run build:wasm, dans packages/runtime-goravel).',
		archiveMissing: (status: number) => `Archive du projet introuvable (HTTP ${status}).`,
		stoppedWithoutAnswer: 'le programme Go s\'est arrêté sans répondre',
	},
	tests: {
		noOwnTest: 'Aucun de vos tests ne s\'est exécuté.',
		skipped: '(ignoré)',
		notFinished: 'Le test ne s\'est pas terminé.',
		summary: (passed: number, failed: number) => `(${passed} réussi${passed > 1 ? 's' : ''}, ${failed} en échec)`,
	},
};
