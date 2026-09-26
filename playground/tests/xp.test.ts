/**
 * XP d'un exercice réussi, telle que le navigateur l'affiche : mêmes cas que App\Service\XpCalculator côté serveur
 * (platform/tests/Fixtures/xp-cases.json). Modifier la règle d'un seul côté fait échouer l'un des deux tests.
 */
import { describe, expect, it } from 'vitest';
import { xpFor } from '../src/app/progress.ts';
import regle from '../../platform/tests/Fixtures/xp-cases.json';

describe('xpFor : parité avec XpCalculator', () => {
	it.each(regle.cases)('$baseXp XP, $hintsUsed indice(s) : $cas', ({ baseXp, hintsUsed, xp }) => {
		expect(xpFor(baseXp, hintsUsed)).toBe(xp);
	});
});
