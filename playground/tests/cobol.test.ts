import { createRequire } from 'node:module';
import { describe, expect, it } from 'vitest';
import { ddlTable, exemple, fichierDonnees, generer, type Modele } from '../src/cobol/generateur.js';
import { Programme } from '../src/cobol/interpreteur.js';
import { fragmentDe, lireFragment } from '../src/cobol/partage.js';
import { cas } from './cobol/cas.js';
import references from './cobol/gnucobol.json';

/**
 * L'atelier COBOL : le source généré, exécuté par notre interpréteur, doit donner exactement ce que donne le même
 * source compilé par GnuCOBOL (sorties de référence dans cobol/gnucobol.json, à régénérer avec
 * cobol/regenerer-attendus.mjs quand le générateur change). La base de données (EXEC SQL), que GnuCOBOL ne compile pas
 * sans précompilateur, est vérifiée sur SQLite.
 */
const sansEspacesFinaux = (s: string) => s.split('\n').map((l) => l.replace(/ +$/, '')).join('\n');

describe('interpréteur COBOL', () => {
	it.each(cas)('%s : même sortie que GnuCOBOL', (nom, modele) => {
		const r = new Programme(generer(modele)).executer({ fichiers: { [modele.traitement.fichier]: fichierDonnees(modele) } });
		let sortie = r.sortie;
		for (const so of modele.sorties || []) sortie += `--- ${so.fichier}\n` + r.fichiers[so.fichier];
		expect(sansEspacesFinaux(sortie)).toBe(sansEspacesFinaux((references.attendus as Record<string, string>)[nom]));
	});
});

describe('base de données façon DB2', () => {
	const require = createRequire(import.meta.url);
	const ouvrir = async () => {
		const SQL = await require('sql.js/dist/sql-asm.js')();
		const db = new SQL.Database();
		for (const t of exemple.tables || []) db.run(ddlTable(exemple, t));
		return db;
	};
	const programme = (lignes: string[]) => new Programme([
		'       IDENTIFICATION DIVISION.', '       PROGRAM-ID. T.', '       ENVIRONMENT DIVISION.', '       DATA DIVISION.',
		'       WORKING-STORAGE SECTION.', '       01  SQLCODE PIC S9(9) VALUE ZERO.', ...lignes.map((l) => `       ${l}`),
	].join('\n'));

	it('l\'exemple cumule les factures par client, et cumule encore au deuxième passage', async () => {
		const db = await ouvrir();
		const pgm = new Programme(generer(exemple));
		const lancer = () => pgm.executer({ fichiers: { 'FACTURES.DAT': fichierDonnees(exemple) }, db });
		const r = lancer();
		expect(r.sql.erreurs).toEqual([]);
		expect(db.exec('SELECT NUMCLI, NOMCLI, MTFACT, NBFACT FROM CLIENT ORDER BY NUMCLI')[0].values).toEqual([
			[2, 'BERNARD', 99.99, 1], [7, 'MARTIN', -80, 1], [12, 'DUPONT', 1250.5, 1], [18, 'FOURNIER', -15.2, 1], [27, 'GIRAUD', 48.75, 1], [31, 'LEROY', 312, 1],
		]);
		expect(r.sortie).toContain('CLIENTS EN BASE :\n  BERNARD                 99.99   1\n');
		lancer();
		expect(db.exec('SELECT MTFACT, NBFACT FROM CLIENT WHERE NUMCLI = 12')[0].values).toEqual([[2501, 2]]);
	});

	it('SQLCODE vaut -803 sur une clé en double, 100 quand rien ne change, 0 sinon', async () => {
		const db = await ouvrir();
		db.run("INSERT INTO CLIENT VALUES (12, 'DUPONT', 0, 0)");
		const r = programme([
			'01  K PIC 9(6) VALUE 12.', '01  N PIC X(20) VALUE "X".', 'PROCEDURE DIVISION.',
			'    EXEC SQL INSERT INTO CLIENT (NUMCLI, NOMCLI) VALUES (:K, :N)', '        END-EXEC', '    DISPLAY SQLCODE',
			'    MOVE 999 TO K', '    EXEC SQL UPDATE CLIENT SET NOMCLI = :N', '        WHERE NUMCLI = :K END-EXEC', '    DISPLAY SQLCODE',
			'    EXEC SQL DELETE FROM CLIENT WHERE NUMCLI = 12 END-EXEC.', '    DISPLAY SQLCODE', '    STOP RUN.',
		]).executer({ db });
		expect(r.sortie).toBe('-000000803\n+000000100\n+000000000\n');
	});

	it('un arrêt anormal annule tout ce que le programme a écrit', async () => {
		const db = await ouvrir();
		db.run("INSERT INTO CLIENT VALUES (12, 'DUPONT', 0, 0)");
		const pgm = programme(['01  Z PIC 9 VALUE 0.', 'PROCEDURE DIVISION.', '    EXEC SQL DELETE FROM CLIENT END-EXEC', '    COMPUTE Z = 1 / Z', '    STOP RUN.']);
		expect(() => pgm.executer({ db })).toThrow(/division par zéro/);
		expect(db.exec('SELECT COUNT(*) FROM CLIENT')[0].values).toEqual([[1]]);
	});

	it('l\'opérateur SQL du langage structuré prend les codes d\'accès de Pacbase', () => {
		const source = generer(exemple);
		expect(source).toContain('EXEC SQL SELECT NUMCLI, NOMCLI, MTFACT, NBFACT INTO');
		expect(source).toContain('EXEC SQL FETCH C-CL INTO');
		const m: Modele = structuredClone(exemple);
		m.lignes!.push({ fo: '23', sf: '10', ope: 'SQL', opes: 'CL ZZ' });
		expect(() => generer(m)).toThrow(/accès ZZ inconnu/);
	});
});

describe('partage par lien', () => {
	it('le programme fait l\'aller-retour par le fragment de l\'adresse', async () => {
		const fragment = await fragmentDe(exemple);
		expect(fragment).toMatch(/^programme=[A-Za-z0-9_-]+$/);
		expect(fragment.length).toBeLessThan(2000);
		const recu = await lireFragment(`#${fragment}`);
		expect(recu).not.toBeNull();
		expect(generer(recu!)).toBe(generer(exemple));
	});

	it('un fragment abîmé ou étranger n\'ouvre rien', async () => {
		expect(await lireFragment('#code=abc')).toBeNull();
		expect(await lireFragment('#programme=pasducodecompresse')).toBeNull();
		const casse: Modele = structuredClone(exemple);
		casse.traitement.structure = 'INCONNUE';
		expect(await lireFragment(`#${await fragmentDe(casse)}`)).toBeNull();
	});
});
