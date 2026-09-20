export function libelleDifficulte(difficulte: string): string {
	return { facile: 'Facile', moyen: 'Moyen', difficile: 'Difficile' }[difficulte] ?? difficulte
}
