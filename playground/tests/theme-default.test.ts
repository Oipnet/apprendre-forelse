import { existsSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * La feuille du thème default ne garde que les classes que les gabarits emploient (@source). Un chemin qui ne mène
 * plus à eux (dossier déplacé, image Docker qui ne les copie pas) donnerait une feuille presque vide, sans erreur.
 */
const css = readFileSync(new URL('../src/theme-default.css', import.meta.url), 'utf8');
const sources = [...css.matchAll(/^@source '([^']+)';/gm)].map((m) => m[1]);

describe('sources de la feuille du thème default', () => {
	it('lit les gabarits du moteur', () => {
		expect(sources).toContain('../../platform/templates');
	});

	it.each(sources)('%s existe', (source) => {
		expect(existsSync(new URL(source, new URL('../src/', import.meta.url))), source).toBe(true);
	});

	it('mène bien à la page de base', () => {
		expect(existsSync(new URL('../../platform/templates/base.html.twig', new URL('../src/', import.meta.url)))).toBe(true);
	});
});
