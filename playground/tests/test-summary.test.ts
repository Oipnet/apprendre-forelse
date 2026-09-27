import { describe, expect, it } from 'vitest';
import { summaryOf } from '../src/app/results.ts';
import { phpunitSummary } from '../src/runtime/phpunit.ts';

describe('phpunitSummary', () => {
	it('garde le message de l\'auteur seul, sans l\'en-tête ni la trace', () => {
		const message = "App\\Tests\\MenuTest::testPrix\nLe menu doit afficher les prix.\nFailed asserting that false is true.\n\n/app/tests/MenuTest.php:12";
		expect(phpunitSummary(message)).toBe('Le menu doit afficher les prix.');
	});

	it('garde le détail de PHPUnit quand l\'auteur n\'a rien écrit, lignes coupées à 160 caractères', () => {
		expect(phpunitSummary('Failed asserting that two strings are identical.\n--- Expected\n+++ Actual')).toBe('Failed asserting that two strings are identical.\n--- Expected');
		expect(phpunitSummary('x'.repeat(200))).toBe(`${'x'.repeat(160)}…`);
	});
});

describe('summaryOf', () => {
	const test = { className: 'Menu', name: 'affiche les prix', status: 'failed', timeMs: 1 } as const;

	it('préfère le résumé du runtime', () => {
		expect(summaryOf({ ...test, message: 'AssertionError\nexpected 1 to be 2', summary: 'Résumé' })).toBe('Résumé');
	});

	it('à défaut, prend les deux premières lignes du message, sans les chemins', () => {
		expect(summaryOf({ ...test, message: 'AssertionError: expected 1 to be 2\n\n/app/tests/menu.test.ts:3\n- Expected\n+ Received' })).toBe('AssertionError: expected 1 to be 2\n- Expected');
	});
});
