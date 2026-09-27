/** La notation par mutants du contrat : les deux runtimes ne fournissent que le run d'un mutant. */
import { describe, expect, it } from 'vitest';
import { gradeOwnTests, type Grading, type TestCaseResult } from '@forelse/runtime-contract';

const cas = (name: string, status: TestCaseResult['status'], file = 'tests/MenuTest.php'): TestCaseResult => ({ className: 'MenuTest', name, file, status, timeMs: 1 });
const grading: Grading = {
	ownTests: ['tests/MenuTest.php'],
	mutants: [
		{ id: 'prix', label: 'le prix est ignoré', changes: [] },
		{ id: 'tri', label: 'le tri est inversé', changes: [] },
	],
};
const wording = { noOwnTest: 'Écrivez un test.', skipped: '(ignoré)' };

describe('gradeOwnTests', () => {
	it('détecte un mutant quand un test échoue, ou quand le run n\'aboutit pas', async () => {
		const essais: string[] = [];
		const { cases, report } = await gradeOwnTests(grading, [cas('testPrix', 'passed')], async (mutant) => {
			essais.push(mutant.id);
			return mutant.id === 'prix' ? { cases: [cas('testPrix', 'failed')] } : { cases: [cas('testPrix', 'passed')] };
		}, wording);
		expect(essais).toEqual(['prix', 'tri']);
		expect(cases.map((c) => [c.name, c.status])).toEqual([['own-tests', 'passed'], ['mutant:prix', 'passed'], ['mutant:tri', 'failed']]);
		expect(cases[2].message).toBe('Vos tests passent encore quand le tri est inversé : il manque un test.');
		expect(report).toEqual(['✔ détecté — le prix est ignoré', '✘ survivant — le tri est inversé']);

		const casse = await gradeOwnTests(grading, [cas('testPrix', 'passed')], async () => ({ cases: [cas('testPrix', 'passed')], broken: true }), wording);
		expect(casse.cases[1].status).toBe('passed');
		const vide = await gradeOwnTests(grading, [cas('testPrix', 'passed')], async () => ({ cases: [] }), wording);
		expect(vide.cases[1].status).toBe('passed');
	});

	it('ne lance aucun mutant tant que les tests de l\'apprenant ne passent pas', async () => {
		let lances = 0;
		const run = async () => {
			lances++;
			return { cases: [] };
		};
		const echec = await gradeOwnTests(grading, [cas('testPrix', 'failed'), cas('testTri', 'skipped'), cas('testCache', 'failed', 'tests/Cache.php')], run, wording);
		expect(echec.cases[0]).toMatchObject({ name: 'own-tests', status: 'failed', message: '2 de vos tests ne passent pas sur l\'application correcte : testPrix, testTri (ignoré).' });
		expect(echec.cases.slice(1).map((c) => c.message)).toEqual(Array(2).fill('Vos tests doivent d\'abord tous passer sur l\'application correcte.'));
		expect(echec.report).toEqual([]);

		const aucun = await gradeOwnTests(grading, [cas('testCache', 'passed', 'tests/Cache.php')], run, wording);
		expect(aucun.cases[0]).toMatchObject({ status: 'failed', message: 'Écrivez un test.' });
		expect(lances).toBe(0);
	});
});
