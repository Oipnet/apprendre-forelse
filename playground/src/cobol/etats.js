// États, à la façon du chapitre 4 du manuel « Applications Batch » : un état se décrit par ses libellés
// (-L : lignes fixes numérotées), ses structures (-CE : les rubriques à placer sur une ligne et leur
// remplissage) et sa composition (-D : caractéristiques, puis catégories de lignes éditées sous une
// même condition). Il est appelé sur un fichier d'utilisation I et produit une fonction F8e (e : la
// dernière lettre du code de l'état), découpée comme au chapitre 6 : F8ecc chargement des
// catégories, F8eZZ ligne à éditer, F8e00 aiguillage, F8ess remplissage des structures, F8e99
// écriture. Écarts assumés : un seul état par fichier d'impression, pas de structure 00 (SPOOL,
// utilisation J), pas de transformation de date (D, I, C, E, 0, 1, 2) ni de recherche en table (S),
// ni de ligne détail définie dans un autre état ; CSP (SAUT0) devient ADVANCING 0 (GnuCOBOL traite
// CSP comme un saut de page).

import { picEdition, comparerCodes } from './generateur.js';
import { EN, t as tx } from './langue.js';

export const TYPES_LIGNE = EN ? {
  '': 'ordinary line',
  A: 'page header of the iterative categories',
  I: 'iterative category (repeated)',
  Z: 'page footer of the iterative categories',
  '*': 'detail line, with totals',
  T: 'detail line never printed (totals only)',
  0: 'grand total', 1: 'level 1 break total', 2: 'level 2 break total', 3: 'level 3 break total',
} : {
  '': 'ligne ordinaire',
  A: 'en-tête de page des catégories itératives',
  I: 'catégorie itérative (répétée)',
  Z: 'fin de page des catégories itératives',
  '*': 'ligne détail, avec totalisation',
  T: 'ligne détail jamais éditée (totalisation seule)',
  0: 'total général', 1: 'total de rupture 1', 2: 'total de rupture 2', 3: 'total de rupture 3',
};
export const MODES_REMPLISSAGE = {
  M: tx('mouvement de la source', 'move from the source'), T: tx('rubrique à totaliser', 'element to total'), '+': tx('calcul (première ligne) ou addition', 'computation (first line) or addition'),
  R: tx('calcul arrondi (première ligne)', 'rounded computation (first line)'), '-': tx('soustraction', 'subtraction'), '*': 'multiplication', '/': 'division',
  U: tx('remplie par vous (seule la zone est décrite)', 'filled by you (only the field is described)'),
};
export const ETAT_DEFAUT = { longueur: 132, lignesPage: 60, postes: 100, totalEntiers: 11, totalDecimales: 7 };

const deux = (v, def = '00') => String(v ?? '').trim().padStart(2, '0').slice(-2) || def;
const ascii = (s) => String(s ?? '').normalize('NFD').replace(/[^\x20-\x7e]/g, '');
const quote = (s) => `"${s}"`;

// Lettres d'état appelées par les fichiers d'impression (I) : [{ dd, e, code }].
export function etatsAppeles(model) {
  return (model.appels || []).filter((a) => a.utilisation === 'I')
    .flatMap((a) => (a.argument || []).map((e) => ({ dd: a.code, e: String(e).toUpperCase(), code: a.code + String(e).toUpperCase() })));
}

// Vérifie et prépare les états du programme. niveaux : nombre de niveaux de rupture (RTDn).
export function preparerEtats(model, niveaux) {
  const prepares = [];
  const lettres = new Set();
  for (const a of model.appels.filter((x) => x.utilisation === 'I')) {
    const arg = a.argument || [];
    if (!arg.length) throw new Error(tx(`Fichier ${a.code} (impression) : indiquez dans l'argument la lettre de l'état (pour l'état ${a.code}1 : 1)`, `File ${a.code} (print): put the report letter in the argument (for report ${a.code}1: 1)`));
    if (arg.length > 1) throw new Error(tx(`Fichier ${a.code} : un seul état par fichier d'impression dans l'atelier`, `File ${a.code}: only one report per print file in the workshop`));
    const e = String(arg[0]).toUpperCase();
    if (!/^[A-Z0-9]$/.test(e)) throw new Error(tx(`Fichier ${a.code} : la lettre d'état ${arg[0]} doit être un seul caractère, lettre ou chiffre`, `File ${a.code}: report letter ${arg[0]} must be a single character, letter or digit`));
    if (lettres.has(e)) throw new Error(tx(`Deux états finissent par ${e} : la fonction F8${e} serait générée deux fois`, `Two reports end with ${e}: function F8${e} would be generated twice`));
    lettres.add(e);
    const def = (model.etats || []).find((x) => String(x.code || '').toUpperCase() === a.code + e);
    if (!def) throw new Error(tx(`Fichier ${a.code} : l'état ${a.code}${e} n'est pas décrit`, `File ${a.code}: report ${a.code}${e} is not described`));
    prepares.push({ ...preparer(model, def, a.code, e, niveaux), fichier: a.fichier });
  }
  return prepares;
}

function preparer(model, def, dd, e, niveaux) {
  const code = dd + e;
  const ou = tx(`État ${code}`, `Report ${code}`);
  const nombre = (v, d, min, max, quoi, quoiEn) => {
    const n = v === undefined || v === '' ? d : Number(v);
    if (!Number.isInteger(n) || n < min || n > max) throw new Error(tx(`${ou} : ${quoi} de ${min} à ${max}`, `${ou}: ${quoiEn} from ${min} to ${max}`));
    return n;
  };
  const L = nombre(def.longueur, ETAT_DEFAUT.longueur, 1, 264, 'longueur de ligne', 'line length');
  const lignesPage = nombre(def.lignesPage, ETAT_DEFAUT.lignesPage, 1, 99, 'nombre de lignes par page', 'lines per page');
  const postes = nombre(def.postes, ETAT_DEFAUT.postes, 0, 999, 'nombre de postes de la table des catégories (0 : édition directe)', 'entries in the category table (0: direct printing)');
  const totalEntiers = nombre(def.totalEntiers, ETAT_DEFAUT.totalEntiers, 1, 18, 'chiffres entiers des totaux', 'integer digits of the totals');
  const totalDecimales = nombre(def.totalDecimales, ETAT_DEFAUT.totalDecimales, 0, 9, 'décimales des totaux', 'decimals of the totals');
  if (totalEntiers + totalDecimales > 18) throw new Error(tx(`${ou} : les totaux ont 18 chiffres au plus`, `${ou}: totals have 18 digits at most`));

  // ----- libellés (-L) : numérotés de 01 sans trou ni doublon -----
  const libelles = (def.libelles || []).filter((l) => String(l.numero ?? '').trim() !== '')
    .map((l) => ({ numero: deux(l.numero), texte: ascii(l.texte) }))
    .sort((x, y) => x.numero.localeCompare(y.numero));
  libelles.forEach((l, i) => {
    if (l.numero !== deux(i + 1)) throw new Error(tx(`${ou} : les libellés se numérotent de 01 sans trou ni doublon (${l.numero} au lieu de ${deux(i + 1)})`, `${ou}: labels are numbered from 01 with no gap or duplicate (${l.numero} instead of ${deux(i + 1)})`));
    if (l.texte.length > L) throw new Error(tx(`${ou} : le libellé ${l.numero} dépasse la longueur de ligne (${L})`, `${ou}: label ${l.numero} is longer than the line (${L})`));
    if (l.texte.includes('"')) throw new Error(tx(`${ou} : le libellé ${l.numero} contient le délimiteur de littéral "`, `${ou}: label ${l.numero} contains the literal delimiter "`));
  });

  // ----- structures (-CE) : rubriques et remplissage -----
  const structures = new Map();
  let precedente = null;
  for (const [i, x] of (def.structures || []).entries()) {
    const st = deux(x.st);
    const ouL = tx(`${ou}, structure ${st} (ligne ${i + 1})`, `${ou}, structure ${st} (line ${i + 1})`);
    if (!/^(0[1-9]|[1-8]\d|9[0-8])$/.test(st)) throw new Error(tx(`${ouL} : numéro de structure de 01 à 98`, `${ouL}: structure number from 01 to 98`));
    const suite = String(x.suite || '').trim() === '*';
    const mode = String(x.mode ?? '').trim().toUpperCase();
    if (!structures.has(st)) structures.set(st, []);
    const rubs = structures.get(st);
    if (suite) {
      if (!precedente || precedente.st !== st) throw new Error(tx(`${ouL} : une ligne suite (*) prolonge la rubrique de la ligne précédente`, `${ouL}: a continuation line (*) extends the element of the previous line`));
      if (mode && !['+', '-', '*', '/', 'U'].includes(mode)) throw new Error(tx(`${ouL} : une ligne suite porte une opération (+ - * /), U ou rien (suite de la condition)`, `${ouL}: a continuation line carries an operation (+ - * /), U or nothing (condition continued)`));
      if (mode && mode !== 'U' && !['+', 'R'].includes(precedente.mode)) throw new Error(tx(`${ouL} : une opération suit une première ligne + ou R`, `${ouL}: an operation follows a first line + or R`));
      if (mode && mode !== 'U') precedente.operations.push({ op: mode, source: source(x, precedente.rubrique, ouL) });
      if (String(x.condition || '').trim()) precedente.conditions.push(String(x.condition).trim());
      continue;
    }
    const code = String(x.rubrique || '').trim().toUpperCase();
    if (!/^[A-Z0-9]{1,6}$/.test(code)) throw new Error(tx(`${ouL} : code rubrique de 1 à 6 lettres ou chiffres`, `${ouL}: element code of 1 to 6 letters or digits`));
    if (rubs.some((r) => r.rubrique === code)) throw new Error(tx(`${ouL} : la rubrique ${code} figure deux fois dans la structure`, `${ouL}: element ${code} appears twice in the structure`));
    const m = mode || 'M';
    if (!(m in MODES_REMPLISSAGE)) throw new Error(tx(`${ouL} : mode de remplissage ${m} inconnu (${Object.keys(MODES_REMPLISSAGE).join(' ')})`, `${ouL}: unknown fill mode ${m} (${Object.keys(MODES_REMPLISSAGE).join(' ')})`));
    if (['-', '*', '/'].includes(m)) throw new Error(tx(`${ouL} : un calcul commence par + (ou R pour arrondir), les opérations suivent sur des lignes suite`, `${ouL}: a computation starts with + (or R to round), the operations follow on continuation lines`));
    const debut = Number(x.debut);
    if (!Number.isInteger(debut) || debut < 1) throw new Error(tx(`${ouL} : indiquez le début de la rubrique (colonne, à partir de 1)`, `${ouL}: give the start of the element (column, from 1)`));
    const dico = model.dictionnaire.find((d) => d.code === code);
    const format = String(x.format || '').trim().toUpperCase();
    let pic, largeur;
    if (format) {
      if (!/^[A-Z0-9().,+\-/*]{1,30}$/.test(format)) throw new Error(tx(`${ouL} : format d'édition ${format} invalide`, `${ouL}: invalid edit format ${format}`));
      pic = format;
      largeur = largeurPic(format);
    } else if (dico) {
      const ent = dico.longueur - (dico.decimales || 0);
      pic = dico.type === 'X' ? `X(${dico.longueur})` : picEdition(ent, dico.decimales || 0, dico.signe);
      largeur = largeurPic(pic);
    } else throw new Error(tx(`${ouL} : la rubrique ${code} n'est pas au dictionnaire, indiquez son format d'édition`, `${ouL}: element ${code} is not in the dictionary, give its edit format`));
    if (debut + largeur - 1 > L) throw new Error(tx(`${ouL} : la rubrique ${code} déborde de la ligne (colonnes ${debut} à ${debut + largeur - 1}, ligne de ${L})`, `${ouL}: element ${code} runs past the line (columns ${debut} to ${debut + largeur - 1}, line of ${L})`));
    const r = {
      st, rubrique: code, mode: m, debut, largeur, pic, blanc: !dico && String(x.z || '').trim().toUpperCase() === 'Z',
      source: m === 'U' ? null : source(x, code, ouL), operations: [],
      conditions: String(x.condition || '').trim() ? [String(x.condition).trim()] : [],
    };
    rubs.push(r);
    precedente = r;
  }
  const numeros = [...structures.keys()].sort();
  numeros.forEach((st, i) => { if (st !== deux(i + 1)) throw new Error(tx(`${ou} : les structures se numérotent de 01 sans trou (${st} au lieu de ${deux(i + 1)})`, `${ou}: structures are numbered from 01 with no gap (${st} instead of ${deux(i + 1)})`)); });
  for (const [st, rubs] of structures) {
    const tri = [...rubs].sort((x, y) => x.debut - y.debut);
    for (let i = 1; i < tri.length; i++) {
      if (tri[i].debut < tri[i - 1].debut + tri[i - 1].largeur) throw new Error(tx(`${ou}, structure ${st} : ${tri[i].rubrique} chevauche ${tri[i - 1].rubrique}`, `${ou}, structure ${st}: ${tri[i].rubrique} overlaps ${tri[i - 1].rubrique}`));
    }
  }

  // ----- composition (-D) : catégories -----
  const parCode = new Map();
  for (const [i, x] of (def.categories || []).entries()) {
    const ct = String(x.ct || '').trim().toUpperCase();
    const ouL = tx(`${ou}, catégorie ${ct || '?'} (ligne ${i + 1})`, `${ou}, category ${ct || '?'} (line ${i + 1})`);
    if (!/^[A-Z][A-Z0-9]$/.test(ct)) throw new Error(tx(`${ouL} : code de catégorie de 2 caractères, une lettre en tête`, `${ouL}: category code of 2 characters, starting with a letter`));
    if (ct === 'ZZ') throw new Error(tx(`${ouL} : la catégorie ZZ est interdite (F8${e}ZZ est générée)`, `${ouL}: category ZZ is not allowed (F8${e}ZZ is generated)`));
    const type = String(x.type ?? '').trim().toUpperCase();
    if (!(type in TYPES_LIGNE) && !/^[0-9]$/.test(type)) throw new Error(tx(`${ouL} : type de ligne ${type} inconnu (blanc A I Z * T 0 à 9)`, `${ouL}: unknown line type ${type} (blank A I Z * T 0 to 9)`));
    const st = String(x.st ?? '').trim() ? deux(x.st) : '00';
    if (st !== '00' && !structures.has(st)) throw new Error(tx(`${ouL} : la structure ${st} n'est pas décrite`, `${ouL}: structure ${st} is not described`));
    const lb = String(x.lb ?? '').trim() ? deux(x.lb) : '00';
    if (lb !== '00' && !libelles.some((l) => l.numero === lb)) throw new Error(tx(`${ouL} : le libellé ${lb} n'existe pas`, `${ouL}: label ${lb} does not exist`));
    const saut = String(x.saut ?? '').trim() === '' ? '01' : deux(x.saut);
    if (!/^\d\d$/.test(saut)) throw new Error(tx(`${ouL} : saut de 00 à 99`, `${ouL}: skip from 00 to 99`));
    if (!parCode.has(ct)) parCode.set(ct, []);
    // ligne sans structure, libellé ni saut, qui ne porte qu'une condition : suite de la condition de la catégorie
    const rien = (k) => String(x[k] ?? '').trim() === '';
    if (parCode.get(ct).length && rien('st') && rien('lb') && rien('saut') && rien('type') && rien('car') && rien('fosf') && rien('sautType') && !rien('condition')) {
      parCode.get(ct).push({ nlg: String(x.nlg ?? '').trim().padStart(3, '0'), suite: true, condition: String(x.condition).trim() });
      continue;
    }
    parCode.get(ct).push({ nlg: String(x.nlg ?? '').trim().padStart(3, '0'), type, car: String(x.car ?? '').trim().toUpperCase(), st, lb, saut,
      absolu: String(x.sautType || '').trim() === '*', fosf: String(x.fosf || '').trim().toUpperCase(), condition: String(x.condition || '').trim(), ou: ouL });
  }
  if (!parCode.size) throw new Error(tx(`${ou} : aucune catégorie, décrivez au moins une ligne en composition (-D)`, `${ou}: no category, describe at least one line in the layout (-D)`));
  const categories = [...parCode.entries()].sort(([a], [b]) => comparerCodes(a, b)).map(([ct, toutes]) => {
    toutes.sort((x, y) => x.nlg.localeCompare(y.nlg));
    const lignes = toutes.filter((l) => !l.suite);
    const ouC = tx(`${ou}, catégorie ${ct}`, `${ou}, category ${ct}`);
    if (lignes.length > 39) throw new Error(tx(`${ouC} : 39 lignes au plus`, `${ouC}: 39 lines at most`));
    const types = [...new Set(lignes.map((l) => l.type).filter((t) => t !== ''))];
    if (types.length > 1) throw new Error(tx(`${ouC} : une catégorie n'a qu'un type de ligne (ici ${types.join(' et ')})`, `${ouC}: a category has only one line type (here ${types.join(' and ')})`));
    const type = types[0] || '';
    const marquees = lignes.filter((l) => l.type !== '');
    if ((type === '*' || type === 'T') && marquees.length > 1) throw new Error(tx(`${ouC} : une catégorie ne contient qu'une ligne détail`, `${ouC}: a category holds only one detail line`));
    if ((type === '*' || type === 'T' || /\d/.test(type)) && marquees.some((l) => l.st === '00')) throw new Error(tx(`${ouC} : une ligne détail ou de total porte une structure`, `${ouC}: a detail or total line carries a structure`));
    if (/\d/.test(type) && Number(type) > niveaux) throw new Error(tx(`${ouC} : total de rupture ${type}, mais le programme n'a que ${niveaux} niveau(x) de rupture`, `${ouC}: break total ${type}, but the program only has ${niveaux} control break level(s)`));
    if (type === '0' && !niveaux) throw new Error(tx(`${ouC} : un total général demande un niveau de rupture sur le fichier en entrée`, `${ouC}: a grand total needs a control break level on the input file`));
    if (type === 'I' && lignes[0].type !== 'I') throw new Error(tx(`${ouC} : le type I se met sur la première ligne de la catégorie`, `${ouC}: type I goes on the first line of the category`));
    for (const l of lignes) {
      if (l.car && !(type === 'I' && l === lignes[0])) throw new Error(tx(`${l.ou} : les caractéristiques ne servent qu'à la première ligne d'une catégorie itérative (le nombre de répétitions)`, `${l.ou}: the characteristics only apply to the first line of an iterative category (the repeat count)`));
      if (l.fosf && (!/^[A-Z0-9]{2}([A-Z0-9]{2})?$/.test(l.fosf))) throw new Error(tx(`${l.ou} : traitement avant édition ${l.fosf} : un code de fonction (2 caractères) et de sous-fonction`, `${l.ou}: processing before printing ${l.fosf}: a function code (2 characters) and a sub-function code`));
    }
    if (type === 'I' && lignes[0].car && !/^(\d{1,3}|[A-Z][A-Z0-9-]{0,29})$/.test(lignes[0].car)) throw new Error(tx(`${ouC} : nombre de répétitions : un nombre ou un indice`, `${ouC}: repeat count: a number or an index`));
    const nl = lignes.reduce((s, l, i) => s + (i === 0 && l.absolu ? 0 : Number(l.saut)), 0);
    if (nl > 99) throw new Error(tx(`${ouC} : la catégorie compte plus de 99 lignes`, `${ouC}: the category has more than 99 lines`));
    return { ct, type, lignes, nl, absolu: lignes[0].absolu, condition: toutes.map((l) => l.condition).filter(Boolean).join(' ') };
  });

  // ----- totalisation : structure de base (ligne * ou T) et niveaux des lignes de total -----
  const totaux = new Map(); // structure -> { base, niveaux: Set, general, rubriques T, N }
  for (const c of categories) {
    if (c.type !== '*' && c.type !== 'T') continue;
    const st = c.lignes.find((l) => l.type !== '').st;
    if (totaux.has(st)) throw new Error(tx(`${ou} : la structure ${st} est la ligne détail de deux catégories (${totaux.get(st).base.ct} et ${c.ct})`, `${ou}: structure ${st} is the detail line of two categories (${totaux.get(st).base.ct} and ${c.ct})`));
    const rubsT = structures.get(st).filter((r) => r.mode === 'T');
    if (!rubsT.length) throw new Error(tx(`${ou}, catégorie ${c.ct} : la structure ${st} de la ligne détail n'a pas de rubrique à totaliser (mode T)`, `${ou}, category ${c.ct}: structure ${st} of the detail line has no element to total (mode T)`));
    if (!niveaux) throw new Error(tx(`${ou}, catégorie ${c.ct} : la totalisation demande un niveau de rupture sur le fichier en entrée`, `${ou}, category ${c.ct}: totals need a control break level on the input file`));
    totaux.set(st, { base: c, totalCats: [], rubriques: rubsT });
  }
  for (const c of categories) {
    if (!/^\d$/.test(c.type)) continue;
    for (const l of c.lignes.filter((x) => x.type !== '')) {
      const t = totaux.get(l.st);
      if (!t) throw new Error(tx(`${ou}, catégorie ${c.ct} : la structure ${l.st} de la ligne de total n'est la ligne détail (* ou T) d'aucune catégorie`, `${ou}, category ${c.ct}: structure ${l.st} of the total line is not the detail line (* or T) of any category`));
      t.totalCats.push({ ct: c.ct, niveau: Number(c.type) });
    }
  }
  for (const t of totaux.values()) {
    t.N = Math.max(1, ...t.totalCats.map((x) => x.niveau));
    t.general = t.totalCats.some((x) => x.niveau === 0);
    // ordre du chaînage des CATX : les ruptures du niveau 1 au plus bas, puis le total général
    t.totalCats.sort((x, y) => (x.niveau || 99) - (y.niveau || 99) || x.ct.localeCompare(y.ct));
  }
  for (const r of [...structures.values()].flat()) {
    if (r.mode === 'T' && ![...totaux.keys()].includes(r.st)) throw new Error(tx(`${ou}, structure ${r.st} : la rubrique ${r.rubrique} est à totaliser, mais la structure n'est la ligne détail d'aucune catégorie (* ou T)`, `${ou}, structure ${r.st}: element ${r.rubrique} is to be totaled, but the structure is not the detail line of any category (* or T)`));
  }

  return {
    dd, e, code, nom: ascii(def.nom || code), L, lignesPage, postes, direct: postes === 0, totalEntiers, totalDecimales,
    libelles, structures, categories, totaux, condition: String(def.condition || '').trim(),
  };
}

// Source d'une rubrique (-CE) : W type de zone (blanc FD, 1, 2, 5, 6... ou * expression libre),
// puis FF NN RUBRIQ (code du fichier, du segment, de la rubrique ; par défaut la rubrique éditée),
// ou un littéral entre quotes ; l'indice s'ajoute entre parenthèses.
function source(x, code, ou) {
  const w = String(x.w ?? '').trim().toUpperCase();
  const s = String(x.source ?? '').trim();
  const indice = String(x.indice ?? '').trim().toUpperCase();
  if (w === '*') {
    if (!s) throw new Error(tx(`${ou} : W = * : indiquez l'expression source`, `${ou}: W = *: give the source expression`));
    return s + (indice ? ` (${indice})` : '');
  }
  if (/^['"]/.test(s)) {
    if (s.length < 2 || s.at(-1) !== s[0]) throw new Error(tx(`${ou} : littéral ${s} sans quote de fin`, `${ou}: literal ${s} has no closing quote`));
    return s;
  }
  const S = s.toUpperCase();
  if (!/^[A-Z0-9]{2}[A-Z0-9]{2}([A-Z0-9]{1,6})?$/.test(S)) throw new Error(tx(`${ou} : source ${s || '(vide)'} : code fichier (2), segment (2) puis rubrique (par défaut ${code}), un littéral entre quotes ou W = *`, `${ou}: source ${s || '(empty)'}: file code (2), segment (2) then element (default ${code}), a quoted literal or W = *`));
  if (w && !/^[A-Z0-9]$/.test(w)) throw new Error(tx(`${ou} : type de zone ${w} : un caractère (blanc, 1, 2, 5, 6...) ou *`, `${ou}: field type ${w}: one character (blank, 1, 2, 5, 6...) or *`));
  return `${w ? `${w}-` : ''}${S.slice(0, 4)}-${S.slice(4) || code}${indice ? ` (${indice})` : ''}`;
}

// Largeur à l'impression d'une PIC d'édition (X(5), Z(4)9.99, -(5)9...).
function largeurPic(pic) {
  let n = 0;
  const re = /([A-Z9.,+\-/*])(?:\((\d+)\))?/g;
  let m;
  while ((m = re.exec(pic))) { if (!'SV'.includes(m[1])) n += m[2] ? Number(m[2]) : 1; }
  return n;
}

// ================= Génération =================
// Chaque méthode écrit sa partie du programme dans src (Source du générateur).
export function emettreEtats(etats) {
  const zone5 = (et, s) => `5-${et.dd}00-${et.e}${s}`;
  const lib = (et) => `1-LI00-${et.e}`;
  const ligne = (et) => `6-${et.dd}${et.e}00`;
  const champ = (et, st, r) => `6-${et.dd}${et.e}${st}-${r}`;
  const J = (et, ct) => `J${et.dd}${et.e}${ct}`;
  const triplet = (l) => `${l.st}${l.lb}${l.saut}`;
  const stocke = etats.some((et) => !et.direct);
  const iteratives = (et) => et.categories.filter((c) => c.type === 'I');

  return {
    // CONFIGURATION SECTION : le canal 1 de l'imprimante (début de page)
    specialNames(src) {
      src.a('CONFIGURATION SECTION.');
      src.a('SPECIAL-NAMES.');
      src.b('C01 IS SAUTP.');
    },
    selects(src, litteral) {
      for (const et of etats) {
        src.b(`SELECT ${et.dd}-FICHIER ASSIGN TO ${litteral(et.fichier)}`);
        src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
      }
    },
    fds(src) {
      for (const et of etats) {
        src.a(`FD  ${et.dd}-FICHIER.`);
        src.a(`01  ${et.dd}00 PIC X(${et.L}).`);
      }
    },
    codes: () => etats.map((et) => et.dd),
    // indices de l'édition, à placer dans 01 INDICES COMPUTATIONAL SYNC
    indices(src) {
      if (stocke) {
        src.b('05 J00 PIC S9(4) VALUE +1.');
        src.b('05 J01 PIC S9(4) VALUE +1.');
      }
      if (etats.some((et) => et.totaux.size)) for (const j of ['J05', 'J06', 'J07']) src.b(`05 ${j} PIC S9(4) VALUE +0.`);
      for (const et of etats) {
        for (const c of iteratives(et)) {
          src.b(`05 ${J(et, c.ct)} PIC S9(4) VALUE ZERO.`);
          if (et.direct) src.b(`05 ${J(et, c.ct)}M PIC S9(4) VALUE ZERO.`);
        }
      }
    },
    aDesIndices: () => stocke || etats.some((et) => et.totaux.size || iteratives(et).length),
    working(src) {
      if (stocke) {
        const n = Math.max(...etats.filter((et) => !et.direct).map((et) => et.postes));
        src.com(tx('Categories a editer, triplets structure libelle saut', 'Categories to print, structure label skip triplets'));
        src.a('01  CAT-TAB.');
        for (let k = 0; k < n * 2; k += 100) src.b(`05 FILLER PIC X(${Math.min(100, n * 2 - k)}) VALUE SPACES.`);
        src.a('01  CAT-TAB-R REDEFINES CAT-TAB.');
        src.b(`05 CAT PIC XX OCCURS ${String(n).padStart(4, '0')}.`);
      }
      // ST-ABS (saut absolu en tête de catégorie) sert aussi à l'édition directe
      src.a('01  ST-TA.');
      src.b('05 ST-ABS PIC X VALUE SPACE.');
      src.b('05 ST-T.');
      src.i(1, '07 ST-TT OCCURS 40.');
      src.i(2, '10 ST-ST PIC XX.');
      src.i(2, '10 ST-LI PIC 99.');
      src.i(2, '10 ST-SA PIC 99.');
      if (stocke) {
        src.a('01  CONTENU-DES-CATEGORIES.');
        for (const et of etats.filter((x) => !x.direct)) {
          for (const c of et.categories) {
            src.b(`05 TS-${et.e}-${c.ct}.`);
            src.i(1, `10 ABS-${et.e}-${c.ct} PIC X VALUE '${c.absolu ? '*' : ' '}'.`);
            const t = c.lignes.map(triplet).join('');
            for (let k = 0; k < t.length; k += 48) {
              const m = t.slice(k, k + 48);
              src.i(1, `10 FILLER PIC X(${String(m.length).padStart(2, '0')}) VALUE`);
              src.i(1, `'${m}'.`);
            }
          }
        }
      }
      src.a('01  TAILLES-DES-CATEGORIES COMPUTATIONAL-3.');
      for (const et of etats) for (const c of et.categories) src.b(`05 ${et.e}-${c.ct}-NL PIC S99 VALUE +${String(c.nl).padStart(2, '0')}.`);
      if (etats.some((et) => et.totaux.size)) {
        src.a('01  ZONES-TOTALISATION COMPUTATIONAL-3.');
        for (const et of etats) {
          const pic = `S9(${String(et.totalEntiers).padStart(2, '0')})${et.totalDecimales ? `V9(${String(et.totalDecimales).padStart(2, '0')})` : ''}`;
          for (const [st, t] of et.totaux) {
            src.b(`05 ${et.e}${st}-CPT OCCURS ${t.N}.`);
            for (const r of t.rubriques) src.i(1, `10 T${et.e}${st}-${r.rubrique} PIC ${pic}.`);
            if (t.general) for (const r of t.rubriques) src.b(`05 G${et.e}${st}-${r.rubrique} PIC ${pic} VALUE ZERO.`);
          }
        }
      }
      src.a('01  COMPTEURS-ET-VARIABLES-EDITION.');
      src.b('05 COMPTEURS COMPUTATIONAL-3.');
      for (const et of etats) {
        const p = `+${String(et.lignesPage).padStart(2, '0')}`;
        src.i(1, `10 ${zone5(et, 'CLM')} PIC S999 VALUE ${p}.`);
        src.i(1, `10 ${zone5(et, 'CE')} PIC S9(9) VALUE ZERO.`);
        src.i(1, `10 ${zone5(et, 'CL')} PIC S999 VALUE ${p}.`);
        src.i(1, `10 ${zone5(et, 'CL1')} PIC S999 VALUE ${p}.`);
        src.i(1, `10 ${zone5(et, 'CP')} PIC S9(7) VALUE ZERO.`);
      }
      for (const et of etats) src.b(`05 ${zone5(et, 'DP')} PIC X VALUE '1'.`);
      src.b('05 ST-SLS.');
      src.i(1, '10 STX PIC XX.');
      src.i(1, '10 ST9 REDEFINES STX PIC 99.');
      src.i(1, '10 J02 PIC 99.');
      src.i(1, '10 SAUT PIC 99.');
      src.i(1, '10 NUPOL PIC X.');
      src.b('05 CATX PIC XX VALUE SPACE.');
      if (etats.some((et) => et.libelles.length)) {
        src.a('01  LIBELLES.');
        for (const et of etats.filter((x) => x.libelles.length)) {
          src.b(`05 ${et.e}-LIB.`);
          for (const l of et.libelles) {
            src.i(1, `10 ${et.e}-LIB${l.numero}.`);
            const t = l.texte.padEnd(et.L);
            for (let k = 0; k < t.length; k += 44) {
              const m = t.slice(k, k + 44);
              src.i(2, `15 FILLER PIC X(${String(m.length).padStart(2, '0')}) VALUE`);
              src.b(`${quote(m)}.`);
            }
          }
          src.b(`05 ${et.e}-LIB-R REDEFINES ${et.e}-LIB.`);
          src.i(1, `10 ${lib(et)} PIC X(${et.L}) OCCURS ${String(et.libelles.length).padStart(3, '0')}.`);
        }
      }
      // zone d'édition : la ligne (libellé), redéfinie par chaque structure
      for (const et of etats) {
        src.com(tx(`Etat ${et.code} : ${et.nom}`, `Report ${et.code} : ${et.nom}`).slice(0, 64));
        src.a(`01  6-${et.dd}00.`);
        src.b(`05 6-${et.dd}00-${et.e}.`);
        src.i(1, `10 ${ligne(et)} PIC X(${String(et.L).padStart(3, '0')}).`);
        for (const [st, rubs] of [...et.structures].sort(([a], [b]) => a.localeCompare(b))) {
          src.i(1, `10 6-${et.dd}${et.e}${st} REDEFINES ${ligne(et)}.`);
          let col = 1;
          for (const r of [...rubs].sort((x, y) => x.debut - y.debut)) {
            if (r.debut > col) src.i(2, `15 FILLER PIC X(${String(r.debut - col).padStart(3, '0')}).`);
            src.i(2, `15 ${champ(et, st, r.rubrique)} PIC ${r.pic}${r.blanc ? ' BLANK WHEN ZERO' : ''}.`);
            col = r.debut + r.largeur;
          }
        }
      }
    },
    ouverture: (src, dd) => src.b(`OPEN OUTPUT ${dd}-FICHIER.`),

    // ---------- PROCEDURE : une fonction F8e par état ----------
    fonctions() {
      const f = {};
      for (const et of etats) f[`8${et.e}`] = (src) => fonction(src, et);
      return f;
    },
  };

  function fonction(src, et) {
    const F = `F8${et.e}`;
    const CL = zone5(et, 'CL'), CLM = zone5(et, 'CLM'), CL1 = zone5(et, 'CL1');
    const NL = (c) => `${et.e}-${c.ct}-NL`;
    const zs = et.categories.filter((c) => c.type === 'Z');
    const as = et.categories.filter((c) => c.type === 'A');
    const si = (cond, sortie) => { src.instr(0, `IF ${cond}`); src.b(`    NEXT SENTENCE ELSE GO TO ${sortie}.`); };
    // une suite d'instructions dans une seule phrase (sous un IF éventuel), point final
    const phrase = (cond, instrs) => {
      if (cond) src.instr(0, `IF ${cond}`);
      instrs.forEach((x, i) => src.instr(cond ? 1 : 0, x + (i === instrs.length - 1 ? '.' : '')));
    };
    // chargement (ou, en édition directe, édition) d'une catégorie
    const charger = (c) => {
      const l = [];
      if (c.absolu) l.push(`MOVE ${c.lignes[0].saut} TO ${CL}`);
      l.push(`ADD ${NL(c)} TO ${CL}`);
      if (!et.direct) return [...l, `MOVE '${c.ct}' TO CAT (J00) ADD 1 TO J00`];
      l.push(`MOVE '${c.ct}' TO CATX`);
      if (c.absolu) l.push('MOVE \'*\' TO ST-ABS');
      for (const x of c.lignes) l.push(`MOVE '${triplet(x)} ' TO ST-SLS`, `PERFORM ${F}ZZ-010 THRU ${F}99-FN`);
      return l;
    };
    const debordement = () => {
      if (!zs.length && !as.length) return;
      src.instr(0, `IF ${CL} NOT < ${CLM}`);
      const p = [...zs, ...as].map((c) => `PERFORM ${F}${c.ct}`);
      p.forEach((x, i) => src.instr(1, x + (i === p.length - 1 ? '.' : '')));
    };

    src.com(tx(`EDITION ETAT ${et.code} : ${et.nom}`, `PRINT REPORT ${et.code} : ${et.nom}`).slice(0, 62));
    src.a(`${F}.`);
    if (et.condition) si(et.condition, `${F}-FN`);
    for (const c of et.categories) {
      const P = `${F}${c.ct}`;
      src.com(tx(`CHARGEMENT CATEGORIE ${c.ct}`, `LOAD CATEGORY ${c.ct}`));
      const t = [...et.totaux.entries()].find(([, x]) => x.base === c);
      if (t) { base(src, et, c, t[0], t[1], P, charger); continue; }
      if (c.type === 'I') {
        const j = J(et, c.ct), car = c.lignes[0].car;
        src.a(`${P}.`);
        if (c.condition) si(c.condition, `${P}-FN`);
        if (et.direct) {
          if (car) src.b(`MOVE ${car} TO ${j}.`);
          src.b(`MOVE ${j} TO ${j}M.`);
          src.b(`MOVE 1 TO ${j}.`);
          src.a(`${P}-A.`);
          src.b(`IF ${j} > ${j}M GO TO ${P}-FN.`);
          debordement();
          phrase('', [...charger(c), `ADD 1 TO ${j}`, `GO TO ${P}-A`]);
        } else {
          if (car) src.b(`MOVE ${car} TO ${j}.`);
          src.a(`${P}-A.`);
          src.b(`IF ${j} = ZERO GO TO ${P}-FN.`);
          debordement();
          phrase('', [...charger(c), `SUBTRACT 1 FROM ${j}`, `GO TO ${P}-A`]);
        }
        src.a(`${P}-FN. EXIT.`);
        continue;
      }
      // catégorie simple (aussi les en-têtes A et fins de page Z, appelées par PERFORM) : une phrase
      src.a(`${P}.`);
      phrase(c.condition, charger(c));
      src.a(`${P}-FN. EXIT.`);
    }
    // F8eZZ : catégorie et ligne à éditer
    if (et.direct) {
      src.a(`${F}ZZ. MOVE SPACE TO CATX. GO TO ${F}-FN.`);
    } else {
      src.a(`${F}ZZ. MOVE 1 TO J00.`);
      src.a(`${F}ZZ-005. MOVE CAT (J00) TO CATX. IF CATX = ' '`);
      src.b('MOVE 1 TO J00 MOVE SPACE TO CAT-TAB');
      src.b(`GO TO ${F}99-FN. MOVE 0 TO J01.`);
      for (const c of et.categories.filter((x) => x.type !== 'T')) {
        src.b(`IF CATX = '${c.ct}'`);
        src.b(`MOVE TS-${et.e}-${c.ct} TO ST-TA GO TO ${F}ZZ-009.`);
      }
      src.a(`${F}ZZ-009. ADD 1 TO J01.`);
    }
    src.a(`${F}ZZ-010.${et.direct ? '' : ' MOVE ST-TT (J01) TO ST-SLS.'}`);
    if (!et.direct) {
      src.b('IF ST-SLS = SPACE');
      src.b(`ADD 1 TO J00 GO TO ${F}ZZ-005.`);
    }
    if (et.libelles.length) {
      src.b(`IF J02 = '00' MOVE SPACE TO ${ligne(et)} ELSE`);
      src.b(`MOVE ${lib(et)} (J02) TO ${ligne(et)}.`);
    } else src.b(`MOVE SPACE TO ${ligne(et)}.`);
    src.b('IF ST-ABS NOT = \' \' AND SAUT = \'01\'');
    src.b(`ADD 1 TO ${zone5(et, 'CP')}.`);
    src.a(`${F}ZZ-FN. EXIT.`);
    // F8e00 : aiguillage vers la structure
    src.a(`${F}00.`);
    src.b(`IF STX = '00' GO TO ${F}99.`);
    const sts = [...et.structures.keys()].sort();
    if (sts.length) {
      src.b('GO TO');
      for (const st of sts) src.b(`    ${F}${st}`);
      src.b('DEPENDING ON ST9.');
    }
    src.a(`${F}00-FN. EXIT.`);
    for (const st of sts) structure(src, et, st);
    // F8e99 : écriture
    src.com(tx(`ECRITURE ETAT ${et.code}`, `WRITE REPORT ${et.code}`));
    src.a(`${F}99. MOVE 6-${et.dd}00 TO ${et.dd}00.`);
    src.b(`IF ST-ABS = ' ' GO TO ${F}99-10.`);
    src.b('MOVE \' \' TO ST-ABS.');
    src.b(`IF SAUT = '01' MOVE 1 TO ${CL1}`);
    src.b(`WRITE ${et.dd}00 AFTER ADVANCING SAUTP`);
    src.b(`GO TO ${F}99-20.`);
    src.b(`SUBTRACT ${CL1} FROM SAUT.`);
    src.a(`${F}99-10. IF SAUT = '00'`);
    src.b(`WRITE ${et.dd}00 AFTER ADVANCING 0 ELSE`);
    src.b(`WRITE ${et.dd}00 AFTER ADVANCING SAUT`);
    src.b(`ADD SAUT TO ${CL1}.`);
    src.a(`${F}99-20. ADD 1 TO ${zone5(et, 'CE')}.${et.direct ? '' : ` GO TO ${F}ZZ-009.`}`);
    src.a(`${F}99-FN. EXIT.`);
    src.a(`${F}-FN. EXIT.`);
  }

  // Catégorie de la ligne détail d'une structure de totalisation : .090 remise à zéro depuis NRP,
  // .150 cumul au plus bas niveau et chargement, .200/.300 report en cascade jusqu'à NRD, .500 total général.
  function base(src, et, c, st, t, P, charger) {
    const T = (r, i) => `T${et.e}${st}-${r.rubrique} (${i})`;
    const N = t.N;
    src.a(`${P}.`);
    src.b(`IF NRP = ZERO OR NRP > ${N} GO TO ${P}-100.`);
    src.b('MOVE NRP TO J05.');
    src.a(`${P}-090.`);
    for (const r of t.rubriques) src.b(`MOVE ZERO TO ${T(r, 'J05')}.`);
    src.b('ADD 1 TO J05.');
    src.b(`IF J05 NOT > ${N} GO TO ${P}-090.`);
    src.a(`${P}-100. EXIT.`);
    src.a(`${P}-150.`);
    if (c.condition) { src.instr(0, `IF ${c.condition}`); src.b(`    NEXT SENTENCE ELSE GO TO ${P}-200.`); }
    for (const r of t.rubriques) src.instr(0, `ADD ${r.source} TO ${T(r, N)}.`);
    if (c.type === '*') {
      const l = charger(c);
      l.forEach((x, i) => src.instr(0, x + (i === l.length - 1 ? '.' : '')));
    }
    src.a(`${P}-200.`);
    src.b(`IF NRD = ZERO GO TO ${P}-FN.`);
    src.b(`MOVE ${N} TO J07.`);
    src.a(`${P}-300. SUBTRACT 1 FROM J07 GIVING J06.`);
    src.b(`IF J07 < NRD OR J07 = 1 GO TO ${P}-400.`);
    for (const r of t.rubriques) src.instr(0, `ADD ${T(r, 'J07')} TO ${T(r, 'J06')}.`);
    src.b(`SUBTRACT 1 FROM J07 GO TO ${P}-300.`);
    src.a(`${P}-400. EXIT.`);
    if (t.general) {
      src.a(`${P}-500. IF NRD NOT = 1 GO TO ${P}-FN.`);
      for (const r of t.rubriques) src.instr(0, `ADD ${T(r, 1)} TO G${et.e}${st}-${r.rubrique}.`);
    }
    src.a(`${P}-FN. EXIT.`);
  }

  // Remplissage d'une structure : rubriques non totalisées, puis (structure de totalisation) les
  // rubriques à totaliser depuis la source sur la ligne détail, ou depuis le compteur du niveau de
  // la catégorie de total (CATX).
  function structure(src, et, st) {
    const F = `F8${et.e}${st}`;
    const rubs = et.structures.get(st);
    const t = et.totaux.get(st);
    const cible = (r) => `6-${et.dd}${et.e}${st}-${r.rubrique}`;
    src.com(tx(`EDITION STRUCTURE ${st}`, `FILL STRUCTURE ${st}`));
    src.a(`${F}.`);
    // traitements avant édition (FOSF) des lignes qui éditent cette structure
    const fosf = new Set(et.categories.flatMap((c) => c.lignes.filter((l) => l.st === st && l.fosf).map((l) => l.fosf)));
    for (const x of fosf) src.b(`PERFORM F${x} THRU F${x}-FN.`);
    // rang dans une catégorie itérative (édition après chargement) : la première structure l'incrémente
    if (!et.direct) {
      const its = et.categories.filter((c) => c.type === 'I' && c.lignes[0].st === st);
      const seule = its.length === 1 && et.categories.every((c) => c === its[0] || c.lignes.every((l) => l.st !== st));
      for (const c of its) {
        if (seule) src.b(`ADD 1 TO J${et.dd}${et.e}${c.ct}.`);
        else { src.b(`IF CATX = '${c.ct}'`); src.b(`ADD 1 TO J${et.dd}${et.e}${c.ct}.`); }
      }
    }
    const remplir = (r, valeur) => {
      const cond = r.conditions.join(' ');
      if (cond) src.instr(0, `IF ${cond}`);
      const ind = cond ? 1 : 0;
      if (r.operations.length || r.mode === '+' || r.mode === 'R') {
        const expr = [valeur, ...r.operations.flatMap((o) => [o.op, o.source])].join(' ');
        src.instr(ind, `COMPUTE ${cible(r)}${r.mode === 'R' ? ' ROUNDED' : ''} = ${expr}.`);
      } else src.instr(ind, `MOVE ${valeur} TO ${cible(r)}.`);
    };
    for (const r of rubs.filter((x) => x.mode !== 'T' && x.mode !== 'U')) remplir(r, r.source);
    if (t) {
      src.b(`IF CATX NOT = '${t.base.ct}' GO TO ${F}-TOT.`);
      for (const r of t.rubriques) remplir(r, r.source);
      src.b(`GO TO F8${et.e}99.`);
      src.a(`${F}-TOT.`);
      t.totalCats.forEach((x, i) => {
        const dernier = i === t.totalCats.length - 1;
        src.b(`IF CATX NOT = '${x.ct}'`);
        src.b(`    GO TO ${dernier ? `F8${et.e}99` : `${F}-${x.ct}F`}.`);
        for (const r of t.rubriques) {
          const v = x.niveau ? `T${et.e}${st}-${r.rubrique} (${x.niveau})` : `G${et.e}${st}-${r.rubrique}`;
          src.instr(0, `MOVE ${v} TO ${cible(r)}.`);
        }
        if (!dernier) { src.b(`GO TO ${F}-99.`); src.a(`${F}-${x.ct}F.`); }
      });
    }
    src.a(`${F}-99. GO TO F8${et.e}99.`);
    src.a(`${F}-FN. EXIT.`);
  }
}

// Un fichier d'impression tel qu'on le lirait sur le listing : les sauts de page marqués, la surimpression fusionnée.
export function listing(t) {
  const fusion = (ligne) => ligne.split('\r').reduce((acc, x) => [...x.padEnd(acc.length)].map((c, i) => (c === ' ' && i < acc.length ? acc[i] : c)).join(''), '');
  return t.split('\f').map((p, i) => (i ? `${'─'.repeat(16)} ${tx('saut de page', 'page break')} ${'─'.repeat(16)}\n` : '') + p.split('\n').map(fusion).join('\n')).join('').replace(/^\n/, '');
}
