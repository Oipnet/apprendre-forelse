import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/** Les deux palettes des éditeurs (la palette neutre du moteur), lues dans editor.css : la sombre de :root, et la claire
    que la page prend quand elle suit le système (:root[data-editor-scheme='auto'] sous prefers-color-scheme: light). */
const editorCss = readFileSync(new URL('../src/editor.css', import.meta.url), 'utf8');
const block = (start: number) => editorCss.slice(start, editorCss.indexOf('}', start));
const colors = (text: string) => Object.fromEntries([...text.matchAll(/--([\w-]+):\s*(#[0-9a-f]{6})/gi)].map((m) => [m[1], m[2]]));
const editorDark = colors(block(editorCss.indexOf(':root {')));
const editorLight = { ...editorDark, ...colors(block(editorCss.indexOf("{\n\t:root[data-editor-scheme='auto'] {", editorCss.indexOf('@media (prefers-color-scheme: light)')) + 2)) };

function luminance(hex: string): number {
	const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const contrast = (a: string, b: string) => {
	const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
	return (hi + 0.05) / (lo + 0.05);
};

describe.each([
	['sombre', editorDark],
	['clair', editorLight],
])('contraste des éditeurs, thème %s (WCAG AA, 4,5:1 pour le texte)', (_nom, token) => {
	it('texte blanc sur un fond plein --accent-fill (boutons principaux, onglet actif)', () => {
		expect(contrast(token['accent-fill'], '#ffffff')).toBeGreaterThanOrEqual(4.5);
	});

	it('--accent-text sur chacun des fonds', () => {
		for (const fond of ['bg', 'panel', 'panel-2']) expect(contrast(token['accent-text'], token[fond]), fond).toBeGreaterThanOrEqual(4.5);
	});

	it('le texte, le texte discret et les états sur chacun des fonds', () => {
		for (const texte of ['text', 'text-soft', 'muted', 'ok', 'ko', 'gold'])
			for (const fond of ['bg', 'panel', 'panel-2']) expect(contrast(token[texte], token[fond]), `--${texte} sur --${fond}`).toBeGreaterThanOrEqual(4.5);
	});

	it('le texte sur une pastille --ok', () => {
		expect(contrast(token['on-ok'], token.ok)).toBeGreaterThanOrEqual(4.5);
	});

	it('la palette claire est bien lue', () => {
		expect(editorLight.bg).not.toBe(editorDark.bg);
	});
});

/** Le thème default (theme-default.css) : jetons de @theme, et ceux que le thème sombre redéfinit. */
const defaultCss = readFileSync(new URL('../src/theme-default.css', import.meta.url), 'utf8');
const between = (text: string, start: number) => text.slice(start, text.indexOf('}', start));
const tokens = (text: string) => Object.fromEntries([...text.matchAll(/--color-([\w-]+):\s*(#[0-9a-f]{6})/gi)].map((m) => [m[1], m[2]]));
const defaultLight = tokens(between(defaultCss, defaultCss.indexOf('\n@theme {')));
const darkMedia = defaultCss.indexOf('@media (prefers-color-scheme: dark)');
const defaultThemes = { clair: defaultLight, sombre: { ...defaultLight, ...tokens(between(defaultCss, defaultCss.indexOf("html[data-theme='auto'] {", darkMedia))) } };

/** Paires texte / fond, telles que les gabarits du thème default les emploient. */
const defaultPairs: [string, string[]][] = [
	['ink', ['bg', 'bg-2', 'surface']],
	['ink-muted', ['bg', 'bg-2', 'surface']],
	['accent', ['bg', 'bg-2', 'surface']],
	['accent-hover', ['bg', 'bg-2', 'surface']],
	['on-accent', ['accent', 'accent-hover']],
	['danger', ['bg', 'surface', 'danger-bg']],
	['success', ['bg', 'surface', 'success-bg']],
	['highlight', ['bg', 'surface']],
	['dark-ink', ['dark']],
	// Le texte des boutons de suppression (btn-danger) : la couleur du fond de page.
	['bg', ['danger']],
	// La coloration du code, sur le fond des blocs.
	...['keyword', 'type', 'property', 'attribute', 'value', 'variable', 'number', 'generic', 'comment'].map((t): [string, string[]] => [`hl-${t}`, ['dark']]),
];

describe.each(Object.entries(defaultThemes))('contraste du thème default, thème %s (WCAG AA)', (_nom, couleurs) => {
	it.each(defaultPairs)('--color-%s sur ses fonds', (texte, fonds) => {
		for (const fond of fonds) expect(contrast(couleurs[texte], couleurs[fond]), `--color-${texte} sur --color-${fond}`).toBeGreaterThanOrEqual(4.5);
	});
});
