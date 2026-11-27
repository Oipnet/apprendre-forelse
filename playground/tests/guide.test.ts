// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Guide, PAUSE_MS } from '../src/app/guide.ts';

class Memoire {
	readonly items = new Map<string, string>();
	getItem(key: string) {
		return this.items.get(key) ?? null;
	}
	setItem(key: string, value: string) {
		this.items.set(key, value);
	}
}

describe('Guide', () => {
	let root: HTMLElement;
	const halo = () => root.querySelector('.guide-target')?.id || root.querySelector('.guide-target')?.className;
	const texte = () => root.querySelector('.guide-text')!.innerHTML;
	const demarrer = (guide: Guide, changed = false) => {
		guide.start(changed);
		vi.advanceTimersToNextFrame();
		return guide;
	};

	beforeEach(() => {
		vi.useFakeTimers();
		root = document.createElement('div');
		root.innerHTML = '<button id="run"></button><div id="editor"></div><ul class="objectives"></ul>';
	});
	afterEach(() => vi.useRealTimers());

	it('montre quoi modifier, puis, après une pause de frappe, le bouton des tests sans perdre la consigne', () => {
		const guide = demarrer(new Guide(root, "Remplacez `'Fermé'` par `'Ouvert'`."));
		expect(guide.current).toBe('write');
		expect(halo()).toBe('editor');
		expect(texte()).toContain("<code>'Fermé'</code>");
		guide.edited();
		vi.advanceTimersByTime(PAUSE_MS - 100);
		guide.edited();
		vi.advanceTimersByTime(PAUSE_MS - 100);
		expect(guide.current, 'pas pendant la frappe').toBe('write');
		vi.advanceTimersByTime(200);
		expect(guide.current).toBe('run');
		expect(halo()).toBe('run');
		expect(texte(), 'la consigne reste lisible').toContain("<code>'Ouvert'</code>");
	});

	it('après un échec, invite à corriger (onglet Code sur petit écran), puis à relancer', () => {
		const guide = demarrer(new Guide(root, 'x', { narrow: () => true }));
		guide.testsDone(0, 1);
		expect(guide.current).toBe('fix');
		expect(halo()).toContain('objectives');
		expect(texte()).toContain('onglet « Code »');
		guide.edited();
		vi.advanceTimersByTime(PAUSE_MS);
		expect(guide.current).toBe('run');
	});

	it('un brouillon repris commence au bouton des tests', () => {
		expect(demarrer(new Guide(root, 'x'), true).current).toBe('run');
	});

	it('la réussite ferme le guide et retire le halo', () => {
		const guide = demarrer(new Guide(root, 'x'));
		guide.testsDone(1, 1);
		expect(guide.current).toBeNull();
		expect(root.querySelector('.guide')).toBeNull();
		expect(root.querySelector('.guide-target')).toBeNull();
		expect(root.classList.contains('guided')).toBe(false);
	});

	it('fermé par la croix, il ne revient pas sur cet exercice', () => {
		const storage = new Memoire();
		const options = { storage, key: 'guide:t/e' };
		const guide = demarrer(new Guide(root, 'x', options));
		root.querySelector<HTMLButtonElement>('.guide-close')!.click();
		expect(root.querySelector('.guide')).toBeNull();
		guide.edited();
		vi.advanceTimersByTime(PAUSE_MS);
		expect(guide.current).toBeNull();

		const retour = demarrer(new Guide(root, 'x', options));
		expect(retour.current).toBeNull();
		expect(root.querySelector('.guide')).toBeNull();
	});
});
