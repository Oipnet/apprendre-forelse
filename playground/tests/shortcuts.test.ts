import { describe, expect, it } from 'vitest';
import { foldShortcutLabel, modifierLabel, shortcutOf, shortcutsHelpHtml } from '../src/app/shortcuts.ts';

const touche = (key: string, mods: Partial<Record<'ctrlKey' | 'metaKey' | 'altKey' | 'shiftKey', boolean>> = {}, code = '') => ({ key, code, ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, ...mods });

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

describe('replier les volets', () => {
	it('Ctrl + Alt + B ou P, lus sur la touche physique (⌥ + B tape « ∫ » sur Mac)', () => {
		expect(shortcutOf(touche('b', { ctrlKey: true, altKey: true }, 'KeyB'))).toBe('fold-brief');
		expect(shortcutOf(touche('∫', { ctrlKey: true, altKey: true }, 'KeyB'))).toBe('fold-brief');
		expect(shortcutOf(touche('π', { ctrlKey: true, altKey: true }, 'KeyP'))).toBe('fold-preview');
	});

	it('pas avec ⌘ (favoris du navigateur), ni Maj, ni une autre lettre', () => {
		expect(shortcutOf(touche('b', { metaKey: true, altKey: true }, 'KeyB'))).toBeNull();
		expect(shortcutOf(touche('B', { ctrlKey: true, altKey: true, shiftKey: true }, 'KeyB'))).toBeNull();
		expect(shortcutOf(touche('x', { ctrlKey: true, altKey: true }, 'KeyX'))).toBeNull();
	});

	it('s\'écrivent selon la plateforme, et figurent dans l\'aide', () => {
		expect(foldShortcutLabel('MacIntel', 'B')).toBe('⌃ + ⌥ + B');
		expect(foldShortcutLabel('Win32', 'P')).toBe('Ctrl + Alt + P');
		expect(shortcutsHelpHtml('Ctrl', 'Win32')).toContain('<kbd>Ctrl + Alt + B</kbd></dt><dd>Replier ou afficher les consignes (écran large)</dd>');
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
