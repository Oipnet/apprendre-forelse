// Générateur COBOL : dictionnaire de rubriques + structure + description d'un batch d'édition
// -> programme COBOL (format fixe) compilable par GnuCOBOL.

const DONNEES_FACTURES = [
    { REGION: 'NOR', NUMCLI: '12', NOMCLI: 'DUPONT', MTFACT: '1250.50' },
    { REGION: 'SUD', NUMCLI: '7', NOMCLI: 'MARTIN', MTFACT: '-80' },
    { REGION: 'NOR', NUMCLI: '31', NOMCLI: 'LEROY', MTFACT: '312' },
    { REGION: 'EST', NUMCLI: '44', NOMCLI: 'GARNIER', MTFACT: '0' },
    { REGION: 'SUD', NUMCLI: '2', NOMCLI: 'BERNARD', MTFACT: '99.99' },
    { REGION: 'OUE', NUMCLI: '18', NOMCLI: 'FOURNIER', MTFACT: '-15.20' },
    { REGION: 'NOR', NUMCLI: '27', NOMCLI: 'GIRAUD', MTFACT: '48.75' },
  ];

export const exemple = {
  version: 3,
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
  // Appel des fichiers : code (préfixe des zones), utilisation, enregistrement, fichier, argument
  // (rubriques de rupture ou de synchronisation) et nombre de niveaux de rupture.
  appels: [
    { code: 'FA', utilisation: 'C', structure: 'FACTURE', fichier: 'FACTURES.DAT', argument: ['REGION'], rupture: 1, donnees: DONNEES_FACTURES },
  ],
  traitement: {
    programme: 'ETATREG',
    titre: 'CHIFFRE D AFFAIRES PAR REGION',
    filtres: [{ rubrique: 'MTFACT', op: 'NOT =', valeur: '0' }],
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
    { fo: '8E', sf: '10', ope: 'N', opes: 'Montants négatifs à relancer', niv: '05', tc: 'IT', cond: '1-FA00-MTFACT < 0' },
    { fo: '8E', sf: '10', ope: 'M', opes: "'** A RELANCER' D-NOMCLI" },
    { fo: '8E', sf: '10', ope: 'A', opes: '1 W-NBREL' },
    { fo: '8E', sf: '10', ope: 'M', opes: '1-FA00-NUMCLI RL-NUMCLI' },
    { fo: '8E', sf: '10', ope: 'M', opes: '1-FA00-NOMCLI RL-NOMCLI' },
    { fo: '8E', sf: '10', ope: 'M', opes: '1-FA00-MTFACT RL-MTFACT' },
    { fo: '8E', sf: '10', ope: 'ECR', opes: 'RL' },
    { fo: '20', sf: '10', ope: 'N', opes: 'Bilan des relances', niv: '05', tc: 'BL' },
    { fo: '20', sf: '10', ope: 'MES', opes: "'FACTURES A RELANCER : ' W-NBREL" },
    { fo: '20', sf: '20', ope: 'N', opes: 'Clients en base', niv: '05', tc: 'BL' },
    { fo: '20', sf: '20', ope: 'MES', opes: "'CLIENTS EN BASE :'" },
    { fo: '20', sf: '20', ope: 'SQL', opes: 'CL P' },
    { fo: '20', sf: '20', ope: 'SQL', opes: 'CL RN' },
    { fo: '20', sf: '30', ope: 'N', opes: 'Pour chaque client lu', niv: '05', tc: 'DW', cond: 'SQLCODE = 0' },
    { fo: '20', sf: '30', ope: 'MES', opes: "'  ' CL-NOMCLI CL-MTFACT CL-NBFACT" },
    { fo: '20', sf: '30', ope: 'SQL', opes: 'CL RN' },
    { fo: '20', sf: '40', ope: 'N', opes: 'Fin de la liste', niv: '05', tc: 'BL' },
    { fo: '20', sf: '40', ope: 'SQL', opes: 'CL CL' },
    { fo: '30', sf: '10', ope: 'N', opes: 'Cumul par client dans la base', niv: '05', tc: 'BL' },
    { fo: '30', sf: '10', ope: 'M', opes: '1-FA00-NUMCLI CL-NUMCLI' },
    { fo: '30', sf: '10', ope: 'SQL', opes: 'CL R' },
    { fo: '30', sf: '20', ope: 'N', opes: 'Client absent : on le crée', niv: '10', tc: 'IT', cond: 'SQLCODE = 100' },
    { fo: '30', sf: '20', ope: 'M', opes: '1-FA00-NOMCLI CL-NOMCLI' },
    { fo: '30', sf: '20', ope: 'M', opes: '1-FA00-MTFACT CL-MTFACT' },
    { fo: '30', sf: '20', ope: 'M', opes: '1 CL-NBFACT' },
    { fo: '30', sf: '20', ope: 'SQL', opes: 'CL W' },
    { fo: '30', sf: '30', ope: 'N', opes: 'Client connu : on cumule', niv: '10', tc: 'EL' },
    { fo: '30', sf: '30', ope: 'A', opes: '1-FA00-MTFACT CL-MTFACT' },
    { fo: '30', sf: '30', ope: 'A', opes: '1 CL-NBFACT' },
    { fo: '30', sf: '30', ope: 'SQL', opes: 'CL RW' },
  ],
};

// Mise à jour d'un fichier de clients par des mouvements : création, modification, annulation.
export const exempleMiseAJour = {
  version: 3,
  dictionnaire: [
    { code: 'NUMCLI', libelle: 'Client', type: '9', longueur: 6 },
    { code: 'NOMCLI', libelle: 'Nom du client', type: 'X', longueur: 20 },
    { code: 'REGION', libelle: 'Région', type: 'X', longueur: 3 },
    { code: 'PLAFOND', libelle: 'Plafond de crédit', type: '9', longueur: 7, decimales: 2 },
    { code: 'CODMVT', libelle: 'Code mouvement', type: 'X', longueur: 1 },
  ],
  structures: [
    { code: 'CLIENT', libelle: 'Client', rubriques: ['NUMCLI', 'NOMCLI', 'REGION', 'PLAFOND'] },
    { code: 'MVTCLI', libelle: 'Mouvement client', rubriques: ['NUMCLI', 'CODMVT', 'NOMCLI', 'REGION', 'PLAFOND'] },
  ],
  appels: [
    { code: 'CL', utilisation: 'P', structure: 'CLIENT', fichier: 'CLIENTS.DAT', argument: ['NUMCLI'], synchro: 1, donnees: [
      { NUMCLI: '7', NOMCLI: 'MARTIN', REGION: 'SUD', PLAFOND: '1500' },
      { NUMCLI: '12', NOMCLI: 'DUPONT', REGION: 'NOR', PLAFOND: '5000' },
      { NUMCLI: '18', NOMCLI: 'FOURNIER', REGION: 'OUE', PLAFOND: '800' },
      { NUMCLI: '31', NOMCLI: 'LEROY', REGION: 'NOR', PLAFOND: '2000' },
    ] },
    { code: 'MV', utilisation: 'M', structure: 'MVTCLI', fichier: 'MOUVEMENTS.DAT', argument: ['NUMCLI'], synchro: 1,
      mouvement: { rubrique: 'CODMVT', creation: 'C', modification: 'M', annulation: 'A' }, donnees: [
      { NUMCLI: '2', CODMVT: 'C', NOMCLI: 'BERNARD', REGION: 'SUD', PLAFOND: '1200' },
      { NUMCLI: '7', CODMVT: 'M', PLAFOND: '2500' },
      { NUMCLI: '12', CODMVT: 'C', NOMCLI: 'DUPONT BIS', REGION: 'NOR', PLAFOND: '100' },
      { NUMCLI: '18', CODMVT: 'A' },
      { NUMCLI: '25', CODMVT: 'C', NOMCLI: 'GIRAUD', REGION: 'EST', PLAFOND: '900' },
      { NUMCLI: '25', CODMVT: 'M', REGION: 'NOR' },
      { NUMCLI: '27', CODMVT: 'M', NOMCLI: 'INCONNU' },
      { NUMCLI: '31', CODMVT: 'X' },
    ] },
    { code: 'CN', utilisation: 'R', fichier: 'CLIENTS.NEW' },
  ],
  traitement: { programme: 'MAJCLI', titre: 'MISE A JOUR DES CLIENTS' },
  sorties: [],
  tables: [],
  lignes: [],
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

// ================= Appel des fichiers (façon -CD) =================
// Chaque fichier lu ou écrit par le programme est « appelé » avec un code de deux caractères (dd), qui
// préfixe ses zones : dd00-RUBRIQ (enregistrement courant), 1-dd00-RUBRIQ (suivant, lu d'avance),
// dd-FT (fin de fichier), dd-PEn / dd-DEn (premier / dernier au niveau n), dd-CFn, dd-OCn.
export const UTILISATIONS = {
  C: 'consulté : lu en séquence et édité dans l\'état',
  P: 'principal : mis à jour par les mouvements',
  M: 'mouvements : mettent à jour le principal',
  R: 'résultat : le principal après mise à jour',
};
const CODES_RESERVES = ['ED', 'LC', 'LG', 'RT', 'NB', 'IN', 'SQ', 'R1', 'R2', 'R3', 'MI'];
export const MOUVEMENT_DEFAUT = { creation: 'C', modification: 'M', annulation: 'A' };

// Ordre des codes de fonction : celui de l'EBCDIC, les lettres avant les chiffres (0A < 00 < 8E < 80).
const rang = (c) => (/[A-Z]/.test(c) ? c.charCodeAt(0) - 65 : 26 + Number(c));
export const comparerCodes = (a, b) => rang(a[0]) - rang(b[0]) || rang(a[1]) - rang(b[1]);
export const normaliserCode = (c) => {
  const v = String(c ?? '').trim().toUpperCase();
  return /^\d$/.test(v) ? `0${v}` : v;
};

export const estMiseAJour = (model) => (model.appels || []).some((a) => a.utilisation === 'P');
export const appelEdite = (model) => (model.appels || []).find((a) => a.utilisation === 'C');

// Fonctions générées selon le genre de programme (édition ou mise à jour).
export function fonctionsGenerees(model) {
  if (estMiseAJour(model)) return ['01', '05', '10', '20', '24', '70', '73', '76', '90'];
  const c = appelEdite(model);
  const rupture = c && Number(c.rupture || 0) > 0;
  return ['01', '05', ...(rupture ? ['10'] : []), '20', ...(rupture ? ['22'] : []), '8E', '90'];
}

const ROLES_GENERES = {
  '01': 'initialisations', '05': 'lecture sans rupture', '10': 'lecture avec rupture', '20': 'fin de traitement',
  '24': 'configuration et occurrence', '70': 'contrôle de correspondance', '73': 'mise à jour',
  '76': 'mémorisation des erreurs', '8E': 'édition de l\'état', '90': 'écritures',
};

// Où s'exécute une fonction, selon son code.
export function placeFonction(fo, model) {
  const code = normaliserCode(fo);
  const gen = fonctionsGenerees(model);
  if (gen.includes(code)) return `générée (${ROLES_GENERES[code]}) : vos sous-fonctions s'y ajoutent${code === '8E' ? ', avant l\'impression du détail' : code === '20' ? ', avant la fermeture des fichiers' : ''}`;
  const avant = (x) => comparerCodes(code, x) < 0;
  if (avant('01')) return 'avant l\'initialisation, une fois';
  if (avant('05')) return 'une fois, après l\'initialisation';
  if (avant('20')) return 'à chaque itération, après la lecture (aussi au dernier passage, fin de fichier)';
  if (avant('90')) {
    if (gen.includes('8E')) return avant('8E') ? 'à chaque enregistrement, avant l\'état' : 'à chaque enregistrement, après l\'impression';
    return 'à chaque itération (indicatif en cours)';
  }
  return 'après la fin d\'itération : appelée seulement par P';
}
// Ancienne API : liste fixe (programme d'édition avec rupture).
export const RESERVEES = ['01', '05', '10', '20', '8E', '90'];

// Regroupe les lignes en fonctions puis sous-fonctions (ordre EBCDIC des codes).
export function analyserLignes(lignes) {
  const fonctions = new Map();
  const tri = lignes.map((l, i) => ({ ...l, i, fo: normaliserCode(l.fo), sf: normaliserCode(l.sf) || '00' }))
    .filter((l) => l.fo && (l.ope || '').trim())
    .sort((a, b) => (a.fo.length === 2 && b.fo.length === 2 ? comparerCodes(a.fo, b.fo) : a.fo.localeCompare(b.fo))
      || (a.sf.length === 2 && b.sf.length === 2 ? comparerCodes(a.sf, b.sf) : a.sf.localeCompare(b.sf)) || a.i - b.i);
  for (const l of tri) {
    const { fo, sf } = l;
    if (!/^[0-9A-Z]{2}$/.test(fo)) throw new Error(`Code de fonction invalide : ${l.fo} (deux caractères, 0A à 99)`);
    if (!/^[0-9A-Z]{2}$/.test(sf)) throw new Error(`Fonction ${fo} : code de sous-fonction invalide : ${l.sf}`);
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

// ================= Reprise des anciens programmes =================
// Avant l'appel des fichiers, le traitement portait structure, fichier et ruptures, les zones
// s'appelaient E- (courant) et S- (suivant), et les fonctions suivaient une autre numérotation.
const horsLitteraux = (t, f) => String(t ?? '').split(/("[^"]*"|'[^']*')/).map((m, i) => (i % 2 ? m : f(m))).join('');
const renommerZones = (t, dd) => horsLitteraux(t, (m) => m
  .replace(/(^|[^A-Z0-9-])E-(?=[A-Z0-9])/gi, `$1${dd}00-`)
  .replace(/(^|[^A-Z0-9-])S-(?=[A-Z0-9])/gi, `$1 1-${dd}00-`.replace(' ', '')));

function codeLibre(base, pris) {
  const lettres = String(base || 'FA').toUpperCase().replace(/[^A-Z0-9]/g, '');
  const candidats = [lettres.slice(0, 2), lettres[0] + (lettres[2] || 'A'), 'FA', 'FB', 'FC', 'FD'];
  return candidats.find((c) => /^[A-Z][A-Z0-9]$/.test(c) && !pris.has(c) && !CODES_RESERVES.includes(c)) || 'FZ';
}

function reprendreLignes(lignes, dd) {
  const sfPris = new Map(); // fonction cible -> sous-fonctions prises
  const remap = new Map();  // ancienne fonction -> nouvelle (P à réécrire)
  const nouvelles = [];
  const libres9 = ['91', '92', '93', '94', '95'];
  const anciennes = [...new Set(lignes.map((l) => normaliserCode(l.fo)))];
  // fonctions appelées seulement par P : ancienne place 06-09, 11-19
  for (const fo of anciennes.sort()) {
    const n = Number(fo);
    if ((n >= 6 && n <= 9) || (n >= 11 && n <= 19)) { const c = libres9.shift(); if (c) remap.set(fo, c); }
    else if (n >= 91 && n <= 94) remap.set(fo, String(n - 5));
  }
  const cible = (fo) => {
    const n = Number(fo);
    if (n >= 21 && n <= 24) return '20';
    if (n >= 81 && n <= 89) return '8E';
    return remap.get(fo) || fo;
  };
  const tri = lignes.map((l, i) => ({ l, i })).sort((a, b) => normaliserCode(a.l.fo).localeCompare(normaliserCode(b.l.fo)) || a.i - b.i);
  const sfDe = new Map(); // "ancienne fo/sf" -> nouveau sf
  for (const { l } of tri) {
    const fo = normaliserCode(l.fo), sf = normaliserCode(l.sf) || '00', c = cible(fo);
    const cle = `${fo}/${sf}`;
    if (!sfDe.has(cle)) {
      if (!sfPris.has(c)) sfPris.set(c, new Set());
      const pris = sfPris.get(c);
      let n = sf;
      if (pris.has(n) || (['20', '8E'].includes(c) && n === '99')) {
        let k = 10; while (pris.has(String(k)) && k < 98) k++; n = String(k);
      }
      pris.add(n); sfDe.set(cle, n);
    }
    const ope = (l.ope || '').toUpperCase();
    let opes = renommerZones(l.opes, dd);
    if (ope === 'P') {
      const m = decouper(opes)[0]?.replace(/^F/, '').padStart(2, '0');
      if (m && remap.has(m)) opes = remap.get(m);
    }
    nouvelles.push({ ...l, fo: c, sf: sfDe.get(cle), opes, ...(l.cond !== undefined ? { cond: renommerZones(l.cond, dd) } : {}) });
  }
  return nouvelles;
}

// Met un modèle de n'importe quelle version au format courant (copie ; l'original n'est pas touché).
export function normaliser(modele) {
  const m = JSON.parse(JSON.stringify(modele));
  m.traitement ||= {};
  m.sorties ||= [];
  m.tables ||= [];
  m.lignes ||= [];
  if (!Array.isArray(m.appels)) {
    const t = m.traitement;
    const pris = new Set([...m.sorties.map((x) => x.code), ...m.tables.map((x) => x.code)]);
    const dd = codeLibre(t.structure, pris);
    const ruptures = rupturesDe(t);
    m.appels = [{ code: dd, utilisation: 'C', structure: t.structure, fichier: t.fichier || 'ENTREE.DAT', argument: ruptures, rupture: ruptures.length, donnees: m.donnees || [] }];
    m.lignes = reprendreLignes(m.lignes, dd);
    delete t.structure; delete t.fichier; delete t.ruptures; delete t.rupture; delete m.donnees;
  }
  for (const a of m.appels) {
    a.code = String(a.code || '').toUpperCase();
    a.utilisation = String(a.utilisation || 'C').toUpperCase();
    a.argument ||= [];
    a.donnees ||= [];
    if (a.utilisation === 'M') a.mouvement = { ...MOUVEMENT_DEFAUT, ...(a.mouvement || {}) };
  }
  if ((m.version || 0) < VERSION_MODELE) migrerZones(m);
  m.version = VERSION_MODELE;
  return m;
}

// Version 3 (08/11/2026) : les zones suivent le manuel IBM. Pour un fichier lu avec rupture, dd00 est
// l'enregistrement lu d'avance et 1-dd00 celui qu'on traite (c'était l'inverse) ; le principal se
// traite dans 1-dd00 et se met à jour dans 2-dd00 (le résultat s'écrivait depuis rr00).
export const VERSION_MODELE = 3;
function migrerZones(m) {
  const principal = m.appels.find((a) => a.utilisation === 'P');
  const regles = [];
  for (const a of m.appels) {
    const dd = a.code;
    if (!/^[A-Z][A-Z0-9]$/.test(dd)) continue;
    const zone = new RegExp(`(^|[^A-Z0-9-])(1-)?${dd}00-`, 'gi');
    if (a.utilisation === 'M' || (a.utilisation === 'C' && Number(a.rupture || 0) > 0)) regles.push((t) => t.replace(zone, (_, av, un) => `${av}${un ? '' : '1-'}${dd}00-`));
    else if (a.utilisation === 'P') regles.push((t) => t.replace(zone, (_, av) => `${av}1-${dd}00-`));
    else if (a.utilisation === 'R' && principal) regles.push((t) => t.replace(zone, (_, av) => `${av}2-${principal.code}00-`));
  }
  const migrer = (t) => horsLitteraux(t, (x) => regles.reduce((v, f) => f(v), x));
  for (const l of m.lignes) {
    if (l.opes !== undefined) l.opes = migrer(l.opes);
    if (l.cond !== undefined) l.cond = migrer(l.cond);
  }
}

// ================= Données des fichiers en entrée =================
function contenuAppel(model, a) {
  if (a.utilisation === 'R') return null;
  const s = model.structures.find((x) => x.code === a.structure);
  if (!s) throw new Error(`Fichier ${a.code} : enregistrement ${a.structure || '(aucun)'} inconnu`);
  const vide = (r, v) => a.utilisation === 'M' && String(v ?? '').trim() === '';
  return (a.donnees || [])
    .map((ligne) => s.rubriques.map((c, i) => {
      const r = rubrique(model, c);
      const v = Array.isArray(ligne) ? ligne[i] : ligne[c];
      // un mouvement peut laisser une rubrique vide : elle n'est pas modifiée
      return vide(r, v) ? ' '.repeat(longueurFichier(r)) : versFichier(r, v);
    }).join(''))
    .join('\n') + '\n';
}

// { nom du fichier : contenu } pour chaque fichier lu par le programme.
export function fichiersEntree(modele) {
  const model = normaliser(modele);
  const f = {};
  for (const a of model.appels) if (a.utilisation !== 'R') f[a.fichier] = contenuAppel(model, a);
  return f;
}
// Contenu du fichier lu par un programme d'édition (ou du premier fichier lu).
export function fichierDonnees(modele) {
  const model = normaliser(modele);
  const a = appelEdite(model) || model.appels.find((x) => x.utilisation !== 'R');
  return a ? contenuAppel(model, a) : '';
}
// Fichiers écrits par le programme (sorties, résultat de la mise à jour), pour les montrer après la SYSOUT.
export function fichiersSortie(modele) {
  const model = normaliser(modele);
  return [...model.appels.filter((a) => a.utilisation === 'R').map((a) => ({ code: a.code, fichier: a.fichier })),
    ...model.sorties.map((s) => ({ code: s.code, fichier: s.fichier }))];
}

// ================= Vérification de l'appel des fichiers =================
function verifierAppels(model) {
  const appels = model.appels;
  if (!appels.length) throw new Error('Aucun fichier appelé : appelez au moins un fichier en entrée');
  const codes = new Set();
  for (const a of appels) {
    if (!/^[A-Z][A-Z0-9]$/.test(a.code)) throw new Error(`Fichier appelé : code ${a.code || '(vide)'} invalide (2 caractères, une lettre en tête)`);
    if (CODES_RESERVES.includes(a.code)) throw new Error(`Fichier appelé : le code ${a.code} est réservé`);
    if (codes.has(a.code)) throw new Error(`Deux fichiers appelés ont le code ${a.code}`);
    codes.add(a.code);
    if (!(a.utilisation in UTILISATIONS)) throw new Error(`Fichier ${a.code} : utilisation ${a.utilisation} inconnue (${Object.keys(UTILISATIONS).join(' ')})`);
    if (!/^[A-Z0-9][A-Z0-9.]*$/.test(a.fichier || '')) throw new Error(`Fichier ${a.code} : nom de fichier invalide`);
    if (a.utilisation !== 'R' && !model.structures.some((s) => s.code === a.structure)) throw new Error(`Fichier ${a.code} : enregistrement ${a.structure || '(aucun)'} inconnu`);
  }
  for (const x of [...model.sorties, ...model.tables]) if (codes.has(x.code)) throw new Error(`Le code ${x.code} sert à la fois à un fichier appelé et à un fichier en sortie ou une table`);
  const noms = appels.map((a) => a.fichier);
  if (new Set(noms).size !== noms.length) throw new Error('Deux fichiers appelés portent le même nom');
  const de = (u) => appels.filter((a) => a.utilisation === u);
  const [C, P, M, R] = ['C', 'P', 'M', 'R'].map(de);
  const struct = (a) => model.structures.find((s) => s.code === a.structure);
  const verifierArgument = (a, n) => {
    if (a.argument.length < n) throw new Error(`Fichier ${a.code} : l'argument doit compter au moins ${n} rubrique(s)`);
    if (a.argument.length > MAX_RUPTURES) throw new Error(`Fichier ${a.code} : ${MAX_RUPTURES} rubriques d'argument au plus`);
    if (new Set(a.argument).size !== a.argument.length) throw new Error(`Fichier ${a.code} : une rubrique figure deux fois dans l'argument`);
    for (const c of a.argument) if (!struct(a).rubriques.includes(c)) throw new Error(`Fichier ${a.code} : la rubrique ${c} de l'argument n'est pas dans l'enregistrement ${a.structure}`);
  };
  if (P.length) {
    if (C.length) throw new Error('Un programme de mise à jour (fichier P) ne consulte pas de fichier C : l\'état est réservé aux programmes d\'édition');
    if (P.length > 1) throw new Error('Un seul fichier principal (P) par programme');
    if (M.length !== 1) throw new Error('Il faut un et un seul fichier de mouvements (M) pour mettre à jour le principal');
    if (R.length !== 1) throw new Error('Il faut un fichier résultat (R) : le principal après mise à jour');
    const [p, mv] = [P[0], M[0]];
    const n = Number(p.synchro || p.argument.length);
    if (!(n >= 1)) throw new Error(`Fichier ${p.code} : il faut au moins une rubrique d'argument pour synchroniser`);
    verifierArgument(p, n); verifierArgument(mv, n);
    for (let k = 0; k < n; k++) {
      const [rp, rm] = [rubrique(model, p.argument[k]), rubrique(model, mv.argument[k])];
      for (const [a, r] of [[p, rp], [mv, rm]]) {
        if (r.type === '9' && (r.signe || r.decimales)) throw new Error(`Fichier ${a.code} : la rubrique d'argument ${r.code} doit être un entier non signé`);
      }
      if (longueurFichier(rp) !== longueurFichier(rm)) throw new Error(`Argument de niveau ${k + 1} : ${rp.code} (${p.code}) et ${rm.code} (${mv.code}) doivent avoir la même longueur`);
    }
    const mo = mv.mouvement;
    const rc = mo.rubrique ? rubrique(model, mo.rubrique) : null;
    if (!rc || !struct(mv).rubriques.includes(rc.code)) throw new Error(`Fichier ${mv.code} : indiquez la rubrique du code mouvement (dans l'enregistrement ${mv.structure})`);
    if (rc.type !== 'X' || rc.longueur !== 1) throw new Error(`Fichier ${mv.code} : le code mouvement ${rc.code} doit être alphanumérique d'un caractère`);
    const vals = [mo.creation, mo.modification, mo.annulation].map((v) => String(v || '').toUpperCase());
    if (vals.some((v) => !/^[A-Z0-9]$/.test(v)) || new Set(vals).size !== 3) throw new Error(`Fichier ${mv.code} : les codes création, modification et annulation doivent être trois caractères différents`);
    for (const c of struct(mv).rubriques) {
      if (c === rc.code || mv.argument.slice(0, n).includes(c)) continue;
      if (!struct(p).rubriques.includes(c)) throw new Error(`Fichier ${mv.code} : la rubrique ${c} n'est pas dans l'enregistrement principal ${p.structure} : impossible de la reporter`);
    }
    return { mode: 'maj', p, mv, r: R[0], n };
  }
  if (M.length || R.length) throw new Error('Des mouvements (M) ou un résultat (R) sans fichier principal (P)');
  if (C.length !== 1) throw new Error('Un programme d\'édition lit un et un seul fichier consulté (C)');
  const c = C[0];
  const rupture = Number(c.rupture || 0);
  if (!(rupture >= 0 && rupture <= MAX_RUPTURES)) throw new Error(`Fichier ${c.code} : ${MAX_RUPTURES} niveaux de rupture au plus`);
  verifierArgument(c, rupture);
  return { mode: 'edition', c, rupture };
}

// ================= Génération =================
export function generer(modele) {
  const model = normaliser(modele);
  const t = model.traitement;
  const pgm = ident(t.programme).replace(/-/g, '').slice(0, 8) || 'PGM';
  const sorties = model.sorties;
  for (const so of sorties) {
    if (!/^[A-Z][A-Z0-9]$/.test(so.code || '')) throw new Error(`Fichier en sortie : code ${so.code || '(vide)'} invalide (2 caractères, une lettre en tête)`);
    if (CODES_RESERVES.includes(so.code)) throw new Error(`Fichier en sortie : le code ${so.code} est réservé`);
    if (!/^[A-Z0-9][A-Z0-9.]*$/.test(so.fichier || '')) throw new Error(`Fichier en sortie ${so.code} : nom de fichier invalide`);
    if (!so.rubriques?.length) throw new Error(`Fichier en sortie ${so.code} : aucune rubrique`);
    so.rubriques.forEach((c) => rubrique(model, c));
  }
  if (new Set(sorties.map((x) => x.code)).size !== sorties.length) throw new Error('Deux fichiers en sortie ont le même code');
  const tables = model.tables;
  verifierTables(model, tables);
  const cd = verifierAppels(model);
  const src = new Source();
  const dico = new Map(model.dictionnaire.map((d) => [d.code, d]));
  const struct = (a) => model.structures.find((s) => s.code === a.structure);
  const rubs = (a) => struct(a).rubriques.map((c) => rubrique(model, c));
  const K = (r) => ident(r.code);
  const generees = fonctionsGenerees(model);

  // ---------- langage structuré : analyse ----------
  const fonctions = analyserLignes(model.lignes);
  for (const [fo, sous] of fonctions) {
    if (generees.includes(fo) && sous.some((x) => x.sf === '99')) throw new Error(`Fonction ${fo} : la sous-fonction 99 est générée (fin de la fonction) : prenez un autre code`);
  }
  const travail = new Map();   // W-xxx déclarées automatiquement d'après le dictionnaire
  const editees = new Map();   // ED-xxx pour afficher un nombre lisiblement
  const appelsCodes = new Set(model.appels.map((a) => a.code));
  const prefixesConnus = new Set([...sorties.map((x) => x.code), ...tables.map((x) => x.code)]);
  const refNumerique = (mot) => {
    let m = /^(?:1-)?([A-Z][A-Z0-9])00-(.+)$/.exec(mot);
    if (m && appelsCodes.has(m[1])) { const r = dico.get(m[2]); return r && r.type === '9' ? r : null; }
    m = /^([A-Z][A-Z0-9]?)-(.+)$/.exec(mot);
    if (!m || !(prefixesConnus.has(m[1]) || /^W\d?$/.test(m[1]))) return null;
    const r = dico.get(m[2]);
    return r && r.type === '9' ? r : null;
  };
  const noter = (mot) => {
    const m = /^(W\d?)-(.+)$/.exec(mot);
    if (m && dico.has(m[2])) travail.set(mot, dico.get(m[2]));
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
        exiger(1, 'un code de fonction');
        const fo = normaliserCode(mots[0].replace(/^F/, ''));
        if (!fonctions.has(fo)) throw new Error(`${ou} : P ${mots[0]} : la fonction ${fo} n'existe pas`);
        if (generees.includes(fo)) throw new Error(`${ou} : P ${mots[0]} : la fonction ${fo} est générée, elle ne s'appelle pas`);
        src.instr(indent, `PERFORM F${fo} THRU F${fo}-FN`);
        return;
      }
      case 'MES': {
        exiger(1, 'au moins un élément à afficher');
        const elems = mots.map((m) => {
          const r = refNumerique(m);
          if (!r) return m;
          const ed = `ED-${m.replace(/^1-/, 'S-')}`.slice(0, 30);
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
        for (const x of sqlAcces(acces, tb)) src.instr(indent, x);
        return;
      }
      case 'SCO': src.instr(indent, 'EXEC SQL COMMIT END-EXEC'); return;
      case 'SRO': src.instr(indent, 'EXEC SQL ROLLBACK END-EXEC'); return;
      default: throw new Error(`${ou} : opérateur ${ope || '(vide)'} inconnu (${OPERATEURS_STRUCTURES.join(' ')})`);
    }
  };

  // Traduction d'une fonction, à la façon des générateurs : un paragraphe par sous-fonction (FffSS),
  // terminé par FffSS-FN ; IT saute à la fin si la condition est fausse, EL est sauté après un IT vrai,
  // DW reboucle sur son début. Les niveaux (05, 10...) emboîtent les sous-fonctions.
  const corpsFonctions = new Map();
  for (const [fo, sous] of fonctions) {
    const lignesCobol = [];
    const sauver = src.lignes;
    src.lignes = lignesCobol;
    const pile = [];
    const fermer = (suivante) => {
      const x = pile.pop();
      if (x.tc === 'DW') src.b(`GO TO F${fo}${x.sf}.`);
      if (x.tc === 'IT' && suivante?.tc === 'EL' && Number(suivante.niv || 5) === x.niv) src.b(`GO TO F${fo}${suivante.sf}-FN.`);
      src.a(`F${fo}${x.sf}-FN. EXIT.`);
    };
    let precedente = null;
    for (const sf of sous) {
      const ou = `fonction ${fo} sous-fonction ${sf.sf}`;
      const niv = Number(sf.niv || 5);
      while (pile.length && pile.at(-1).niv >= niv) fermer(pile.at(-1).niv === niv ? sf : null);
      if (sf.tc === 'EL' && !(precedente && precedente.tc === 'IT' && Number(precedente.niv || 5) === niv)) throw new Error(`${ou} : EL doit suivre une sous-fonction IT de même niveau`);
      src.a(`F${fo}${sf.sf}.`);
      if (sf.titre) src.com(`${fo}${sf.sf} ${sf.titre}`.normalize('NFD').replace(/[^\x20-\x7e]/g, '').slice(0, 62));
      if (sf.tc === 'IT' || sf.tc === 'DW') src.instr(0, `IF NOT (${conditionStructuree(sf.cond, ou)}) GO TO F${fo}${sf.sf}-FN.`);
      for (const l of sf.lignes) traduire(l, 0, ou);
      if (sf.lignes.length) src.lignes[src.lignes.length - 1] += src.lignes.at(-1).trimEnd().endsWith('.') ? '' : '.';
      for (const mot of decouper(sf.cond || '')) noter(mot);
      pile.push({ niv, tc: sf.tc, sf: sf.sf });
      precedente = sf;
    }
    while (pile.length) fermer(null);
    src.lignes = sauver;
    corpsFonctions.set(fo, lignesCobol);
  }
  const corps = (fo) => corpsFonctions.get(fo) || [];
  const fonctionUtilisateur = (fo) => {
    const titreF = fonctions.get(fo).find((x) => x.titre)?.titre;
    const l = [`       F${fo}.`];
    if (titreF) l.push(`      * Fonction ${fo} : ${titreF}`.normalize('NFD').replace(/[^\x20-\x7e]/g, '').slice(0, 72));
    return [...l, ...corps(fo), `       F${fo}-FN. EXIT.`];
  };

  const enregistrement = (nom, a, niveau = '01', pref = `${nom}-`) => {
    src.a(`${niveau}  ${nom}.`);
    for (const r of rubs(a)) {
      const signe = r.type === '9' && r.signe ? ' SIGN LEADING SEPARATE' : '';
      src.b(`05 ${pref}${K(r)} PIC ${picStockage(r)}${signe}.`);
    }
  };
  const image = (nom, a) => {   // même enregistrement, toutes les rubriques en X (présence d'une rubrique)
    src.a(`01  ${nom}.`);
    for (const r of rubs(a)) src.b(`05 ${nom}-${K(r)} PIC X(${longueurFichier(r)}).`);
  };
  const longueurEnr = (a) => rubs(a).reduce((n, r) => n + longueurFichier(r), 0);

  const sousFonctions = (fo) => { for (const l of corps(fo)) src.lignes.push(l); };
  const res = cd.mode === 'maj' ? genererMiseAJour() : genererEdition();
  return res;

  // ---------- parties communes ----------
  function entete(commentaires) {
    src.a('IDENTIFICATION DIVISION.');
    src.a(`PROGRAM-ID. ${pgm}.`);
    src.com(`Programme genere : ${t.titre || pgm}`.normalize('NFD').replace(/[^\x20-\x7e]/g, '').slice(0, 60));
    for (const c of commentaires) src.com(c);
    src.a('ENVIRONMENT DIVISION.');
    src.a('INPUT-OUTPUT SECTION.');
    src.a('FILE-CONTROL.');
  }
  function selectSorties() {
    for (const so of sorties) {
      src.b(`SELECT ${so.code}-FICHIER ASSIGN TO ${litteral(so.fichier)}`);
      src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
    }
  }
  function fdSorties() {
    for (const so of sorties) {
      src.a(`FD  ${so.code}-FICHIER.`);
      src.a(`01  ${so.code}-ENR.`);
      for (const c of so.rubriques) {
        const r = rubrique(model, c);
        const signe = r.type === '9' && r.signe ? ' SIGN LEADING SEPARATE' : '';
        src.b(`05 ${so.code}-${ident(r.code)} PIC ${picStockage(r)}${signe}.`);
      }
    }
  }
  function zonesCommunes() {
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
  }
  // Déroulement : fonctions générées et fonctions de l'utilisateur, dans l'ordre de leurs codes.
  function derouler(generes) {
    const codes = [...new Set([...Object.keys(generes), ...fonctions.keys()])].sort(comparerCodes);
    for (const fo of codes) {
      if (generes[fo]) generes[fo]();
      else for (const l of fonctionUtilisateur(fo)) src.lignes.push(l);
    }
  }

  // Variables conditionnelles (chapitre 6) : RTDn, NRD, NRP, RTPn, puis par fichier dd-DEn, dd-PEn,
  // dd-CFn (VCF), dd-OCn, dd-FT (groupe FT) et dd-FI (groupe FI).
  function variablesConditionnelles({ niveaux = 0, aRupture = [], synchro = [], occurrence = [], tous = [] }) {
    const groupe = (nom, n, v) => { src.b(`05 ${nom}.`); for (let i = 1; i <= n; i++) src.i(1, `10 ${nom}${i} PIC X VALUE '${v}'.`); };
    src.a('01  VARIABLES-CONDITIONNELLES.');
    if (niveaux) {
      groupe('RTD', niveaux, '1');
      src.b('05 NRD PIC 9 VALUE 1.');
      src.b('05 NRP PIC 9 VALUE ZERO.');
      groupe('RTP', niveaux, '1');
    }
    for (const [dd, n] of aRupture) groupe(`${dd}-DE`, n, '1');
    for (const [dd, n] of aRupture) groupe(`${dd}-PE`, n, '1');
    if (synchro.length) {
      src.b('05 VCF.');
      for (const [dd, n] of synchro) {
        src.i(1, `10 ${dd}-CF.`);
        for (let i = 1; i <= n; i++) src.i(2, `15 ${dd}-CF${i} PIC X VALUE '1'.`);
      }
    }
    for (const [dd, n] of occurrence) groupe(`${dd}-OC`, n, '0');
    src.b('05 FT.');
    for (const dd of tous) src.i(1, `10 ${dd}-FT PIC X VALUE '0'.`);
    if (aRupture.length) {
      src.b('05 FI.');
      for (const [dd] of aRupture) src.i(1, `10 ${dd}-FI PIC X VALUE '0'.`);
    }
  }
  // F90 écritures (les vôtres), puis la fin d'itération.
  function finIteration() {
    src.a('F90. EXIT.');
    sousFonctions('90');
    src.a('F90-FN. EXIT.');
    src.a('F9099-ITER-FN. GO TO F05.');
  }

  // ================= Programme d'édition (fichier consulté C) =================
  // Squelette du chapitre 6 du manuel « Applications Batch » : F01 ouverture et première lecture,
  // F05 début d'itération (lecture d'un fichier sans rupture), F10 lecture avec rupture (la zone
  // dd00 est lue d'avance, l'enregistrement traité passe dans 1-dd00), F20 fin de traitement,
  // F22 calcul des derniers (dd-DEn, et RTD/RTP/NRD/NRP faute de synchronisation), F8E l'état,
  // F90 écritures, F9099-ITER-FN retour en F05.
  // Écarts assumés : le fichier est trié avant lecture (le vrai programme l'attend trié), la sélection
  // se fait à la lecture, et l'état reste une édition simplifiée sur la SYSOUT.
  function genererEdition() {
    const { c, rupture: N } = cd;
    const dd = c.code;
    const ruptures = c.argument.slice(0, N).map((x) => rubrique(model, x));
    const colonnes = (t.colonnes || []).map((x) => rubrique(model, x));
    const totaux = (t.totaux || []).map((x) => rubrique(model, x));
    for (const r of totaux) if (r.type !== '9') throw new Error(`Total impossible sur une rubrique alphanumérique : ${r.code}`);
    for (const r of [...colonnes, ...totaux, ...(t.filtres || []).map((f) => rubrique(model, f.rubrique))]) {
      if (!struct(c).rubriques.includes(r.code)) throw new Error(`La rubrique ${r.code} n'est pas dans l'enregistrement ${c.structure} du fichier ${dd}`);
    }
    const lu = N ? `${dd}-TRIE` : `${dd}-FICHIER`;  // le fichier que lit l'itération
    const cour = N ? `1-${dd}00` : `${dd}00`;        // l'enregistrement traité

    entete([
      'F01 initialisations, F05 debut d iteration,',
      `${N ? 'F10 lecture avec rupture, ' : ''}F20 fin de traitement,`,
      `${N ? 'F22 calcul des derniers, ' : ''}F8E edition, F90 ecritures,`,
      'F9099-ITER-FN fin d iteration.',
      'Les autres fonctions sont les votres.',
    ]);
    src.b(`SELECT ${dd}-FICHIER ASSIGN TO ${litteral(c.fichier)}`);
    src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
    if (N) {
      src.b(`SELECT ${dd}-TRIE ASSIGN TO "${dd}TRIE.TMP"`);
      src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
      src.b(`SELECT ${dd}-TRAVAIL ASSIGN TO "${dd}TRAV.TMP".`);
    }
    selectSorties();
    src.a('DATA DIVISION.');
    src.a('FILE SECTION.');
    src.a(`FD  ${dd}-FICHIER.`);
    if (N) {
      src.a(`01  ${dd}-ENR PIC X(${longueurEnr(c)}).`);
      src.a(`SD  ${dd}-TRAVAIL.`);
      enregistrement(`${dd}-TRV`, c);
      src.a(`FD  ${dd}-TRIE.`);
    }
    enregistrement(`${dd}00`, c);
    fdSorties();
    src.a('WORKING-STORAGE SECTION.');
    if (N) {
      src.com(`Fichier ${dd} : ${dd}00 lu d avance, 1-${dd}00 en traitement`);
      enregistrement(`1-${dd}00`, c);
    }
    variablesConditionnelles({ niveaux: N, aRupture: N ? [[dd, N]] : [], tous: [dd] });
    src.a(`01  5-${dd}00.`);
    src.b(`05 5-${dd}00-CPTENR PIC 9(9) VALUE ZERO.`);
    src.a('01  NB-LUS PIC 9(9) VALUE ZERO.');
    const niveauxTotal = ['G', ...ruptures.map((_, i) => `R${i + 1}`)];
    for (const n of niveauxTotal) {
      if (t.compter) src.a(`01  ${n}-NB PIC 9(7) VALUE ZERO.`);
      for (const r of totaux) {
        const ent = r.longueur - (r.decimales || 0) + 4;
        src.a(`01  ${n}-${ident(r.code)} PIC S9(${ent})${r.decimales ? `V9(${r.decimales})` : ''} VALUE ZERO.`);
      }
    }
    zonesCommunes();
    // ---------- lignes de l'état ----------
    const largeurs = colonnes.map((r) => {
      const l = r.type === 'X' ? r.longueur : largeurEdition(r.longueur - (r.decimales || 0), r.decimales || 0, r.signe);
      return Math.max(l, r.code.length);
    });
    const libelleCol = (r, l) => {
      const lib = (r.libelle || '').normalize('NFD').replace(/[^\x20-\x7e]/g, '').toUpperCase();
      const texte = lib.length <= l ? lib : r.code;
      return r.type === 'X' ? texte.padEnd(l) : texte.padStart(l);
    };
    const titre = (t.titre || pgm).normalize('NFD').replace(/[^\x20-\x7e]/g, '').toUpperCase();
    src.com('Lignes de l etat');
    emettreLigneEdition(src, 'L-TITRE', [{ valeur: titre }]);
    // sans colonne (un état de totaux seuls, ou un traitement pas encore décrit), les lignes restent valides
    const auMoinsUn = (champs) => (champs.length ? champs : [{ blanc: 1 }]);
    emettreLigneEdition(src, 'L-ENTETE', auMoinsUn(colonnes.flatMap((r, i) => [
      ...(i ? [{ blanc: 2 }] : []), { valeur: libelleCol(r, largeurs[i]) },
    ])));
    emettreLigneEdition(src, 'L-TRAIT', [{ valeur: '-'.repeat(Math.max(1, largeurs.reduce((a, b) => a + b, 0) + 2 * (largeurs.length - 1))) }]);
    emettreLigneEdition(src, 'L-DETAIL', auMoinsUn(colonnes.flatMap((r, i) => {
      const champ = r.type === 'X'
        ? { nom: `D-${ident(r.code)}`, pic: `X(${largeurs[i]})` }
        : { nom: `D-${ident(r.code)}`, pic: picEdition(largeurs[i] - (r.signe ? 1 : 0) - (r.decimales ? r.decimales + 1 : 0), r.decimales || 0, r.signe) };
      return [...(i ? [{ blanc: 2 }] : []), champ];
    })));
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
    // lecture d'un enregistrement retenu par la sélection ; para : paragraphe de la lecture, fin : sortie
    const conds = condition(model, t, `${dd}00-`);
    const lire = (para, fin, auBout) => {
      src.a(`${para}.`);
      src.b(`READ ${lu} AT END`);
      src.b(`    MOVE 1 TO ${auBout} GO TO ${fin}.`);
      src.b('ADD 1 TO NB-LUS.');
      if (conds.length) {
        src.instr(0, `IF ${conds.join(' AND ')}`);
        src.b(`    NEXT SENTENCE ELSE GO TO ${para}.`);
      }
    };
    const ouvertures = () => {
      for (const code of [dd, ...sorties.map((x) => x.code)].sort(comparerCodes)) {
        src.a(`F01${code}.`);
        if (code === dd) {
          if (N) {
            src.b(`SORT ${dd}-TRAVAIL`);
            for (const r of ruptures) src.b(`    ON ASCENDING KEY ${dd}-TRV-${K(r)}`);
            src.b(`    USING ${dd}-FICHIER GIVING ${dd}-TRIE.`);
          }
          src.b(`OPEN INPUT ${lu}.`);
          if (N) lire(`F01${dd}-10`, `F01${dd}-FN`, `${dd}-FI`);
        } else src.b(`OPEN OUTPUT ${code}-FICHIER.`);
        src.a(`F01${code}-FN. EXIT.`);
      }
    };
    const fermetures = () => {
      for (const code of [dd, ...sorties.map((x) => x.code)].sort(comparerCodes)) {
        src.a(`F20${code}. CLOSE ${code === dd ? lu : `${code}-FICHIER`}.`);
        src.a(`F20${code}-FN. EXIT.`);
      }
    };
    src.a('PROCEDURE DIVISION.');
    derouler({
      '01': () => {
        src.a('F01.');
        src.b('DISPLAY L-TITRE');
        src.b('DISPLAY L-ENTETE');
        src.b('DISPLAY L-TRAIT.');
        ouvertures();
        sousFonctions('01');
        src.a('F01-FN. EXIT.');
      },
      '05': () => {
        src.a('F05. EXIT.');
        if (!N) {
          src.a(`F05${dd}.`);
          lire(`F05${dd}-10`, `F05${dd}-FN`, `${dd}-FT`);
          src.b(`ADD 1 TO 5-${dd}00-CPTENR.`);
          src.a(`F05${dd}-FN. EXIT.`);
        }
        sousFonctions('05');
        src.a('F05-FN. EXIT.');
      },
      ...(N ? {
        '10': () => {
          src.a('F10. EXIT.');
          src.a(`F10${dd}.`);
          src.a(`F10${dd}-10. MOVE ${dd}-DE TO ${dd}-PE.`);
          src.b(`IF ${dd}-FI = '1'`);
          src.b(`    MOVE 1 TO ${dd}-FT GO TO F10${dd}-FN.`);
          src.b(`MOVE ${dd}00 TO 1-${dd}00.`);
          src.b(`ADD 1 TO 5-${dd}00-CPTENR.`);
          lire(`F10${dd}-20`, `F10${dd}-FN`, `${dd}-FI`);
          src.a(`F10${dd}-FN. EXIT.`);
          sousFonctions('10');
          src.a('F10-FN. EXIT.');
        },
        '22': () => {
          src.a('F22. EXIT.');
          src.a(`F22${dd}. MOVE ZERO TO ${dd}-DE.`);
          src.b(`IF ${dd}-FI = '1' GO TO F22${dd}-1.`);
          ruptures.forEach((r, i) => {
            src.b(`IF ${dd}00-${K(r)} NOT = 1-${dd}00-${K(r)}`);
            src.b(`    GO TO F22${dd}-${i + 1}.`);
          });
          src.b(`GO TO F22${dd}-RT.`);
          ruptures.forEach((_, i) => src.a(`F22${dd}-${i + 1}. MOVE 1 TO ${dd}-DE${i + 1}.`));
          src.com('Sans synchronisation : ruptures totales = celles du fichier');
          src.a(`F22${dd}-RT. MOVE ${dd}-DE TO RTD. MOVE ${dd}-PE TO RTP.`);
          src.b('MOVE NRD TO NRP. MOVE ZERO TO NRD.');
          ruptures.forEach((_, i) => src.b(`IF RTD${i + 1} = '1' MOVE ${i + 1} TO NRD GO TO F22${dd}-FN.`));
          src.a(`F22${dd}-FN. EXIT.`);
          sousFonctions('22');
          src.a('F22-FN. EXIT.');
        },
      } : {}),
      '20': () => {
        src.a('F20. IF FT = ALL \'1\'');
        src.b('NEXT SENTENCE ELSE GO TO F20-FN.');
        if (t.compter) src.b('MOVE G-NB TO LG-NB');
        for (const r of totaux) src.b(`MOVE G-${ident(r.code)} TO LG-${ident(r.code)}`);
        src.b('DISPLAY L-TRAIT');
        src.b('DISPLAY L-TOTAL-G');
        src.b('MOVE NB-LUS TO LC-LUS');
        src.b(`MOVE 5-${dd}00-CPTENR TO LC-RETENUS`);
        src.b('DISPLAY L-COMPTES.');
        sousFonctions('20');
        fermetures();
        src.a('F2099. STOP RUN.');
        src.a('F2099-FN. EXIT.');
        src.a('F20-FN. EXIT.');
      },
      '8E': () => {
        src.a('F8E.');
        ruptures.forEach((r, i) => {
          const nv = `R${i + 1}`;
          src.b(`IF RTP${i + 1} = '1'`);
          if (t.compter) src.i(1, `MOVE ZERO TO ${nv}-NB`);
          for (const x of totaux) src.i(1, `MOVE ZERO TO ${nv}-${ident(x.code)}`);
          src.i(1, `MOVE ${cour}-${K(r)} TO L-CLE-${i + 1}`);
          src.i(1, `DISPLAY L-GROUPE-${i + 1}`);
          src.b('END-IF');
        });
        const niv = N ? `R${N}` : 'G';
        if (t.compter) src.b(`ADD 1 TO ${niv}-NB`);
        for (const r of totaux) src.b(`ADD ${cour}-${ident(r.code)} TO ${niv}-${ident(r.code)}`);
        for (const r of colonnes) src.b(`MOVE ${cour}-${ident(r.code)} TO D-${ident(r.code)}`);
        src.b('CONTINUE.');
        sousFonctions('8E');
        src.a('F8E99.');
        if (t.detail) src.b('DISPLAY L-DETAIL');
        for (let i = N - 1; i >= 0; i--) {
          const nv = `R${i + 1}`, parent = i ? `R${i}` : 'G';
          src.b(`IF RTD${i + 1} = '1'`);
          if (t.compter) src.i(1, `MOVE ${nv}-NB TO L${nv}-NB`);
          for (const x of totaux) src.i(1, `MOVE ${nv}-${ident(x.code)} TO L${nv}-${ident(x.code)}`);
          src.i(1, `DISPLAY L-TOTAL-${nv}`);
          if (t.compter) src.i(1, `ADD ${nv}-NB TO ${parent}-NB`);
          for (const x of totaux) src.i(1, `ADD ${nv}-${ident(x.code)} TO ${parent}-${ident(x.code)}`);
          if (i === 0) src.i(1, 'DISPLAY " "');
          src.b('END-IF');
        }
        src.b('CONTINUE.');
        src.a('F8E-FN. EXIT.');
      },
      '90': finIteration,
    });
    return src.texte();
  }
  // ================= Programme de mise à jour (principal P, mouvements M, résultat R) =================
  function genererMiseAJour() {
    const { p, mv, r: rs, n: N } = cd;
    const [PP, MM, RR] = [p.code, mv.code, rs.code];
    const argP = p.argument.slice(0, N).map((x) => rubrique(model, x));
    const argM = mv.argument.slice(0, N).map((x) => rubrique(model, x));
    const mo = mv.mouvement;
    const rc = rubrique(model, mo.rubrique);
    const [vC, vM, vA] = [mo.creation, mo.modification, mo.annulation].map((v) => String(v).toUpperCase());
    // rubriques du mouvement reportées sur le principal (ni argument, ni code mouvement)
    const reportees = rubs(mv).filter((r) => r.code !== rc.code && !argM.includes(r));
    const titre = (t.titre || pgm).normalize('NFD').replace(/[^\x20-\x7e]/g, '').toUpperCase();
    const pic = (r) => (r.type === 'X' ? `X(${r.longueur})` : `9(${r.longueur})`);
    const niveaux = [...Array(N).keys()].map((i) => i + 1);
    const tous = (f) => niveaux.map(f).join(' ');

    entete([
      'Mise a jour : F01 initialisations, F05 lecture du principal,',
      'F10 lecture des mouvements, F20 fin, F24 configuration,',
      'F70 correspondance, F73 mise a jour, F76 erreurs,',
      'F90 ecritures, F9099 fin d iteration.',
    ]);
    for (const a of [p, mv]) {
      src.b(`SELECT ${a.code}-FICHIER ASSIGN TO ${litteral(a.fichier)}`);
      src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
    }
    src.b(`SELECT ${RR}-FICHIER ASSIGN TO ${litteral(rs.fichier)}`);
    src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
    selectSorties();
    src.a('DATA DIVISION.');
    src.a('FILE SECTION.');
    src.a(`FD  ${PP}-FICHIER.`);
    src.a(`01  ${PP}-ENR PIC X(${longueurEnr(p)}).`);
    src.a(`FD  ${MM}-FICHIER.`);
    src.a(`01  ${MM}-ENR PIC X(${longueurEnr(mv)}).`);
    src.a(`FD  ${RR}-FICHIER.`);
    src.a(`01  ${RR}-ENR PIC X(${longueurEnr(p)}).`);
    fdSorties();
    src.a('WORKING-STORAGE SECTION.');
    src.com(`Principal ${PP}, mouvement courant ${MM}00 et suivant 1-${MM}00,`);
    src.com(`resultat ${RR}00 ; ${MM}00X : le mouvement tel que lu (presence)`);
    enregistrement(`${PP}00`, p);
    enregistrement(`${MM}00`, mv);
    enregistrement(`1-${MM}00`, mv);
    image(`${MM}00X`, mv);
    image(`1-${MM}00X`, mv);
    enregistrement(`${RR}00`, p);
    src.com('Arguments (indicatifs) par niveau, et minimum en cours');
    const argument = (base, rubriquesArg) => {
      // groupes emboîtés : base-ARGn contient les niveaux 1 à n
      const emettre = (k, niveau) => {
        if (k === 0) return;
        src.lignes.push(`${niveau === 1 ? '       01  ' : '           ' + '   '.repeat(niveau - 2)}${niveau === 1 ? '' : String(niveau * 5 - 5).padStart(2, '0') + ' '}${base}-ARG${k}.`);
        if (k > 1) emettre(k - 1, niveau + 1);
        const r = rubriquesArg[k - 1];
        src.lignes.push(`           ${'   '.repeat(niveau - 1)}${String(niveau * 5).padStart(2, '0')} ${base}-A${k} PIC ${pic(r)}.`);
      };
      emettre(N, 1);
    };
    argument(PP, argP);
    argument(`${PP}P`, argP);
    argument(MM, argM);
    argument(`1-${MM}`, argM);
    argument('MIN', argP);
    src.com('FT fin de fichier, CFn configuration (participe au minimum),');
    src.com('DEn dernier mouvement du niveau, OC1 indicatif en cours');
    src.a(`01  ${PP}-FT PIC 9 VALUE ZERO.`);
    src.a(`01  ${MM}-FT PIC 9 VALUE ZERO.`);
    src.a(`01  1-${MM}-FT PIC 9 VALUE ZERO.`);
    for (const i of niveaux) src.a(`01  ${PP}-CF${i} PIC 9 VALUE 1.`);
    for (const i of niveaux) src.a(`01  ${MM}-CF${i} PIC 9 VALUE 1.`);
    for (const i of niveaux) src.a(`01  ${MM}-DE${i} PIC 9 VALUE ZERO.`);
    src.a(`01  ${PP}-OC1 PIC 9 VALUE ZERO.`);
    src.a(`01  ${RR}-EX PIC 9 VALUE ZERO.`);
    src.a(`01  ${MM}-ERREUR PIC X(40) VALUE SPACES.`);
    src.a('01  IND-FIN PIC X VALUE "N".');
    src.b('88 FIN-TRAITEMENT VALUE "O".');
    for (const c of ['NB-PRINC', 'NB-MVTS', 'NB-CREES', 'NB-MODIFIES', 'NB-ANNULES', 'NB-REJETES', 'NB-ECRITS']) src.a(`01  ${c} PIC 9(7) VALUE ZERO.`);
    zonesCommunes();
    src.com('Lignes du compte rendu');
    emettreLigneEdition(src, 'L-TITRE', [{ valeur: titre }]);
    emettreLigneEdition(src, 'L-ERREUR', [
      { valeur: 'MVT ' },
      ...argM.flatMap((r, i) => [...(i ? [{ blanc: 1 }] : []), { nom: `LE-A${i + 1}`, pic: `X(${longueurFichier(r)})` }]),
      { blanc: 1 }, { nom: 'LE-CODE', pic: 'X' }, { valeur: ' : ' }, { nom: 'LE-MESSAGE', pic: 'X(40)' },
    ]);
    emettreLigneEdition(src, 'L-COMPTE', [{ nom: 'LC-LIBELLE', pic: 'X(20)' }, { valeur: ' : ' }, { nom: 'LC-NOMBRE', pic: 'Z(6)9' }]);

    const lireMouvement = (cible) => {
      src.i(cible, `READ ${MM}-FICHIER INTO 1-${MM}00`);
      src.i(cible + 1, `AT END MOVE 1 TO 1-${MM}-FT`);
      src.i(cible + 1, 'NOT AT END');
      src.i(cible + 2, 'ADD 1 TO NB-MVTS');
      src.i(cible + 2, `MOVE ${MM}-ENR TO 1-${MM}00X`);
      argM.forEach((r, i) => src.i(cible + 2, `MOVE 1-${MM}00-${K(r)} TO 1-${MM}-A${i + 1}`));
      src.i(cible, 'END-READ');
    };
    const arret = (n, message) => {
      src.instr(n, `DISPLAY ${litteral(message)}`);
      src.i(n, 'STOP RUN');
    };
    const compte = (libelle, zone) => {
      src.b(`MOVE ${litteral(libelle)} TO LC-LIBELLE`);
      src.b(`MOVE ${zone} TO LC-NOMBRE`);
      src.b('DISPLAY L-COMPTE');
    };

    src.a('PROCEDURE DIVISION.');
    derouler({
      '01': () => {
        src.a('F01.');
        src.b(`OPEN INPUT ${PP}-FICHIER ${MM}-FICHIER`);
        src.b(`OPEN OUTPUT ${RR}-FICHIER`);
        for (const so of sorties) src.b(`OPEN OUTPUT ${so.code}-FICHIER`);
        src.b('DISPLAY L-TITRE');
        lireMouvement(0);
        src.lignes[src.lignes.length - 1] += '.';
        sousFonctions('01');
        src.a('F01-FN. EXIT.');
      },
      '05': () => {
        src.a('F05.');
        src.com(`Lecture du principal ${PP} s il a participe au tour precedent`);
        src.b(`IF ${PP}-CF${N} = 1 AND ${PP}-FT = 0`);
        src.i(1, `MOVE ${PP}-ARG${N} TO ${PP}P-ARG${N}`);
        src.i(1, `READ ${PP}-FICHIER INTO ${PP}00`);
        src.i(2, `AT END MOVE 1 TO ${PP}-FT`);
        src.i(2, 'NOT AT END');
        src.i(3, 'ADD 1 TO NB-PRINC');
        argP.forEach((r, i) => src.i(3, `MOVE ${PP}00-${K(r)} TO ${PP}-A${i + 1}`));
        src.i(3, `IF NB-PRINC > 1 AND ${PP}-ARG${N} NOT > ${PP}P-ARG${N}`);
        arret(4, `${PP} : INDICATIF EN DOUBLE OU HORS ORDRE`);
        src.i(3, 'END-IF');
        src.i(1, 'END-READ');
        src.b('END-IF.');
        sousFonctions('05');
        src.a('F05-FN. EXIT.');
      },
      '10': () => {
        src.a('F10.');
        src.com(`Lecture des mouvements ${MM} (lus d avance pour la rupture)`);
        src.b(`IF ${MM}-CF${N} = 1 AND ${MM}-FT = 0`);
        src.i(1, `IF 1-${MM}-FT = 1`);
        src.i(2, `MOVE 1 TO ${MM}-FT`);
        src.i(1, 'ELSE');
        src.i(2, `MOVE 1-${MM}00 TO ${MM}00`);
        src.i(2, `MOVE 1-${MM}00X TO ${MM}00X`);
        src.i(2, `MOVE 1-${MM}-ARG${N} TO ${MM}-ARG${N}`);
        lireMouvement(2);
        src.i(2, `IF 1-${MM}-FT = 0 AND 1-${MM}-ARG${N} < ${MM}-ARG${N}`);
        arret(3, `${MM} : MOUVEMENTS HORS ORDRE`);
        src.i(2, 'END-IF');
        src.i(1, 'END-IF');
        src.b('END-IF');
        src.b(`IF ${PP}-FT = 1 AND ${MM}-FT = 1`);
        src.i(1, 'SET FIN-TRAITEMENT TO TRUE');
        src.b('END-IF');
        for (const i of niveaux) {
          src.b(`IF 1-${MM}-FT = 1 OR 1-${MM}-ARG${i} NOT = ${MM}-ARG${i}`);
          src.i(1, `MOVE 1 TO ${MM}-DE${i}`);
          src.b('ELSE');
          src.i(1, `MOVE 0 TO ${MM}-DE${i}`);
          src.b('END-IF');
        }
        src.lignes[src.lignes.length - 1] += '.';
        sousFonctions('10');
        src.a('F10-FN. EXIT.');
      },
      '20': () => {
        src.a('F20.');
        src.b('IF NOT FIN-TRAITEMENT GO TO F20-FN.');
        src.b('DISPLAY " "');
        compte('PRINCIPAL LU', 'NB-PRINC');
        compte('MOUVEMENTS LUS', 'NB-MVTS');
        compte('CREATIONS', 'NB-CREES');
        compte('MODIFICATIONS', 'NB-MODIFIES');
        compte('ANNULATIONS', 'NB-ANNULES');
        compte('MOUVEMENTS REJETES', 'NB-REJETES');
        compte('RESULTAT ECRIT', 'NB-ECRITS');
        src.lignes[src.lignes.length - 1] += '.';
        sousFonctions('20');
        src.a('F2099.');
        for (const so of sorties) src.b(`CLOSE ${so.code}-FICHIER`);
        src.b(`CLOSE ${PP}-FICHIER ${MM}-FICHIER ${RR}-FICHIER`);
        src.b('STOP RUN.');
        src.a('F20-FN. EXIT.');
      },
      '24': () => {
        src.a('F24.');
        src.com('Minimum en cours et participation de chaque fichier');
        src.b(`IF ${PP}-FT = 1`);
        src.i(1, `MOVE ${MM}-ARG${N} TO MIN-ARG${N}`);
        src.b(`ELSE IF ${MM}-FT = 1 OR ${PP}-ARG${N} < ${MM}-ARG${N}`);
        src.i(1, `MOVE ${PP}-ARG${N} TO MIN-ARG${N}`);
        src.b('ELSE');
        src.i(1, `MOVE ${MM}-ARG${N} TO MIN-ARG${N}`);
        src.b('END-IF END-IF');
        for (const [dd] of [[PP], [MM]]) {
          for (const i of niveaux) {
            src.b(`IF ${dd}-FT = 0 AND ${dd}-ARG${i} = MIN-ARG${i}`);
            src.i(1, `MOVE 1 TO ${dd}-CF${i}`);
            src.b('ELSE');
            src.i(1, `MOVE 0 TO ${dd}-CF${i}`);
            src.b('END-IF');
          }
        }
        src.com('Debut d un indicatif : le resultat part du principal, ou de rien');
        src.b(`IF ${PP}-OC1 = 0`);
        src.i(1, `MOVE 1 TO ${PP}-OC1`);
        src.i(1, `IF ${PP}-CF${N} = 1`);
        src.i(2, `MOVE ${PP}00 TO ${RR}00`);
        src.i(2, `MOVE 1 TO ${RR}-EX`);
        src.i(1, 'ELSE');
        src.i(2, `INITIALIZE ${RR}00`);
        argP.forEach((r, i) => src.i(2, `MOVE ${MM}00-${K(argM[i])} TO ${RR}00-${K(r)}`));
        src.i(2, `MOVE 0 TO ${RR}-EX`);
        src.i(1, 'END-IF');
        src.b('END-IF.');
        sousFonctions('24');
        src.a('F24-FN. EXIT.');
      },
      '70': () => {
        src.a('F70.');
        src.b(`IF ${MM}-CF${N} = 0 GO TO F70-FN.`);
        src.b(`MOVE SPACES TO ${MM}-ERREUR`);
        const code = `${MM}00-${K(rc)}`;
        src.b(`IF ${code} = ${litteral(vC)}`);
        src.i(1, `IF ${RR}-EX = 1`);
        src.i(2, `MOVE "CREATION : INDICATIF DEJA PRESENT" TO ${MM}-ERREUR`);
        src.i(1, 'END-IF');
        src.b(`ELSE IF ${code} = ${litteral(vM)} OR ${code} = ${litteral(vA)}`);
        src.i(1, `IF ${RR}-EX = 0`);
        src.i(2, `MOVE "INDICATIF ABSENT DU PRINCIPAL" TO ${MM}-ERREUR`);
        src.i(1, 'END-IF');
        src.b('ELSE');
        src.i(1, `MOVE "CODE MOUVEMENT INCONNU" TO ${MM}-ERREUR`);
        src.b('END-IF END-IF.');
        sousFonctions('70');
        src.a('F70-FN. EXIT.');
      },
      '73': () => {
        src.a('F73.');
        src.b(`IF ${MM}-CF${N} = 0 OR ${MM}-ERREUR NOT = SPACES GO TO F73-FN.`);
        const code = `${MM}00-${K(rc)}`;
        const reporter = (n) => {
          for (const r of reportees) {
            src.i(n, `IF ${MM}00X-${K(r)} NOT = SPACES`);
            src.i(n + 1, `MOVE ${MM}00-${K(r)} TO ${RR}00-${K(r)}`);
            src.i(n, 'END-IF');
          }
        };
        src.b(`IF ${code} = ${litteral(vC)}`);
        src.i(1, `INITIALIZE ${RR}00`);
        argP.forEach((r, i) => src.i(1, `MOVE ${MM}00-${K(argM[i])} TO ${RR}00-${K(r)}`));
        src.i(1, `MOVE 1 TO ${RR}-EX`);
        reporter(1);
        src.i(1, 'ADD 1 TO NB-CREES');
        src.b('END-IF');
        src.b(`IF ${code} = ${litteral(vM)}`);
        reporter(1);
        src.i(1, 'ADD 1 TO NB-MODIFIES');
        src.b('END-IF');
        src.b(`IF ${code} = ${litteral(vA)}`);
        src.i(1, `MOVE 0 TO ${RR}-EX`);
        src.i(1, 'ADD 1 TO NB-ANNULES');
        src.b('END-IF.');
        sousFonctions('73');
        src.a('F73-FN. EXIT.');
      },
      '76': () => {
        src.a('F76.');
        src.b(`IF ${MM}-CF${N} = 0 OR ${MM}-ERREUR = SPACES GO TO F76-FN.`);
        src.b('ADD 1 TO NB-REJETES');
        argM.forEach((r, i) => src.b(`MOVE ${MM}00X-${K(r)} TO LE-A${i + 1}`));
        src.b(`MOVE ${MM}00-${K(rc)} TO LE-CODE`);
        src.b(`MOVE ${MM}-ERREUR TO LE-MESSAGE`);
        src.b('DISPLAY L-ERREUR.');
        sousFonctions('76');
        src.a('F76-FN. EXIT.');
      },
      '90': () => {
        src.a('F90.');
        src.com('Fin de l indicatif : plus de mouvement pour lui');
        src.b(`IF ${MM}-CF${N} = 0 OR ${MM}-DE${N} = 1`);
        src.i(1, `IF ${RR}-EX = 1`);
        src.i(2, `WRITE ${RR}-ENR FROM ${RR}00`);
        src.i(2, 'ADD 1 TO NB-ECRITS');
        src.i(1, 'END-IF');
        src.i(1, `MOVE 0 TO ${PP}-OC1`);
        src.b('END-IF.');
        sousFonctions('90');
        src.a('F9099.');
        src.b('GO TO F05.');
        src.a('F90-FN. EXIT.');
      },
    });
    void tous;
    return src.texte();
  }
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
