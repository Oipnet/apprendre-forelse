import { exemple, generer, fichierDonnees, operateurs, longueurFichier, picStockage, rupturesDe, MAX_RUPTURES, analyserLignes, placeFonction, RESERVEES } from './generateur.js';
import { Programme } from './interpreteur.js';
import { bandeauReception, montrerLien } from './partage.js';
import { ouvrirBase, sauverBase, synchroniser, contenuTable, viderTable, formaterValeur } from './base.js';
import { verifierTables, OPERATEURS_BASE } from './generateur.js';

const CLE = 'atelier-cobol-modele-v1';
let modele;
try { modele = JSON.parse(localStorage.getItem(CLE)) || null; } catch { modele = null; }
if (!modele?.dictionnaire) modele = structuredClone(exemple);
if (!modele.sorties) modele.sorties = structuredClone(exemple.sorties);
if (!modele.lignes) {
  // modèle d'une version précédente : on lui ajoute l'exemple de langage structuré
  modele.lignes = structuredClone(exemple.lignes);
  for (const d of exemple.dictionnaire) if (!modele.dictionnaire.some((x) => x.code === d.code)) modele.dictionnaire.push({ ...d });
  delete modele.specifique;
}
if (!modele.tables) {
  // modèle d'avant la base : table d'exemple et, si les fonctions sont libres, son traitement
  modele.tables = structuredClone(exemple.tables);
  for (const d of exemple.dictionnaire) if (!modele.dictionnaire.some((x) => x.code === d.code)) modele.dictionnaire.push({ ...d });
  const base = (l) => l.fo === '30' || (l.fo === '22' && l.sf >= '20');
  if (!modele.lignes.some(base)) modele.lignes.push(...structuredClone(exemple.lignes.filter(base)));
}
// opérateurs de base de la première v0.5 -> opérateur SQL de Pacbase
{ const anciens = { INS: 'W', LEC: 'R', MAJ: 'RW', SUP: 'D', OUV: 'P', SUI: 'RN', FER: 'CL' };
  for (const l of modele.lignes || []) { const a = anciens[(l.ope || '').toUpperCase()]; if (a) { l.opes = `${(l.opes || '').trim()} ${a}`; l.ope = 'SQL'; } } }
if (!modele.traitement.ruptures) { modele.traitement.ruptures = rupturesDe(modele.traitement); delete modele.traitement.rupture; }
let vue = 'dico';
let sourceModifie = false;
let derniersFichiers = {};
let fichierAffiche = 'SYSOUT';

const $ = (s) => document.querySelector(s);
const el = (tag, attrs = {}, ...enfants) => {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else if (k === 'checked' || k === 'disabled' || k === 'selected' || k === 'value') e[k] = v;
    else if (v !== false && v !== undefined) e.setAttribute(k, v);
  }
  for (const x of enfants.flat()) if (x != null) e.append(x);
  return e;
};
const structure = () => modele.structures[0];
const rub = (c) => modele.dictionnaire.find((d) => d.code === c);
const numeriques = () => structure().rubriques.filter((c) => rub(c)?.type === '9');

function sauver() { try { localStorage.setItem(CLE, JSON.stringify(modele)); } catch {} }
function changer(redessiner = false) {
  nettoyer();
  sauver();
  if (!sourceModifie) regenerer();
  if (redessiner) afficher();
}
// garde le modèle cohérent quand une rubrique disparaît
function nettoyer() {
  const codes = new Set(modele.dictionnaire.map((d) => d.code));
  const s = structure();
  s.rubriques = s.rubriques.filter((c) => codes.has(c));
  const t = modele.traitement;
  const dansS = new Set(s.rubriques);
  t.colonnes = t.colonnes.filter((c) => dansS.has(c));
  t.totaux = t.totaux.filter((c) => dansS.has(c) && rub(c).type === '9');
  t.filtres = t.filtres.filter((f) => dansS.has(f.rubrique));
  t.ruptures = t.ruptures.filter((c, i, a) => dansS.has(c) && a.indexOf(c) === i);
  for (const tb of modele.tables || []) { tb.rubriques = tb.rubriques.filter((c) => codes.has(c)); tb.cle = tb.cle.filter((c) => tb.rubriques.includes(c)); }
}

// ---------- Vues du modèle ----------
function vueDico() {
  const lignes = modele.dictionnaire.map((d, i) => el('tr', {},
    el('td', {}, el('input', { class: 'code', value: d.code, 'aria-label': 'Code', oninput: (e) => renommer(d, e.target) })),
    el('td', {}, el('input', { value: d.libelle || '', 'aria-label': 'Libellé', oninput: (e) => { d.libelle = e.target.value; changer(); } })),
    el('td', {}, el('select', { 'aria-label': 'Type', onchange: (e) => { d.type = e.target.value; if (d.type === 'X') { delete d.decimales; delete d.signe; } changer(true); } },
      el('option', { value: 'X', selected: d.type === 'X' }, 'X texte'), el('option', { value: '9', selected: d.type === '9' }, '9 nombre'))),
    el('td', {}, el('input', { class: 'num', type: 'number', min: 1, max: 18, value: d.longueur, 'aria-label': 'Longueur', onchange: (e) => { d.longueur = Math.max(1, Math.min(d.type === '9' ? 18 : 200, Number(e.target.value) || 1)); if ((d.decimales || 0) >= d.longueur) d.decimales = d.longueur - 1; changer(true); } })),
    el('td', {}, d.type === '9' ? el('input', { class: 'num', type: 'number', min: 0, max: 9, value: d.decimales || 0, 'aria-label': 'Décimales', onchange: (e) => { d.decimales = Math.max(0, Math.min(d.longueur - 1, Number(e.target.value) || 0)); changer(true); } }) : ''),
    el('td', { style: 'text-align:center' }, d.type === '9' ? el('input', { type: 'checkbox', checked: !!d.signe, 'aria-label': 'Signé', onchange: (e) => { d.signe = e.target.checked; changer(true); } }) : ''),
    el('td', { class: 'code' }, picStockage(d)),
    el('td', {}, el('button', { class: 'mini', type: 'button', title: 'Supprimer la rubrique', 'aria-label': `Supprimer ${d.code}`, onclick: () => { modele.dictionnaire.splice(i, 1); changer(true); } }, '×')),
  ));
  return [
    el('p', { class: 'aide' }, 'Chaque rubrique est décrite une seule fois : son format sert partout, dans l\'enregistrement, les filtres, l\'état et la saisie des données.'),
    el('div', { class: 'table-defile' }, el('table', {},
      el('thead', {}, el('tr', {}, ...['Code', 'Libellé', 'Type', 'Long.', 'Déc.', 'Signé', 'PIC', ''].map((t) => el('th', {}, t)))),
      el('tbody', {}, lignes))),
    el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => {
      let n = 1; while (rub(`RUB${n}`)) n++;
      modele.dictionnaire.push({ code: `RUB${n}`, libelle: '', type: 'X', longueur: 10 });
      changer(true);
    } }, 'Ajouter une rubrique')),
  ];
}
function renommer(d, input) {
  const nouveau = input.value.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 12);
  if (!nouveau || modele.dictionnaire.some((x) => x !== d && x.code === nouveau)) { input.setCustomValidity('Code vide ou déjà utilisé'); input.reportValidity(); return; }
  input.setCustomValidity('');
  const ancien = d.code;
  d.code = nouveau;
  const s = structure(), t = modele.traitement;
  const r = (c) => (c === ancien ? nouveau : c);
  s.rubriques = s.rubriques.map(r); t.colonnes = t.colonnes.map(r); t.totaux = t.totaux.map(r);
  t.filtres.forEach((f) => (f.rubrique = r(f.rubrique))); t.ruptures = t.ruptures.map(r);
  modele.donnees.forEach((l) => { if (ancien in l) { l[nouveau] = l[ancien]; delete l[ancien]; } });
  changer();
}

function vueStruct() {
  const s = structure();
  let pos = 1;
  const liste = s.rubriques.map((c, i) => {
    const d = rub(c), l = longueurFichier(d), debut = pos; pos += l;
    return el('div', {},
      el('span', {}, `${c} `, el('small', {}, `${picStockage(d)}${d.signe ? ' signe séparé' : ''} · positions ${debut} à ${debut + l - 1}`)),
      el('button', { class: 'mini', type: 'button', 'aria-label': `Monter ${c}`, disabled: i === 0, onclick: () => { [s.rubriques[i - 1], s.rubriques[i]] = [s.rubriques[i], s.rubriques[i - 1]]; changer(true); } }, '↑'),
      el('button', { class: 'mini', type: 'button', 'aria-label': `Descendre ${c}`, disabled: i === s.rubriques.length - 1, onclick: () => { [s.rubriques[i + 1], s.rubriques[i]] = [s.rubriques[i], s.rubriques[i + 1]]; changer(true); } }, '↓'),
      el('button', { class: 'mini', type: 'button', 'aria-label': `Retirer ${c}`, onclick: () => { s.rubriques.splice(i, 1); changer(true); } }, '×'));
  });
  const absentes = modele.dictionnaire.filter((d) => !s.rubriques.includes(d.code));
  return [
    el('p', { class: 'aide' }, 'L\'enregistrement du fichier en entrée : les rubriques, dans l\'ordre où elles se suivent sur chaque ligne.'),
    el('div', { class: 'ligne-champs' },
      el('label', { class: 'champ' }, 'Code', el('input', { class: 'code', value: s.code, oninput: (e) => { s.code = e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '') || 'ENR'; modele.traitement.structure = s.code; changer(); } })),
      el('label', { class: 'champ' }, 'Libellé', el('input', { value: s.libelle || '', oninput: (e) => { s.libelle = e.target.value; sauver(); } }))),
    el('div', { class: 'ordre' }, liste),
    el('p', { class: 'aide' }, `Longueur d'une ligne : ${pos - 1} caractères.`),
    absentes.length ? el('div', { class: 'actions' }, 'Ajouter :', absentes.map((d) => el('button', { class: 'bouton', type: 'button', onclick: () => { s.rubriques.push(d.code); changer(true); } }, d.code))) : null,
  ];
}

function vueTrt() {
  const t = modele.traitement, s = structure();
  const cases = (liste, cible) => el('div', { class: 'cases' }, liste.map((c) => el('label', {},
    el('input', { type: 'checkbox', checked: cible.includes(c), onchange: (e) => {
      const a = e.target.checked ? [...cible, c] : cible.filter((x) => x !== c);
      cible.splice(0, cible.length, ...s.rubriques.filter((x) => a.includes(x)));
      changer();
    } }), c)));
  const filtres = t.filtres.map((f, i) => el('div', { class: 'ligne-champs', style: 'grid-template-columns: minmax(0,1.2fr) minmax(0,1fr) minmax(0,1.2fr) auto; align-items:center' },
    el('select', { 'aria-label': 'Rubrique', onchange: (e) => { f.rubrique = e.target.value; changer(); } }, s.rubriques.map((c) => el('option', { value: c, selected: c === f.rubrique }, c))),
    el('select', { 'aria-label': 'Opérateur', onchange: (e) => { f.op = e.target.value; changer(); } }, operateurs.map((o) => el('option', { value: o, selected: o === f.op }, o))),
    el('input', { value: f.valeur, 'aria-label': 'Valeur', oninput: (e) => { f.valeur = e.target.value; changer(); } }),
    el('button', { class: 'mini', type: 'button', 'aria-label': 'Supprimer le filtre', onclick: () => { t.filtres.splice(i, 1); changer(true); } }, '×')));
  return [
    el('p', { class: 'aide' }, 'Un batch d\'édition : lire le fichier, garder les lignes qui passent les filtres, trier et totaliser par rupture, imprimer l\'état.'),
    el('div', { class: 'ligne-champs' },
      el('label', { class: 'champ' }, 'Programme (8 car.)', el('input', { class: 'code', value: t.programme, maxlength: 8, oninput: (e) => { t.programme = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '') || 'PGM'; changer(); } })),
      el('label', { class: 'champ' }, 'Fichier en entrée', el('input', { class: 'code', value: t.fichier, oninput: (e) => { t.fichier = e.target.value.toUpperCase().replace(/[^A-Z0-9.]/g, '') || 'ENTREE.DAT'; changer(); } })),
      el('label', { class: 'champ', style: 'grid-column: 1 / -1' }, 'Titre de l\'état', el('input', { value: t.titre, oninput: (e) => { t.titre = e.target.value; changer(); } }))),
    el('fieldset', {}, el('legend', {}, 'Filtres (tous vrais)'), filtres,
      el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', disabled: !s.rubriques.length, onclick: () => { t.filtres.push({ rubrique: s.rubriques[0], op: '=', valeur: '' }); changer(true); } }, 'Ajouter un filtre'))),
    el('fieldset', {}, el('legend', {}, 'Ruptures, de la plus large à la plus fine'),
      el('p', { class: 'aide' }, 'Le fichier est trié sur ces rubriques ; un sous-total est imprimé à chaque changement de valeur.'),
      Array.from({ length: Math.min(MAX_RUPTURES, t.ruptures.length + 1) }, (_, i) => el('select', { 'aria-label': `Rupture de niveau ${i + 1}`, onchange: (e) => {
        const v = e.target.value;
        if (v) t.ruptures[i] = v; else t.ruptures.splice(i);
        t.ruptures = t.ruptures.filter((c, k, a) => a.indexOf(c) === k);
        changer(true);
      } },
        el('option', { value: '', selected: !t.ruptures[i] }, i ? `Niveau ${i + 1} : aucun` : 'Aucune : liste simple'),
        s.rubriques.filter((c) => c === t.ruptures[i] || !t.ruptures.includes(c)).map((c) => el('option', { value: c, selected: c === t.ruptures[i] }, `Niveau ${i + 1} : ${c}`))))),
    el('fieldset', {}, el('legend', {}, 'Colonnes de l\'état'), cases(s.rubriques, t.colonnes),
      el('div', { class: 'cases' },
        el('label', {}, el('input', { type: 'checkbox', checked: t.detail, onchange: (e) => { t.detail = e.target.checked; changer(); } }), 'imprimer le détail'),
        el('label', {}, el('input', { type: 'checkbox', checked: t.compter, onchange: (e) => { t.compter = e.target.checked; changer(); } }), 'compter les lignes'))),
    el('fieldset', {}, el('legend', {}, 'Totaux'), numeriques().length ? cases(numeriques(), t.totaux) : el('p', { class: 'aide' }, 'Aucune rubrique numérique dans l\'enregistrement.')),
    el('fieldset', {}, el('legend', {}, 'Fichiers en sortie'),
      el('p', { class: 'aide' }, 'Écrits depuis le langage structuré : M E-NOMCLI RL-NOMCLI puis ECR RL. Leur contenu apparaît dans la sortie, à côté de la SYSOUT.'),
      (modele.sorties ||= []).map((so, i) => el('div', { class: 'ligne-champs', style: 'grid-template-columns: 4em minmax(0,1fr) minmax(0,2fr) auto; align-items:center' },
        el('input', { class: 'code', value: so.code, maxlength: 2, 'aria-label': 'Code du fichier', oninput: (e) => { so.code = e.target.value.toUpperCase(); changer(); } }),
        el('input', { class: 'code', value: so.fichier, 'aria-label': 'Nom du fichier', oninput: (e) => { so.fichier = e.target.value.toUpperCase(); changer(); } }),
        el('input', { class: 'code', value: so.rubriques.join(' '), 'aria-label': 'Rubriques', oninput: (e) => { so.rubriques = e.target.value.toUpperCase().split(/\s+/).filter(Boolean); changer(); } }),
        el('button', { class: 'mini', type: 'button', 'aria-label': `Supprimer le fichier ${so.code}`, onclick: () => { modele.sorties.splice(i, 1); changer(true); } }, '×'))),
      el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { let n = 1; while (modele.sorties.some((x) => x.code === `S${n}`)) n++; modele.sorties.push({ code: `S${n}`, fichier: `SORTIE${n}.DAT`, rubriques: [...s.rubriques] }); changer(true); } }, 'Ajouter un fichier en sortie'))),
  ];
}

function vueSpe() {
  const lignes = modele.lignes ||= [];
  const champ = (l, k, attrs = {}) => el('input', { value: l[k] ?? '', 'aria-label': k, ...attrs, oninput: (e) => { l[k] = attrs.class === 'code' ? e.target.value.toUpperCase() : e.target.value; changer(); majInfos(); } });
  const rangs = lignes.map((l, i) => {
    const titre = (l.ope || '').toUpperCase() === 'N';
    return el('tr', {},
      el('td', {}, champ(l, 'fo', { class: 'code', maxlength: 2, style: 'width:3.2em' })),
      el('td', {}, champ(l, 'sf', { class: 'code', maxlength: 2, style: 'width:3.2em' })),
      el('td', {}, champ(l, 'ope', { class: 'code', maxlength: 3, style: 'width:4em' })),
      el('td', {}, champ(l, 'opes', { style: `min-width:16em;font-family:var(--f-code);font-size:.85rem${titre ? ';font-weight:600' : ''}` })),
      el('td', {}, titre ? champ(l, 'niv', { class: 'code', maxlength: 2, style: 'width:3.2em' }) : ''),
      el('td', {}, titre ? champ(l, 'tc', { class: 'code', maxlength: 2, style: 'width:3.2em' }) : ''),
      el('td', {}, titre ? champ(l, 'cond', { style: 'min-width:11em;font-family:var(--f-code);font-size:.85rem' }) : ''),
      el('td', {}, el('button', { class: 'mini', type: 'button', 'aria-label': `Supprimer la ligne ${i + 1}`, onclick: () => { lignes.splice(i, 1); changer(true); } }, '×')));
  });
  const ajouter = (l) => { lignes.push(l); changer(true); };
  const der = lignes.at(-1) || { fo: '30', sf: '10' };
  return [
    el('p', { class: 'aide' }, 'Vos traitements, sans écrire de COBOL. Une sous-fonction commence par une ligne N : titre, niveau (05, 10…), type (BL bloc, IT si, EL sinon, DW tant que) et condition. Les lignes suivantes portent un opérateur : M déplacer, A ajouter, S soustraire, C calculer (C W-X = A + B), P appeler une fonction, MES afficher, ECR écrire un fichier, SQL accéder à une table (SQL CL R, voir l\'onglet Base), SCO / SRO valider / annuler, COB ligne COBOL, * commentaire.'),
    el('p', { class: 'aide' }, 'Rubriques : E- enregistrement courant, P- précédent, S- suivant, W- zone de travail (déclarée d\'après le dictionnaire), D- ligne de détail. Ruptures : RTP1 = 1 au premier d\'un groupe, RTD1 = 1 au dernier. Conditions : = # > < >= <=, ET, OU.'),
    el('div', { class: 'table-defile' }, el('table', { style: 'min-width:760px' },
      el('thead', {}, el('tr', {}, ...['FO', 'SF', 'OPE', 'Opérandes / titre', 'NV', 'TC', 'Condition', ''].map((x) => el('th', {}, x)))),
      el('tbody', {}, rangs))),
    el('div', { class: 'actions' },
      el('button', { class: 'bouton', type: 'button', onclick: () => ajouter({ fo: der.fo, sf: String(Number(der.sf || 0) + 10).padStart(2, '0').slice(-2), ope: 'N', opes: 'Nouvelle sous-fonction', niv: '05', tc: 'BL', cond: '' }) }, 'Ajouter une sous-fonction'),
      el('button', { class: 'bouton', type: 'button', onclick: () => ajouter({ fo: der.fo, sf: der.sf, ope: 'M', opes: '' }) }, 'Ajouter une ligne')),
    el('p', { class: 'aide', id: 'infos-fonctions' }),
  ];
}
function majInfos() {
  const p = $('#infos-fonctions'); if (!p) return;
  try {
    const fns = analyserLignes(modele.lignes || []);
    const res = [...fns.keys()].filter((fo) => RESERVEES.includes(fo));
    p.textContent = res.length ? `F${res[0]} est générée par l'atelier : prenez un autre numéro.` : [...fns.keys()].map((fo) => `F${fo} : ${placeFonction(fo)}`).join(' · ') || 'Aucune fonction.';
  } catch (e) { p.textContent = e.message; }
}

let db = null;
function vueBase() {
  const tables = modele.tables ||= [];
  const erreur = (() => { try { verifierTables(modele); return null; } catch (e) { return e.message; } })();
  const blocs = tables.map((tb, i) => {
    const contenu = el('div', { class: 'champ' }, el('span', { class: 'aide' }, db ? '' : 'Contenu : chargement de la base…'));
    if (db) {
      try {
        const { lignes } = contenuTable(db, tb);
        contenu.replaceChildren(el('span', { class: 'aide' }, `${lignes.length} ligne(s) en base`), el('div', { class: 'table-defile' }, el('table', {},
          el('thead', {}, el('tr', {}, tb.rubriques.map((c) => el('th', { style: rub(c)?.type === '9' ? 'text-align:right' : '' }, c)))),
          el('tbody', {}, lignes.map((l) => el('tr', {}, l.map((v, k) => el('td', { class: 'code', style: rub(tb.rubriques[k])?.type === '9' ? 'text-align:right' : '' }, formaterValeur(rub(tb.rubriques[k]), v)))))))));
      } catch { contenu.replaceChildren(el('span', { class: 'aide' }, 'Table pas encore créée : elle le sera à la prochaine exécution.')); }
    }
    return el('fieldset', { style: 'min-width:0' }, el('legend', {}, `Table ${tb.nom || '?'}`),
      el('div', { class: 'ligne-champs', style: 'grid-template-columns: 4em minmax(0,1fr) auto; align-items:end' },
        el('label', { class: 'champ' }, 'Code', el('input', { class: 'code', value: tb.code, maxlength: 2, oninput: (e) => { tb.code = e.target.value.toUpperCase(); changer(); } })),
        el('label', { class: 'champ' }, 'Nom de la table', el('input', { class: 'code', value: tb.nom, maxlength: 18, oninput: (e) => { tb.nom = e.target.value.toUpperCase(); changer(); } })),
        el('button', { class: 'mini', type: 'button', 'aria-label': `Supprimer la description de ${tb.nom}`, onclick: () => { tables.splice(i, 1); changer(true); } }, '×')),
      el('label', { class: 'champ' }, 'Rubriques (colonnes)', el('input', { class: 'code', value: tb.rubriques.join(' '), onchange: (e) => { tb.rubriques = e.target.value.toUpperCase().split(/\s+/).filter(Boolean); changer(true); } })),
      el('label', { class: 'champ' }, 'Clé', el('input', { class: 'code', value: tb.cle.join(' '), onchange: (e) => { tb.cle = e.target.value.toUpperCase().split(/\s+/).filter(Boolean); changer(true); } })),
      contenu,
      el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', disabled: !db, onclick: () => { try { viderTable(db, tb); sauverBase(db); } catch {} afficher(); } }, 'Vider la table')));
  });
  if (!db) ouvrirBase().then((d) => { db = d; try { synchroniser(db, modele); sauverBase(db); } catch {} if (vue === 'base') afficher(); }, () => {});
  return [
    el('p', { class: 'aide' }, 'Une base SQLite tourne dans le navigateur, à la manière de DB2. Chaque table a un code de deux lettres : ses colonnes s\'utilisent dans le langage structuré sous la forme CL-NOMCLI. Le programme généré y accède par des EXEC SQL, et SQLCODE dit le résultat : 0 trouvé, 100 absent ou fin de curseur, -803 clé en double.'),
    el('p', { class: 'aide' }, 'Opérateur SQL, suivi du code de la table et du type d\'accès (codes VisualAge Pacbase) : ' + Object.entries(OPERATEURS_BASE).map(([o, t]) => `${o} : ${t}`).join(' · ')),
    el('p', { class: 'aide' }, 'La base garde ses lignes d\'une exécution à l\'autre (dans ce navigateur, partagée avec le terminal). Un arrêt anormal annule tout ce que le programme a écrit. Changer les colonnes ou la clé recrée la table, vide.'),
    ...(erreur ? [el('p', { class: 'message erreur' }, erreur)] : []),
    ...blocs,
    el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { let n = 1; while (tables.some((x) => x.code === `T${n}`)) n++; tables.push({ code: `T${n}`, nom: `TABLE${n}`, rubriques: [...structure().rubriques], cle: [structure().rubriques[0]] }); changer(true); } }, 'Ajouter une table')),
  ];
}

function vueDon() {
  const s = structure();
  let brut = '', erreur = null;
  try { brut = fichierDonnees(modele); } catch (e) { erreur = e.message; }
  const lignes = modele.donnees.map((l, i) => el('tr', {},
    s.rubriques.map((c) => el('td', {}, el('input', { class: rub(c).type === 'X' ? '' : 'code', value: l[c] ?? '', 'aria-label': `${c}, ligne ${i + 1}`, inputmode: rub(c).type === '9' ? 'decimal' : 'text',
      oninput: (e) => { l[c] = e.target.value; changer(); majBrut(); } }))),
    el('td', {}, el('button', { class: 'mini', type: 'button', 'aria-label': `Supprimer la ligne ${i + 1}`, onclick: () => { modele.donnees.splice(i, 1); changer(true); } }, '×'))));
  return [
    el('p', { class: 'aide' }, `Le contenu du fichier ${modele.traitement.fichier}, saisi colonne par colonne. Montants avec un point décimal, signe moins accepté pour les rubriques signées.`),
    el('div', { class: 'table-defile' }, el('table', {},
      el('thead', {}, el('tr', {}, s.rubriques.map((c) => el('th', {}, c)), el('th', {}, ''))),
      el('tbody', {}, lignes))),
    el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { modele.donnees.push({}); changer(true); } }, 'Ajouter une ligne')),
    el('div', { class: 'champ' }, el('span', { class: 'aide' }, 'Le fichier tel que le programme le lit :'), el('pre', { class: 'brut', id: 'brut' }, erreur ? `⚠ ${erreur}` : brut || '(vide)')),
  ];
}
function majBrut() {
  const b = $('#brut'); if (!b) return;
  try { b.textContent = fichierDonnees(modele) || '(vide)'; } catch (e) { b.textContent = `⚠ ${e.message}`; }
}

function afficher() {
  const v = $('#vue');
  v.replaceChildren(...({ dico: vueDico, struct: vueStruct, trt: vueTrt, spe: vueSpe, don: vueDon, base: vueBase }[vue])());
  majInfos();
  document.querySelectorAll('.onglets button').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.vue === vue)));
}
document.querySelectorAll('.onglets button').forEach((b) => b.addEventListener('click', () => { vue = b.dataset.vue; afficher(); }));

// ---------- Source ----------
const zoneSource = $('#source');
function regenerer() {
  try {
    zoneSource.value = generer(modele);
    sourceModifie = false;
    $('#etat-source').textContent = 'généré depuis le modèle';
    $('#etat-source').className = 'etat';
  } catch (e) {
    $('#etat-source').textContent = `Le modèle est incomplet : ${e.message}`;
    $('#etat-source').className = 'etat modifie';
  }
  majSource();
}
function majSource() {
  const n = zoneSource.value.split('\n').length;
  $('#gouttiere').textContent = Array.from({ length: n }, (_, i) => String(i + 1).padStart(4, ' ')).join('\n');
  zoneSource.style.height = 'auto';
  zoneSource.style.height = `${zoneSource.scrollHeight + 4}px`;
}
zoneSource.addEventListener('input', () => {
  sourceModifie = true;
  $('#etat-source').textContent = 'modifié à la main : le modèle ne l\'écrase plus';
  $('#etat-source').className = 'etat modifie';
  majSource();
});
$('#regenerer').addEventListener('click', () => { regenerer(); });
$('#copier').addEventListener('click', async (e) => {
  const b = e.currentTarget;
  try { await navigator.clipboard.writeText(zoneSource.value); b.textContent = 'Copié'; }
  catch { zoneSource.select(); b.textContent = 'Texte sélectionné'; }
  setTimeout(() => (b.textContent = 'Copier le source'), 1600);
});
$('#regle').textContent = Array.from({ length: 80 }, (_, i) => ((i + 1) % 10 === 0 ? String((i + 1) / 10 % 10) : (i + 1) % 5 === 0 ? '+' : '.')).join('');

function texteTable(tb) {
  const { lignes } = contenuTable(db, tb);
  const larg = tb.rubriques.map((c, k) => Math.max(c.length, ...lignes.map((l) => formaterValeur(rub(c), l[k]).length)));
  const cel = (c, t, k) => (rub(c)?.type === '9' ? t.padStart(larg[k]) : t.padEnd(larg[k]));
  return [`${tb.nom} : ${lignes.length} ligne(s), clé ${tb.cle.join(' ')}`, '', tb.rubriques.map((c, k) => cel(c, c, k)).join('  '),
    ...lignes.map((l) => l.map((v, k) => cel(tb.rubriques[k], formaterValeur(rub(tb.rubriques[k]), v), k)).join('  '))].join('\n');
}
async function executer() {
  const msg = $('#message');
  msg.replaceChildren();
  let fichierEntree = '';
  try { fichierEntree = fichierDonnees(modele); } catch (e) { msg.append(el('p', { class: 'message erreur' }, `Données : ${e.message}`)); return; }
  try {
    const t0 = performance.now();
    const pgm = new Programme(zoneSource.value);
    let recreees = [];
    if (pgm.utiliseSql()) {
      if (!db) { msg.append(el('p', { class: 'message' }, 'Ouverture de la base…')); db = await ouvrirBase(); msg.replaceChildren(); }
      recreees = synchroniser(db, modele);
    }
    const t1 = performance.now();
    const r = pgm.executer({ fichiers: { [modele.traitement.fichier]: fichierEntree }, db });
    const t2 = performance.now();
    if (db) sauverBase(db);
    derniersFichiers = { SYSOUT: r.sortie, ...r.fichiers };
    if (r.sql.executees) for (const tb of modele.tables || []) { try { derniersFichiers[`Table ${tb.nom}`] = texteTable(tb); } catch {} }
    $('#stats').textContent = `analyse ${(t1 - t0).toFixed(1)} ms · exécution ${(t2 - t1).toFixed(1)} ms · ${r.instructions} instructions${r.sql.executees ? ` · ${r.sql.executees} ordres SQL` : ''}`;
    msg.append(el('p', { class: 'message ok' }, 'Fin normale (STOP RUN).'));
    for (const nom of recreees) msg.append(el('p', { class: 'message' }, `Table ${nom} recréée vide : sa description avait changé.`));
    for (const x of r.sql.erreurs) msg.append(el('p', { class: 'message erreur' }, `SQLCODE ${x.code}, ligne ${x.ligne} : ${x.message}`));
    if (vue === 'base') afficher();
  } catch (e) {
    derniersFichiers = {};
    $('#stats').textContent = '';
    msg.append(el('p', { class: 'message erreur' }, `Erreur, ${e.message}${db ? ' La base n\'a pas été modifiée.' : ''}`));
    if (e.ligne) surligner(e.ligne);
  }
  if (!(fichierAffiche in derniersFichiers)) fichierAffiche = 'SYSOUT';
  afficherFichiers();
}
function afficherFichiers() {
  $('#fichiers').replaceChildren(...Object.keys(derniersFichiers).map((nom) => el('button', { class: 'bouton', type: 'button', 'aria-pressed': String(nom === fichierAffiche), onclick: () => { fichierAffiche = nom; afficherFichiers(); } }, nom)));
  $('#sysout').textContent = derniersFichiers[fichierAffiche] ?? '';
}
function surligner(ligne) {
  const lignes = zoneSource.value.split('\n');
  const debut = lignes.slice(0, ligne - 1).reduce((s, l) => s + l.length + 1, 0);
  zoneSource.focus();
  zoneSource.setSelectionRange(debut, debut + (lignes[ligne - 1] || '').length);
}
$('#executer').addEventListener('click', executer);

bandeauReception(document.getElementById('bandeau-partage'));
document.getElementById('partager')?.addEventListener('click', () => montrerLien(document.getElementById('lien-partage'), modele));

afficher();
regenerer();
// le programme écrit dans la base : on ne le lance pas tout seul au chargement
if ((modele.tables || []).length && /EXEC SQL/.test(zoneSource.value)) $('#sysout').textContent = 'Cliquez sur Exécuter : le programme met à jour la base à chaque lancement.';
else executer();
