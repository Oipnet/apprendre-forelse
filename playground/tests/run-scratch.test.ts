// @vitest-environment happy-dom
/** « Exécuter » dans le bac à sable : un seul rechargement à la fois, et un retour visible jusqu'à l'affichage. */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { bindRunButton, type RunButtonDeps } from '../src/app/run.ts';

afterEach(() => {
	delete (window as { umami?: unknown }).umami;
});

const flushMicrotasks = () => new Promise((resolve) => setTimeout(resolve, 0));

function monter() {
	const button = document.createElement('button');
	const track = vi.fn();
	(window as { umami?: unknown }).umami = { track };
	let afficher: () => void = () => {};
	const reload = vi.fn(() => new Promise<void>((resolve) => (afficher = resolve)));
	const running = vi.fn();
	const status = vi.fn();
	const deps = {
		exercise: {},
		scratch: true,
		session: { flush: async () => true },
		reload,
		running,
		status,
		mesure: { exercice: 'bac-a-sable-laravel', parcours: 'bac-a-sable' },
	} as unknown as RunButtonDeps;
	bindRunButton(button, deps);
	return { button, track, reload, running, status, afficher: () => afficher() };
}

describe('Exécuter dans le bac à sable', () => {
	it('montre le chargement jusqu\'à ce que l\'aperçu soit affiché', async () => {
		const { button, running, status, afficher } = monter();
		button.click();
		await flushMicrotasks();
		expect(button.disabled).toBe(true);
		expect(button.getAttribute('aria-busy')).toBe('true');
		expect(running).toHaveBeenLastCalledWith(true);
		expect(status).toHaveBeenLastCalledWith(expect.any(String), 'busy');

		afficher();
		await flushMicrotasks();
		expect(button.disabled).toBe(false);
		expect(button.hasAttribute('aria-busy')).toBe(false);
		expect(running).toHaveBeenLastCalledWith(false);
		expect(status).toHaveBeenLastCalledWith(expect.stringMatching(/\d{2}.\d{2}.\d{2}/), 'ok');
	});

	it('ne relance ni ne compte une exécution tant que la précédente n\'est pas affichée', async () => {
		const { button, track, reload, afficher } = monter();
		button.click();
		await flushMicrotasks();
		button.dispatchEvent(new MouseEvent('click'));
		button.dispatchEvent(new MouseEvent('click'));
		await flushMicrotasks();
		expect(reload).toHaveBeenCalledTimes(1);
		expect(track).toHaveBeenCalledTimes(1);
		expect(track).toHaveBeenCalledWith('bac-a-sable-execute', { exercice: 'bac-a-sable-laravel', parcours: 'bac-a-sable' });

		afficher();
		await flushMicrotasks();
		button.click();
		await flushMicrotasks();
		expect(reload).toHaveBeenCalledTimes(2);
		expect(track).toHaveBeenCalledTimes(2);
	});
});
