import { createRequire } from 'node:module';
import { describe, expect, it } from 'vitest';
import { ddlTable, exemple, type Modele } from '../src/cobol/generateur.js';
import { analyserEcran, genererEcran, lireSortie, messageEntree, zonesMaquette, type Sortie } from '../src/cobol/dialogue.js';
import { Programme } from '../src/cobol/interpreteur.js';
import { fragmentDe, lireFragment } from '../src/cobol/partage.js';
import { casEcrans } from './cobol/cas.js';
import references from './cobol/gnucobol.json';

/**
 * Les écrans de saisie (Dialogue) : la transaction générée, exécutée par notre interpréteur, renvoie le même écran
 * que la même transaction compilée par GnuCOBOL (écrans sans base, cobol/gnucobol.json). Les accès aux Segments
 * (EXEC SQL) sont vérifiés sur SQLite, comme le ferait le moniteur transactionnel du terminal (-TP).
 */
const require = createRequire(import.meta.url);

describe('écrans de saisie : même écran renvoyé que GnuCOBOL', () => {
	const attendus = (references as unknown as { ecrans: Record<string, string[]> }).ecrans;
	it.each(casEcrans)('%s', (nom, modele, code, echanges) => {
		const ecran = modele.ecrans!.find((e) => e.code === code)!;
		const m = analyserEcran(modele, ecran);
		const pgm = new Programme(genererEcran(modele, ecran));
		let conv = '';
		const sorties = echanges.map((x) => {
			const r = pgm.executer({ fichiers: { 'ECRAN-E': messageEntree(m, x), 'CONV-E': conv } });
			conv = r.fichiers['CONV-S'];
			return r.fichiers['ECRAN-S'];
		});
		expect(sorties).toEqual(attendus[nom]);
	});
});

describe('écrans de saisie sur la base (exemple CL0010 / CL0020)', () => {
	const ouvrir = async () => {
		const SQL = await require('sql.js/dist/sql-asm.js')();
		const db = new SQL.Database();
		for (const t of exemple.tables || []) db.run(ddlTable(exemple, t));
		return db;
	};
	// le moniteur : un échange par touche, la zone de conversation gardée entre deux échanges
	const transaction = (db: unknown, code: string) => {
		const ecran = exemple.ecrans!.find((e) => e.code === code)!;
		const m = analyserEcran(exemple, ecran);
		const pgm = new Programme(genererEcran(exemple, ecran));
		let conv = '';
		return (x: { icf?: string; touche?: string; valeurs?: Record<string, string> }): Sortie => {
			const r = pgm.executer({ fichiers: { 'ECRAN-E': messageEntree(m, x), 'CONV-E': conv }, db });
			conv = r.fichiers['CONV-S'];
			return lireSortie(m, r.fichiers['ECRAN-S']);
		};
	};
	const lignes = (db: any) => db.exec('SELECT NUMCLI, NOMCLI, MTFACT, NBFACT FROM CLIENT ORDER BY NUMCLI')[0]?.values ?? [];

	it('la fiche crée, affiche, modifie et annule un client, et signale les erreurs', async () => {
		const db = await ouvrir();
		const e = transaction(db, 'CL0010');
		expect(e({ icf: '0' }).oper).toBe('A');
		expect(e({ valeurs: { ACTION: 'C', NUMCLI: '12', NOMCLI: 'DUPONT', MTFACT: '1250.50', NBFACT: '3' } }).lierr).toBe('MISE A JOUR EFFECTUEE');
		expect(lignes(db)).toEqual([[12, 'DUPONT', 1250.5, 3]]);
		const vu = e({ valeurs: { NUMCLI: '12' } });
		expect(vu.valeurs).toMatchObject({ NUMCLI: '    12', NOMCLI: 'DUPONT', MTFACT: '  1250.50', NBFACT: '  3' });
		e({ valeurs: { ACTION: 'M', NUMCLI: '12', NOMCLI: 'DUPONT BIS', MTFACT: '-3', NBFACT: '4' } });
		expect(lignes(db)).toEqual([[12, 'DUPONT BIS', -3, 4]]);
		const doublon = e({ valeurs: { ACTION: 'C', NUMCLI: '12', NOMCLI: 'X' } });
		expect(doublon).toMatchObject({ lierr: 'CLIENT : CREATION, LA CLE EXISTE DEJA', curseur: 'NUMCLI' });
		expect(doublon.erreurs.NUMCLI).toBe('5');
		expect(e({ valeurs: { ACTION: 'M', NUMCLI: '99', NOMCLI: 'X' } }).lierr).toBe('CLIENT : CLE INEXISTANTE');
		const classe = e({ valeurs: { ACTION: 'C', NUMCLI: 'AB' } });
		expect(classe.lierr).toBe('CLIENT : NOMBRE ATTENDU');
		expect(classe.erreurs).toMatchObject({ NUMCLI: '4', NOMCLI: '2' });
		expect(e({ valeurs: { ACTION: 'Z', NUMCLI: '12' } }).curseur).toBe('ACTION');
		expect(lignes(db)).toEqual([[12, 'DUPONT BIS', -3, 4]]);
		e({ valeurs: { ACTION: 'A', NUMCLI: '12' } });
		expect(lignes(db)).toEqual([]);
		expect(e({ touche: '02' })).toMatchObject({ oper: 'O', suite: 'CL0020' });
		expect(e({ touche: '03' }).oper).toBe('E');
	});

	it('la liste pagine (PF8), repart du début à l\'appel et met à jour plusieurs lignes', async () => {
		const db = await ouvrir();
		for (let i = 1; i <= 15; i++) db.run(`INSERT INTO CLIENT VALUES (${i * 3}, 'CLIENT ${i}', ${i * 10.5}, ${i})`);
		const e = transaction(db, 'CL0020');
		expect(e({ icf: '0' }).valeurs['NUMCLI/11']).toBe('    36');
		const suite = e({ touche: '08' });
		expect(suite.valeurs['NUMCLI/0']).toBe('    39');
		expect(suite.valeurs['NUMCLI/3']).toBe('');
		expect(e({ touche: '08' }).lierr).toBe('FIN DE LA LISTE');
		const debut = e({ icf: '0' });
		expect(debut.valeurs['NUMCLI/0']).toBe('     3');
		e({ valeurs: { ...debut.valeurs, 'ACTION/0': 'M', 'NOMCLI/0': 'RENOMME', 'ACTION/1': 'A', 'ACTION/2': 'C', 'NUMCLI/2': '100', 'NOMCLI/2': 'NOUVEAU' } });
		const l = lignes(db);
		expect(l.find((x: unknown[]) => x[0] === 3)[1]).toBe('RENOMME');
		expect(l.some((x: unknown[]) => x[0] === 6)).toBe(false);
		expect(l.find((x: unknown[]) => x[0] === 100)[1]).toBe('NOUVEAU');
		// une erreur sur une ligne : rien n'est écrit, la ligne fautive est signalée
		const av = lignes(db);
		const err = e({ valeurs: { ...debut.valeurs, 'ACTION/3': 'M', 'NOMCLI/3': 'CHANGE', 'ACTION/4': 'M', 'MTFACT/4': 'ZZ' } });
		expect(err).toMatchObject({ lierr: 'MONTANT : NOMBRE ATTENDU', curseur: 'MTFACT/4' });
		expect(lignes(db)).toEqual(av);
	});
});

describe('description des écrans (-CE, -CS)', () => {
	const avec = (zones: Array<Record<string, string>>, segments: Array<Record<string, string>> = []): Modele => ({ ...structuredClone(exemple), ecrans: [{ code: 'TS0001', nom: '', zones, segments }] });
	const analyser = (m: Modele) => analyserEcran(m, m.ecrans![0]);

	it('place les libellés et les zones, et répète la partie répétitive', () => {
		const m = analyserEcran(exemple, exemple.ecrans![1]);
		expect(m.irr).toBe(12);
		const zones = zonesMaquette(m);
		expect(zones.filter((z) => z.nom?.startsWith('NUMCLI/')).map((z) => z.l)).toEqual(Array.from({ length: 12 }, (_, i) => 3 + i));
		expect(zones.find((z) => z.texte === 'LISTE DES CLIENTS')).toMatchObject({ l: 0, c: 27, protege: true });
	});

	it('refuse ce que le Dialogue refuserait', () => {
		expect(() => analyser(avec([{ nlg: '100', rub: 'INCONNU', nat: 'V' }]))).toThrow(/absente du dictionnaire/);
		expect(() => analyser(avec([{ nlg: '100', pos: 'A', lg: '2', col: '2', nat: 'L', libelle: 'AAAA' }, { nlg: '110', pos: 'A', lg: '2', col: '4', nat: 'L', libelle: 'BB' }]))).toThrow(/chevauche/);
		expect(() => analyser(avec([{ nlg: '100', rub: 'NOMCLI', pos: 'A', lg: '2', col: '70', nat: 'V', pres: 'F' }]))).toThrow(/déborde/);
		expect(() => analyser(avec([{ nlg: '100', rub: 'PFKEY', maj: 'G', segMaj: 'XX0000', segSrc: '02' }]))).toThrow(/n'existe pas/);
		expect(() => analyser(avec([{ nlg: '100', rub: 'NUMCLI', nat: 'V', segMaj: 'CL00' }]))).toThrow(/absent du -CS/);
		expect(() => analyser(avec([{ nlg: '100', rub: 'NUMCLI', nat: 'V' }], [{ seg: 'ZZ00', recep: 'L', alim: '0001-NUMCLI' }]))).toThrow(/aucune table de code ZZ/);
	});

	it('décrit les enchaînements de touches (PFKEY)', () => {
		expect(analyserEcran(exemple, exemple.ecrans![0]).touches).toEqual([
			{ touche: '02', ecran: 'CL0020', simulation: false, differe: false },
			{ touche: '03', ecran: '', simulation: false, differe: false },
		]);
	});

	it('les écrans voyagent avec le programme partagé', async () => {
		const recu = await lireFragment(`#${await fragmentDe(exemple)}`);
		expect(recu!.ecrans!.map((e) => e.code)).toEqual(['CL0010', 'CL0020']);
		expect(genererEcran(recu!, recu!.ecrans![1])).toBe(genererEcran(exemple, exemple.ecrans![1]));
	});
});
