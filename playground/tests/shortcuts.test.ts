import { describe, expect, it } from 'vitest';
import { modifierLabel, shortcutOf, shortcutsHelpHtml } from '../src/app/shortcuts.ts';

const touche = (key: string, mods: Partial<Record<'ctrlKey' | 'metaKey' | 'altKey' | 'shiftKey', boolean>> = {}) => ({ key, ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, ...mods });

describe('shortcutOf', () => {
	it('Ctrl (ou ⌘) + Entrée lance les tests, + S enregistre', () => {
		expect(shortcutOf(touche('Enter', { ctrlKey: true }))).toBe('run');
		expect(shortcutOf(touche('Enter', { metaKey: true }))).toBe('run');
		expect(shortcutOf(touche('s', { ctrlKey: true }))).toBe('save');
		expect(shortcutOf(touche('S', { metaKey: true }))).toBe('save');
	});

	it('laisse passer le reste : Entrée seule, Ctrl+Maj+S, Ctrl+Alt+Entrée', () => {
		expect(shortcutOf(touche('Enter'))).toBeNull();
		expect(shortcutOf(touche('s', { ctrlKey: true, shiftKey: true }))).toBeNull();
		expect(shortcutOf(touche('Enter', { ctrlKey: true, altKey: true }))).toBeNull();
	});
});

describe('aide', () => {
	it('nomme la touche de la plateforme', () => {
		expect(modifierLabel('MacIntel')).toBe('⌘');
		expect(modifierLabel('Win32')).toBe('Ctrl');
		expect(shortcutsHelpHtml('⌘')).toContain('<kbd>⌘ + Entrée</kbd></dt><dd>Lancer les tests</dd>');
		expect(shortcutsHelpHtml('Ctrl')).toContain('Ctrl + M');
	});
});
