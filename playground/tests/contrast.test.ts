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

/** Les couleurs des pages du site : thème clair (body.site-page), et thème sombre qui en redéfinit une partie. */
const block = (selector: string) => {
	const start = css.indexOf(`${selector} {`);
	return css.slice(start, css.indexOf('}', start));
};
const palette = (text: string) => Object.fromEntries([...text.matchAll(/--(lp-[\w-]+|ok|ko):\s*(#[0-9a-f]{6})/gi)].map((m) => [m[1], m[2]]));
const light = palette(block('\nbody.site-page'));
const themes = { clair: light, sombre: { ...light, ...palette(block("html[data-theme='auto'] body.site-page")) } };

/** Paires texte / fond du site, telles que les règles de site.css les emploient. */
const pairs: [string, string[]][] = [
	['lp-ink', ['lp-bg', 'lp-bg-2', 'lp-card', 'lp-sand', 'lp-tint-3']],
	['lp-ink-2', ['lp-bg', 'lp-bg-2', 'lp-card']],
	['lp-ink-3', ['lp-bg', 'lp-bg-2', 'lp-card']],
	['lp-ink-4', ['lp-bg', 'lp-bg-2', 'lp-card']],
	['lp-ink-5', ['lp-bg', 'lp-card', 'lp-sand']],
	['lp-rust', ['lp-bg', 'lp-bg-2', 'lp-card', 'lp-gold-bg']],
	['lp-on-ink', ['lp-ink', 'lp-ink-hover']],
	['lp-green-ink', ['lp-bg', 'lp-card', 'lp-ok-bg']],
	['lp-ok-ink', ['lp-ok-bg']],
	['lp-ko-text', ['lp-bg', 'lp-card', 'lp-ko-bg']],
	['lp-ko-ink', ['lp-ko-bg', 'lp-card']],
	['lp-ko-ink-2', ['lp-ko-bg', 'lp-ko-field']],
	['lp-rust-ink', ['lp-gold-bg']],
	['lp-dark-ink', ['lp-dark', 'lp-dark-2']],
];

describe.each(Object.entries(themes))('contraste des pages du site, thème %s (WCAG AA)', (_nom, couleurs) => {
	it.each(pairs)('--%s sur ses fonds', (texte, fonds) => {
		for (const fond of fonds) expect(contrast(couleurs[texte], couleurs[fond]), `--${texte} sur --${fond}`).toBeGreaterThanOrEqual(4.5);
	});

	it('texte clair sur les boutons de suppression (--lp-ko, --lp-ko-hover)', () => {
		for (const fond of ['lp-ko', 'lp-ko-hover']) expect(contrast('#fdf4f2', couleurs[fond]), fond).toBeGreaterThanOrEqual(4.5);
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
