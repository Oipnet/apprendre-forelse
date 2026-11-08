import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { ddlTable, exemple, exempleEtat, exempleMiseAJour, fichierDonnees, fichiersEntree, fichiersSortie, generer, normaliser, placeFonction, type Modele } from '../src/cobol/generateur.js';
import { Programme } from '../src/cobol/interpreteur.js';
import { fragmentDe, lireFragment } from '../src/cobol/partage.js';
import { DEFIS, comparer, defiDuFragment } from '../src/cobol/defis.js';
import { cas, SOLUTIONS } from './cobol/cas.js';
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
		const r = new Programme(generer(modele)).executer({ fichiers: fichiersEntree(modele) });
		let sortie = r.sortie;
		for (const so of fichiersSortie(modele)) sortie += `--- ${so.fichier}\n` + (r.fichiers[so.fichier] ?? '');
		expect(sansEspacesFinaux(sortie)).toBe(sansEspacesFinaux((references.attendus as Record<string, string>)[nom]));
	});
});

describe('interpréteur COBOL, sources écrits à la main', () => {
	const sources = (references as unknown as { sources: Record<string, { sortie: string; fichiers: Record<string, string> }> }).sources;
	const lire = (nom: string) => readFileSync(new URL(`./cobol/sources/${nom}`, import.meta.url), 'latin1');
	it.each(Object.keys(sources))('%s : mêmes octets que GnuCOBOL', (nom) => {
		const r = new Programme(lire(nom)).executer();
		expect(r.sortie).toBe(sources[nom].sortie);
		for (const [f, contenu] of Object.entries(sources[nom].fichiers)) expect(r.fichiers[f]).toBe(contenu);
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
		m.lignes!.push({ fo: '20', sf: '60', ope: 'SQL', opes: 'CL ZZ' });
		expect(() => generer(m)).toThrow(/accès ZZ inconnu/);
	});
});

describe('à la manière de Pacbase', () => {
	it('les fonctions générées portent les codes du générateur d\'IBM et s\'enchaînent par GO TO', () => {
		const source = generer(exemple);
		for (const f of ['F01.', 'F01FA.', 'F05. EXIT.', 'F10FA-10.', 'F22FA.', 'F2099. STOP RUN.', 'F8E.', 'F90. EXIT.']) expect(source).toContain(`       ${f}`);
		expect(source).toContain('       F9099-ITER-FN. GO TO F05.');
		expect(source).toContain("       F20. IF FT = ALL '1'");
		expect(source).toContain('MOVE FA00 TO 1-FA00.');
		expect(source).toContain('IF FA00-REGION NOT = 1-FA00-REGION');
		const maj = generer(exempleMiseAJour);
		for (const f of ['F10CL.', 'F10MV.', 'F22CL.', 'F2401.', 'F26.', 'F30.', 'F33BB.', 'F3900.', 'F70CL.', 'F7301.', 'F76-A.', 'F76CL.', 'F90CN.']) expect(maj).toContain(`       ${f}`);
		expect(maj).toContain('MOVE ZERO TO VCF MOVE HIGH-VALUE TO IND.');
		expect(maj).toContain("IF RTD1 = '1' AND CL-CF1 = '1'");
		expect(maj).toContain('MOVE 1-CL00 TO 2-CL00.');
		expect(maj).toContain('MOVE 2-CL00 TO CN00.');
	});

	it('les lettres passent avant les chiffres : 8E s\'exécute avant 80', () => {
		expect(placeFonction('8E', exemple)).toMatch(/générée/);
		expect(placeFonction('85', exemple)).toMatch(/après l'impression/);
		expect(placeFonction('30', exemple)).toMatch(/avant l'état/);
		expect(placeFonction('95', exemple)).toMatch(/appelée seulement par P/);
	});

	it('un programme d\'avant l\'appel des fichiers est repris : zones E- et S-, fonctions renumérotées', () => {
		const ancien = {
			dictionnaire: exemple.dictionnaire, structures: exemple.structures, sorties: [], tables: [],
			traitement: { ...exemple.traitement, structure: 'FACTURE', fichier: 'FACTURES.DAT', ruptures: ['REGION'] },
			donnees: exemple.appels![0].donnees,
			lignes: [
				{ fo: '85', sf: '10', ope: 'N', opes: 'Negatifs', niv: '05', tc: 'IT', cond: 'E-MTFACT < 0' },
				{ fo: '85', sf: '10', ope: 'M', opes: "'E-LITTERAL' D-NOMCLI" },
				{ fo: '22', sf: '10', ope: 'MES', opes: 'S-NOMCLI' },
			],
		} as unknown as Modele;
		const m = normaliser(ancien);
		expect(m.appels).toEqual([expect.objectContaining({ code: 'FA', utilisation: 'C', structure: 'FACTURE', fichier: 'FACTURES.DAT', argument: ['REGION'], rupture: 1 })]);
		expect(m.lignes).toEqual([
			// noms du manuel IBM : 1-FA00 l'enregistrement traité, FA00 le suivant (lu d'avance)
			expect.objectContaining({ fo: '20', sf: '10', opes: 'FA00-NOMCLI' }),
			expect.objectContaining({ fo: '8E', sf: '10', cond: '1-FA00-MTFACT < 0' }),
			expect.objectContaining({ fo: '8E', sf: '10', opes: "'E-LITTERAL' D-NOMCLI" }),
		]);
		expect(fichierDonnees(ancien)).toBe(fichierDonnees(exemple));
	});

	it('la mise à jour refuse un mouvement sans code mouvement et un argument de longueur différente', () => {
		const m: Modele = structuredClone(exempleMiseAJour);
		m.appels![1].mouvement!.rubrique = '';
		expect(() => generer(m)).toThrow(/code mouvement/);
		const n: Modele = structuredClone(exempleMiseAJour);
		n.appels![1].argument = ['NOMCLI'];
		expect(() => generer(n)).toThrow(/même longueur/);
	});
});

describe('états à la manière de Pacbase', () => {
	it('l\'état donne une fonction F8e : catégories, ligne à éditer, aiguillage, structures, écriture', () => {
		const source = generer(exempleEtat);
		for (const f of ['F81.', 'F81AA.', 'F81DD-090.', 'F81DD-150.', 'F81DD-300. SUBTRACT 1 FROM J07 GIVING J06.', 'F81DD-500. IF NRD NOT = 1 GO TO F81DD-FN.',
			'F81ZZ. MOVE 1 TO J00.', 'F8100.', 'F8102-TOT.', 'F8199. MOVE 6-ET00 TO ET00.', 'F8199-20. ADD 1 TO 5-ET00-1CE. GO TO F81ZZ-009.']) expect(source).toContain(`       ${f}`);
		expect(source).toContain('C01 IS SAUTP.');
		expect(source).toContain("MOVE TS-1-AA TO ST-TA GO TO F81ZZ-009.");
		expect(source).toContain('WRITE ET00 AFTER ADVANCING SAUTP');
		expect(source).toContain('MOVE 1-LI00-1 (J02) TO 6-ET100.');
		expect(source).toContain('10 6-ET102 REDEFINES 6-ET100.');
		expect(source).toContain('05 102-CPT OCCURS 1.');
		expect(source).toContain('MOVE G102-MTFACT TO 6-ET102-MTFACT.');
		// sans état décrit, l'édition simplifiée reste sur la SYSOUT
		expect(source).not.toContain('DISPLAY L-TITRE');
		expect(generer(exemple)).toContain('DISPLAY L-TITRE');
		expect(fichiersSortie(exempleEtat)).toEqual([{ code: 'ET', fichier: 'ETAT.TXT', etat: true }]);
		expect(placeFonction('81', exempleEtat)).toMatch(/état ET1/);
	});

	it('en édition directe, chaque ligne s\'édite au chargement de sa catégorie', () => {
		const m: Modele = structuredClone(exempleEtat);
		m.etats![0].postes = 0;
		const source = generer(m);
		expect(source).toContain("MOVE '010101 ' TO ST-SLS");
		expect(source).toContain('PERFORM F81ZZ-010 THRU F8199-FN');
		expect(source).toContain('       F81ZZ. MOVE SPACE TO CATX. GO TO F81-FN.');
		expect(source).not.toContain('CAT-TAB');
	});

	it('refuse un état mal décrit, avec un message qui dit où', () => {
		const essai = (f: (m: Modele) => void) => { const m: Modele = structuredClone(exempleEtat); f(m); return () => generer(m); };
		expect(essai((m) => { m.appels![1].argument = []; })).toThrow(/lettre de l'état/);
		expect(essai((m) => { m.etats![0].code = 'ET2'; })).toThrow(/ET1 n'est pas décrit/);
		expect(essai((m) => { m.etats![0].libelles![2].numero = '09'; })).toThrow(/sans trou ni doublon/);
		expect(essai((m) => { m.etats![0].structures![1].debut = 59; })).toThrow(/déborde/);
		expect(essai((m) => { m.etats![0].structures![2].debut = 2; })).toThrow(/chevauche/);
		expect(essai((m) => { m.etats![0].structures![0].format = ''; })).toThrow(/format d'édition/);
		expect(essai((m) => { m.etats![0].categories![4].type = '2'; })).toThrow(/n'a que 1 niveau/);
		expect(essai((m) => { m.etats![0].categories![3].st = '01'; })).toThrow(/rubrique à totaliser/);
		expect(essai((m) => { m.etats![0].categories!.push({ ct: 'ZZ', st: '01' }); })).toThrow(/ZZ est interdite/);
		expect(essai((m) => { m.lignes = [{ fo: '81', sf: '10', ope: 'N', opes: 'x', niv: '05', tc: 'BL' }]; })).toThrow(/générée pour l'état ET1/);
	});

	it('l\'état voyage dans le lien de partage', async () => {
		const m = await lireFragment('#' + await fragmentDe(exempleEtat));
		expect(m?.etats).toEqual(exempleEtat.etats);
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

	it('une mise à jour voyage aussi, avec les données de chaque fichier', async () => {
		const recu = await lireFragment(`#${await fragmentDe(exempleMiseAJour)}`);
		expect(recu!.appels.map((a) => a.code)).toEqual(['CL', 'MV', 'CN']);
		expect(generer(recu!)).toBe(generer(exempleMiseAJour));
	});

	it('un lien de la première version (un seul fichier) s\'ouvre encore', async () => {
		const v1 = { v: 1, dictionnaire: exemple.dictionnaire, structures: exemple.structures, sorties: [], tables: [], lignes: [],
			traitement: { ...exemple.traitement, structure: 'FACTURE', fichier: 'FACTURES.DAT', ruptures: ['REGION'] }, donnees: exemple.appels![0].donnees };
		const octets = new Uint8Array(await new Response(new Blob([JSON.stringify(v1)]).stream().pipeThrough(new CompressionStream('deflate-raw'))).arrayBuffer());
		const b64 = Buffer.from(octets).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
		const recu = await lireFragment(`#programme=${b64}`);
		expect(recu!.appels[0]).toEqual(expect.objectContaining({ code: 'FA', fichier: 'FACTURES.DAT', rupture: 1 }));
	});

	it('un fragment abîmé ou étranger n\'ouvre rien', async () => {
		expect(await lireFragment('#code=abc')).toBeNull();
		expect(await lireFragment('#programme=pasducodecompresse')).toBeNull();
		const casse: Modele = structuredClone(exemple);
		casse.appels![0].structure = 'INCONNUE';
		expect(await lireFragment(`#${await fragmentDe(casse)}`)).toBeNull();
	});
});

describe('les défis du vieux', () => {
	const attendus = references.attendus as Record<string, string>;
	it.each(Object.keys(DEFIS))('défi %s : la SYSOUT attendue est celle de la solution de référence sous GnuCOBOL', (id) => {
		expect(comparer(attendus[`defi ${id} : solution`], DEFIS[id].attendu)).toEqual({ reussi: true });
		// le modèle de départ tourne, mais ne réussit pas le défi
		expect(comparer(attendus[`defi ${id} : depart`], DEFIS[id].attendu).reussi).toBe(false);
	});

	it('compare ligne à ligne, espaces de fin ignorés, et montre la première différence', () => {
		expect(comparer('A  \nB\n\n', 'A\nB')).toEqual({ reussi: true });
		expect(comparer('A\nC\nD', 'A\nB\nD')).toEqual({ reussi: false, ligne: 2, attendue: 'B', obtenue: 'C' });
		expect(comparer('A', 'A\nB')).toMatchObject({ reussi: false, ligne: 2, obtenue: '(fin de la SYSOUT)' });
	});

	it('un lien de défi désigne un défi qui existe', () => {
		expect(defiDuFragment('#defi=01')).toBe('01');
		expect(defiDuFragment('#defi=99')).toBeNull();
		expect(defiDuFragment('#programme=abc')).toBeNull();
	});

	it('le défi relevé voyage avec le programme partagé, dans un lien que le formulaire de contact accepte', async () => {
		const solution = { ...SOLUTIONS['01'](normaliser(DEFIS['01'].modele)), defi: '01' };
		const fragment = await fragmentDe(solution);
		const recu = await lireFragment(`#${fragment}`);
		expect(recu?.defi).toBe('01');
		expect(`https://apprendre.forelse.fr/atelier-cobol?utm_source=lien&utm_medium=partage&utm_campaign=atelier-cobol#${fragment}`.length).toBeLessThan(4500);
	});
});
