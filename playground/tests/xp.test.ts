/**
 * XP d'un exercice réussi, telle que le navigateur l'affiche : mêmes cas que App\Service\XpCalculator côté serveur.
 * Modifier la règle d'un seul côté fait échouer l'un des deux tests. Les cas vivent ici plutôt que côté plateforme :
 * l'image Docker vérifie les types du playground sans le reste du dépôt.
 */
import { describe, expect, it } from 'vitest';
import { xpFor } from '../src/app/progress.ts';
import regle from './fixtures/xp-cases.json';

describe('xpFor : parité avec XpCalculator', () => {
	it.each(regle.cases)('$baseXp XP, $hintsUsed indice(s) : $cas', ({ baseXp, hintsUsed, xp }) => {
		expect(xpFor(baseXp, hintsUsed)).toBe(xp);
	});
});
