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

	describe('étape rouge', () => {
		const rouge: Grading = { ownTests: ['tests/MenuTest.php'], mutants: [], red: { reference: { 'src/Menu.php': '<?php // attendu' } } };

		it('réussit quand un test échoue sur le code de l\'apprenant et passe sur le code attendu', async () => {
			const poses: Record<string, string>[] = [];
			const echec = { ...cas('testPrix', 'failed'), message: 'Failed asserting that 0 is identical to 500.' };
			const { cases } = await gradeOwnTests(rouge, [cas('testCarte', 'passed'), echec], async () => ({ cases: [] }), wording, async (files) => {
				poses.push(files);
				return { cases: [cas('testCarte', 'passed'), cas('testPrix', 'passed')] };
			});
			expect(poses).toEqual([{ 'src/Menu.php': '<?php // attendu' }]);
			expect(cases.map((c) => [c.name, c.status])).toEqual([['own-tests', 'failed'], ['own-tests-fail', 'passed'], ['own-tests-reference', 'passed']]);
			// Réussi, l'objectif dit quand même pourquoi c'est rouge.
			expect(cases[1].message).toBe('Rouge comme prévu : testPrix échoue — Failed asserting that 0 is identical to 500.');
		});

		it('échoue quand tous les tests passent déjà, ou quand le test échoue aussi sur le code attendu', async () => {
			const vert = await gradeOwnTests(rouge, [cas('testPrix', 'passed')], async () => ({ cases: [] }), wording, async () => ({ cases: [cas('testPrix', 'passed')] }));
			expect(vert.cases[1]).toMatchObject({ name: 'own-tests-fail', status: 'failed', message: 'Vos tests passent tous : écrivez un test qui échoue sur le code actuel.' });

			const faux = await gradeOwnTests(rouge, [cas('testPrix', 'failed')], async () => ({ cases: [] }), wording, async () => ({ cases: [cas('testPrix', 'failed')] }));
			expect(faux.cases[1].status).toBe('passed');
			expect(faux.cases[2]).toMatchObject({ name: 'own-tests-reference', status: 'failed' });
			expect(faux.cases[2].message).toContain('testPrix');

			const casse = await gradeOwnTests(rouge, [cas('testPrix', 'error')], async () => ({ cases: [] }), wording, async () => ({ cases: [], broken: true }));
			expect(casse.cases[1].status).toBe('passed');
			expect(casse.cases[2].status).toBe('failed');
		});

		it('échoue sans runtime capable de poser le code attendu', async () => {
			const { cases } = await gradeOwnTests(rouge, [cas('testPrix', 'failed')], async () => ({ cases: [] }), wording);
			expect(cases[2]).toMatchObject({ name: 'own-tests-reference', status: 'failed' });
		});
	});
});
