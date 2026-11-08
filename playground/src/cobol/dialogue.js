// Écrans de saisie, à la façon du manuel « Dialogue » de VisualAge Pacbase : un Écran (code de 6 caractères,
// les 2 premiers sont le code du Dialogue) se décrit par la liste de ses zones (-CE : rubriques du dictionnaire,
// libellés fixes, catégories en-tête, répétitive R et bas d'écran Z, LIERR, PFKEY) et par les Segments
// auxquels il accède (-CS). On en tire la maquette (24 x 80, simulée par -SIM) et une transaction COBOL
// pseudo-conversationnelle, découpée comme la « structure standard de la procédure » (annexe du manuel) :
// F01 initialisations, F05 réception (F0510 écran reçu, F0520 code opération), F10 catégorie en cours,
// F15 code mouvement, F20 contrôles, F25 accès en réception, F30 transferts, F35 écritures, F40 gestion de
// la conversation, F50 affichage (F55 catégorie, F60 accès, F65 alimentation), F70 erreurs, F8Z envoi,
// F80 accès physiques, F81UT mémorisation des erreurs.
//
// Le moniteur transactionnel est joué par le terminal : il écrit l'écran reçu dans ECRAN-E, la zone de
// conversation dans CONV-E, lance le programme et lit ECRAN-S (l'écran à afficher, les indicateurs
// d'erreur PR-, le message LIERR) et CONV-S (gardée jusqu'à l'échange suivant).
//
// Écarts assumés : un Dialogue implicite (taille 24 x 80, présentation par défaut N, attributs par nature),
// pas de rubrique répétée horizontalement, ni d'appel d'écran dans l'écran (nature S), ni de traitements
// spécifiques (-P) ; des Segments SQL seulement (organisation S, sur les tables de la base), une clé d'une
// rubrique, une seule ligne 00 par Segment ; le code mouvement d'une catégorie vaut C, M, A (D en anglais),
// blanc = pas de mise à jour ; les nombres se saisissent avec un point décimal.

import { Source, rubrique, picStockage, picEdition } from './generateur.js';
import { EN, t as tx } from './langue.js';

export const LIGNES_ECRAN = 24;
export const COLONNES_ECRAN = 80;
export const ANNULATION = EN ? 'D' : 'A';

export const NATURES = EN ? {
  V: 'variable: shown, enterable, received', F: 'shown, protected, received', P: 'shown, protected, not received',
  O: 'label of the data element only', L: 'fixed label', R: 'repetitive category', Z: 'bottom category',
} : {
  V: 'variable : affichée, saisissable, reçue', F: 'affichée, protégée, reçue', P: 'affichée, protégée, non reçue',
  O: 'libellé de la rubrique seul', L: 'libellé fixe', R: 'catégorie répétitive', Z: 'catégorie bas d\'écran',
};
export const PRESENTATIONS = EN ? {
  L: 'long label on 36, left', M: 'long label on 36, right', N: 'useful long label', S: 'short label on 18, left',
  T: 'short label on 18, right', U: 'useful short label', F: 'data element alone', 1: 'column header above',
} : {
  L: 'libellé long sur 36, à gauche', M: 'libellé long sur 36, à droite', N: 'libellé long utile', S: 'libellé court sur 18, à gauche',
  T: 'libellé court sur 18, à droite', U: 'libellé court utile', F: 'rubrique seule', 1: 'en-tête de colonne au-dessus',
};
export const RECEPTIONS = EN ? {
  N: 'not used', L: 'read', E: 'existence check', M: 'updated (modify)', X: 'updated (create, modify)', T: 'updated (create, modify, delete)',
} : {
  N: 'non utilisé', L: 'lu', E: 'contrôle d\'existence', M: 'mis à jour (modification)', X: 'mis à jour (création, modification)', T: 'mis à jour (création, modification, annulation)',
};
export const OPERATIONS = EN ? {
  A: 'display', M: 'update', S: 'next page', E: 'end of conversation', O: 'call another screen', P: 'same screen',
} : {
  A: 'affichage', M: 'mise à jour', S: 'suite de l\'écran', E: 'abandon de la conversation', O: 'appel d\'un autre écran', P: 'même écran',
};

// ---------------------------------------------------------------- outils
const s = (v) => String(v ?? '').trim();
const maj = (v) => s(v).toUpperCase();
const sansAccent = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[«»"]/g, "'");
const litteral = (t) => `'${sansAccent(t).toUpperCase().replace(/'/g, "''")}'`;
const nombre = (v, def = 0) => (s(v) === '' ? def : Number(s(v)));
export const largeurZone = (r) => (r.type === 'X' ? r.longueur : r.longueur + (r.signe ? 1 : 0) + (r.decimales ? 1 : 0));
const picAffiche = (r) => picEdition(r.longueur - (r.decimales || 0), r.decimales || 0, !!r.signe);
const touche = (v) => { const t = maj(v); return /^\d$/.test(t) ? `0${t}` : t; };

export function ecranVide(code, nom = '') {
  return { code, nom, zones: [], segments: [] };
}

export function normaliserEcran(e) {
  e.code = maj(e.code); e.nom = s(e.nom);
  e.zones = (e.zones || []).map((z) => ({ ...z }));
  e.segments = (e.segments || []).map((x) => ({ ...x }));
  return e;
}

// ---------------------------------------------------------------- analyse et maquette
// Renvoie la maquette (éléments positionnés), les zones de l'écran par catégorie, les touches et les Segments.
export function analyserEcran(modele, ecran) {
  const code = maj(ecran.code);
  if (!/^[A-Z][A-Z0-9][A-Z0-9]{4}$/.test(code)) throw new Error(tx(`Écran ${code || '?'} : code de 6 caractères, les 2 premiers étant le code du Dialogue`, `Screen ${code || '?'}: 6-character code, the first 2 being the Dialogue code`));
  const scr = code.slice(2);
  const ou0 = tx(`Écran ${code}`, `Screen ${code}`);
  const dico = new Map((modele.dictionnaire || []).map((r) => [r.code, r]));
  const lignes = [...(ecran.zones || [])].map((z) => ({ ...z, nlg: s(z.nlg) })).sort((a, b) => Number(a.nlg) - Number(b.nlg));
  const vus = new Set();
  const elements = [];      // { type: 'lib' | 'champ', l, c, long, texte, ... }
  const champs = [];        // rubriques de l'écran (hors libellés)
  const touches = [];       // { touche, oper?, ecran?, simulation?, differe? }
  const operations = [];    // { champ, valeur, oper } : code opération saisi
  let cat = ' ', groupeR = null, groupeZ = null;
  let prec = { l: 1, fin: 1 };
  let dernierChamp = null;
  const occupe = new Map();
  const placer = (l, c, long, ou) => {
    if (l < 1 || l > LIGNES_ECRAN) throw new Error(tx(`${ou} : ligne ${l} hors de l'écran (1 à ${LIGNES_ECRAN})`, `${ou}: line ${l} off the screen (1 to ${LIGNES_ECRAN})`));
    if (c < 1 || c + long - 1 > COLONNES_ECRAN) throw new Error(tx(`${ou} : la zone déborde de la ligne (colonnes ${c} à ${c + long - 1})`, `${ou}: the field runs off the line (columns ${c} to ${c + long - 1})`));
    for (let i = 0; i < long; i++) {
      const k = `${l}:${c + i}`;
      if (occupe.has(k)) throw new Error(tx(`${ou} : elle chevauche ${occupe.get(k)} (ligne ${l}, colonne ${c + i})`, `${ou}: it overlaps ${occupe.get(k)} (line ${l}, column ${c + i})`));
      occupe.set(k, ou.replace(/^.*, /, ''));
    }
  };
  // position du premier caractère (libellé ou contenu) : absolue (A) ou relative à la zone précédente
  const position = (z, ou) => {
    const lg = nombre(z.lg), col = nombre(z.col);
    if (!Number.isInteger(lg) || lg < 0 || lg > LIGNES_ECRAN) throw new Error(tx(`${ou} : numéro de ligne de 0 à ${LIGNES_ECRAN}`, `${ou}: line number from 0 to ${LIGNES_ECRAN}`));
    if (!Number.isInteger(col) || col < 0 || col > COLONNES_ECRAN) throw new Error(tx(`${ou} : numéro de colonne de 0 à ${COLONNES_ECRAN}`, `${ou}: column number from 0 to ${COLONNES_ECRAN}`));
    const type = maj(z.pos);
    if (type === 'A') return { l: lg || 1, c: col || 2 };
    if (type !== '') throw new Error(tx(`${ou} : positionnement blanc (relatif) ou A (absolu)`, `${ou}: positioning blank (relative) or A (absolute)`));
    if (lg > 0) return { l: prec.l + lg, c: col || 2 };
    if (col > 0) return { l: prec.l, c: prec.fin + col };
    return { l: prec.l + 1, c: 2 };
  };

  for (const z of lignes) {
    const nlg = z.nlg;
    if (!/^\d{1,3}$/.test(nlg)) throw new Error(tx(`${ou0} : numéro de ligne ${nlg || '(vide)'} invalide (3 chiffres)`, `${ou0}: invalid line number ${nlg || '(empty)'} (3 digits)`));
    if (vus.has(Number(nlg))) throw new Error(tx(`${ou0} : la ligne ${nlg} est en double`, `${ou0}: line ${nlg} is duplicated`));
    vus.add(Number(nlg));
    const ou = tx(`${ou0}, ligne ${nlg.padStart(3, '0')}`, `${ou0}, line ${nlg.padStart(3, '0')}`);
    const rub = maj(z.rub), nat = maj(z.nat), lib = s(z.libelle), typeLib = maj(z.typeLib);
    const optMaj = maj(z.maj), trt = maj(z.trt);

    // ligne suite : pas de rubrique, pas de libellé, pas de nature : valeurs (V) ou touches de la rubrique précédente
    if (!rub && !nat && !lib) {
      if (!dernierChamp) throw new Error(tx(`${ou} : ligne suite sans rubrique avant elle`, `${ou}: continuation line with no data element before it`));
      if (!['V', 'G', 'S'].includes(optMaj)) throw new Error(tx(`${ou} : une ligne suite porte une valeur (option V) ou un débranchement (G, S)`, `${ou}: a continuation line holds a value (option V) or a branch (G, S)`));
      suite(dernierChamp, z, ou);
      continue;
    }
    if (!rub) {
      // libellé fixe
      if (nat && nat !== 'L') throw new Error(tx(`${ou} : sans rubrique, la nature est L (libellé fixe)`, `${ou}: without a data element, the nature is L (fixed label)`));
      let texte = lib;
      if (typeLib === 'A') {
        const m = /^(\d{1,3})(.)$/.exec(lib);
        if (!m) throw new Error(tx(`${ou} : type A, le libellé s'écrit nnnX (nnn répétitions du caractère X)`, `${ou}: type A, the label is written nnnX (nnn repetitions of character X)`));
        texte = m[2].repeat(Number(m[1]));
      } else if (texte.length > 30) throw new Error(tx(`${ou} : libellé fixe de 30 caractères au plus`, `${ou}: fixed label of 30 characters at most`));
      if (!texte) throw new Error(tx(`${ou} : libellé fixe vide (zone LIBELLÉ, option C2)`, `${ou}: empty fixed label (LABEL field, option C2)`));
      const p = position(z, ou);
      placer(p.l, p.c, texte.length, `${ou}, ${tx('libellé', 'label')} « ${texte.slice(0, 12)} »`);
      elements.push({ type: 'lib', l: p.l, c: p.c, texte, cat, nlg });
      prec = { l: p.l, fin: p.c + texte.length };
      dernierChamp = null;
      continue;
    }
    if (nat === 'R' || nat === 'Z') {
      // rubrique groupe : début d'une catégorie
      if (nat === 'R' && (groupeR || groupeZ)) throw new Error(tx(`${ou} : une seule catégorie répétitive, avant le bas d'écran`, `${ou}: only one repetitive category, before the bottom one`));
      if (nat === 'Z' && groupeZ) throw new Error(tx(`${ou} : une seule catégorie bas d'écran`, `${ou}: only one bottom category`));
      const g = { code: rub, nlg };
      if (nat === 'R') {
        g.rv = nombre(z.rv, 1); g.rh = nombre(z.rh, 1);
        if (!Number.isInteger(g.rv) || g.rv < 1 || g.rv > 20) throw new Error(tx(`${ou} : RV, nombre de lignes de la partie répétitive (1 à 20)`, `${ou}: RV, number of lines of the repetitive part (1 to 20)`));
        if (!Number.isInteger(g.rh) || g.rh < 1 || g.rh > 5) throw new Error(tx(`${ou} : RH, nombre de lignes d'une instance (1 à 5)`, `${ou}: RH, number of lines of one instance (1 to 5)`));
        groupeR = g;
      } else groupeZ = g;
      cat = nat;
      if (s(z.lg) || s(z.col) || maj(z.pos)) { const p = position(z, ou); prec = { l: p.l, fin: p.c }; g.l = p.l; }
      else g.l = prec.l + 1;
      dernierChamp = null;
      continue;
    }
    if (rub === 'PFKEY') {
      const c = { rub, nlg, cat, nature: 'P', speciale: true, valeurs: [] };
      dernierChamp = c;
      suite(c, z, ou);
      continue;
    }
    // rubrique élémentaire (ou LIERR)
    const r = rub === 'LIERR' ? (dico.get('LIERR') || { code: 'LIERR', libelle: tx('Message d\'erreur', 'Error message'), type: 'X', longueur: 72 }) : dico.get(rub);
    if (!r) throw new Error(tx(`${ou} : rubrique ${rub} absente du dictionnaire`, `${ou}: data element ${rub} is not in the dictionary`));
    if (!['V', 'F', 'P', 'O'].includes(nat)) throw new Error(tx(`${ou} : nature de la rubrique V, F, P ou O (L sans rubrique, R ou Z pour une catégorie)`, `${ou}: nature of the data element V, F, P or O (L without a data element, R or Z for a category)`));
    if (rub === 'LIERR' && nat !== 'P') throw new Error(tx(`${ou} : LIERR est obligatoirement de nature P`, `${ou}: LIERR must have nature P`));
    if (champs.some((x) => x.rub === rub && x.nature !== 'O')) throw new Error(tx(`${ou} : la rubrique ${rub} est déjà dans l'écran`, `${ou}: data element ${rub} is already on the screen`));
    if (cat === 'R' && rub === 'LIERR') throw new Error(tx(`${ou} : LIERR hors de la partie répétitive`, `${ou}: LIERR outside the repetitive part`));
    const pres = maj(z.pres) || 'N';
    if (!(pres in PRESENTATIONS)) throw new Error(tx(`${ou} : présentation ${pres} inconnue (${Object.keys(PRESENTATIONS).join(' ')})`, `${ou}: unknown presentation ${pres} (${Object.keys(PRESENTATIONS).join(' ')})`));
    const presence = maj(z.presence);
    if (!['', 'R'].includes(presence)) throw new Error(tx(`${ou} : présence blanc (facultative) ou R (obligatoire)`, `${ou}: presence blank (optional) or R (required)`));
    if (presence && nat !== 'V' && nat !== 'F') throw new Error(tx(`${ou} : seule une rubrique reçue (V ou F) peut être obligatoire`, `${ou}: only a received data element (V or F) can be required`));
    if (!['', 'I', 'O'].includes(trt)) throw new Error(tx(`${ou} : option de traitement blanc, I (code mouvement) ou O (code opération)`, `${ou}: processing option blank, I (transaction code) or O (operation code)`));
    if (trt && nat !== 'V') throw new Error(tx(`${ou} : un code mouvement ou opération est une rubrique variable (V)`, `${ou}: a transaction or operation code is a variable data element (V)`));
    if (trt === 'O' && cat !== ' ') throw new Error(tx(`${ou} : le code opération est dans l'en-tête`, `${ou}: the operation code goes in the header`));
    if (trt === 'I' && champs.some((x) => x.cat === cat && x.trt === 'I')) throw new Error(tx(`${ou} : un seul code mouvement par catégorie`, `${ou}: only one transaction code per category`));
    // libellé et contenu
    const lib36 = s(r.libelle), court = lib36.slice(0, 18);
    let etiquette = '', dessus = '';
    switch (pres) {
      case 'L': etiquette = `${lib36.slice(0, 34).padEnd(35, '.')}:`; break;
      case 'M': etiquette = `${lib36.slice(0, 34).padStart(35, '.')}:`; break;
      case 'N': etiquette = `${lib36}:`; break;
      case 'S': etiquette = `${court.slice(0, 16).padEnd(17, '.')}:`; break;
      case 'T': etiquette = `${court.slice(0, 16).padStart(17, '.')}:`; break;
      case 'U': etiquette = `${court}:`; break;
      case '1': dessus = court; break;
    }
    if (nat === 'O') { etiquette = pres === 'F' || pres === '1' ? court : etiquette.replace(/[.:]+$/, ''); dessus = ''; }
    const long = nat === 'O' ? 0 : largeurZone(r);
    const p = position(z, ou);
    let l = p.l, c = p.c;
    const ouZ = `${ou}, ${rub}`;
    if (dessus) { placer(l, c, dessus.length, ouZ); elements.push({ type: 'lib', l, c, texte: dessus, cat, nlg }); l += 1; }
    if (etiquette) {
      placer(l, c, etiquette.length, ouZ);
      elements.push({ type: 'lib', l, c, texte: etiquette, cat, nlg });
      c += etiquette.length + 1;
    }
    const champ = {
      rub, nlg, cat, nature: nat, r, long, l, c, presence, trt, valeurs: [], ou: ouZ,
      segMaj: '', rubMaj: '', segSrc: '', rubSrc: '', initial: typeLib === 'I' ? lib : '', presentation: typeLib === 'P' ? lib : '',
    };
    if (long) { placer(l, c, long, ouZ); elements.push({ type: 'champ', champ }); }
    prec = { l, fin: c + long };
    champs.push(champ);
    dernierChamp = champ;
    suite(champ, z, ou);
  }

  function suite(c, z, ou) {
    const optMaj = maj(z.maj), segMaj = maj(z.segMaj), segSrc = maj(z.segSrc), trt = maj(z.trt);
    if (c.rub === 'PFKEY') {
      if (!optMaj) return;
      const t = touche(segSrc);
      if (!/^(0\d|1\d|2[0-4]|EN)$/.test(t)) throw new Error(tx(`${ou} : PFKEY, la touche (zone SOURCE) vaut 01 à 24 ou EN (Entrée)`, `${ou}: PFKEY, the key (SOURCE field) is 01 to 24 or EN (Enter)`));
      if (t === '00') throw new Error(tx(`${ou} : PFKEY 00 (Effacer) est gardée par le terminal pour sortir`, `${ou}: PFKEY 00 (Clear) is kept by the terminal to leave`));
      if (touches.some((x) => x.touche === t)) throw new Error(tx(`${ou} : la touche ${t} est déjà décrite`, `${ou}: key ${t} is already described`));
      if (optMaj === 'V') {
        if ((trt || 'O') !== 'O') throw new Error(tx(`${ou} : PFKEY avec option V : option de traitement O`, `${ou}: PFKEY with option V: processing option O`));
        if (!(segMaj in OPERATIONS) || segMaj === 'O') throw new Error(tx(`${ou} : valeur de OPER (zone MISE À JOUR) : A, M, S, E ou P`, `${ou}: OPER value (UPDATE field): A, M, S, E or P`));
        touches.push({ touche: t, oper: segMaj });
      } else if (optMaj === 'G' || optMaj === 'S') {
        if (segMaj && !/^[A-Z][A-Z0-9]{5}$/.test(segMaj)) throw new Error(tx(`${ou} : écran appelé (zone MISE À JOUR) de 6 caractères, ou blanc pour finir`, `${ou}: called screen (UPDATE field) of 6 characters, or blank to end`));
        touches.push({ touche: t, ecran: segMaj, simulation: optMaj === 'S', differe: maj(z.typeSrc) === 'D' });
      } else throw new Error(tx(`${ou} : PFKEY : option de mise à jour V (code opération), G ou S (débranchement)`, `${ou}: PFKEY: update option V (operation code), G or S (branch)`));
      return;
    }
    if (optMaj === 'V') {
      if (!segMaj && !segSrc) throw new Error(tx(`${ou} : option V sans valeur (zone MISE À JOUR)`, `${ou}: option V with no value (UPDATE field)`));
      if (c.trt === 'O') {
        if (!(segMaj in OPERATIONS) || segMaj === 'O') throw new Error(tx(`${ou} : valeur de OPER (zone MISE À JOUR) : A, M, S, E ou P`, `${ou}: OPER value (UPDATE field): A, M, S, E or P`));
        operations.push({ champ: c, valeur: s(z.segSrc), oper: segMaj });
      } else c.valeurs.push(s(z.segMaj));
      return;
    }
    if (optMaj === 'G' || optMaj === 'S') throw new Error(tx(`${ou} : les débranchements (G, S) se décrivent sur PFKEY`, `${ou}: branches (G, S) are described on PFKEY`));
    if (optMaj && optMaj !== 'M') throw new Error(tx(`${ou} : option de mise à jour M (substitution), V (valeur) ou, sur PFKEY, G et S`, `${ou}: update option M (substitution), V (value) or, on PFKEY, G and S`));
    if (segMaj) {
      if (c.nature !== 'V' && c.nature !== 'F') throw new Error(tx(`${ou} : seule une rubrique reçue (V ou F) met à jour un Segment`, `${ou}: only a received data element (V or F) updates a Segment`));
      c.segMaj = segMaj; c.rubMaj = maj(z.rubMaj) || c.rub;
    }
    if (segSrc) {
      if (c.nature === 'O') throw new Error(tx(`${ou} : un libellé seul (O) n'a pas de source`, `${ou}: a label only (O) has no source`));
      c.segSrc = segSrc; c.rubSrc = maj(z.rubSrc) || c.rub;
    }
  }

  // répétitive : les éléments de la catégorie R sont dupliqués RV fois, RH lignes plus bas à chaque fois
  const irr = groupeR ? groupeR.rv : 0, rh = groupeR ? groupeR.rh : 1;
  if (groupeR) {
    const base = elements.filter((e) => (e.type === 'lib' ? e.cat : e.champ.cat) === 'R');
    for (let i = 1; i < irr; i++) {
      for (const e of base) {
        const l = (e.type === 'lib' ? e.l : e.champ.l) + i * rh;
        const c = e.type === 'lib' ? e.c : e.champ.c, long = e.type === 'lib' ? e.texte.length : e.champ.long;
        placer(l, c, long, `${tx('partie répétitive', 'repetitive part')}, ${tx('instance', 'instance')} ${i + 1}, ${e.type === 'lib' ? tx('libellé', 'label') : e.champ.rub}`);
      }
    }
  }
  const segments = analyserSegments(modele, ecran, code, champs, ou0);
  // contrôles croisés
  for (const c of champs) {
    if (c.segMaj && !segments.some((x) => x.seg === c.segMaj && 'MXT'.includes(x.recep) && x.cat === c.cat)) throw new Error(tx(`${c.ou} : Segment ${c.segMaj} à mettre à jour absent du -CS (même catégorie, réception M, X ou T)`, `${c.ou}: Segment ${c.segMaj} to update is missing from -CS (same category, reception M, X or T)`));
    if (c.segSrc) {
      const sg = segments.find((x) => x.seg === c.segSrc);
      if (!sg || sg.affich !== 'A' || sg.cat !== c.cat) throw new Error(tx(`${c.ou} : Segment source ${c.segSrc} absent du -CS (même catégorie, affichage A)`, `${c.ou}: source Segment ${c.segSrc} is missing from -CS (same category, display A)`));
      if (!sg.table.rubriques.includes(c.rubSrc)) throw new Error(tx(`${c.ou} : ${c.rubSrc} n'est pas une colonne de la table ${sg.table.nom}`, `${c.ou}: ${c.rubSrc} is not a column of table ${sg.table.nom}`));
    }
    if (c.segMaj) {
      const sg = segments.find((x) => x.seg === c.segMaj);
      if (!sg.table.rubriques.includes(c.rubMaj)) throw new Error(tx(`${c.ou} : ${c.rubMaj} n'est pas une colonne de la table ${sg.table.nom}`, `${c.ou}: ${c.rubMaj} is not a column of table ${sg.table.nom}`));
    }
  }
  for (const t of touches) if (t.ecran && !(modele.ecrans || []).some((e) => maj(e.code) === t.ecran)) throw new Error(tx(`${ou0}, PFKEY ${t.touche} : l'écran ${t.ecran} n'existe pas`, `${ou0}, PFKEY ${t.touche}: screen ${t.ecran} does not exist`));
  const recus = (k) => champs.filter((c) => c.cat === k && (c.nature === 'V' || c.nature === 'F'));
  const affiches = (k) => champs.filter((c) => c.cat === k && c.long && c.nature !== 'O' && c.rub !== 'LIERR');
  return {
    code, scr, nom: s(ecran.nom), elements, champs, touches, operations, segments, irr, rh, groupeR, groupeZ,
    categories: [' ', ...(groupeR ? ['R'] : []), ...(groupeZ ? ['Z'] : [])], recus, affiches,
    lierr: champs.find((c) => c.rub === 'LIERR'),
  };
}

function analyserSegments(modele, ecran, code, champs, ou0) {
  const segs = [];
  for (const x of ecran.segments || []) {
    const seg = maj(x.seg);
    const ou = `${ou0}, Segment ${seg || '?'}`;
    if (!/^[A-Z][A-Z0-9]{3}$/.test(seg)) throw new Error(tx(`${ou} : code du Segment dans l'écran sur 4 caractères (ex. CL00)`, `${ou}: code of the Segment in the screen on 4 characters (e.g. CL00)`));
    if (segs.some((y) => y.seg === seg)) throw new Error(tx(`${ou} : une seule ligne 00 par Segment`, `${ou}: only one 00 line per Segment`));
    const cat = maj(x.cat) || ' ';
    if (![' ', 'R', 'Z'].includes(cat)) throw new Error(tx(`${ou} : catégorie blanc (en-tête), R ou Z`, `${ou}: category blank (header), R or Z`));
    const org = maj(x.org) || 'S';
    if (org !== 'S') throw new Error(tx(`${ou} : organisation S (DB2) seulement, sur une table de la base`, `${ou}: organization S (DB2) only, on a table of the database`));
    const ext = maj(x.externe) || seg.slice(0, 2);
    const table = (modele.tables || []).find((t) => t.code === ext);
    if (!table) throw new Error(tx(`${ou} : aucune table de code ${ext} dans la base (écran B)`, `${ou}: no table with code ${ext} in the database (screen B)`));
    if (table.cle.length !== 1) throw new Error(tx(`${ou} : la table ${table.nom} a une clé de ${table.cle.length} rubriques ; l'atelier n'accède qu'avec une clé d'une rubrique`, `${ou}: table ${table.nom} has a key of ${table.cle.length} elements; the workshop only accesses with a one-element key`));
    const recep = maj(x.recep) || 'N', affich = maj(x.affich) || 'N';
    if (!(recep in RECEPTIONS)) throw new Error(tx(`${ou} : utilisation en réception ${Object.keys(RECEPTIONS).join(', ')}`, `${ou}: reception usage ${Object.keys(RECEPTIONS).join(', ')}`));
    if (!['N', 'A'].includes(affich)) throw new Error(tx(`${ou} : utilisation en affichage N ou A`, `${ou}: display usage N or A`));
    if (cat === 'R' && 'MXT'.includes(recep)) {
      // mise à jour en répétitive : permise, la clé vient de la ligne
    }
    const cle = maj(x.cle) || table.cle[0];
    if (cle !== table.cle[0]) throw new Error(tx(`${ou} : la rubrique d'accès est la clé de la table, ${table.cle[0]}`, `${ou}: the access element is the table's key, ${table.cle[0]}`));
    // alimentation de la clé : une zone de l'écran (ssss-RUBRIQ), ou blanc / SPACES (début de la table)
    const alim = maj(x.alim);
    let source = null;
    if (alim && alim !== 'SPACES' && alim !== 'ZERO') {
      const m = /^([A-Z0-9]{4})-([A-Z0-9-]+)$/.exec(alim);
      if (!m || m[1] !== code.slice(2)) throw new Error(tx(`${ou} : alimentation de la clé : une zone de l'écran, ${code.slice(2)}-RUBRIQ, ou SPACES`, `${ou}: key source: a screen field, ${code.slice(2)}-ELEMNT, or SPACES`));
      source = champs.find((c) => c.rub === m[2] && (c.nature === 'V' || c.nature === 'F'));
      if (!source) throw new Error(tx(`${ou} : ${m[2]} n'est pas une rubrique reçue de l'écran (V ou F)`, `${ou}: ${m[2]} is not a received data element of the screen (V or F)`));
      if (source.cat !== cat && !(cat === 'R' && source.cat === ' ')) throw new Error(tx(`${ou} : la zone ${alim} n'est pas dans la catégorie du Segment (ou dans l'en-tête, pour la répétitive)`, `${ou}: field ${alim} is not in the Segment's category (or in the header, for the repetitive part)`));
    } else if ('EMXT'.includes(recep) && recep !== 'N' && cat !== 'R') {
      throw new Error(tx(`${ou} : en réception, la clé s'alimente depuis une zone de l'écran (${code.slice(2)}-RUBRIQ)`, `${ou}: in reception, the key comes from a screen field (${code.slice(2)}-ELEMNT)`));
    }
    if (cat === 'R' && recep !== 'N' && (!source || source.cat !== 'R')) throw new Error(tx(`${ou} : en réception, la clé d'une ligne répétitive vient d'une zone de la ligne`, `${ou}: in reception, the key of a repetitive line comes from a field of the line`));
    segs.push({ seg, cat, recep, affich, table, cle, alim, source, ou });
  }
  if (segs.filter((x) => x.cat === 'R' && x.affich === 'A').length > 1) throw new Error(tx(`${ou0} : un seul Segment affiché dans la partie répétitive`, `${ou0}: only one displayed Segment in the repetitive part`));
  return segs;
}

// ---------------------------------------------------------------- maquette pour le terminal
// Zones pour l'écran 3270 : libellés protégés, rubriques (saisissables si V). valeurs : { 'RUB' | 'RUB/i' : texte }.
export function zonesMaquette(m, { valeurs = {}, erreurs = {}, simulation = false } = {}) {
  const zones = [];
  const instances = (cat) => (cat === 'R' ? m.irr : 1);
  for (const e of m.elements) {
    const cat = e.type === 'lib' ? e.cat : e.champ.cat;
    for (let i = 0; i < instances(cat); i++) {
      const dl = cat === 'R' ? i * m.rh : 0;
      if (e.type === 'lib') { zones.push({ l: e.l - 1 + dl, c: e.c - 1, texte: e.texte, protege: true, couleur: 'bleu' }); continue; }
      const c = e.champ, cle = cat === 'R' ? `${c.rub}/${i}` : c.rub;
      let v = valeurs[cle] ?? '';
      if (simulation && !v) v = c.presentation || c.initial || (c.rub === 'LIERR' ? '' : '');
      const saisie = c.nature === 'V';
      zones.push({
        l: c.l - 1 + dl, c: c.c - 1, long: c.long, texte: v, nom: saisie ? cle : undefined, protege: !saisie,
        num: saisie && c.r.type === '9', vif: c.nature === 'F', couleur: c.rub === 'LIERR' ? 'jaune' : saisie ? undefined : 'turquoise',
        erreur: ['2', '4', '5'].includes(erreurs[cle]),
      });
    }
  }
  return zones;
}

// Les zones reçues, dans l'ordre du message (en-tête, partie répétitive ligne par ligne, bas d'écran).
export function ordreRecus(m) {
  const liste = [];
  for (const c of m.recus(' ')) liste.push({ c, cle: c.rub });
  for (let i = 0; i < m.irr; i++) for (const c of m.recus('R')) liste.push({ c, cle: `${c.rub}/${i}`, i });
  for (const c of m.recus('Z')) liste.push({ c, cle: c.rub });
  return liste;
}
export function ordreAffiches(m) {
  const liste = [];
  for (const c of m.affiches(' ')) liste.push({ c, cle: c.rub });
  for (let i = 0; i < m.irr; i++) for (const c of m.affiches('R')) liste.push({ c, cle: `${c.rub}/${i}`, i });
  for (const c of m.affiches('Z')) liste.push({ c, cle: c.rub });
  return liste;
}

// Message envoyé au programme : ICF, touche, puis les zones reçues.
export function messageEntree(m, { icf = '1', touche: t = 'EN', valeurs = {} } = {}) {
  let ligne = icf + t.padEnd(2).slice(0, 2);
  for (const { c, cle } of ordreRecus(m)) {
    const v = String(valeurs[cle] ?? '').replace(/ +$/, '');
    ligne += v.padEnd(c.long).slice(0, c.long);
  }
  return ligne + '\n';
}

// Lecture de l'écran renvoyé : OPER, écran appelé, curseur, LIERR, zones affichées, indicateurs d'erreur.
export function lireSortie(m, texte) {
  const t = (texte || '').split('\n')[0] || '';
  let p = 0;
  const prendre = (n) => { const x = t.slice(p, p + n).padEnd(n); p += n; return x; };
  const oper = prendre(1).trim(), suite = prendre(6).trim(), curseur = Number(prendre(3)) || 0;
  const lierr = prendre(m.lierr ? m.lierr.long : 72).replace(/ +$/, '');
  const valeurs = {}, erreurs = {};
  for (const { c, cle } of ordreAffiches(m)) valeurs[cle] = prendre(c.long).replace(/ +$/, '');
  for (const { cle } of ordreRecus(m)) erreurs[cle] = prendre(1);
  const recus = ordreRecus(m);
  return { oper, suite, curseur: curseur ? recus[curseur - 1]?.cle : null, lierr, valeurs, erreurs };
}

// ---------------------------------------------------------------- génération de la transaction
export function genererEcran(modele, ecran) {
  const m = analyserEcran(modele, ecran);
  const { code, scr } = m;
  const src = new Source();
  const N = (c) => `${scr}-${c.rub}`;                // zone interne (format du dictionnaire)
  const I = (c) => (c.cat === 'R' ? ' (ICATR)' : '');  // indice en partie répétitive
  const largeurLierr = m.lierr ? m.lierr.long : 72;
  const recus = ordreRecus(m), affiches = ordreAffiches(m);
  const nR = m.recus('R').length;
  const rangDe = (c) => {
    // rang de la zone dans le message (1 à n) : constant, ou calculé pour la répétitive
    const k = recus.findIndex((x) => x.c === c);
    return c.cat === 'R' ? { base: k + 1, pas: nR } : { base: k + 1 };
  };
  const longS = 1 + 6 + 3 + largeurLierr + affiches.reduce((a, x) => a + x.c.long, 0) + recus.length;
  const longE = 3 + recus.reduce((a, x) => a + x.c.long, 0);
  const segAff = m.segments.filter((x) => x.affich === 'A');
  const segRec = m.segments.filter((x) => x.recep !== 'N');
  const segR = segAff.find((x) => x.cat === 'R');
  const rubs = (sg) => sg.table.rubriques.map((c) => rubrique(modele, c));
  const pfx = (sg) => sg.seg;
  const estNum = (r) => r.type === '9';

  // ---- en-tête
  src.a('IDENTIFICATION DIVISION.');
  src.a(`PROGRAM-ID. ${code}.`);
  src.com(tx(`Ecran ${code} : ${sansAccent(m.nom || code).toUpperCase()}`, `Screen ${code} : ${sansAccent(m.nom || code).toUpperCase()}`));
  src.com(tx('Transaction generee, pseudo-conversationnelle :', 'Generated transaction, pseudo-conversational :'));
  src.com(tx('F05 reception, F10-F35 categories (controles, mises', 'F05 reception, F10-F35 categories (checks, updates),'));
  src.com(tx('a jour), F40 conversation, F50-F65 affichage, F8Z envoi.', 'F40 conversation, F50-F65 display, F8Z send.'));
  src.a('ENVIRONMENT DIVISION.');
  src.a('INPUT-OUTPUT SECTION.');
  src.a('FILE-CONTROL.');
  for (const f of ['ECRAN-E', 'ECRAN-S', 'CONV-E', 'CONV-S']) {
    src.b(`SELECT ${f} ASSIGN TO "${f}"`);
    src.i(1, 'ORGANIZATION IS LINE SEQUENTIAL.');
  }
  src.a('DATA DIVISION.');
  src.a('FILE SECTION.');
  src.a('FD  ECRAN-E.'); src.a(`01  E-ENR PIC X(${longE}).`);
  src.a('FD  ECRAN-S.'); src.a(`01  S-ENR PIC X(${longS}).`);
  const conv = [['CV-ECRAN', 'X(6)'], ['CV-SUITE', 'X'], ['CV-FT', 'X']];
  for (const sg of segAff) {
    const r = rubrique(modele, sg.cle);
    conv.push([`K-${pfx(sg)}-${sg.cle}`, picStockage(r)]);
    if (sg.cat === 'R') conv.push([`KD-${pfx(sg)}-${sg.cle}`, picStockage(r)]);
  }
  const longConv = conv.reduce((a, [, p]) => a + longueurPic(p), 0);
  src.a('FD  CONV-E.'); src.a(`01  CE-ENR PIC X(${longConv}).`);
  src.a('FD  CONV-S.'); src.a(`01  CS-ENR PIC X(${longConv}).`);
  src.a('WORKING-STORAGE SECTION.');
  src.com(tx('Variables de controle (annexe du manuel Dialogue)', 'Control variables (appendix of the Dialogue manual)'));
  for (const [n, p, v] of [['ICF', 'X', "'0'"], ['OCF', 'X', "'1'"], ['OPER', 'X', "'A'"], ['OPERD', 'X', 'SPACE'], ['CATX', 'X', "'0'"], ['CATM', 'X', 'SPACE'],
    ['CATG', 'X', 'SPACE'], ['GR-EG', 'X', "'1'"], ['FT', 'X', "'0'"], ['ICATR', '99', 'ZERO'], ['IRR', '99', String(m.irr).padStart(2, '0')],
    ['IER', '99', 'ZERO'], ['TOUCHE', 'XX', 'SPACES'], ['ECRAN-APPELE', 'X(6)', 'SPACES'], ['W-MAJ', 'X', "'0'"], ['W-MESSAGE', `X(${largeurLierr})`, 'SPACES'],
    ['W-LIERR', `X(${largeurLierr})`, 'SPACES'], ['W-POSITION', '999', 'ZERO'], ['W-CURSEUR', '999', 'ZERO']]) src.a(`01  ${n} PIC ${p} VALUE ${v}.`);
  if (m.segments.length) src.a('01  SQLCODE PIC S9(9) VALUE ZERO.');
  // écran reçu
  src.com(tx('Ecran recu (zones saisies, telles que tapees)', 'Received screen (fields as typed)'));
  src.a('01  E-ECRAN.');
  src.b('05 E-ICF PIC X.');
  src.b('05 E-TOUCHE PIC XX.');
  const zonesMsg = (pre, liste, pic) => {
    for (const c of liste.filter((x) => x.cat === ' ')) src.b(`05 ${pre}-${N(c)} PIC ${pic(c)}.`);
    const r = liste.filter((x) => x.cat === 'R');
    if (r.length) { src.b(`05 ${pre}-R OCCURS ${m.irr}.`); for (const c of r) src.i(1, `10 ${pre}-${N(c)} PIC ${pic(c)}.`); }
    for (const c of liste.filter((x) => x.cat === 'Z')) src.b(`05 ${pre}-${N(c)} PIC ${pic(c)}.`);
  };
  const recusChamps = m.champs.filter((c) => c.nature === 'V' || c.nature === 'F');
  const affChamps = m.champs.filter((c) => c.long && c.nature !== 'O');
  zonesMsg('E', recusChamps, (c) => `X(${c.long})`);
  // écran envoyé
  src.com(tx('Ecran envoye : OPER, ecran appele, curseur, message, zones,', 'Sent screen : OPER, called screen, cursor, message, fields,'));
  src.com(tx('puis indicateurs d erreur PR- (0 absente, 1 presente,', 'then error flags PR- (0 absent, 1 present, 2 wrongly'));
  src.com(tx('2 absente a tort, 4 classe erronee, 5 contenu errone)', 'absent, 4 wrong class, 5 wrong content)'));
  src.a('01  S-ECRAN.');
  src.b('05 S-OPER PIC X.');
  src.b('05 S-SUITE PIC X(6).');
  src.b('05 S-CURSEUR PIC 999.');
  src.b(`05 S-LIERR PIC X(${largeurLierr}).`);
  zonesMsg('S', affChamps.filter((c) => c.rub !== 'LIERR'), (c) => `X(${c.long})`);
  src.b('05 S-ERREURS.');
  {
    for (const c of recusChamps.filter((x) => x.cat === ' ')) src.i(1, `10 PR-${N(c)} PIC X.`);
    const r = recusChamps.filter((x) => x.cat === 'R');
    if (r.length) { src.i(1, `10 PR-R OCCURS ${m.irr}.`); for (const c of r) src.i(2, `15 PR-${N(c)} PIC X.`); }
    for (const c of recusChamps.filter((x) => x.cat === 'Z')) src.i(1, `10 PR-${N(c)} PIC X.`);
  }
  // zones internes et zones d'édition
  src.com(tx('Zones de l ecran au format du dictionnaire', 'Screen fields in the dictionary format'));
  src.a(`01  W-${scr}.`);
  const internes = m.champs.filter((c) => c.long && c.nature !== 'O' && c.rub !== 'LIERR');
  for (const c of internes.filter((x) => x.cat === ' ')) src.b(`05 ${N(c)} PIC ${picStockage(c.r)}.`);
  {
    const r = internes.filter((x) => x.cat === 'R');
    if (r.length) { src.b(`05 W-R OCCURS ${m.irr}.`); for (const c of r) src.i(1, `10 ${N(c)} PIC ${picStockage(c.r)}.`); }
  }
  for (const c of internes.filter((x) => x.cat === 'Z')) src.b(`05 ${N(c)} PIC ${picStockage(c.r)}.`);
  if (!internes.length) src.b('05 FILLER PIC X.');
  const numeriques = [...new Map(internes.filter((c) => estNum(c.r)).map((c) => [c.rub, c])).values()];
  for (const c of numeriques) src.a(`01  ED-${c.rub} PIC ${picAffiche(c.r)}.`);
  // zone de conversation
  src.com(tx('Conversation (cles d affichage), gardee d un echange a l autre', 'Conversation area (display keys), kept between two exchanges'));
  src.a('01  CONV.');
  for (const [n, p] of conv) src.b(`05 ${n} PIC ${p}.`);
  // Segments
  for (const sg of m.segments) {
    src.com(tx(`Segment ${sg.seg} : table ${sg.table.nom}, cle ${sg.cle}`, `Segment ${sg.seg} : table ${sg.table.nom}, key ${sg.cle}`));
    src.a(`01  ${pfx(sg)}-CF PIC X VALUE '0'.`);
    src.a(`01  ${pfx(sg)}.`);
    for (const r of rubs(sg)) src.b(`05 ${pfx(sg)}-${r.code} PIC ${picStockage(r)}.`);
  }
  const hv = (sg) => sg.table.rubriques.map((c) => `:${pfx(sg)}-${c}`).join(', ');
  if (segR) {
    for (const [nom, op, k] of [[`C-${pfx(segR)}`, '>=', 'K'], [`C-${pfx(segR)}S`, '>', 'K']]) {
      src.instr(0, `EXEC SQL DECLARE ${nom} CURSOR FOR SELECT ${segR.table.rubriques.join(', ')} FROM ${segR.table.nom} WHERE ${segR.cle} ${op} :${k}-${pfx(segR)}-${segR.cle} ORDER BY ${segR.cle} END-EXEC.`);
    }
  }

  // ---- procédure
  const erreur = (c, code_, message, n = 1) => {
    // un indicateur PR- et un message (le premier gardé), puis F81UT
    src.instr(n, `MOVE '${code_}' TO PR-${N(c)}${I(c)}`);
    src.instr(n, `MOVE ${litteral(message)} TO W-MESSAGE`);
    const r = rangDe(c);
    if (c.cat === 'R') src.instr(n, `COMPUTE W-POSITION = ${r.base} + (ICATR - 1) * ${r.pas}`);
    else src.instr(n, `MOVE ${r.base} TO W-POSITION`);
    src.instr(n, 'PERFORM F81UT');
  };
  const libelleDe = (c) => sansAccent(c.r.libelle || c.rub).toUpperCase();
  src.a('PROCEDURE DIVISION.');
  src.a('F01. EXIT.');
  src.a('F0110.');
  src.instr(0, 'OPEN INPUT ECRAN-E CONV-E');
  src.instr(0, 'OPEN OUTPUT ECRAN-S CONV-S');
  src.instr(0, 'MOVE SPACES TO E-ECRAN');
  src.instr(0, "READ ECRAN-E INTO E-ECRAN AT END MOVE '0' TO E-ICF.");
  src.instr(0, 'INITIALIZE CONV');
  src.instr(0, 'READ CONV-E INTO CONV AT END INITIALIZE CONV.');
  src.instr(0, `IF CV-ECRAN NOT = '${code}' OR E-ICF = '0' INITIALIZE CONV.`);
  src.instr(0, 'MOVE E-ICF TO ICF');
  src.instr(0, 'INITIALIZE S-ECRAN');
  src.instr(0, "MOVE ALL '0' TO S-ERREURS");
  src.instr(0, `INITIALIZE W-${scr}.`);
  src.a('F0110-FN. EXIT.');
  src.a('F01-FN. EXIT.');
  src.com(tx('Reception', 'Reception'));
  src.a("F05. IF ICF NOT = '1' GO TO F50.");
  src.a('F0510. MOVE E-TOUCHE TO TOUCHE.');
  src.a('F0510-FN. EXIT.');
  src.a("F0520. MOVE 'M' TO OPER.");
  for (const o of m.operations) src.instr(0, `IF E-${N(o.champ)} = ${litteral(o.valeur)} MOVE '${o.oper}' TO OPER.`);
  for (const t of m.touches) {
    if (t.simulation) continue;
    if (t.oper) src.instr(0, `IF TOUCHE = '${t.touche}' MOVE '${t.oper}' TO OPER.`);
    else if (t.differe) src.instr(0, `IF TOUCHE = '${t.touche}' MOVE '${t.ecran ? 'O' : 'E'}' TO OPERD MOVE '${t.ecran.padEnd(6)}' TO ECRAN-APPELE.`);
    else src.instr(0, `IF TOUCHE = '${t.touche}' MOVE '${t.ecran ? 'O' : 'E'}' TO OPER MOVE '${t.ecran.padEnd(6)}' TO ECRAN-APPELE.`);
  }
  src.instr(0, "IF OPER NOT = 'M' GO TO F40.");
  src.a('F0520-FN. EXIT.');
  src.a('F05-FN. EXIT.');
  // F10 : catégorie en cours
  src.com(tx('Positionnement de la categorie en cours', 'Positioning of the current category'));
  src.a('F10. EXIT.');
  const enchainer = (pre, fin) => {
    src.a(`${pre}10. IF CATX = '0' MOVE ' ' TO CATX GO TO ${pre}-FN.`);
    if (m.groupeR) {
      src.instr(0, `IF CATX = ' ' MOVE 'R' TO CATX MOVE 1 TO ICATR GO TO ${pre}-FN.`);
      src.instr(0, `IF CATX = 'R' AND ICATR < IRR ADD 1 TO ICATR GO TO ${pre}-FN.`);
    }
    if (m.groupeZ) src.instr(0, `IF CATX NOT = 'Z' MOVE 'Z' TO CATX GO TO ${pre}-FN.`);
    src.instr(0, `GO TO ${fin}.`);
    src.a(`${pre}-FN. EXIT.`);
  };
  enchainer('F10', 'F3999-ITER-FT');
  // F15 : code mouvement
  src.com(tx('Code mouvement de la categorie : C, M, A, X (implicite)', 'Category transaction code : C, M, A, X (implicit)'));
  src.a("F15. MOVE SPACE TO CATM. MOVE SPACE TO CATG.");
  for (const k of m.categories) {
    const met = m.segments.some((x) => x.cat === k && 'MXT'.includes(x.recep));
    const action = m.champs.find((c) => c.cat === k && c.trt === 'I');
    const ind = k === 'R' ? ' (ICATR)' : '';
    if (action) {
      const e = `E-${N(action)}${ind}`;
      src.instr(0, `IF CATX = '${k}' AND ${e} = 'C' MOVE 'C' TO CATM.`);
      src.instr(0, `IF CATX = '${k}' AND ${e} = 'M' MOVE 'M' TO CATM.`);
      src.instr(0, `IF CATX = '${k}' AND ${e} = '${ANNULATION}' MOVE 'A' TO CATM.`);
      src.instr(0, `IF CATX = '${k}' AND ${e} NOT = SPACE AND CATM = SPACE`);
      erreur(action, '5', tx(`${libelleDe(action)} : C, M OU ${ANNULATION}`, `${libelleDe(action)} : C, M OR ${ANNULATION}`), 1);
      src.instr(1, 'GO TO F3999-ITER-FN.');
    } else if (met || m.recus(k).length) {
      // mouvement implicite (contrôles, mise à jour s'il y a un Segment) : en répétitive, seulement si la ligne est remplie
      const lignes = m.recus(k);
      if (k === 'R' && lignes.length) src.instr(0, `IF CATX = 'R' AND (${lignes.map((c) => `E-${N(c)} (ICATR) NOT = SPACES`).join(' OR ')}) MOVE 'X' TO CATM.`);
      else src.instr(0, `IF CATX = '${k}' MOVE 'X' TO CATM.`);
    }
  }
  src.instr(0, 'IF CATM = SPACE GO TO F3999-ITER-FN.');
  src.a('F15-FN. EXIT.');
  // F20 : contrôles des rubriques
  src.com(tx('Controles des rubriques : presence, classe, valeurs', 'Data element checks : presence, class, values'));
  src.a('F20. EXIT.');
  let nf = 0;
  for (const k of m.categories) {
    for (const c of m.recus(k)) {
      if (c.trt === 'I') continue;
      const sf = `F20${String(++nf * 10).padStart(2, '0').slice(-2)}`;
      const e = `E-${N(c)}${I(c)}`, z = `${N(c)}${I(c)}`, pr = `PR-${N(c)}${I(c)}`;
      src.a(`${sf}. IF CATX NOT = '${c.cat}' GO TO ${sf}-FN.`);
      src.instr(0, `IF ${e} = SPACES MOVE '0' TO ${pr}.`);
      if (c.presence === 'R') {
        src.instr(0, `IF ${e} = SPACES AND CATM NOT = 'A'`);
        erreur(c, '2', tx(`${libelleDe(c)} : ZONE OBLIGATOIRE`, `${libelleDe(c)} : REQUIRED FIELD`), 1);
        src.instr(1, `GO TO ${sf}-FN.`);
      }
      src.instr(0, `IF ${e} = SPACES GO TO ${sf}-FN.`);
      src.instr(0, `MOVE '1' TO ${pr}.`);
      if (estNum(c.r)) {
        src.instr(0, `IF FUNCTION TEST-NUMVAL (${e}) NOT = 0`);
        erreur(c, '4', tx(`${libelleDe(c)} : NOMBRE ATTENDU`, `${libelleDe(c)} : NUMBER EXPECTED`), 1);
        src.instr(1, `GO TO ${sf}-FN.`);
        src.instr(0, `COMPUTE ${z} = FUNCTION NUMVAL (${e}).`);
      } else src.instr(0, `MOVE ${e} TO ${z}.`);
      if (c.valeurs.length) {
        const v = (x) => (estNum(c.r) ? (/^[+-]?\d+(\.\d+)?$/.test(x) ? x : '0') : litteral(x));
        src.instr(0, `IF ${c.valeurs.map((x) => `${z} NOT = ${v(x)}`).join(' AND ')}`);
        erreur(c, '5', tx(`${libelleDe(c)} : VALEUR NON PREVUE`, `${libelleDe(c)} : UNEXPECTED VALUE`), 1);
        src.instr(1, `GO TO ${sf}-FN.`);
      }
      src.a(`${sf}-FN. EXIT.`);
    }
  }
  // F25 : accès aux Segments en réception
  src.com(tx('Acces aux Segments en reception', 'Segment access in reception'));
  src.a('F25. EXIT.');
  for (const sg of segRec) {
    const p = `F25${pfx(sg)}`;
    const cle = sg.source;
    const ind = sg.cat === 'R' ? ' (ICATR)' : '';
    src.a(`${p}. IF CATX NOT = '${sg.cat}' OR CATG = 'E' GO TO ${p}-FN.`);
    src.instr(0, `IF PR-${N(cle)}${ind} NOT = '1'`);
    erreur(cle, '2', tx(`${libelleDe(cle)} : CLE OBLIGATOIRE`, `${libelleDe(cle)} : KEY REQUIRED`), 1);
    src.instr(1, `GO TO ${p}-FN.`);
    src.instr(0, `MOVE ${N(cle)}${ind} TO ${pfx(sg)}-${sg.cle}.`);
    src.instr(0, `PERFORM F80-${pfx(sg)}-R.`);
    const nomT = sansAccent(sg.table.nom).toUpperCase();
    if (sg.recep === 'E') {
      src.instr(0, 'IF SQLCODE NOT = 0');
      erreur(cle, '5', tx(`${nomT} : CLE INEXISTANTE`, `${nomT} : KEY NOT FOUND`), 1);
    }
    if ('MXT'.includes(sg.recep)) {
      src.instr(0, "IF CATM = 'X' AND SQLCODE = 0 MOVE 'M' TO CATM.");
      src.instr(0, "IF CATM = 'X' MOVE 'C' TO CATM.");
      if (sg.recep === 'M') { src.instr(0, "IF CATM NOT = 'M'"); erreur(cle, '5', tx(`${nomT} : MODIFICATION SEULEMENT`, `${nomT} : MODIFICATION ONLY`), 1); src.instr(1, `GO TO ${p}-FN.`); }
      if (sg.recep === 'X') { src.instr(0, "IF CATM = 'A'"); erreur(cle, '5', tx(`${nomT} : ANNULATION NON PERMISE`, `${nomT} : DELETION NOT ALLOWED`), 1); src.instr(1, `GO TO ${p}-FN.`); }
      src.instr(0, "IF CATM = 'C' AND SQLCODE = 0");
      erreur(cle, '5', tx(`${nomT} : CREATION, LA CLE EXISTE DEJA`, `${nomT} : CREATE, THE KEY ALREADY EXISTS`), 1);
      src.instr(1, `GO TO ${p}-FN.`);
      src.instr(0, "IF CATM NOT = 'C' AND SQLCODE NOT = 0");
      erreur(cle, '5', tx(`${nomT} : CLE INEXISTANTE`, `${nomT} : KEY NOT FOUND`), 1);
      src.instr(1, `GO TO ${p}-FN.`);
      src.instr(0, `IF CATM = 'C' INITIALIZE ${pfx(sg)} MOVE ${N(cle)}${ind} TO ${pfx(sg)}-${sg.cle}.`);
    }
    src.a(`${p}-FN. EXIT.`);
  }
  // F30 : transferts
  src.com(tx('Transfert des rubriques saisies vers les Segments', 'Transfer of the entered data elements to the Segments'));
  src.a("F30. IF CATG = 'E' GO TO F30-FN.");
  for (const c of m.champs.filter((x) => x.segMaj)) {
    src.instr(0, `IF CATX = '${c.cat}' AND PR-${N(c)}${I(c)} = '1' MOVE ${N(c)}${I(c)} TO ${c.segMaj}-${c.rubMaj}.`);
  }
  src.a('F30-FN. EXIT.');
  // F35 : écritures
  src.com(tx('Ecritures de mise a jour (si aucune erreur sur l ecran)', 'Update writes (if no error on the screen)'));
  src.a("F35. IF GR-EG = '4' GO TO F35-FN.");
  for (const sg of segRec.filter((x) => 'MXT'.includes(x.recep))) {
    src.a(`F35${pfx(sg)}. IF CATX NOT = '${sg.cat}' GO TO F35${pfx(sg)}-FN.`);
    src.instr(0, `IF CATM = 'C' PERFORM F80-${pfx(sg)}-W.`);
    src.instr(0, `IF CATM = 'M' PERFORM F80-${pfx(sg)}-RW.`);
    src.instr(0, `IF CATM = 'A' PERFORM F80-${pfx(sg)}-D.`);
    src.instr(0, "MOVE '1' TO W-MAJ.");
    src.a(`F35${pfx(sg)}-FN. EXIT.`);
  }
  src.a('F35-FN. EXIT.');
  src.a('F3999-ITER-FN. GO TO F10.');
  src.a('F3999-ITER-FT. EXIT.');
  // F40 : conversation
  src.com(tx('Gestion de la conversation', 'Conversation management'));
  src.a(m.segments.length ? "F40. IF GR-EG = '4' EXEC SQL ROLLBACK END-EXEC GO TO F50." : "F40. IF GR-EG = '4' GO TO F50.");
  src.instr(0, 'IF OPERD NOT = SPACE MOVE OPERD TO OPER.');
  src.a('F4010.');
  src.com(tx('Alimentation des cles d affichage', 'Display keys'));
  let aDesCles = false;
  for (const sg of segAff) {
    const c = sg.source;
    if (!c || c.cat === 'R') continue;
    aDesCles = true;
    const k = `K-${pfx(sg)}-${sg.cle}`, e = `E-${N(c)}`;
    src.instr(0, `IF OPER = 'A' OR OPER = 'M'`);
    src.instr(1, `IF ${e} = SPACES INITIALIZE ${k}`);
    if (estNum(c.r)) src.instr(1, `ELSE IF FUNCTION TEST-NUMVAL (${e}) = 0 COMPUTE ${k} = FUNCTION NUMVAL (${e}).`);
    else src.instr(1, `ELSE MOVE ${e} TO ${k}.`);
    if (sg.cat === 'R') src.instr(0, "IF OPER = 'A' OR OPER = 'M' MOVE '0' TO CV-SUITE.");
  }
  if (!aDesCles) src.instr(0, 'CONTINUE.');
  src.a('F4010-FN. EXIT.');
  src.a("F4020. IF OPER NOT = 'S' GO TO F4020-FN.");
  if (segR) {
    src.instr(0, "IF CV-FT = '1'");
    src.instr(1, `MOVE ${litteral(tx('FIN DE LA LISTE', 'END OF THE LIST'))} TO W-LIERR`);
    src.instr(0, 'ELSE');
    src.instr(1, `MOVE KD-${pfx(segR)}-${segR.cle} TO K-${pfx(segR)}-${segR.cle}`);
    src.instr(1, "MOVE '1' TO CV-SUITE.");
  } else src.instr(0, 'CONTINUE.');
  src.a('F4020-FN. EXIT.');
  src.a("F4030. IF OPER = 'E' MOVE '0' TO OCF GO TO F8Z05.");
  src.a("F4040. IF OPER = 'O' MOVE '0' TO OCF GO TO F8Z05.");
  src.a('F40-FN. EXIT.');
  // F50 : affichage
  src.com(tx('Affichage', 'Display'));
  src.a("F50. IF GR-EG NOT = '4' GO TO F5010.");
  src.com(tx('Ecran en erreur : on renvoie ce qui a ete saisi', 'Screen in error : what was typed is sent back'));
  for (const c of recusChamps.filter((x) => x.cat !== 'R')) src.instr(0, `MOVE E-${N(c)} TO S-${N(c)}.`);
  if (m.recus('R').length) {
    src.instr(0, 'MOVE 1 TO ICATR.');
    src.a('F50-R. IF ICATR > IRR GO TO F70.');
    for (const c of m.recus('R')) src.instr(0, `MOVE E-${N(c)} (ICATR) TO S-${N(c)} (ICATR).`);
    src.instr(0, 'ADD 1 TO ICATR. GO TO F50-R.');
  } else src.instr(0, 'GO TO F70.');
  src.a("F5010. MOVE '0' TO CATX.");
  src.a('F5010-FN. EXIT.');
  src.a('F55. EXIT.');
  enchainer('F55', 'F6999-ITER-FT');
  src.com(tx('Acces aux Segments en affichage', 'Segment access for display'));
  src.a('F60. EXIT.');
  for (const sg of segAff) {
    const p = `F60${pfx(sg)}`, k = `K-${pfx(sg)}-${sg.cle}`;
    src.a(`${p}. IF CATX NOT = '${sg.cat}' GO TO ${p}-FN.`);
    if (sg.cat !== 'R') {
      src.instr(0, `MOVE '0' TO ${pfx(sg)}-CF.`);
      src.instr(0, `IF ${k} = ${estNum(rubrique(modele, sg.cle)) ? 'ZERO' : 'SPACES'} GO TO ${p}-FN.`);
      src.instr(0, `MOVE ${k} TO ${pfx(sg)}-${sg.cle}.`);
      src.instr(0, `PERFORM F80-${pfx(sg)}-R.`);
      src.instr(0, `IF SQLCODE = 0 MOVE '1' TO ${pfx(sg)}-CF.`);
      if (sg.source) {
        src.instr(0, `IF SQLCODE NOT = 0 AND W-LIERR = SPACES AND W-MAJ = '0'`);
        src.instr(1, `MOVE ${litteral(tx(`${sansAccent(sg.table.nom).toUpperCase()} : CLE INEXISTANTE`, `${sansAccent(sg.table.nom).toUpperCase()} : KEY NOT FOUND`))} TO W-LIERR.`);
      }
    } else {
      const c = `C-${pfx(sg)}`;
      src.instr(0, 'IF ICATR = 1');
      src.instr(1, "MOVE '0' TO FT");
      src.instr(1, `IF CV-SUITE = '1' EXEC SQL OPEN ${c}S END-EXEC`);
      src.instr(1, `ELSE EXEC SQL OPEN ${c} END-EXEC.`);
      src.instr(0, `MOVE '0' TO ${pfx(sg)}-CF.`);
      src.instr(0, `IF FT = '1' GO TO ${p}-90.`);
      src.instr(0, `IF CV-SUITE = '1' EXEC SQL FETCH ${c}S INTO ${hv(sg)} END-EXEC`);
      src.instr(0, `ELSE EXEC SQL FETCH ${c} INTO ${hv(sg)} END-EXEC.`);
      src.instr(0, "IF SQLCODE NOT = 0 MOVE '1' TO FT");
      src.instr(0, `ELSE MOVE '1' TO ${pfx(sg)}-CF MOVE ${pfx(sg)}-${sg.cle} TO KD-${pfx(sg)}-${sg.cle}.`);
      src.a(`${p}-90. IF ICATR NOT = IRR GO TO ${p}-FN.`);
      src.instr(0, `IF CV-SUITE = '1' EXEC SQL CLOSE ${c}S END-EXEC`);
      src.instr(0, `ELSE EXEC SQL CLOSE ${c} END-EXEC.`);
      src.instr(0, 'MOVE FT TO CV-FT.');
    }
    src.a(`${p}-FN. EXIT.`);
  }
  // F65 : alimentation des rubriques affichées
  src.com(tx('Alimentation des rubriques de l ecran', 'Screen data elements'));
  src.a('F65. EXIT.');
  nf = 0;
  for (const k of m.categories) {
    const liste = m.affiches(k).filter((c) => c.rub !== 'LIERR');
    if (!liste.length) continue;
    const sf = `F65${String(++nf * 10).padStart(2, '0').slice(-2)}`;
    src.a(`${sf}. IF CATX NOT = '${k}' GO TO ${sf}-FN.`);
    for (const c of liste) {
      const ind = I(c), z = `${N(c)}${ind}`, sz = `S-${N(c)}${ind}`;
      const sg = c.segSrc && m.segments.find((x) => x.seg === c.segSrc);
      if (sg) {
        src.instr(0, `IF ${pfx(sg)}-CF = '1'`);
        src.instr(1, `MOVE ${pfx(sg)}-${c.rubSrc} TO ${z}`);
        if (estNum(c.r)) { src.instr(1, `MOVE ${z} TO ED-${c.rub}`); src.instr(1, `MOVE ED-${c.rub} TO ${sz}`); }
        else src.instr(1, `MOVE ${z} TO ${sz}`);
        if (c.nature === 'V' || c.nature === 'F') src.instr(0, `ELSE MOVE E-${N(c)}${ind} TO ${sz}.`);
        else src.instr(0, `ELSE MOVE SPACES TO ${sz}.`);
      } else if (c.nature === 'V' || c.nature === 'F') {
        src.instr(0, `MOVE E-${N(c)}${ind} TO ${sz}.`);
      }
      if (c.initial) src.instr(0, `IF ${sz} = SPACES MOVE ${litteral(c.initial)} TO ${sz}.`);
    }
    if (k === 'R') {
      // après une mise à jour réussie, le code mouvement de la ligne est effacé
      const action = m.champs.find((x) => x.cat === 'R' && x.trt === 'I');
      if (action && !action.segSrc) src.instr(0, `IF W-MAJ = '1' MOVE SPACES TO S-${N(action)} (ICATR).`);
    } else {
      const action = m.champs.find((x) => x.cat === k && x.trt === 'I');
      if (action && !action.segSrc) src.instr(0, `IF W-MAJ = '1' MOVE SPACES TO S-${N(action)}.`);
    }
    src.a(`${sf}-FN. EXIT.`);
  }
  src.a('F6999-ITER-FN. GO TO F55.');
  src.a('F6999-ITER-FT. EXIT.');
  // F70 : erreurs et message
  src.com(tx('Traitement des erreurs, message en bas de l ecran', 'Error handling, message at the bottom of the screen'));
  src.a("F70. IF GR-EG = '4' MOVE W-CURSEUR TO S-CURSEUR.");
  src.instr(0, `IF W-LIERR = SPACES AND W-MAJ = '1' MOVE ${litteral(tx('MISE A JOUR EFFECTUEE', 'UPDATE DONE'))} TO W-LIERR.`);
  src.instr(0, 'MOVE W-LIERR TO S-LIERR.');
  src.a('F70-FN. EXIT.');
  // F8Z : envoi
  src.com(tx('Memorisation et envoi de l ecran, fin de programme', 'Saving and sending the screen, end of program'));
  src.a(`F8Z05. MOVE '${code}' TO CV-ECRAN.`);
  src.instr(0, 'WRITE CS-ENR FROM CONV.');
  src.a('F8Z10. MOVE OPER TO S-OPER. MOVE ECRAN-APPELE TO S-SUITE.');
  src.instr(0, 'WRITE S-ENR FROM S-ECRAN.');
  src.a('F8Z20. CLOSE ECRAN-E ECRAN-S CONV-E CONV-S.');
  src.instr(0, 'STOP RUN.');
  // F80 : accès physiques
  if (m.segments.length) src.com(tx('Acces physiques aux Segments', 'Physical access to the Segments'));
  for (const sg of m.segments) {
    const p = pfx(sg), cols = sg.table.rubriques, ouSql = `WHERE ${sg.cle} = :${p}-${sg.cle}`;
    const autres = cols.filter((c) => c !== sg.cle);
    src.a(`F80-${p}-R.`);
    src.instr(0, `EXEC SQL SELECT ${cols.join(', ')} INTO ${hv(sg)} FROM ${sg.table.nom} ${ouSql} END-EXEC.`);
    if ('MXT'.includes(sg.recep)) {
      src.a(`F80-${p}-W.`);
      src.instr(0, `EXEC SQL INSERT INTO ${sg.table.nom} (${cols.join(', ')}) VALUES (${hv(sg)}) END-EXEC.`);
      src.a(`F80-${p}-RW.`);
      if (autres.length) src.instr(0, `EXEC SQL UPDATE ${sg.table.nom} SET ${autres.map((c) => `${c} = :${p}-${c}`).join(', ')} ${ouSql} END-EXEC.`);
      else src.instr(0, 'CONTINUE.');
      src.a(`F80-${p}-D.`);
      src.instr(0, `EXEC SQL DELETE FROM ${sg.table.nom} ${ouSql} END-EXEC.`);
    }
  }
  src.a('F81UT.');
  src.com(tx('Memorisation des erreurs pour l affichage', 'Storing the errors for display'));
  src.instr(0, "MOVE '4' TO GR-EG. MOVE 'E' TO CATG. ADD 1 TO IER.");
  src.instr(0, 'IF W-LIERR = SPACES MOVE W-MESSAGE TO W-LIERR MOVE W-POSITION TO W-CURSEUR.');
  return src.texte();
}

function longueurPic(p) {
  let n = 0;
  const re = /([X9])(?:\((\d+)\))?|V|S/g;
  let x;
  while ((x = re.exec(p))) if (x[1]) n += x[2] ? Number(x[2]) : 1;
  return n;
}
