import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/** Les couleurs de :root (thème sombre des éditeurs), lues dans site.css. */
const css = readFileSync(new URL('../src/site.css', import.meta.url), 'utf8');
const root = css.slice(css.indexOf(':root {'), css.indexOf('}', css.indexOf(':root {')));
const token = (name: string) => root.match(new RegExp(`--${name}:\\s*(#[0-9a-f]{6})`, 'i'))![1];

function luminance(hex: string): number {
	const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const contrast = (a: string, b: string) => {
	const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
	return (hi + 0.05) / (lo + 0.05);
};

describe('contraste des couleurs d\'accent (WCAG AA, 4,5:1 pour le texte)', () => {
	it('texte blanc sur un fond plein --accent-fill (boutons principaux, onglet actif)', () => {
		expect(contrast(token('accent-fill'), '#ffffff')).toBeGreaterThanOrEqual(4.5);
	});

	it('--accent-text sur chacun des fonds sombres', () => {
		for (const fond of ['bg', 'panel', 'panel-2']) expect(contrast(token('accent-text'), token(fond)), fond).toBeGreaterThanOrEqual(4.5);
	});
});
