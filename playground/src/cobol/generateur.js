// Générateur COBOL : dictionnaire de rubriques + structure + description d'un batch d'édition
// -> programme COBOL (format fixe) compilable par GnuCOBOL.

export const exemple = {
  dictionnaire: [
    { code: 'REGION', libelle: 'Région', type: 'X', longueur: 3 },
    { code: 'NUMCLI', libelle: 'Client', type: '9', longueur: 6 },
    { code: 'NOMCLI', libelle: 'Nom du client', type: 'X', longueur: 20 },
    { code: 'MTFACT', libelle: 'Montant', type: '9', longueur: 7, decimales: 2, signe: true },
    { code: 'NBREL', libelle: 'Nombre de relances', type: '9', longueur: 5 },
    { code: 'NBFACT', libelle: 'Nombre de factures', type: '9', longueur: 3 },
  ],
  structures: [
    { code: 'FACTURE', libelle: 'Facture', rubriques: ['REGION', 'NUMCLI', 'NOMCLI', 'MTFACT'] },
  ],
  traitement: {
    programme: 'ETATREG',
    titre: 'CHIFFRE D AFFAIRES PAR REGION',
    structure: 'FACTURE',
    fichier: 'FACTURES.DAT',
    filtres: [{ rubrique: 'MTFACT', op: 'NOT =', valeur: '0' }],
    ruptures: ['REGION'],
    detail: true,
    colonnes: ['NUMCLI', 'NOMCLI', 'MTFACT'],
    totaux: ['MTFACT'],
    compter: true,
  },
  // Fichiers en sortie : code de 2 lettres (préfixe des rubriques), nom du fichier, rubriques.
  sorties: [
    { code: 'RL', fichier: 'RELANCES.DAT', rubriques: ['NUMCLI', 'NOMCLI', 'MTFACT'] },
  ],
  // Tables de la base (façon DB2) : code de 2 lettres (préfixe des zones d'échange), nom, rubriques, clé.
  tables: [
    { code: 'CL', nom: 'CLIENT', rubriques: ['NUMCLI', 'NOMCLI', 'MTFACT', 'NBFACT'], cle: ['NUMCLI'] },
  ],
  // Langage structuré : une sous-fonction commence par une ligne N (titre, niveau, type, condition).
  lignes: [
    { fo: '85', sf: '10', ope: 'N', opes: 'Montants négatifs à relancer', niv: '05', tc: 'IT', cond: 'E-MTFACT < 0' },
    { fo: '85', sf: '10', ope: 'M', opes: "'** A RELANCER' D-NOMCLI" },
    { fo: '85', sf: '10', ope: 'A', opes: '1 W-NBREL' },
    { fo: '85', sf: '10', ope: 'M', opes: 'E-NUMCLI RL-NUMCLI' },
    { fo: '85', sf: '10', ope: 'M', opes: 'E-NOMCLI RL-NOMCLI' },
    { fo: '85', sf: '10', ope: 'M', opes: 'E-MTFACT RL-MTFACT' },
    { fo: '85', sf: '10', ope: 'ECR', opes: 'RL' },
    { fo: '22', sf: '10', ope: 'N', opes: 'Bilan des relances', niv: '05', tc: 'BL' },
    { fo: '22', sf: '10', ope: 'MES', opes: "'FACTURES A RELANCER : ' W-NBREL" },
    { fo: '22', sf: '20', ope: 'N', opes: 'Clients en base', niv: '05', tc: 'BL' },
    { fo: '22', sf: '20', ope: 'MES', opes: "'CLIENTS EN BASE :'" },
    { fo: '22', sf: '20', ope: 'SQL', opes: 'CL P' },
    { fo: '22', sf: '20', ope: 'SQL', opes: 'CL RN' },
    { fo: '22', sf: '30', ope: 'N', opes: 'Pour chaque client lu', niv: '05', tc: 'DW', cond: 'SQLCODE = 0' },
    { fo: '22', sf: '30', ope: 'MES', opes: "'  ' CL-NOMCLI CL-MTFACT CL-NBFACT" },
    { fo: '22', sf: '30', ope: 'SQL', opes: 'CL RN' },
    { fo: '22', sf: '40', ope: 'N', opes: 'Fin de la liste', niv: '05', tc: 'BL' },
    { fo: '22', sf: '40', ope: 'SQL', opes: 'CL CL' },
    { fo: '30', sf: '10', ope: 'N', opes: 'Cumul par client dans la base', niv: '05', tc: 'BL' },
    { fo: '30', sf: '10', ope: 'M', opes: 'E-NUMCLI CL-NUMCLI' },
    { fo: '30', sf: '10', ope: 'SQL', opes: 'CL R' },
    { fo: '30', sf: '20', ope: 'N', opes: 'Client absent : on le crée', niv: '10', tc: 'IT', cond: 'SQLCODE = 100' },
    { fo: '30', sf: '20', ope: 'M', opes: 'E-NOMCLI CL-NOMCLI' },
    { fo: '30', sf: '20', ope: 'M', opes: 'E-MTFACT CL-MTFACT' },
    { fo: '30', sf: '20', ope: 'M', opes: '1 CL-NBFACT' },
    { fo: '30', sf: '20', ope: 'SQL', opes: 'CL W' },
    { fo: '30', sf: '30', ope: 'N', opes: 'Client connu : on cumule', niv: '10', tc: 'EL' },
    { fo: '30', sf: '30', ope: 'A', opes: 'E-MTFACT CL-MTFACT' },
    { fo: '30', sf: '30', ope: 'A', opes: '1 CL-NBFACT' },
    { fo: '30', sf: '30', ope: 'SQL', opes: 'CL RW' },
  ],
  donnees: [
    { REGION: 'NOR', NUMCLI: '12', NOMCLI: 'DUPONT', MTFACT: '1250.50' },
    { REGION: 'SUD', NUMCLI: '7', NOMCLI: 'MARTIN', MTFACT: '-80' },
    { REGION: 'NOR', NUMCLI: '31', NOMCLI: 'LEROY', MTFACT: '312' },
    { REGION: 'EST', NUMCLI: '44', NOMCLI: 'GARNIER', MTFACT: '0' },
    { REGION: 'SUD', NUMCLI: '2', NOMCLI: 'BERNARD', MTFACT: '99.99' },
    { REGION: 'OUE', NUMCLI: '18', NOMCLI: 'FOURNIER', MTFACT: '-15.20' },
    { REGION: 'NOR', NUMCLI: '27', NOMCLI: 'GIRAUD', MTFACT: '48.75' },
  ],
};

const OPS = ['=', 'NOT =', '>', '<', '>=', '<='];
export const operateurs = OPS;

const ident = (s) => String(s).toUpperCase().replace(/[^A-Z0-9-]/g, '-').replace(/^-+|-+$/g, '').slice(0, 24) || 'X';
const litteral = (s) => '"' + String(s).replace(/"/g, '""') + '"';

export function rubrique(model, code) {
  const r = model.dictionnaire.find((d) => d.code === code);
  if (!r) throw new Error(`Rubrique inconnue : ${code}`);
  return r;
}

// Clause PIC de stockage d'une rubrique.
export function picStockage(r) {
  if (r.type === 'X') return `X(${r.longueur})`;
  const ent = r.longueur - (r.decimales || 0);
  let p = (r.signe ? 'S' : '') + `9(${ent})`;
  if (r.decimales) p += `V9(${r.decimales})`;
  return p;
}

// Longueur sur fichier (le signe séparé prend un caractère).
export function longueurFichier(r) {
  return r.longueur + (r.type === '9' && r.signe ? 1 : 0);
}

// Clause PIC d'édition pour un nombre de chiffres entiers donné.
function picEdition(ent, dec, signe) {
  let p;
  if (signe) p = ent > 1 ? `-(${ent})9` : '-9';
  else p = ent > 1 ? `Z(${ent - 1})9` : '9';
  if (dec) p += '.' + '9'.repeat(dec);
  return p;
}
function largeurEdition(ent, dec, signe) {
  return ent + (signe ? 1 : 0) + (dec ? dec + 1 : 0);
}

// Valeur saisie (ex. "-12.5") -> image fichier de la rubrique.
export function versFichier(r, valeur) {
  const v = String(valeur ?? '').trim();
  if (r.type === 'X') return v.slice(0, r.longueur).padEnd(r.longueur, ' ');
  const dec = r.decimales || 0;
  const m = v.replace(',', '.').match(/^([+-]?)(\d*)(?:\.(\d*))?$/);
  if (!m) throw new Error(`Valeur numérique invalide pour ${r.code} : "${v}"`);
  const negatif = m[1] === '-';
  const ent = (m[2] || '0').replace(/^0+(?=\d)/, '');
  const frac = (m[3] || '').padEnd(dec, '0').slice(0, dec);
  const chiffres = (ent + frac).padStart(r.longueur, '0');
  if (chiffres.length > r.longueur) throw new Error(`Valeur trop grande pour ${r.code} : "${v}"`);
  return r.signe ? (negatif ? '-' : '+') + chiffres : chiffres;
}

export function fichierDonnees(model) {
  const t = model.traitement;
  const s = model.structures.find((x) => x.code === t.structure);
  return model.donnees
    .map((ligne) => s.rubriques.map((c, i) => versFichier(rubrique(model, c), Array.isArray(ligne) ? ligne[i] : ligne[c])).join(''))
    .join('\n') + '\n';
}

// ---- Émission en format fixe ----
class Source {
  constructor() { this.lignes = []; }
  a(t = '') { this.ligne('       ' + t); }            // zone A (colonne 8)
  b(t = '') { this.ligne('           ' + t); }        // zone B (colonne 12)
  i(n, t) { this.ligne('           ' + '   '.repeat(n) + t); }
  com(t) { this.ligne('      * ' + t); }
  // instruction coupée entre deux mots (hors littéral) si elle dépasse la colonne 72
  instr(n, t) {
    const marge = '           ' + '   '.repeat(n);
    const mots = decouper(t);
    let ligne = marge;
    for (const m of mots) {
      const essai = ligne.trim() ? `${ligne} ${m}` : marge + m;
      if (essai.length > 72 && ligne.trim()) { this.ligne(ligne); ligne = marge + '    ' + m; }
      else ligne = essai;
    }
    if (ligne.trim()) this.ligne(ligne);
  }
  ligne(t) {
    if (t.length > 72) throw new Error(`Ligne COBOL trop longue (${t.length}) : ${t}`);
    this.lignes.push(t);
  }
  texte() { return this.lignes.join('\n') + '\n'; }
}

// Découpe en mots en respectant les littéraux 'xx' ou "xx".
export function decouper(t) {
  const mots = [];
  let i = 0;
  while (i < t.length) {
    if (t[i] === ' ') { i++; continue; }
    let j = i;
    if (t[i] === '"' || t[i] === "'") {
      const q = t[i]; j++;
      while (j < t.length && !(t[j] === q && t[j + 1] !== q)) j += t[j] === q ? 2 : 1;
      j++;
    } else while (j < t.length && t[j] !== ' ') j++;
    mots.push(t.slice(i, j));
    i = j;
  }
  return mots;
}

// Ligne d'édition : liste de { nom?, pic?, valeur? }. Les littéraux sont découpés pour tenir en 72 colonnes.
function emettreLigneEdition(src, nom, champs) {
  src.a(`01  ${nom}.`);
  for (const c of champs) {
    if (c.valeur !== undefined) {
      const morceaux = c.valeur.match(/.{1,28}/g) || [''];
      for (const m of morceaux) src.b(`05 FILLER PIC X(${m.length}) VALUE ${litteral(m)}.`);
    } else if (c.blanc) {
      src.b(`05 FILLER PIC X(${c.blanc}) VALUE SPACES.`);
    } else {
      src.b(`05 ${c.nom} PIC ${c.pic}.`);
    }
  }
}

function condition(model, t, prefixe) {
  return (t.filtres || []).map((f) => {
    if (!OPS.includes(f.op)) throw new Error(`Opérateur inconnu : ${f.op}`);
    const r = rubrique(model, f.rubrique);
    const val = r.type === 'X' ? litteral(f.valeur) : String(Number(String(f.valeur).replace(',', '.')));
    if (r.type === '9' && Number.isNaN(Number(val))) throw new Error(`Valeur de filtre invalide : ${f.valeur}`);
    return `${prefixe}${ident(r.code)} ${f.op} ${val}`;
  });
}

// Rubriques de rupture, de la plus large à la plus fine (ancien format : une seule « rupture »).
export function rupturesDe(t) {
  return t.ruptures ?? (t.rupture ? [t.rupture] : []);
}
export const MAX_RUPTURES = 3;

export function generer(model) {
  const t = model.traitement;
  const s = model.structures.find((x) => x.code === t.structure);
  if (!s) throw new Error(`Structure inconnue : ${t.structure}`);
  const pgm = ident(t.programme).replace(/-/g, '').slice(0, 8) || 'PGM';
  const ruptures = rupturesDe(t).map((c) => rubrique(model, c));
  if (ruptures.length > MAX_RUPTURES) throw new Error(`${MAX_RUPTURES} niveaux de rupture au plus`);
  if (new Set(ruptures).size !== ruptures.length) throw new Error('Une rubrique ne peut servir qu une fois en rupture');
  const ruptureR = ruptures[0] || null;
  const N = ruptures.length;
  const K = (r) => ident(r.code);
  const colonnes = t.colonnes.map((c) => rubrique(model, c));
  const totaux = (t.totaux || []).map((c) => rubrique(model, c));
  for (const r of totaux) if (r.type !== '9') throw new Error(`Total impossible sur une rubrique alphanumérique : ${r.code}`);
  const lecture = ruptureR ? 'F-TRIE' : 'F-ENTREE';
  const src = new Source();
  const dico = new Map(model.dictionnaire.map((d) => [d.code, d]));

  // ---------- langage structuré : analyse ----------
  const fonctions = analyserLignes(model.lignes || []);
  const sorties = model.sorties || [];
  for (const so of sorties) {
    if (!/^[A-Z][A-Z0-9]$/.test(so.code || '')) throw new Error(`Fichier en sortie : code ${so.code || '(vide)'} invalide (2 caractères, une lettre en tête)`);
    if (['ED', 'LC', 'LG', 'RT', 'NB', 'IN'].includes(so.code)) throw new Error(`Fichier en sortie : le code ${so.code} est réservé`);
    if (!/^[A-Z0-9][A-Z0-9.]*$/.test(so.fichier || '')) throw new Error(`Fichier en sortie ${so.code} : nom de fichier invalide`);
    if (!so.rubriques?.length) throw new Error(`Fichier en sortie ${so.code} : aucune rubrique`);
    so.rubriques.forEach((c) => rubrique(model, c));
  }
  if (new Set(sorties.map((x) => x.code)).size !== sorties.length) throw new Error('Deux fichiers en sortie ont le même code');
  const tables = model.tables || [];
  verifierTables(model, tables);
  const travail = new Map();   // W-xxx déclarées automatiquement d'après le dictionnaire
  const editees = new Map();   // ED-xxx pour afficher un nombre lisiblement
  const prefixesConnus = new Set(['E', 'S', 'P', ...sorties.map((x) => x.code), ...tables.map((x) => x.code)]);
  const refNumerique = (mot) => {
    const m = /^([A-Z][A-Z0-9]?)-(.+)$/.exec(mot);
    if (!m || !(prefixesConnus.has(m[1]) || /^W\d?$/.test(m[1]))) return null;
    const r = dico.get(m[2]);
    return r && r.type === '9' ? r : null;
  };
  const noter = (mot) => {
    const m = /^(W\d?)-(.+)$/.exec(mot);
    if (m && dico.has(m[2])) travail.set(mot, dico.get(m[2]));
  };

  const enregistrement = (pref, nom, niveau = '01') => {
    src.a(`${niveau}  ${nom}.`);
    for (const c of s.rubriques) {
      const r = rubrique(model, c);
      const signe = r.type === '9' && r.signe ? ' SIGN LEADING SEPARATE' : '';
      src.b(`05 ${pref}${ident(r.code)} PIC ${picStockage(r)}${signe}.`);
    }
  };

  // traduction d'une ligne d'instruction
  const traduire = (l, indent, ou) => {
    const mots = decouper(l.opes || '');
    mots.forEach(noter);
    const ope = (l.ope || '').toUpperCase();
    const exiger = (n, forme) => { if (mots.length < n) throw new Error(`${ou} : ${ope} attend ${forme}`); };
    switch (ope) {
      case '*': src.com(l.opes || ''); return;
      case 'M': exiger(2, 'une source et au moins une cible'); src.instr(indent, `MOVE ${mots[0]} TO ${mots.slice(1).join(' ')}`); return;
      case 'A': exiger(2, 'une valeur et une cible'); src.instr(indent, `ADD ${mots.slice(0, -1).join(' ')} TO ${mots.at(-1)}`); return;
      case 'S': exiger(2, 'une valeur et une cible'); src.instr(indent, `SUBTRACT ${mots.slice(0, -1).join(' ')} FROM ${mots.at(-1)}`); return;
      case 'C': {
        const k = mots.indexOf('=');
        if (k !== 1 || mots.length < 3) throw new Error(`${ou} : C s'écrit « C CIBLE = expression » (espaces autour des opérateurs)`);
        src.instr(indent, `COMPUTE ${mots[0]} = ${mots.slice(2).join(' ')}`);
        return;
      }
      case 'P': {
        exiger(1, 'un numéro de fonction');
        const fo = mots[0].replace(/^F/, '').padStart(2, '0');
        if (!fonctions.has(fo)) throw new Error(`${ou} : P ${mots[0]} : la fonction ${fo} n'existe pas`);
        src.instr(indent, `PERFORM F${fo}`);
        return;
      }
      case 'MES': {
        exiger(1, 'au moins un élément à afficher');
        const elems = mots.map((m) => {
          const r = refNumerique(m);
          if (!r) return m;
          const ed = `ED-${m}`.slice(0, 30);
          editees.set(ed, r);
          src.instr(indent, `MOVE ${m} TO ${ed}`);
          return ed;
        });
        src.instr(indent, `DISPLAY ${elems.join(' ')}`);
        return;
      }
      case 'COB': src.instr(indent, l.opes || ''); return;
      case 'ECR': {
        exiger(1, 'le code d\'un fichier en sortie');
        const so = sorties.find((x) => x.code === mots[0].toUpperCase());
        if (!so) throw new Error(`${ou} : ECR ${mots[0]} : aucun fichier en sortie de ce code`);
        src.instr(indent, `WRITE ${so.code}-ENR`);
        return;
      }
      case 'SQL': {
        exiger(2, 'le code de la table puis le type d\'accès (R RU W RW D P RN CL)');
        const tb = tables.find((x) => x.code === mots[0].toUpperCase());
        if (!tb) throw new Error(`${ou} : SQL ${mots[0]} : aucune table de ce code`);
        const acces = mots[1].toUpperCase();
        if (!(acces in ACCES_SQL)) throw new Error(`${ou} : accès ${acces} inconnu (${Object.keys(ACCES_SQL).join(' ')})`);
        for (const t of sqlAcces(acces, tb)) src.instr(indent, t);
        return;
      }
      case 'SCO': src.instr(indent, 'EXEC SQL COMMIT END-EXEC'); return;
      case 'SRO': src.instr(indent, 'EXEC SQL ROLLBACK END-EXEC'); return;
      default: throw new Error(`${ou} : opérateur ${ope || '(vide)'} inconnu (${OPERATEURS_STRUCTURES.join(' ')})`);
    }
  };

  // traduction d'une fonction : sous-fonctions imbriquées selon leur niveau
  const corpsFonctions = new Map();
  for (const [fo, sous] of fonctions) {
    const lignesCobol = [];
    const sauver = src.lignes;
    src.lignes = lignesCobol;
    const pile = [];
    const fermer = () => { const x = pile.pop(); src.i(x.indent, x.tc === 'DW' ? 'END-PERFORM' : (x.tc === 'BL' ? '' : 'END-IF')); if (x.tc === 'BL') src.lignes.pop(); };
    for (const sf of sous) {
      const ou = `fonction ${fo} sous-fonction ${sf.sf}`;
      const niv = Number(sf.niv || 5);
      while (pile.length && pile.at(-1).niv > niv) fermer();
      let el = false;
      if (pile.length && pile.at(-1).niv === niv) {
        if (sf.tc === 'EL') {
          if (pile.at(-1).tc !== 'IT') throw new Error(`${ou} : EL doit suivre une sous-fonction IT de même niveau`);
          el = true;
        } else fermer();
      } else if (sf.tc === 'EL') throw new Error(`${ou} : EL sans IT de même niveau juste avant`);
      const indent = el ? pile.at(-1).indent : pile.length ? pile.at(-1).indent + (pile.at(-1).tc === 'BL' ? 0 : 1) : 0;
      if (sf.titre) src.com(`${fo}${sf.sf} ${sf.titre}`.normalize('NFD').replace(/[^\x20-\x7e]/g, '').slice(0, 62));
      if (el) { src.i(indent, 'ELSE'); pile.at(-1).tc = 'EL'; }
      else if (sf.tc === 'IT') { src.instr(indent, `IF ${conditionStructuree(sf.cond, ou)}`); pile.push({ niv, tc: 'IT', indent }); }
      else if (sf.tc === 'DW') { src.instr(indent, `PERFORM UNTIL NOT (${conditionStructuree(sf.cond, ou)})`); pile.push({ niv, tc: 'DW', indent }); }
      else pile.push({ niv, tc: 'BL', indent });
      const dedans = sf.tc === 'BL' ? indent : indent + 1;
      for (const l of sf.lignes) traduire(l, dedans, ou);
      for (const mot of decouper(sf.cond || '')) noter(mot);
    }
    while (pile.length) fermer();
    src.lignes = sauver;
    corpsFonctions.set(fo, lignesCobol);
  }
  const fonctionsEntre = (a, b) => [...fonctions.keys()].filter((fo) => Number(fo) >= a && Number(fo) <= b).sort();
  for (const fo of fonctions.keys()) {
    if (RESERVEES.includes(fo)) throw new Error(`La fonction ${fo} est générée automatiquement : prenez un autre numéro`);
  }

  // ---------- IDENTIFICATION / ENVIRONMENT ----------
  src.a('IDENTIFICATION DIVISION.');
  src.a(`PROGRAM-ID. ${pgm}.`);
  src.com(`Programme genere : ${t.titre || pgm}`.normalize('NFD').replace(/[^\x20-\x7e]/g, '').slice(0, 60));
  src.com('F01 initialisations, F05 lecture, F10 ruptures, F20 fin de');
  src.com('traitement, F80 preparation et F90 impression de l etat,');
  src.com('F95 lecture d avance. Les autres fonctions sont les votres.');
  src.a('ENVIRONMENT DIVISION.');
  src.a('INPUT-OUTPUT SECTION.');
  src.a('FILE-CONTROL.');
  src.b(`SELECT F-ENTREE ASSIGN TO ${litteral(t.fichier)}`);
  src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
  if (ruptureR) {
    src.b('SELECT F-TRIE ASSIGN TO "TRIE.TMP"');
    src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
    src.b('SELECT F-TRAVAIL ASSIGN TO "TRAVAIL.TMP".');
  }
  for (const so of sorties) {
    src.b(`SELECT F-${so.code} ASSIGN TO ${litteral(so.fichier)}`);
    src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
  }
  src.a('DATA DIVISION.');
  src.a('FILE SECTION.');
  src.a('FD  F-ENTREE.');
  enregistrement('I-', 'I-ENR');
  if (ruptureR) {
    src.a('SD  F-TRAVAIL.');
    enregistrement('W-', 'W-ENR');
    src.a('FD  F-TRIE.');
    enregistrement('T-', 'T-ENR');
  }
  for (const so of sorties) {
    src.a(`FD  F-${so.code}.`);
    src.a(`01  ${so.code}-ENR.`);
    for (const c of so.rubriques) {
      const r = rubrique(model, c);
      const signe = r.type === '9' && r.signe ? ' SIGN LEADING SEPARATE' : '';
      src.b(`05 ${so.code}-${ident(r.code)} PIC ${picStockage(r)}${signe}.`);
    }
  }
  src.a('WORKING-STORAGE SECTION.');
  src.com('Enregistrements precedent (P-), courant (E-) et suivant (S-)');
  enregistrement('P-', 'P-ENR');
  enregistrement('E-', 'E-ENR');
  enregistrement('S-', 'S-ENR');
  src.a('01  IND-FIN PIC X VALUE "N".');
  src.b('88 FIN-TRAITEMENT VALUE "O".');
  src.a('01  IND-SUIVANT PIC X VALUE "N".');
  src.b('88 FIN-SUIVANT VALUE "O".');
  src.a('01  IND-RETENU PIC X VALUE "N".');
  src.b('88 RETENU VALUE "O".');
  if (ruptureR) {
    src.a('01  IND-PREMIER PIC X VALUE "O".');
    src.b('88 PREMIER-ENR VALUE "O".');
    src.com('Ruptures : RTPn avec le precedent, RTDn avec le suivant');
    ruptures.forEach((_, i) => src.a(`01  RTP${i + 1} PIC 9 VALUE ZERO.`));
    ruptures.forEach((_, i) => src.a(`01  RTD${i + 1} PIC 9 VALUE ZERO.`));
  }
  src.a('01  NB-LUS PIC 9(7) VALUE ZERO.');
  src.a('01  NB-RETENUS PIC 9(7) VALUE ZERO.');
  const niveauxTotal = ['G', ...ruptures.map((_, i) => `R${i + 1}`)];
  for (const n of niveauxTotal) {
    if (t.compter) src.a(`01  ${n}-NB PIC 9(7) VALUE ZERO.`);
    for (const r of totaux) {
      const ent = r.longueur - (r.decimales || 0) + 4;
      src.a(`01  ${n}-${ident(r.code)} PIC S9(${ent})${r.decimales ? `V9(${r.decimales})` : ''} VALUE ZERO.`);
    }
  }
  if (travail.size) {
    src.com('Zones de travail, au format des rubriques du dictionnaire');
    for (const [nom, r] of travail) src.a(`01  ${nom} PIC ${picStockage(r)} VALUE ${r.type === 'X' ? 'SPACES' : 'ZERO'}.`);
  }
  if (tables.length) {
    src.com('Base de donnees : code retour et zones d echange des tables');
    src.a('01  SQLCODE PIC S9(9) VALUE ZERO.');
    for (const tb of tables) {
      src.com(`Table ${tb.nom} (code ${tb.code}), cle ${tb.cle.join(' ')}`);
      src.a(`01  ${tb.code}-LIGNE.`);
      for (const c of tb.rubriques) src.b(`05 ${tb.code}-${ident(c)} PIC ${picStockage(rubrique(model, c))}.`);
    }
    for (const tb of tables) {
      src.instr(0, `EXEC SQL DECLARE C-${tb.code} CURSOR FOR SELECT ${tb.rubriques.join(', ')} FROM ${tb.nom} ORDER BY ${tb.cle.join(', ')} END-EXEC.`);
    }
  }
  if (editees.size) {
    src.com('Zones d affichage des nombres (MES)');
    for (const [nom, r] of editees) src.a(`01  ${nom} PIC ${picEdition(r.longueur - (r.decimales || 0), r.decimales || 0, true)}.`);
  }

  // ---------- lignes de l'état ----------
  const largeurs = colonnes.map((r) => {
    const l = r.type === 'X' ? r.longueur : largeurEdition(r.longueur - (r.decimales || 0), r.decimales || 0, r.signe);
    return Math.max(l, r.code.length);
  });
  const entete = (r, l) => {
    const lib = (r.libelle || '').normalize('NFD').replace(/[^\x20-\x7e]/g, '').toUpperCase();
    const texte = lib.length <= l ? lib : r.code;
    return r.type === 'X' ? texte.padEnd(l) : texte.padStart(l);
  };
  const titre = (t.titre || pgm).normalize('NFD').replace(/[^\x20-\x7e]/g, '').toUpperCase();
  src.com('Lignes de l etat');
  emettreLigneEdition(src, 'L-TITRE', [{ valeur: titre }]);
  emettreLigneEdition(src, 'L-ENTETE', colonnes.flatMap((r, i) => [
    ...(i ? [{ blanc: 2 }] : []), { valeur: entete(r, largeurs[i]) },
  ]));
  emettreLigneEdition(src, 'L-TRAIT', [{ valeur: '-'.repeat(largeurs.reduce((a, b) => a + b, 0) + 2 * (largeurs.length - 1)) }]);
  emettreLigneEdition(src, 'L-DETAIL', colonnes.flatMap((r, i) => {
    const champ = r.type === 'X'
      ? { nom: `D-${ident(r.code)}`, pic: `X(${largeurs[i]})` }
      : { nom: `D-${ident(r.code)}`, pic: picEdition(largeurs[i] - (r.signe ? 1 : 0) - (r.decimales ? r.decimales + 1 : 0), r.decimales || 0, r.signe) };
    return [...(i ? [{ blanc: 2 }] : []), champ];
  }));
  const marge = (i) => '  '.repeat(i);
  const nomRupture = (r) => (r.libelle || r.code).normalize('NFD').replace(/[^\x20-\x7e]/g, '').toUpperCase();
  ruptures.forEach((r, i) => {
    emettreLigneEdition(src, `L-GROUPE-${i + 1}`, [
      { valeur: `${marge(i)}${nomRupture(r)} : ` },
      { nom: `L-CLE-${i + 1}`, pic: `X(${r.longueur})` },
    ]);
  });
  for (const n of niveauxTotal) {
    const i = n === 'G' ? -1 : Number(n.slice(1)) - 1;
    const champs = [{ valeur: n === 'G' ? 'TOTAL GENERAL' : `${marge(i)}  TOTAL ${ruptures[i].code}` }];
    if (t.compter) champs.push({ valeur: '  NB : ' }, { nom: `L${n}-NB`, pic: 'Z(6)9' });
    for (const r of totaux) {
      const ent = r.longueur - (r.decimales || 0) + 4;
      champs.push({ valeur: `  ${r.code} : ` }, { nom: `L${n}-${ident(r.code)}`, pic: picEdition(ent, r.decimales || 0, true) });
    }
    emettreLigneEdition(src, `L-TOTAL-${n}`, champs);
  }
  src.a('01  L-COMPTES.');
  src.b('05 FILLER PIC X(14) VALUE "LUS / RETENUS ".');
  src.b('05 LC-LUS PIC Z(6)9.');
  src.b('05 FILLER PIC X(3) VALUE " / ".');
  src.b('05 LC-RETENUS PIC Z(6)9.');

  // ---------- PROCEDURE DIVISION ----------
  const appeler = (fos, n = 1) => fos.forEach((fo) => src.i(n, `PERFORM F${fo}`));
  src.a('PROCEDURE DIVISION.');
  src.a('F00-ENCHAINEMENT.');
  src.b('PERFORM F01-INITIALISATIONS');
  src.b('PERFORM F05-LECTURE');
  src.b('PERFORM UNTIL FIN-TRAITEMENT');
  src.i(1, 'PERFORM F10-RUPTURES');
  appeler(fonctionsEntre(25, 79));
  src.i(1, 'PERFORM F80-PREPARATION');
  appeler(fonctionsEntre(81, 89));
  src.i(1, 'PERFORM F90-IMPRESSION');
  appeler(fonctionsEntre(91, 94));
  src.i(1, 'PERFORM F05-LECTURE');
  src.b('END-PERFORM');
  src.b('PERFORM F20-FIN-TRAITEMENT');
  src.b('STOP RUN.');

  src.a('F01-INITIALISATIONS.');
  if (ruptureR) {
    src.b('SORT F-TRAVAIL');
    for (const r of ruptures) src.b(`    ON ASCENDING KEY W-${K(r)}`);
    src.b('    USING F-ENTREE GIVING F-TRIE');
  }
  src.b(`OPEN INPUT ${lecture}`);
  for (const so of sorties) src.b(`OPEN OUTPUT F-${so.code}`);
  src.b('DISPLAY L-TITRE');
  src.b('DISPLAY L-ENTETE');
  src.b('DISPLAY L-TRAIT');
  appeler(fonctionsEntre(2, 4), 0);
  src.b('PERFORM F95-LIRE-SUIVANT.');

  src.a('F05-LECTURE.');
  src.b('IF FIN-SUIVANT');
  src.i(1, 'SET FIN-TRAITEMENT TO TRUE');
  src.b('ELSE');
  src.i(1, 'MOVE E-ENR TO P-ENR');
  src.i(1, 'MOVE S-ENR TO E-ENR');
  src.i(1, 'PERFORM F95-LIRE-SUIVANT');
  src.b('END-IF.');

  src.a('F10-RUPTURES.');
  if (ruptureR) {
    const tous = (pref) => ruptures.map((_, i) => `${pref}${i + 1}`).join(' ');
    src.b('IF PREMIER-ENR');
    src.i(1, `MOVE 1 TO ${tous('RTP')}`);
    src.i(1, 'MOVE "N" TO IND-PREMIER');
    src.b('ELSE');
    src.i(1, `MOVE ZERO TO ${tous('RTP')}`);
    ruptures.forEach((r, i) => {
      src.i(1, `IF ${i ? `RTP${i} = 1 OR ` : ''}E-${K(r)} NOT = P-${K(r)}`);
      src.i(2, `MOVE 1 TO RTP${i + 1}`);
      src.i(1, 'END-IF');
    });
    src.b('END-IF');
    src.b('IF FIN-SUIVANT');
    src.i(1, `MOVE 1 TO ${tous('RTD')}`);
    src.b('ELSE');
    src.i(1, `MOVE ZERO TO ${tous('RTD')}`);
    ruptures.forEach((r, i) => {
      src.i(1, `IF ${i ? `RTD${i} = 1 OR ` : ''}E-${K(r)} NOT = S-${K(r)}`);
      src.i(2, `MOVE 1 TO RTD${i + 1}`);
      src.i(1, 'END-IF');
    });
    src.b('END-IF.');
  } else src.b('CONTINUE.');

  src.a('F20-FIN-TRAITEMENT.');
  if (t.compter) src.b('MOVE G-NB TO LG-NB');
  for (const r of totaux) src.b(`MOVE G-${ident(r.code)} TO LG-${ident(r.code)}`);
  src.b('DISPLAY L-TRAIT');
  src.b('DISPLAY L-TOTAL-G');
  src.b('MOVE NB-LUS TO LC-LUS');
  src.b('MOVE NB-RETENUS TO LC-RETENUS');
  src.b('DISPLAY L-COMPTES');
  appeler(fonctionsEntre(21, 24), 0);
  for (const so of sorties) src.b(`CLOSE F-${so.code}`);
  src.b(`CLOSE ${lecture}.`);

  // fonctions de l'utilisateur, dans l'ordre des numéros
  for (const [fo, corps] of [...corpsFonctions].sort((a, b) => a[0].localeCompare(b[0]))) {
    const titreF = fonctions.get(fo)[0]?.titre;
    src.a(`F${fo}.`);
    if (titreF) src.com(`Fonction ${fo} : ${titreF}`.normalize('NFD').replace(/[^\x20-\x7e]/g, '').slice(0, 62));
    for (const l of corps) src.lignes.push(l);
    src.b('CONTINUE.');
  }

  src.a('F80-PREPARATION.');
  src.b('ADD 1 TO NB-RETENUS');
  ruptures.forEach((r, i) => {
    const nv = `R${i + 1}`;
    src.b(`IF RTP${i + 1} = 1`);
    if (t.compter) src.i(1, `MOVE ZERO TO ${nv}-NB`);
    for (const x of totaux) src.i(1, `MOVE ZERO TO ${nv}-${ident(x.code)}`);
    src.i(1, `MOVE E-${K(r)} TO L-CLE-${i + 1}`);
    src.i(1, `DISPLAY L-GROUPE-${i + 1}`);
    src.b('END-IF');
  });
  const niv = ruptureR ? `R${N}` : 'G';
  if (t.compter) src.b(`ADD 1 TO ${niv}-NB`);
  for (const r of totaux) src.b(`ADD E-${ident(r.code)} TO ${niv}-${ident(r.code)}`);
  for (const r of colonnes) src.b(`MOVE E-${ident(r.code)} TO D-${ident(r.code)}`);
  src.b('CONTINUE.');

  src.a('F90-IMPRESSION.');
  if (t.detail) src.b('DISPLAY L-DETAIL');
  for (let i = N - 1; i >= 0; i--) {
    const nv = `R${i + 1}`, parent = i ? `R${i}` : 'G';
    src.b(`IF RTD${i + 1} = 1`);
    if (t.compter) src.i(1, `MOVE ${nv}-NB TO L${nv}-NB`);
    for (const x of totaux) src.i(1, `MOVE ${nv}-${ident(x.code)} TO L${nv}-${ident(x.code)}`);
    src.i(1, `DISPLAY L-TOTAL-${nv}`);
    if (t.compter) src.i(1, `ADD ${nv}-NB TO ${parent}-NB`);
    for (const x of totaux) src.i(1, `ADD ${nv}-${ident(x.code)} TO ${parent}-${ident(x.code)}`);
    if (i === 0) src.i(1, 'DISPLAY " "');
    src.b('END-IF');
  }
  src.b('CONTINUE.');

  src.a('F95-LIRE-SUIVANT.');
  src.b('MOVE "N" TO IND-RETENU');
  src.b('PERFORM UNTIL RETENU OR FIN-SUIVANT');
  src.i(1, `READ ${lecture} INTO S-ENR`);
  src.i(2, 'AT END SET FIN-SUIVANT TO TRUE');
  src.i(2, 'NOT AT END');
  src.i(3, 'ADD 1 TO NB-LUS');
  const conds = condition(model, t, 'S-');
  if (conds.length) {
    src.i(3, `IF ${conds[0]}`);
    for (const c of conds.slice(1)) src.i(3, `   AND ${c}`);
    src.i(4, 'SET RETENU TO TRUE');
    src.i(3, 'END-IF');
  } else src.i(3, 'SET RETENU TO TRUE');
  src.i(1, 'END-READ');
  src.b('END-PERFORM.');
  return src.texte();
}

// Fonctions générées par l'atelier : l'utilisateur ne peut pas les redéfinir.
export const RESERVEES = ['00', '01', '05', '10', '20', '80', '90', '95'];

// Où s'exécute une fonction de l'utilisateur, selon son numéro.
export function placeFonction(fo) {
  const n = Number(fo);
  if (RESERVEES.includes(String(fo).padStart(2, '0'))) return 'générée par l\'atelier';
  if (n >= 2 && n <= 4) return 'initialisation, une fois';
  if (n >= 21 && n <= 24) return 'fin de traitement, une fois';
  if (n >= 25 && n <= 79) return 'chaque enregistrement, avant l\'état';
  if (n >= 81 && n <= 89) return 'avant l\'impression du détail';
  if (n >= 91 && n <= 94) return 'après l\'impression';
  return 'appelée seulement par P';
}

// Regroupe les lignes en fonctions puis sous-fonctions (ordre des codes).
export function analyserLignes(lignes) {
  const fonctions = new Map();
  const tri = lignes.map((l, i) => ({ ...l, i })).filter((l) => (l.fo || '').trim() && (l.ope || '').trim())
    .sort((a, b) => a.fo.padStart(2, '0').localeCompare(b.fo.padStart(2, '0')) || (a.sf || '').localeCompare(b.sf || '') || a.i - b.i);
  for (const l of tri) {
    const fo = l.fo.trim().padStart(2, '0');
    if (!/^\d\d$/.test(fo)) throw new Error(`Numéro de fonction invalide : ${l.fo}`);
    const sf = (l.sf || '').trim().padStart(2, '0');
    if (!fonctions.has(fo)) fonctions.set(fo, []);
    const sous = fonctions.get(fo);
    let cur = sous.find((x) => x.sf === sf);
    if (!cur) { cur = { sf, niv: '05', tc: 'BL', cond: '', titre: '', lignes: [] }; sous.push(cur); }
    if ((l.ope || '').toUpperCase() === 'N') {
      cur.titre = l.opes || '';
      cur.niv = (l.niv || '05').trim() || '05';
      cur.tc = ((l.tc || 'BL').trim() || 'BL').toUpperCase();
      cur.cond = l.cond || '';
      if (!['BL', 'IT', 'EL', 'DW'].includes(cur.tc)) throw new Error(`Fonction ${fo}${sf} : type ${cur.tc} inconnu (BL IT EL DW)`);
      if (['IT', 'DW'].includes(cur.tc) && !cur.cond.trim()) throw new Error(`Fonction ${fo}${sf} : ${cur.tc} demande une condition`);
      if (!/^\d\d$/.test(cur.niv)) throw new Error(`Fonction ${fo}${sf} : niveau ${cur.niv} invalide (05, 10, 15...)`);
    } else cur.lignes.push(l);
  }
  return fonctions;
}

// Condition simplifiée -> COBOL : # pour différent, ET / OU, opérateurs collés acceptés.
export function conditionStructuree(cond, ou = 'condition') {
  let out = '', i = 0;
  const c = String(cond || '');
  while (i < c.length) {
    const ch = c[i];
    if (ch === '"' || ch === "'") {
      let j = i + 1;
      while (j < c.length && c[j] !== ch) j++;
      if (j >= c.length) throw new Error(`${ou} : littéral non fermé dans la condition`);
      out += c.slice(i, j + 1); i = j + 1; continue;
    }
    const deux = c.slice(i, i + 2);
    if (deux === '>=' || deux === '<=') { out += ` ${deux} `; i += 2; continue; }
    if (ch === '#') { out += ' NOT = '; i++; continue; }
    if ('=<>'.includes(ch)) { out += ` ${ch} `; i++; continue; }
    if (ch === '(' || ch === ')') { out += ` ${ch} `; i++; continue; }
    out += ch; i++;
  }
  return decouper(out).map((m) => (m.toUpperCase() === 'ET' ? 'AND' : m.toUpperCase() === 'OU' ? 'OR' : m)).join(' ');
}

export const OPERATEURS_STRUCTURES = ['N', 'M', 'A', 'S', 'C', 'P', 'MES', 'ECR', 'SQL', 'SCO', 'SRO', 'COB', '*'];
// Types d'accès de l'opérateur SQL (« SQL CL R »), codes de VisualAge Pacbase.
export const ACCES_SQL = {
  R: 'lire par la clé (SELECT)',
  RU: 'lire pour mettre à jour (SELECT FOR UPDATE)',
  W: 'insérer (INSERT)',
  RW: 'mettre à jour par la clé (UPDATE)',
  D: 'supprimer par la clé (DELETE)',
  DC: 'déclarer le curseur (déjà fait par le générateur)',
  P: 'ouvrir le curseur : toute la table, dans l\'ordre de la clé (OPEN)',
  RN: 'ligne suivante du curseur (FETCH)',
  CL: 'fermer le curseur (CLOSE)',
};
export const OPERATEURS_BASE = { ...Object.fromEntries(Object.entries(ACCES_SQL).map(([k, v]) => [`SQL CL ${k}`, v])), SCO: 'valider (COMMIT)', SRO: 'annuler (ROLLBACK)' };

export function verifierTables(model, tables = model.tables || []) {
  const codes = new Set((model.sorties || []).map((x) => x.code));
  const noms = new Set();
  for (const tb of tables) {
    if (!/^[A-Z][A-Z0-9]$/.test(tb.code || '')) throw new Error(`Table : code ${tb.code || '(vide)'} invalide (2 caractères, une lettre en tête)`);
    if (['ED', 'LC', 'LG', 'RT', 'NB', 'IN', 'SQ'].includes(tb.code)) throw new Error(`Table : le code ${tb.code} est réservé`);
    if (codes.has(tb.code)) throw new Error(`Table ${tb.code} : code déjà pris par un fichier en sortie ou une autre table`);
    codes.add(tb.code);
    if (!/^[A-Z][A-Z0-9_]{0,17}$/.test(tb.nom || '')) throw new Error(`Table ${tb.code} : nom invalide (lettres, chiffres, _ ; 18 au plus)`);
    if (noms.has(tb.nom)) throw new Error(`Deux tables s'appellent ${tb.nom}`);
    noms.add(tb.nom);
    if (!tb.rubriques?.length) throw new Error(`Table ${tb.code} : aucune rubrique`);
    tb.rubriques.forEach((c) => rubrique(model, c));
    if (new Set(tb.rubriques).size !== tb.rubriques.length) throw new Error(`Table ${tb.code} : rubrique en double`);
    if (!tb.cle?.length) throw new Error(`Table ${tb.code} : il faut une clé`);
    const k = tb.cle.find((c) => !tb.rubriques.includes(c));
    if (k) throw new Error(`Table ${tb.code} : la clé ${k} doit être une rubrique de la table`);
  }
}

// Description SQL d'une table (identique à ce que garde SQLite, pour détecter un changement).
export function ddlTable(model, tb) {
  const col = (c) => {
    const r = rubrique(model, c);
    const type = r.type === 'X' ? `VARCHAR(${r.longueur})` : r.decimales ? `DECIMAL(${r.longueur},${r.decimales})` : 'INTEGER';
    return `${c} ${type}${tb.cle.includes(c) ? ' NOT NULL' : ''}`;
  };
  return `CREATE TABLE ${tb.nom} (${tb.rubriques.map(col).join(', ')}, PRIMARY KEY (${tb.cle.join(', ')}))`;
}

// Instructions EXEC SQL d'un opérateur d'accès.
function sqlAcces(ope, tb) {
  const hv = (c) => `:${tb.code}-${c}`;
  const ou = `WHERE ${tb.cle.map((c) => `${c} = ${hv(c)}`).join(' AND ')}`;
  const liste = (l) => l.map((x, i) => x + (i < l.length - 1 ? ',' : '')).join(' ');
  const autres = tb.rubriques.filter((c) => !tb.cle.includes(c));
  switch (ope) {
    case 'W': return [`EXEC SQL INSERT INTO ${tb.nom} (${liste(tb.rubriques)}) VALUES (${liste(tb.rubriques.map(hv))}) END-EXEC`];
    case 'R': case 'RU': return [`EXEC SQL SELECT ${liste(tb.rubriques)} INTO ${liste(tb.rubriques.map(hv))} FROM ${tb.nom} ${ou} END-EXEC`];
    case 'RW':
      if (!autres.length) throw new Error(`SQL ${tb.code} RW ${tb.code} : la table n'a que des rubriques de clé, rien à mettre à jour`);
      return [`EXEC SQL UPDATE ${tb.nom} SET ${liste(autres.map((c) => `${c} = ${hv(c)}`))} ${ou} END-EXEC`];
    case 'D': return [`EXEC SQL DELETE FROM ${tb.nom} ${ou} END-EXEC`];
    case 'DC': return ['CONTINUE'];
    case 'P': return [`EXEC SQL OPEN C-${tb.code} END-EXEC`];
    case 'RN': return [`EXEC SQL FETCH C-${tb.code} INTO ${liste(tb.rubriques.map(hv))} END-EXEC`];
    case 'CL': return [`EXEC SQL CLOSE C-${tb.code} END-EXEC`];
  }
}
