import { describe, expect, it } from 'vitest';
import references from './cobol/gnucobol-en.json';

/**
 * L'atelier COBOL en anglais (/en/cobol-workshop) : les exemples et le défi traduits donnent sous notre interpréteur
 * la même sortie que sous GnuCOBOL (cobol/gnucobol-en.json, COBOL_LANGUE=en node cobol/regenerer-attendus.mjs).
 * La langue se fixe au chargement des modules : ce fichier les importe après l'avoir posée.
 */
(globalThis as { COBOL_LANGUE?: string }).COBOL_LANGUE = 'en';
const { generer, fichiersEntree, fichiersSortie, exemple, MOUVEMENT_DEFAUT } = await import('../src/cobol/generateur.js');
const { Programme } = await import('../src/cobol/interpreteur.js');
const { DEFIS, comparer } = await import('../src/cobol/defis.js');
const { casEn } = await import('./cobol/cas-en.js');

const sansEspacesFinaux = (s: string) => s.split('\n').map((l) => l.replace(/ +$/, '')).join('\n');

describe('atelier COBOL en anglais', () => {
	it.each(casEn)('%s : même sortie que GnuCOBOL', (nom, modele) => {
		const r = new Programme(generer(modele)).executer({ fichiers: fichiersEntree(modele) });
		let sortie = r.sortie;
		for (const so of fichiersSortie(modele)) sortie += `--- ${so.fichier}\n` + (r.fichiers[so.fichier] ?? '');
		expect(sansEspacesFinaux(sortie)).toBe(sansEspacesFinaux((references.attendus as Record<string, string>)[nom]));
	});

	it('le défi 01 attend la SYSOUT de la solution de référence, en anglais', () => {
		const attendus = references.attendus as Record<string, string>;
		expect(comparer(attendus['defi 01 : solution'], DEFIS['01'].attendu)).toEqual({ reussi: true });
		expect(comparer(attendus['defi 01 : depart'], DEFIS['01'].attendu).reussi).toBe(false);
		expect(DEFIS['01'].modele.appels![0].fichier).toBe('SALES.DAT');
	});

	it('les exemples, le source généré et les messages sont en anglais', () => {
		expect(exemple.dictionnaire.map((d) => d.code)).toContain('CUSTNO');
		const source = generer(exemple);
		expect(source).toContain('Generated program');
		expect(source).not.toMatch(/Programme genere/);
		expect(MOUVEMENT_DEFAUT.annulation).toBe('D');
		expect(() => generer({ ...exemple, appels: [] })).toThrow('No file called');
	});
});
