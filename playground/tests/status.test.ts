// @vitest-environment happy-dom
/** La barre de statut : le texte visible, et un texte parlé à part quand le visible est trop abrégé. */
import { describe, expect, it } from 'vitest';
import { statusBar } from '../src/app/status.ts';

describe('statusBar', () => {
	it('écrit le texte et l\'état', () => {
		const el = document.createElement('p');
		statusBar(el)('Prêt', 'ok');
		expect(el.textContent).toBe('Prêt');
		expect(el.dataset.kind).toBe('ok');
	});

	it('donne au lecteur d\'écran le texte parlé, et lui cache le texte abrégé', () => {
		const el = document.createElement('p');
		statusBar(el)('3/4 · 120 ms', 'ko', '3 objectifs sur 4');
		expect(el.querySelector('[aria-hidden="true"]')?.textContent).toBe('3/4 · 120 ms');
		expect(el.querySelector('.sr-only')?.textContent).toBe('3 objectifs sur 4');
		expect(el.dataset.kind).toBe('ko');
	});
});
