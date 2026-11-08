import {
  exemple, exempleMiseAJour, generer, fichiersEntree, operateurs, longueurFichier, picStockage, analyserLignes, placeFonction,
  normaliser, UTILISATIONS, MOUVEMENT_DEFAUT, estMiseAJour, appelEdite, fonctionsGenerees, MAX_RUPTURES, verifierTables, OPERATEURS_BASE,
} from './generateur.js';
import { Programme } from './interpreteur.js';
import { bandeauReception, montrerLien, lienDe, oublierFragment } from './partage.js';
import { DEFIS, defiDuFragment, comparer } from './defis.js';
import { ouvrirBase, sauverBase, synchroniser, contenuTable, viderTable, formaterValeur } from './base.js';

const CLE = 'atelier-cobol-modele-v1';
const CLE_PRECEDENT = 'atelier-cobol-modele-precedent';
let modele;
try { modele = JSON.parse(localStorage.getItem(CLE)) || null; } catch { modele = null; }
if (!modele?.dictionnaire) modele = structuredClone(exemple);
modele.sorties ||= [];
modele.lignes ||= [];
modele.tables ||= [];
// opérateurs de base de la première v0.5 -> opérateur SQL de Pacbase
{ const anciens = { INS: 'W', LEC: 'R', MAJ: 'RW', SUP: 'D', OUV: 'P', SUI: 'RN', FER: 'CL' };
  for (const l of modele.lignes) { const a = anciens[(l.ope || '').toUpperCase()]; if (a) { l.opes = `${(l.opes || '').trim()} ${a}`; l.ope = 'SQL'; } } }
// programmes d'avant l'appel des fichiers : un seul fichier, décrit dans le traitement
modele = normaliser(modele);

let vue = 'dico';
let structCourante = 0;
let appelDonnees = null;
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
const rub = (c) => modele.dictionnaire.find((d) => d.code === c);
const structDe = (code) => modele.structures.find((s) => s.code === code);
const appel = (u) => modele.appels.find((a) => a.utilisation === u);
// l'enregistrement du fichier édité (programme d'édition) ou du principal (mise à jour)
const structureLue = () => structDe((appelEdite(modele) || appel('P') || modele.appels[0])?.structure) || modele.structures[0];
const numeriques = (s) => s.rubriques.filter((c) => rub(c)?.type === '9');

function sauver() { try { localStorage.setItem(CLE, JSON.stringify(modele)); } catch {} }
function changer(redessiner = false) {
  nettoyer();
  sauver();
  if (!sourceModifie) regenerer();
  if (redessiner) afficher();
}
// garde le modèle cohérent quand une rubrique ou un enregistrement disparaît
function nettoyer() {
  const codes = new Set(modele.dictionnaire.map((d) => d.code));
  for (const s of modele.structures) s.rubriques = s.rubriques.filter((c, i, a) => codes.has(c) && a.indexOf(c) === i);
  const p = appel('P');
  for (const a of modele.appels) {
    if (a.utilisation === 'R') { a.structure = p?.structure; continue; }
    const s = structDe(a.structure) || modele.structures[0];
    a.structure = s?.code;
    const dansS = new Set(s?.rubriques || []);
    a.argument = (a.argument || []).filter((c, i, l) => dansS.has(c) && l.indexOf(c) === i).slice(0, MAX_RUPTURES);
    if (a.utilisation === 'C') a.rupture = Math.min(Number(a.rupture || 0), a.argument.length);
    if (a.utilisation === 'M' && a.mouvement && !dansS.has(a.mouvement.rubrique)) a.mouvement.rubrique = '';
  }
  const t = modele.traitement;
  const dansC = new Set(structureLue()?.rubriques || []);
  t.colonnes = (t.colonnes || []).filter((c) => dansC.has(c));
  t.totaux = (t.totaux || []).filter((c) => dansC.has(c) && rub(c).type === '9');
  t.filtres = (t.filtres || []).filter((f) => dansC.has(f.rubrique));
  for (const tb of modele.tables) { tb.rubriques = tb.rubriques.filter((c) => codes.has(c)); tb.cle = tb.cle.filter((c) => tb.rubriques.includes(c)); }
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
    el('p', { class: 'aide' }, 'Chaque rubrique est décrite une seule fois : son format sert partout, dans les enregistrements, les filtres, l\'état et la saisie des données.'),
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
  const r = (c) => (c === ancien ? nouveau : c);
  const t = modele.traitement;
  for (const s of modele.structures) s.rubriques = s.rubriques.map(r);
  t.colonnes = t.colonnes.map(r); t.totaux = t.totaux.map(r);
  t.filtres.forEach((f) => (f.rubrique = r(f.rubrique)));
  for (const a of modele.appels) {
    a.argument = (a.argument || []).map(r);
    if (a.mouvement) a.mouvement.rubrique = r(a.mouvement.rubrique);
    (a.donnees || []).forEach((l) => { if (ancien in l) { l[nouveau] = l[ancien]; delete l[ancien]; } });
  }
  for (const so of modele.sorties) so.rubriques = so.rubriques.map(r);
  for (const tb of modele.tables) { tb.rubriques = tb.rubriques.map(r); tb.cle = tb.cle.map(r); }
  changer();
}

function vueStruct() {
  structCourante = Math.min(structCourante, modele.structures.length - 1);
  const s = modele.structures[structCourante];
  const utilisee = (code) => modele.appels.some((a) => a.structure === code);
  const choix = el('div', { class: 'fichiers', style: 'padding:0' },
    modele.structures.map((x, i) => el('button', { class: 'bouton', type: 'button', 'aria-pressed': String(i === structCourante), onclick: () => { structCourante = i; afficher(); } }, x.code)),
    el('button', { class: 'bouton', type: 'button', onclick: () => {
      let n = 1; while (structDe(`ENR${n}`)) n++;
      modele.structures.push({ code: `ENR${n}`, libelle: '', rubriques: [] });
      structCourante = modele.structures.length - 1;
      changer(true);
    } }, 'Nouvel enregistrement'));
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
    el('p', { class: 'aide' }, 'Un enregistrement : les rubriques d\'une ligne de fichier, dans l\'ordre où elles se suivent. Un programme de mise à jour en utilise deux : le principal et le mouvement.'),
    choix,
    el('div', { class: 'ligne-champs' },
      el('label', { class: 'champ' }, 'Code', el('input', { class: 'code', value: s.code, onchange: (e) => {
        const nouveau = e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '') || 'ENR';
        if (modele.structures.some((x) => x !== s && x.code === nouveau)) { e.target.value = s.code; return; }
        for (const a of modele.appels) if (a.structure === s.code) a.structure = nouveau;
        s.code = nouveau; changer(true);
      } })),
      el('label', { class: 'champ' }, 'Libellé', el('input', { value: s.libelle || '', oninput: (e) => { s.libelle = e.target.value; sauver(); } }))),
    el('div', { class: 'ordre' }, liste),
    el('p', { class: 'aide' }, `Longueur d'une ligne : ${pos - 1} caractères.${utilisee(s.code) ? ` Lu par le fichier ${modele.appels.filter((a) => a.structure === s.code && a.utilisation !== 'R').map((a) => a.code).join(', ')}.` : ' Aucun fichier ne l\'utilise.'}`),
    absentes.length ? el('div', { class: 'actions' }, 'Ajouter :', absentes.map((d) => el('button', { class: 'bouton', type: 'button', onclick: () => { s.rubriques.push(d.code); changer(true); } }, d.code))) : null,
    !utilisee(s.code) && modele.structures.length > 1 ? el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { modele.structures.splice(structCourante, 1); structCourante = 0; changer(true); } }, `Supprimer l'enregistrement ${s.code}`)) : null,
  ];
}

// ---------- Appel des fichiers ----------
function passerEnMiseAJour() {
  const s = modele.structures[0];
  const p = { code: 'MA', utilisation: 'P', structure: s.code, fichier: 'PRINCIPAL.DAT', argument: s.rubriques.slice(0, 1), synchro: 1, donnees: [] };
  const m = { code: 'MV', utilisation: 'M', structure: modele.structures[1]?.code || s.code, fichier: 'MOUVEMENTS.DAT', argument: s.rubriques.slice(0, 1), synchro: 1, mouvement: { ...MOUVEMENT_DEFAUT, rubrique: '' }, donnees: [] };
  modele.appels = [p, m, { code: 'MR', utilisation: 'R', fichier: 'RESULTAT.DAT' }];
}
function passerEnEdition() {
  const p = appel('P') || modele.appels[0];
  modele.appels = [{ code: 'FA', utilisation: 'C', structure: p?.structure || modele.structures[0].code, fichier: p?.fichier || 'ENTREE.DAT', argument: [], rupture: 0, donnees: p?.donnees || [] }];
}
function vueAppels() {
  const maj = estMiseAJour(modele);
  const p = appel('P');
  const champCode = (a) => el('label', { class: 'champ' }, 'Code', el('input', { class: 'code', value: a.code, maxlength: 2, onchange: (e) => { a.code = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 2); changer(true); } }));
  const champFichier = (a) => el('label', { class: 'champ' }, 'Fichier', el('input', { class: 'code', value: a.fichier, oninput: (e) => { a.fichier = e.target.value.toUpperCase().replace(/[^A-Z0-9.]/g, '') || 'FICHIER.DAT'; changer(); } }));
  const champStruct = (a) => el('label', { class: 'champ' }, 'Enregistrement', el('select', { onchange: (e) => { a.structure = e.target.value; changer(true); } },
    modele.structures.map((s) => el('option', { value: s.code, selected: s.code === a.structure }, s.code))));
  const argument = (a, legende, aide) => {
    const s = structDe(a.structure);
    return el('fieldset', {}, el('legend', {}, legende), el('p', { class: 'aide' }, aide),
      Array.from({ length: Math.min(MAX_RUPTURES, a.argument.length + 1) }, (_, i) => el('select', { 'aria-label': `${legende}, niveau ${i + 1}`, onchange: (e) => {
        const v = e.target.value;
        if (v) a.argument[i] = v; else a.argument.splice(i);
        a.argument = a.argument.filter((c, k, l) => l.indexOf(c) === k);
        if (a.utilisation === 'C') a.rupture = a.argument.length;
        if (a.utilisation === 'P' || a.utilisation === 'M') for (const x of modele.appels) if (x.utilisation === 'P' || x.utilisation === 'M') x.synchro = a.argument.length;
        changer(true);
      } },
        el('option', { value: '', selected: !a.argument[i] }, i ? `Niveau ${i + 1} : aucun` : 'Aucun'),
        (s?.rubriques || []).filter((c) => c === a.argument[i] || !a.argument.includes(c)).map((c) => el('option', { value: c, selected: c === a.argument[i] }, `Niveau ${i + 1} : ${c}`)))));
  };
  const blocs = modele.appels.map((a) => {
    const tete = el('div', { class: 'ligne-champs', style: 'grid-template-columns: 4em minmax(0,1fr) minmax(0,1fr); align-items:end' },
      champCode(a), a.utilisation === 'R' ? el('span', { class: 'aide' }, `Enregistrement : celui du principal (${p?.structure || '?'})`) : champStruct(a), champFichier(a));
    const corps = [];
    if (a.utilisation === 'C') {
      corps.push(argument(a, 'Ruptures, de la plus large à la plus fine', 'Le fichier est trié sur ces rubriques ; un sous-total est imprimé à chaque changement (RTPn, RTDn, ' + `${a.code}-PEn, ${a.code}-DEn).`));
    }
    if (a.utilisation === 'P') corps.push(argument(a, 'Argument (indicatif)', `Les rubriques qui identifient un enregistrement. Le fichier doit être trié dessus, sans doublon. ${a.code}-CFn = 1 quand l'enregistrement participe au tour.`));
    if (a.utilisation === 'M') {
      corps.push(argument(a, 'Argument du mouvement', 'Mêmes niveaux et mêmes longueurs que l\'argument du principal : c\'est sur lui que les fichiers sont synchronisés. Trié, plusieurs mouvements par indicatif possibles.'));
      const s = structDe(a.structure);
      const mo = a.mouvement ||= { ...MOUVEMENT_DEFAUT, rubrique: '' };
      const code = (k, libelle) => el('label', { class: 'champ' }, libelle, el('input', { class: 'code', value: mo[k], maxlength: 1, style: 'width:3em', oninput: (e) => { mo[k] = e.target.value.toUpperCase(); changer(); } }));
      corps.push(el('fieldset', {}, el('legend', {}, 'Code mouvement'),
        el('p', { class: 'aide' }, 'Une rubrique d\'un caractère dit quoi faire de chaque mouvement. Une rubrique laissée vide dans un mouvement ne modifie rien.'),
        el('div', { class: 'ligne-champs', style: 'grid-template-columns: minmax(0,1.4fr) repeat(3, minmax(0,1fr)); align-items:end' },
          el('label', { class: 'champ' }, 'Rubrique', el('select', { onchange: (e) => { mo.rubrique = e.target.value; changer(true); } },
            el('option', { value: '', selected: !mo.rubrique }, '(à choisir)'),
            (s?.rubriques || []).filter((c) => rub(c)?.type === 'X' && rub(c)?.longueur === 1).map((c) => el('option', { value: c, selected: c === mo.rubrique }, c)))),
          code('creation', 'Création'), code('modification', 'Modification'), code('annulation', 'Annulation'))));
    }
    return el('fieldset', { style: 'min-width:0' }, el('legend', {}, `${a.code} · utilisation ${a.utilisation} : ${UTILISATIONS[a.utilisation]}`), tete, ...corps);
  });
  const exemples = el('div', { class: 'actions' }, 'Repartir d\'un exemple :',
    el('button', { class: 'bouton', type: 'button', onclick: () => { if (confirm('Remplacer votre programme par l\'exemple d\'édition ?')) { modele = normaliser(structuredClone(exemple)); structCourante = 0; appelDonnees = null; sourceModifie = false; changer(true); } } }, 'édition par région'),
    el('button', { class: 'bouton', type: 'button', onclick: () => { if (confirm('Remplacer votre programme par l\'exemple de mise à jour ?')) { modele = normaliser(structuredClone(exempleMiseAJour)); structCourante = 0; appelDonnees = null; sourceModifie = false; changer(true); } } }, 'mise à jour des clients'));
  return [
    el('p', { class: 'aide' }, 'L\'appel des fichiers du programme. Chaque fichier a un code de deux caractères qui préfixe ses zones : CL00-NOMCLI pour l\'enregistrement courant, 1-CL00-NOMCLI pour le suivant, CL-FT à la fin du fichier.'),
    el('div', { class: 'cases', style: 'display:grid;grid-template-columns:1fr;gap:4px' },
      el('label', {}, el('input', { type: 'radio', name: 'genre', style: 'width:auto', checked: !maj, onchange: () => { passerEnEdition(); changer(true); } }), 'Édition : un fichier consulté (C), un état'),
      el('label', {}, el('input', { type: 'radio', name: 'genre', style: 'width:auto', checked: maj, onchange: () => { passerEnMiseAJour(); changer(true); } }), 'Mise à jour : principal (P) + mouvements (M) → résultat (R)')),
    ...blocs,
    exemples,
  ];
}

function vueTrt() {
  const t = modele.traitement;
  const maj = estMiseAJour(modele);
  const s = structureLue();
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
  const etat = maj ? [
    el('p', { class: 'aide' }, 'Un programme de mise à jour n\'imprime pas d\'état : il écrit le fichier résultat et un compte rendu (mouvements rejetés, nombre de créations, modifications et annulations).'),
  ] : [
    el('fieldset', {}, el('legend', {}, 'Filtres (tous vrais)'), filtres,
      el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', disabled: !s.rubriques.length, onclick: () => { t.filtres.push({ rubrique: s.rubriques[0], op: '=', valeur: '' }); changer(true); } }, 'Ajouter un filtre'))),
    el('p', { class: 'aide' }, 'Les ruptures se déclarent à l\'appel du fichier (onglet Fichiers).'),
    el('fieldset', {}, el('legend', {}, 'Colonnes de l\'état'), cases(s.rubriques, t.colonnes),
      el('div', { class: 'cases' },
        el('label', {}, el('input', { type: 'checkbox', checked: t.detail, onchange: (e) => { t.detail = e.target.checked; changer(); } }), 'imprimer le détail'),
        el('label', {}, el('input', { type: 'checkbox', checked: t.compter, onchange: (e) => { t.compter = e.target.checked; changer(); } }), 'compter les lignes'))),
    el('fieldset', {}, el('legend', {}, 'Totaux'), numeriques(s).length ? cases(numeriques(s), t.totaux) : el('p', { class: 'aide' }, 'Aucune rubrique numérique dans l\'enregistrement.')),
  ];
  const dd = (appelEdite(modele) || appel('P'))?.code || 'FA';
  return [
    el('p', { class: 'aide' }, maj ? 'Le programme lit le principal et les mouvements, les synchronise sur leur argument, applique les mouvements et écrit le résultat.' : 'Un batch d\'édition : lire le fichier, garder les lignes qui passent les filtres, trier et totaliser par rupture, imprimer l\'état.'),
    el('div', { class: 'ligne-champs' },
      el('label', { class: 'champ' }, 'Programme (8 car.)', el('input', { class: 'code', value: t.programme, maxlength: 8, oninput: (e) => { t.programme = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '') || 'PGM'; changer(); } })),
      el('label', { class: 'champ' }, maj ? 'Titre du compte rendu' : 'Titre de l\'état', el('input', { value: t.titre, oninput: (e) => { t.titre = e.target.value; changer(); } }))),
    ...etat,
    el('fieldset', {}, el('legend', {}, 'Fichiers en sortie'),
      el('p', { class: 'aide' }, `Écrits depuis le langage structuré : M ${dd}00-${s.rubriques[0] || 'RUBRIQ'} RL-${s.rubriques[0] || 'RUBRIQ'} puis ECR RL. Leur contenu apparaît dans la sortie, à côté de la SYSOUT.`),
      modele.sorties.map((so, i) => el('div', { class: 'ligne-champs', style: 'grid-template-columns: 4em minmax(0,1fr) minmax(0,2fr) auto; align-items:center' },
        el('input', { class: 'code', value: so.code, maxlength: 2, 'aria-label': 'Code du fichier', oninput: (e) => { so.code = e.target.value.toUpperCase(); changer(); } }),
        el('input', { class: 'code', value: so.fichier, 'aria-label': 'Nom du fichier', oninput: (e) => { so.fichier = e.target.value.toUpperCase(); changer(); } }),
        el('input', { class: 'code', value: so.rubriques.join(' '), 'aria-label': 'Rubriques', oninput: (e) => { so.rubriques = e.target.value.toUpperCase().split(/\s+/).filter(Boolean); changer(); } }),
        el('button', { class: 'mini', type: 'button', 'aria-label': `Supprimer le fichier ${so.code}`, onclick: () => { modele.sorties.splice(i, 1); changer(true); } }, '×'))),
      el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { let n = 1; while (modele.sorties.some((x) => x.code === `S${n}`)) n++; modele.sorties.push({ code: `S${n}`, fichier: `SORTIE${n}.DAT`, rubriques: [...s.rubriques] }); changer(true); } }, 'Ajouter un fichier en sortie'))),
  ];
}

function vueSpe() {
  const lignes = modele.lignes;
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
  const maj = estMiseAJour(modele);
  const zones = modele.appels.filter((a) => a.utilisation !== 'R').map((a) => `${a.code}00- courant du fichier ${a.code}, 1-${a.code}00- suivant`).join(' ; ');
  return [
    el('p', { class: 'aide' }, 'Vos traitements, sans écrire de COBOL. Une sous-fonction commence par une ligne N : titre, niveau (05, 10…), type (BL bloc, IT si, EL sinon, DW tant que) et condition. Les lignes suivantes portent un opérateur : M déplacer, A ajouter, S soustraire, C calculer (C W-X = A + B), P appeler une fonction, MES afficher, ECR écrire un fichier, SQL accéder à une table (SQL CL R, voir l\'onglet Base), SCO / SRO valider / annuler, COB ligne COBOL, * commentaire.'),
    el('p', { class: 'aide' }, `Rubriques : ${zones} ; W- zone de travail (déclarée d'après le dictionnaire)${maj ? '' : ', D- ligne de détail'}. ${maj ? 'Synchronisation : CL-CF1 = 1 si le principal participe au tour, MV-CF1 pour le mouvement, MV-DE1 = 1 au dernier mouvement de l\'indicatif.' : 'Ruptures : RTP1 = 1 au premier d\'un groupe, RTD1 = 1 au dernier.'} Conditions : = # > < >= <=, ET, OU.`),
    el('p', { class: 'aide' }, `Fonctions générées : ${fonctionsGenerees(modele).map((f) => `F${f}`).join(' ')} ; ajoutez-y des sous-fonctions (par exemple ${maj ? '20' : '8E'} 10) ou créez les vôtres, placées selon leur code (les lettres passent avant les chiffres : 8E avant 80).`),
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
    const fns = analyserLignes(modele.lignes);
    p.textContent = [...fns.keys()].map((fo) => `F${fo} : ${placeFonction(fo, modele)}`).join(' · ') || 'Aucune fonction.';
  } catch (e) { p.textContent = e.message; }
}

let db = null;
function vueBase() {
  const tables = modele.tables;
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
  const s = structureLue();
  return [
    el('p', { class: 'aide' }, 'Une base SQLite tourne dans le navigateur, à la manière de DB2. Chaque table a un code de deux lettres : ses colonnes s\'utilisent dans le langage structuré sous la forme CL-NOMCLI. Le programme généré y accède par des EXEC SQL, et SQLCODE dit le résultat : 0 trouvé, 100 absent ou fin de curseur, -803 clé en double.'),
    el('p', { class: 'aide' }, 'Opérateur SQL, suivi du code de la table et du type d\'accès (codes VisualAge Pacbase) : ' + Object.entries(OPERATEURS_BASE).map(([o, t]) => `${o} : ${t}`).join(' · ')),
    el('p', { class: 'aide' }, 'La base garde ses lignes d\'une exécution à l\'autre (dans ce navigateur, partagée avec le terminal). Un arrêt anormal annule tout ce que le programme a écrit. Changer les colonnes ou la clé recrée la table, vide.'),
    ...(erreur ? [el('p', { class: 'message erreur' }, erreur)] : []),
    ...blocs,
    el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { let n = 1; while (tables.some((x) => x.code === `T${n}`)) n++; tables.push({ code: `T${n}`, nom: `TABLE${n}`, rubriques: [...s.rubriques], cle: [s.rubriques[0]] }); changer(true); } }, 'Ajouter une table')),
  ];
}

// Données : un onglet par fichier lu (le résultat est produit par le programme).
const appelsLus = () => modele.appels.filter((a) => a.utilisation !== 'R');
function contenuBrut(a) {
  const f = fichiersEntree(modele);
  return f[a.fichier] || '(vide)';
}
function vueDon() {
  const lus = appelsLus();
  const a = lus.find((x) => x.code === appelDonnees) || lus[0];
  if (!a) return [el('p', { class: 'aide' }, 'Aucun fichier en entrée : appelez-en un dans l\'onglet Fichiers.')];
  appelDonnees = a.code;
  const s = structDe(a.structure);
  a.donnees ||= [];
  let brut = '', erreur = null;
  try { brut = contenuBrut(a); } catch (e) { erreur = e.message; }
  const lignes = a.donnees.map((l, i) => el('tr', {},
    s.rubriques.map((c) => el('td', {}, el('input', { class: rub(c).type === 'X' ? '' : 'code', value: l[c] ?? '', 'aria-label': `${c}, ligne ${i + 1}`, inputmode: rub(c).type === '9' ? 'decimal' : 'text',
      oninput: (e) => { l[c] = e.target.value; changer(); majBrut(); } }))),
    el('td', {}, el('button', { class: 'mini', type: 'button', 'aria-label': `Supprimer la ligne ${i + 1}`, onclick: () => { a.donnees.splice(i, 1); changer(true); } }, '×'))));
  const trier = a.argument?.length && a.utilisation !== 'C'
    ? el('button', { class: 'bouton', type: 'button', onclick: () => {
      const cle = (l) => a.argument.map((c) => { const r = rub(c); const v = String(l[c] ?? '').trim(); return r.type === '9' ? v.padStart(r.longueur, '0') : v.padEnd(r.longueur); }).join('');
      a.donnees = a.donnees.map((l, i) => ({ l, i })).sort((x, y) => (cle(x.l) < cle(y.l) ? -1 : cle(x.l) > cle(y.l) ? 1 : x.i - y.i)).map((x) => x.l);
      changer(true);
    } }, `Trier sur ${a.argument.join(' ')}`) : null;
  return [
    lus.length > 1 ? el('div', { class: 'fichiers', style: 'padding:0' }, lus.map((x) => el('button', { class: 'bouton', type: 'button', 'aria-pressed': String(x === a), onclick: () => { appelDonnees = x.code; afficher(); } }, `${x.code} ${x.fichier}`))) : null,
    el('p', { class: 'aide' }, `Le contenu du fichier ${a.fichier} (${a.code}), saisi colonne par colonne. Montants avec un point décimal, signe moins accepté pour les rubriques signées.${a.utilisation === 'M' ? ' Une case vide ne modifie pas la rubrique.' : ''}${a.utilisation === 'C' ? '' : ' Le fichier doit être trié sur son argument.'}`),
    el('div', { class: 'table-defile' }, el('table', {},
      el('thead', {}, el('tr', {}, s.rubriques.map((c) => el('th', {}, c)), el('th', {}, ''))),
      el('tbody', {}, lignes))),
    el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { a.donnees.push({}); changer(true); } }, 'Ajouter une ligne'), trier),
    el('div', { class: 'champ' }, el('span', { class: 'aide' }, 'Le fichier tel que le programme le lit :'), el('pre', { class: 'brut', id: 'brut' }, erreur ? `⚠ ${erreur}` : brut)),
  ];
}
function majBrut() {
  const b = $('#brut'); if (!b) return;
  const a = appelsLus().find((x) => x.code === appelDonnees);
  try { b.textContent = a ? contenuBrut(a) : ''; } catch (e) { b.textContent = `⚠ ${e.message}`; }
}

function afficher() {
  const v = $('#vue');
  v.replaceChildren(...({ dico: vueDico, struct: vueStruct, cd: vueAppels, trt: vueTrt, spe: vueSpe, don: vueDon, base: vueBase }[vue])());
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
  let entrees = {};
  try { entrees = fichiersEntree(modele); } catch (e) { msg.append(el('p', { class: 'message erreur' }, `Données : ${e.message}`)); return; }
  try {
    const t0 = performance.now();
    const pgm = new Programme(zoneSource.value);
    let recreees = [];
    if (pgm.utiliseSql()) {
      if (!db) { msg.append(el('p', { class: 'message' }, 'Ouverture de la base…')); db = await ouvrirBase(); msg.replaceChildren(); }
      recreees = synchroniser(db, modele);
    }
    const t1 = performance.now();
    const r = pgm.executer({ fichiers: entrees, db });
    const t2 = performance.now();
    if (db) sauverBase(db);
    const ecrits = Object.fromEntries(Object.entries(r.fichiers).filter(([nom]) => !(nom in entrees) && !nom.endsWith('.TMP')));
    derniersFichiers = { SYSOUT: r.sortie, ...ecrits };
    if (r.sql.executees) for (const tb of modele.tables) { try { derniersFichiers[`Table ${tb.nom}`] = texteTable(tb); } catch {} }
    $('#stats').textContent = `analyse ${(t1 - t0).toFixed(1)} ms · exécution ${(t2 - t1).toFixed(1)} ms · ${r.instructions} instructions${r.sql.executees ? ` · ${r.sql.executees} ordres SQL` : ''}`;
    msg.append(el('p', { class: 'message ok' }, 'Fin normale (STOP RUN).'));
    for (const nom of recreees) msg.append(el('p', { class: 'message' }, `Table ${nom} recréée vide : sa description avait changé.`));
    for (const x of r.sql.erreurs) msg.append(el('p', { class: 'message erreur' }, `SQLCODE ${x.code}, ligne ${x.ligne} : ${x.message}`));
    if (vue === 'base') afficher();
    document.dispatchEvent(new CustomEvent('atelier:execute', { detail: { sortie: r.sortie, modele } }));
  } catch (e) {
    derniersFichiers = {};
    $('#stats').textContent = '';
    msg.append(el('p', { class: 'message erreur' }, `Erreur, ${e.message}${db ? ' La base n\'a pas été modifiée.' : ''}`));
    if (e.ligne) surligner(e.ligne);
    document.dispatchEvent(new CustomEvent('atelier:execute', { detail: { sortie: null, modele } }));
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
defis();
document.getElementById('partager')?.addEventListener('click', () => montrerLien(document.getElementById('lien-partage'), modele));

// ================= Les défis du vieux =================
// Un lien #defi=01 propose de charger le modèle du défi ; ensuite, chaque exécution compare la SYSOUT à l'attendue.
function defis() {
  const bandeau = document.getElementById('bandeau-partage');
  const id = defiDuFragment();
  if (id && id === modele.defi) oublierFragment();
  else if (id) {
    const d = DEFIS[id];
    bandeau.replaceChildren(
      el('p', {}, `Défi n°${id} : ${d.titre}. Le relever remplace votre programme, qui reste récupérable.`),
      el('button', { class: 'bouton principal', type: 'button', onclick: () => {
        try {
          const actuel = localStorage.getItem(CLE);
          if (actuel && !JSON.parse(actuel).defi) localStorage.setItem(CLE_PRECEDENT, actuel);
          localStorage.setItem(CLE, JSON.stringify({ ...structuredClone(d.modele), defi: id }));
        } catch {
          bandeau.replaceChildren(el('p', {}, 'Votre navigateur bloque le stockage local : impossible d\'ouvrir le défi ici.'));
          return;
        }
        oublierFragment();
        location.reload();
      } }, 'Relever le défi'),
      el('button', { class: 'bouton', type: 'button', onclick: () => { oublierFragment(); bandeau.hidden = true; } }, 'Plus tard'),
    );
    bandeau.hidden = false;
  }
  const d = DEFIS[modele.defi];
  const zone = document.getElementById('defi');
  if (!d || !zone) return;
  const verdict = el('p', { class: 'verdict', role: 'status' }, 'Exécutez votre programme : la SYSOUT est comparée à celle attendue.');
  const actions = el('div', { class: 'actions' });
  const quitter = el('button', { class: 'bouton', type: 'button', onclick: () => {
    let precedent = null;
    try { precedent = localStorage.getItem(CLE_PRECEDENT); } catch {}
    if (precedent) { try { localStorage.setItem(CLE, precedent); localStorage.removeItem(CLE_PRECEDENT); } catch {} location.reload(); return; }
    delete modele.defi; sauver(); zone.hidden = true;
  } }, 'Quitter le défi');
  zone.replaceChildren(
    el('h2', {}, `Défi n°${modele.defi} : ${d.titre}`),
    el('p', {}, d.enonce[0]),
    el('ol', {}, ...d.enonce.slice(1, -1).map((x) => el('li', {}, x))),
    el('p', {}, d.enonce.at(-1)),
    el('details', {}, el('summary', {}, 'Voir la SYSOUT attendue'), el('pre', { class: 'sysout' }, d.attendu)),
    verdict, actions,
  );
  actions.append(quitter);
  zone.hidden = false;
  document.addEventListener('atelier:execute', ({ detail }) => {
    actions.replaceChildren(quitter);
    verdict.className = 'verdict';
    if (detail.sortie === null) { verdict.textContent = 'Le programme s\'arrête en erreur : corrigez-le, puis exécutez-le à nouveau.'; return; }
    const r = comparer(detail.sortie, d.attendu);
    if (!r.reussi) {
      verdict.replaceChildren(`Pas encore. Ligne ${r.ligne}, attendu : `, el('code', {}, r.attendue || '(ligne vide)'), ', obtenu : ', el('code', {}, r.obtenue || '(ligne vide)'), '.');
      return;
    }
    verdict.className = 'verdict reussi';
    verdict.textContent = 'Défi réussi ✓ Votre SYSOUT est identique à celle attendue.';
    actions.prepend(el('button', { class: 'bouton principal', type: 'button', onclick: async () => {
      const lien = await lienDe(modele);
      const url = new URL(zone.dataset.contact || '/contact', location.origin);
      url.search = new URLSearchParams({ objet: 'autre', defi: modele.defi, programme: lien }).toString();
      location.href = url.href;
    } }, 'Envoyer ma solution'));
  });
}

afficher();
regenerer();
// le programme écrit dans la base : on ne le lance pas tout seul au chargement
if (modele.tables.length && /EXEC SQL/.test(zoneSource.value)) $('#sysout').textContent = 'Cliquez sur Exécuter : le programme met à jour la base à chaque lancement.';
else executer();
