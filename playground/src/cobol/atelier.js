import {
  exemple, exempleMiseAJour, exempleEtat, generer, fichiersEntree, operateurs, longueurFichier, picStockage, analyserLignes, placeFonction,
  normaliser, zonesTraitees, UTILISATIONS, MOUVEMENT_DEFAUT, estMiseAJour, appelEdite, fonctionsGenerees, MAX_RUPTURES, verifierTables, OPERATEURS_BASE,
} from './generateur.js';
import { Programme } from './interpreteur.js';
import { bandeauReception, montrerLien, lienDe, oublierFragment } from './partage.js';
import { DEFIS, defiDuFragment, comparer } from './defis.js';
import { TYPES_LIGNE, MODES_REMPLISSAGE, ETAT_DEFAUT, listing } from './etats.js';
import { ouvrirBase, sauverBase, synchroniser, contenuTable, viderTable, formaterValeur } from './base.js';
import { EN, cle, t as tx } from './langue.js';

const CLE = cle('modele-v1');
const CLE_PRECEDENT = cle('modele-precedent');
let modele;
try { modele = JSON.parse(localStorage.getItem(CLE)) || null; } catch { modele = null; }
if (!modele?.dictionnaire) modele = structuredClone(exemple);
modele.sorties ||= [];
modele.lignes ||= [];
modele.tables ||= [];
modele.etats ||= [];
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
let fichierAffiche = null;

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
    // fichier d'impression : pas d'enregistrement, l'argument est la lettre de l'état
    if (a.utilisation === 'I') { delete a.structure; a.argument = (a.argument || []).slice(0, 1); continue; }
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
    el('td', {}, el('input', { value: d.libelle || '', 'aria-label': tx('Libellé', 'Label'), oninput: (e) => { d.libelle = e.target.value; changer(); } })),
    el('td', {}, el('div', { class: 'segment', role: 'group', 'aria-label': tx(`Type de ${d.code}`, `Type of ${d.code}`) },
      ...[['X', tx('texte', 'text')], ['9', tx('nombre', 'number')]].map(([t, nom]) => el('button', { type: 'button', title: `${t} ${nom}`, 'aria-pressed': String(d.type === t), onclick: () => {
        if (d.type === t) return;
        d.type = t; if (t === 'X') { delete d.decimales; delete d.signe; } changer(true);
      } }, t)))),
    el('td', {}, el('input', { class: 'num', type: 'number', min: 1, max: 18, value: d.longueur, 'aria-label': tx('Longueur', 'Length'), onchange: (e) => { d.longueur = Math.max(1, Math.min(d.type === '9' ? 18 : 200, Number(e.target.value) || 1)); if ((d.decimales || 0) >= d.longueur) d.decimales = d.longueur - 1; changer(true); } })),
    el('td', {}, d.type === '9' ? el('input', { class: 'num', type: 'number', min: 0, max: 9, value: d.decimales || 0, 'aria-label': tx('Décimales', 'Decimals'), onchange: (e) => { d.decimales = Math.max(0, Math.min(d.longueur - 1, Number(e.target.value) || 0)); changer(true); } }) : ''),
    el('td', { style: 'text-align:center' }, d.type === '9' ? el('input', { type: 'checkbox', checked: !!d.signe, 'aria-label': tx('Signé', 'Signed'), onchange: (e) => { d.signe = e.target.checked; changer(true); } }) : ''),
    el('td', {}, el('code', { class: 'pic' }, picStockage(d))),
    el('td', {}, el('button', { class: 'mini', type: 'button', title: tx('Supprimer la rubrique', 'Delete the data element'), 'aria-label': tx(`Supprimer ${d.code}`, `Delete ${d.code}`), onclick: () => { modele.dictionnaire.splice(i, 1); changer(true); } }, '×')),
  ));
  return [
    el('p', { class: 'aide' }, tx('Chaque rubrique est décrite une seule fois : son format sert partout, dans les enregistrements, les filtres, l\'état et la saisie des données.', 'Each data element is described once: its format is used everywhere, in the records, the filters, the report and the data entry.')),
    el('div', { class: 'table-defile' }, el('table', {},
      el('thead', {}, el('tr', {}, ...(EN ? ['Code', 'Label', 'Type', 'Len.', 'Dec.', 'Signed', 'PIC', ''] : ['Code', 'Libellé', 'Type', 'Long.', 'Déc.', 'Signé', 'PIC', '']).map((t) => el('th', {}, t)))),
      el('tbody', {}, lignes))),
    el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => {
      const base = tx('RUB', 'ELEM'); let n = 1; while (rub(`${base}${n}`)) n++;
      modele.dictionnaire.push({ code: `${base}${n}`, libelle: '', type: 'X', longueur: 10 });
      changer(true);
    } }, tx('Ajouter une rubrique', 'Add a data element'))),
  ];
}
function renommer(d, input) {
  const nouveau = input.value.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 12);
  if (!nouveau || modele.dictionnaire.some((x) => x !== d && x.code === nouveau)) { input.setCustomValidity(tx('Code vide ou déjà utilisé', 'Code empty or already used')); input.reportValidity(); return; }
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
      const base = tx('ENR', 'REC'); let n = 1; while (structDe(`${base}${n}`)) n++;
      modele.structures.push({ code: `${base}${n}`, libelle: '', rubriques: [] });
      structCourante = modele.structures.length - 1;
      changer(true);
    } }, tx('Nouvel enregistrement', 'New record')));
  let pos = 1;
  const liste = s.rubriques.map((c, i) => {
    const d = rub(c), l = longueurFichier(d), debut = pos; pos += l;
    return el('div', {},
      el('span', {}, `${c} `, el('small', {}, tx(`${picStockage(d)}${d.signe ? ' signe séparé' : ''} · positions ${debut} à ${debut + l - 1}`, `${picStockage(d)}${d.signe ? ' separate sign' : ''} · positions ${debut} to ${debut + l - 1}`))),
      el('button', { class: 'mini', type: 'button', 'aria-label': tx(`Monter ${c}`, `Move ${c} up`), disabled: i === 0, onclick: () => { [s.rubriques[i - 1], s.rubriques[i]] = [s.rubriques[i], s.rubriques[i - 1]]; changer(true); } }, '↑'),
      el('button', { class: 'mini', type: 'button', 'aria-label': tx(`Descendre ${c}`, `Move ${c} down`), disabled: i === s.rubriques.length - 1, onclick: () => { [s.rubriques[i + 1], s.rubriques[i]] = [s.rubriques[i], s.rubriques[i + 1]]; changer(true); } }, '↓'),
      el('button', { class: 'mini', type: 'button', 'aria-label': tx(`Retirer ${c}`, `Remove ${c}`), onclick: () => { s.rubriques.splice(i, 1); changer(true); } }, '×'));
  });
  const absentes = modele.dictionnaire.filter((d) => !s.rubriques.includes(d.code));
  return [
    el('p', { class: 'aide' }, tx('Un enregistrement : les rubriques d\'une ligne de fichier, dans l\'ordre où elles se suivent. Un programme de mise à jour en utilise deux : le principal et le mouvement.', 'A record: the data elements of a file line, in the order they follow each other. An update program uses two: the master and the transaction.')),
    choix,
    el('div', { class: 'ligne-champs' },
      el('label', { class: 'champ' }, 'Code', el('input', { class: 'code', value: s.code, onchange: (e) => {
        const nouveau = e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '') || tx('ENR', 'REC');
        if (modele.structures.some((x) => x !== s && x.code === nouveau)) { e.target.value = s.code; return; }
        for (const a of modele.appels) if (a.structure === s.code) a.structure = nouveau;
        s.code = nouveau; changer(true);
      } })),
      el('label', { class: 'champ' }, tx('Libellé', 'Label'), el('input', { value: s.libelle || '', oninput: (e) => { s.libelle = e.target.value; sauver(); } }))),
    el('div', { class: 'ordre' }, liste),
    el('p', { class: 'aide' }, (() => { const lu = modele.appels.filter((a) => a.structure === s.code && a.utilisation !== 'R').map((a) => a.code).join(', '); return tx(`Longueur d'une ligne : ${pos - 1} caractères.${utilisee(s.code) ? ` Lu par le fichier ${lu}.` : ' Aucun fichier ne l\'utilise.'}`, `Line length: ${pos - 1} characters.${utilisee(s.code) ? ` Read by file ${lu}.` : ' No file uses it.'}`); })()),
    absentes.length ? el('div', { class: 'actions' }, tx('Ajouter :', 'Add:'), absentes.map((d) => el('button', { class: 'bouton', type: 'button', onclick: () => { s.rubriques.push(d.code); changer(true); } }, d.code))) : null,
    !utilisee(s.code) && modele.structures.length > 1 ? el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { modele.structures.splice(structCourante, 1); structCourante = 0; changer(true); } }, tx(`Supprimer l'enregistrement ${s.code}`, `Delete record ${s.code}`))) : null,
  ];
}

// ---------- Appel des fichiers ----------
const impressions = () => modele.appels.filter((a) => a.utilisation === 'I');
function passerEnMiseAJour() {
  const garder = impressions();
  const s = modele.structures[0];
  const p = { code: 'MA', utilisation: 'P', structure: s.code, fichier: tx('PRINCIPAL.DAT', 'MASTER.DAT'), argument: s.rubriques.slice(0, 1), synchro: 1, donnees: [] };
  const m = { code: 'MV', utilisation: 'M', structure: modele.structures[1]?.code || s.code, fichier: tx('MOUVEMENTS.DAT', 'TRANSACT.DAT'), argument: s.rubriques.slice(0, 1), synchro: 1, mouvement: { ...MOUVEMENT_DEFAUT, rubrique: '' }, donnees: [] };
  modele.appels = [p, m, { code: 'MR', utilisation: 'R', fichier: tx('RESULTAT.DAT', 'RESULT.DAT') }, ...garder];
}
function passerEnEdition() {
  const garder = impressions();
  const p = appel('P') || modele.appels[0];
  modele.appels = [{ code: 'FA', utilisation: 'C', structure: p?.structure || modele.structures[0].code, fichier: p?.fichier || tx('ENTREE.DAT', 'INPUT.DAT'), argument: [], rupture: 0, donnees: p?.donnees || [] }, ...garder];
}
function vueAppels() {
  const maj = estMiseAJour(modele);
  const p = appel('P');
  const champCode = (a) => el('label', { class: 'champ' }, 'Code', el('input', { class: 'code', value: a.code, maxlength: 2, onchange: (e) => { a.code = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 2); changer(true); } }));
  const champFichier = (a) => el('label', { class: 'champ' }, tx('Fichier', 'File'), el('input', { class: 'code', value: a.fichier, oninput: (e) => { a.fichier = e.target.value.toUpperCase().replace(/[^A-Z0-9.]/g, '') || tx('FICHIER.DAT', 'FILE.DAT'); changer(); } }));
  const champStruct = (a) => el('label', { class: 'champ' }, tx('Enregistrement', 'Record'), el('select', { onchange: (e) => { a.structure = e.target.value; changer(true); } },
    modele.structures.map((s) => el('option', { value: s.code, selected: s.code === a.structure }, s.code))));
  const argument = (a, legende, aide) => {
    const s = structDe(a.structure);
    return el('fieldset', {}, el('legend', {}, legende), el('p', { class: 'aide' }, aide),
      Array.from({ length: Math.min(MAX_RUPTURES, a.argument.length + 1) }, (_, i) => el('select', { 'aria-label': tx(`${legende}, niveau ${i + 1}`, `${legende}, level ${i + 1}`), onchange: (e) => {
        const v = e.target.value;
        if (v) a.argument[i] = v; else a.argument.splice(i);
        a.argument = a.argument.filter((c, k, l) => l.indexOf(c) === k);
        if (a.utilisation === 'C') a.rupture = a.argument.length;
        if (a.utilisation === 'P' || a.utilisation === 'M') for (const x of modele.appels) if (x.utilisation === 'P' || x.utilisation === 'M') x.synchro = a.argument.length;
        changer(true);
      } },
        el('option', { value: '', selected: !a.argument[i] }, i ? tx(`Niveau ${i + 1} : aucun`, `Level ${i + 1}: none`) : tx('Aucun', 'None')),
        (s?.rubriques || []).filter((c) => c === a.argument[i] || !a.argument.includes(c)).map((c) => el('option', { value: c, selected: c === a.argument[i] }, tx(`Niveau ${i + 1} : ${c}`, `Level ${i + 1}: ${c}`))))));
  };
  const blocs = modele.appels.map((a) => {
    const tete = el('div', { class: 'ligne-champs', style: 'grid-template-columns: 4em minmax(0,1fr) minmax(0,1fr); align-items:end' },
      champCode(a), a.utilisation === 'R' ? el('span', { class: 'aide' }, tx(`Enregistrement : celui du principal (${p?.structure || '?'})`, `Record: the master's (${p?.structure || '?'})`))
        : a.utilisation === 'I' ? el('label', { class: 'champ' }, tx('Argument : lettre de l\'état', 'Argument: report letter'), el('input', { class: 'code', value: a.argument[0] || '', maxlength: 1, style: 'width:3em', onchange: (e) => {
          const ancien = a.code + (a.argument[0] || '');
          a.argument = [e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '')].filter(Boolean);
          const et = modele.etats.find((x) => x.code === ancien);
          if (et && a.argument[0]) et.code = a.code + a.argument[0];
          changer(true);
        } }))
        : champStruct(a), champFichier(a));
    const corps = [];
    if (a.utilisation === 'I') corps.push(el('p', { class: 'aide' }, tx(`L'état ${a.code}${a.argument[0] || '?'} se décrit dans l'onglet États. Le programme l'écrit dans ${a.fichier}, avec ses sauts de ligne et de page.`, `Report ${a.code}${a.argument[0] || '?'} is described in the Reports tab. The program writes it to ${a.fichier}, with its line and page skips.`)),
      el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => {
        if (!confirm(tx(`Retirer le fichier d'impression ${a.code} et son état ?`, `Remove print file ${a.code} and its report?`))) return;
        modele.etats = modele.etats.filter((x) => x.code !== a.code + (a.argument[0] || ''));
        modele.appels.splice(modele.appels.indexOf(a), 1); changer(true);
      } }, tx(`Retirer ${a.code}`, `Remove ${a.code}`))));
    if (a.utilisation === 'C') {
      corps.push(argument(a, tx('Ruptures, de la plus large à la plus fine', 'Control breaks, from the widest to the finest'), tx('Le fichier est trié sur ces rubriques ; un sous-total est imprimé à chaque changement (RTPn, RTDn, ', 'The file is sorted on these elements; a subtotal is printed at each change (RTPn, RTDn, ') + `${a.code}-PEn, ${a.code}-DEn).`));
    }
    if (a.utilisation === 'P') corps.push(argument(a, tx('Argument (indicatif)', 'Argument (key)'), tx(`Les rubriques qui identifient un enregistrement. Le fichier doit être trié dessus, sans doublon. ${a.code}-CFn = 1 quand l'enregistrement participe au tour.`, `The elements that identify a record. The file must be sorted on them, with no duplicate. ${a.code}-CFn = 1 when the record takes part in the round.`)));
    if (a.utilisation === 'M') {
      corps.push(argument(a, tx('Argument du mouvement', 'Transaction argument'), tx('Mêmes niveaux et mêmes longueurs que l\'argument du principal : c\'est sur lui que les fichiers sont synchronisés. Trié, plusieurs mouvements par indicatif possibles.', 'Same levels and same lengths as the master argument: the files are matched on it. Sorted, several transactions per key allowed.')));
      const s = structDe(a.structure);
      const mo = a.mouvement ||= { ...MOUVEMENT_DEFAUT, rubrique: '' };
      const code = (k, libelle) => el('label', { class: 'champ' }, libelle, el('input', { class: 'code', value: mo[k], maxlength: 1, style: 'width:3em', oninput: (e) => { mo[k] = e.target.value.toUpperCase(); changer(); } }));
      corps.push(el('fieldset', {}, el('legend', {}, tx('Code mouvement', 'Transaction code')),
        el('p', { class: 'aide' }, tx('Une rubrique d\'un caractère dit quoi faire de chaque mouvement. Une rubrique laissée vide dans un mouvement ne modifie rien.', 'A one-character element says what to do with each transaction. An element left empty in a transaction changes nothing.')),
        el('div', { class: 'ligne-champs', style: 'grid-template-columns: minmax(0,1.4fr) repeat(3, minmax(0,1fr)); align-items:end' },
          el('label', { class: 'champ' }, tx('Rubrique', 'Element'), el('select', { onchange: (e) => { mo.rubrique = e.target.value; changer(true); } },
            el('option', { value: '', selected: !mo.rubrique }, tx('(à choisir)', '(choose)')),
            (s?.rubriques || []).filter((c) => rub(c)?.type === 'X' && rub(c)?.longueur === 1).map((c) => el('option', { value: c, selected: c === mo.rubrique }, c)))),
          code('creation', tx('Création', 'Create')), code('modification', tx('Modification', 'Modify')), code('annulation', tx('Annulation', 'Delete')))));
    }
    return el('fieldset', { style: 'min-width:0' }, el('legend', {}, tx(`${a.code} · utilisation ${a.utilisation} : ${UTILISATIONS[a.utilisation]}`, `${a.code} · usage ${a.utilisation}: ${UTILISATIONS[a.utilisation]}`)), tete, ...corps);
  });
  const exemples = el('div', { class: 'actions' }, tx('Repartir d\'un exemple :', 'Start again from an example:'),
    el('button', { class: 'bouton', type: 'button', onclick: () => { if (confirm(tx('Remplacer votre programme par l\'exemple d\'édition ?', 'Replace your program with the listing example?'))) { modele = normaliser(structuredClone(exemple)); structCourante = 0; appelDonnees = null; sourceModifie = false; changer(true); } } }, tx('édition par région', 'listing by region')),
    el('button', { class: 'bouton', type: 'button', onclick: () => { if (confirm(tx('Remplacer votre programme par l\'exemple de mise à jour ?', 'Replace your program with the update example?'))) { modele = normaliser(structuredClone(exempleMiseAJour)); structCourante = 0; appelDonnees = null; sourceModifie = false; changer(true); } } }, tx('mise à jour des clients', 'customer update')),
    el('button', { class: 'bouton', type: 'button', onclick: () => { if (confirm(tx('Remplacer votre programme par l\'exemple d\'état ?', 'Replace your program with the report example?'))) { modele = normaliser(structuredClone(exempleEtat)); structCourante = 0; appelDonnees = null; sourceModifie = false; changer(true); } } }, tx('état par région', 'report by region')));
  return [
    el('p', { class: 'aide' }, tx('L\'appel des fichiers du programme. Chaque fichier a un code de deux caractères qui préfixe ses zones : CL00-NOMCLI pour la zone lue, 1-CL00-NOMCLI pour l\'enregistrement traité quand le fichier a des ruptures (CL00 est alors le suivant, lu d\'avance), CL-FT à la fin du fichier.', 'The files called by the program. Each file has a two-character code that prefixes its fields: CL00-CUSTNM for the field read, 1-CL00-CUSTNM for the current record when the file has control breaks (CL00 is then the next one, read ahead), CL-FT at end of file.')),
    el('div', { class: 'cases', style: 'display:grid;grid-template-columns:1fr;gap:4px' },
      el('label', {}, el('input', { type: 'radio', name: 'genre', style: 'width:auto', checked: !maj, onchange: () => { passerEnEdition(); changer(true); } }), tx('Édition : un fichier consulté (C), un état', 'Report: one consulted file (C), one report')),
      el('label', {}, el('input', { type: 'radio', name: 'genre', style: 'width:auto', checked: maj, onchange: () => { passerEnMiseAJour(); changer(true); } }), tx('Mise à jour : principal (P) + mouvements (M) → résultat (R)', 'Update: master (P) + transactions (M) → result (R)'))),
    ...blocs,
    impressions().length ? null : el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { ajouterEtat(); vue = 'etats'; changer(true); } }, tx('Ajouter un fichier d\'impression (état)', 'Add a print file (report)'))),
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
    el('select', { 'aria-label': tx('Rubrique', 'Element'), onchange: (e) => { f.rubrique = e.target.value; changer(); } }, s.rubriques.map((c) => el('option', { value: c, selected: c === f.rubrique }, c))),
    el('select', { 'aria-label': tx('Opérateur', 'Operator'), onchange: (e) => { f.op = e.target.value; changer(); } }, operateurs.map((o) => el('option', { value: o, selected: o === f.op }, o))),
    el('input', { value: f.valeur, 'aria-label': tx('Valeur', 'Value'), oninput: (e) => { f.valeur = e.target.value; changer(); } }),
    el('button', { class: 'mini', type: 'button', 'aria-label': tx('Supprimer le filtre', 'Delete the filter'), onclick: () => { t.filtres.splice(i, 1); changer(true); } }, '×')));
  const avecEtat = impressions().length > 0;
  const etat = maj ? [
    el('p', { class: 'aide' }, tx('Un programme de mise à jour n\'imprime pas d\'état : il écrit le fichier résultat et un compte rendu (mouvements rejetés, nombre de créations, modifications et annulations).', 'An update program prints no listing: it writes the result file and a run report (rejected transactions, number of creations, changes and deletions).')),
  ] : [
    el('fieldset', {}, el('legend', {}, tx('Filtres (tous vrais)', 'Filters (all true)')), filtres,
      el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', disabled: !s.rubriques.length, onclick: () => { t.filtres.push({ rubrique: s.rubriques[0], op: '=', valeur: '' }); changer(true); } }, tx('Ajouter un filtre', 'Add a filter')))),
    el('p', { class: 'aide' }, tx('Les ruptures se déclarent à l\'appel du fichier (onglet Fichiers).', 'Control breaks are declared when the file is called (Files tab).')),
    ...(avecEtat ? [el('p', { class: 'aide' }, tx(`L'état ${impressions().map((a) => a.code + (a.argument[0] || '')).join(', ')} se décrit dans l'onglet États. Sans état, le programme imprime une édition simplifiée sur la SYSOUT, réglée ici par des colonnes et des totaux.`, `Report ${impressions().map((a) => a.code + (a.argument[0] || '')).join(', ')} is described in the Reports tab. Without a report, the program prints a simple listing on SYSOUT, set here with columns and totals.`))] : [
    el('fieldset', {}, el('legend', {}, tx('Colonnes de l\'état', 'Listing columns')), cases(s.rubriques, t.colonnes),
      el('div', { class: 'cases' },
        el('label', {}, el('input', { type: 'checkbox', checked: t.detail, onchange: (e) => { t.detail = e.target.checked; changer(); } }), tx('imprimer le détail', 'print the detail')),
        el('label', {}, el('input', { type: 'checkbox', checked: t.compter, onchange: (e) => { t.compter = e.target.checked; changer(); } }), tx('compter les lignes', 'count the lines')))),
    el('fieldset', {}, el('legend', {}, tx('Totaux', 'Totals')), numeriques(s).length ? cases(numeriques(s), t.totaux) : el('p', { class: 'aide' }, tx('Aucune rubrique numérique dans l\'enregistrement.', 'No numeric element in the record.')))]),
  ];
  const dd = (appelEdite(modele) || appel('P'))?.code || 'FA';
  return [
    el('p', { class: 'aide' }, maj ? tx('Le programme lit le principal et les mouvements, les synchronise sur leur argument, applique les mouvements et écrit le résultat.', 'The program reads the master and the transactions, matches them on their argument, applies the transactions and writes the result.') : tx('Un batch d\'édition : lire le fichier, garder les lignes qui passent les filtres, trier et totaliser par rupture, imprimer l\'état.', 'A report batch: read the file, keep the lines that pass the filters, sort and total by control break, print the report.')),
    el('div', { class: 'ligne-champs' },
      el('label', { class: 'champ' }, tx('Programme (8 car.)', 'Program (8 char.)'), el('input', { class: 'code', value: t.programme, maxlength: 8, oninput: (e) => { t.programme = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '') || 'PGM'; changer(); } })),
      el('label', { class: 'champ' }, maj ? tx('Titre du compte rendu', 'Run report title') : tx('Titre de l\'état', 'Listing title'), el('input', { value: t.titre, oninput: (e) => { t.titre = e.target.value; changer(); } }))),
    ...etat,
    el('fieldset', {}, el('legend', {}, tx('Fichiers en sortie', 'Output files')),
      el('p', { class: 'aide' }, tx(`Écrits depuis le langage structuré : M ${dd}00-${s.rubriques[0] || 'RUBRIQ'} RL-${s.rubriques[0] || 'RUBRIQ'} puis ECR RL. Leur contenu apparaît dans la sortie, à côté de la SYSOUT.`, `Written from the structured code: M ${dd}00-${s.rubriques[0] || 'ELEMNT'} RL-${s.rubriques[0] || 'ELEMNT'} then ECR RL. Their content shows in the output, next to SYSOUT.`)),
      modele.sorties.map((so, i) => el('div', { class: 'ligne-champs', style: 'grid-template-columns: 4em minmax(0,1fr) minmax(0,2fr) auto; align-items:center' },
        el('input', { class: 'code', value: so.code, maxlength: 2, 'aria-label': tx('Code du fichier', 'File code'), oninput: (e) => { so.code = e.target.value.toUpperCase(); changer(); } }),
        el('input', { class: 'code', value: so.fichier, 'aria-label': tx('Nom du fichier', 'File name'), oninput: (e) => { so.fichier = e.target.value.toUpperCase(); changer(); } }),
        el('input', { class: 'code', value: so.rubriques.join(' '), 'aria-label': tx('Rubriques', 'Elements'), oninput: (e) => { so.rubriques = e.target.value.toUpperCase().split(/\s+/).filter(Boolean); changer(); } }),
        el('button', { class: 'mini', type: 'button', 'aria-label': tx(`Supprimer le fichier ${so.code}`, `Delete file ${so.code}`), onclick: () => { modele.sorties.splice(i, 1); changer(true); } }, '×'))),
      el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { let n = 1; while (modele.sorties.some((x) => x.code === `S${n}`)) n++; modele.sorties.push({ code: `S${n}`, fichier: tx(`SORTIE${n}.DAT`, `OUTPUT${n}.DAT`), rubriques: [...s.rubriques] }); changer(true); } }, 'Ajouter un fichier en sortie'))),
  ];
}

// ---------- États (-L libellés, -CE structures, -D composition) ----------
function ajouterEtat() {
  const pris = new Set(modele.appels.map((a) => a.code));
  const dd = ['ET', 'IM', 'EA', 'EB'].find((c) => !pris.has(c)) || 'EZ';
  modele.appels.push({ code: dd, utilisation: 'I', fichier: tx('ETAT.TXT', 'REPORT.TXT'), argument: ['1'] });
  if (!modele.etats.some((x) => x.code === `${dd}1`)) {
    modele.etats.push({ code: `${dd}1`, nom: modele.traitement.titre || tx('ETAT', 'REPORT'), longueur: 80, lignesPage: 60, postes: 100,
      libelles: [{ numero: '01', texte: (modele.traitement.titre || tx('ETAT', 'REPORT')).toUpperCase() }], structures: [], categories: [{ ct: 'AA', nlg: '100', lb: '01', saut: '01', sautType: '*', condition: `5-${dd}00-1CL NOT < 5-${dd}00-1CLM` }] });
  }
}
// Table de saisie : une ligne par objet, une colonne par champ ({ k, titre, l : largeur en caractères, code : majuscules, liste }).
function grille(lignes, colonnes, legende) {
  const rangs = lignes.map((l, i) => el('tr', {}, colonnes.map((c) => el('td', {}, c.liste
    ? el('select', { 'aria-label': tx(`${c.titre}, ligne ${i + 1}`, `${c.titre}, line ${i + 1}`), style: 'min-width:5.5ch;font-family:var(--f-code);font-size:.85rem', onchange: (e) => { l[c.k] = e.target.value; changer(); } },
      Object.entries(c.liste).map(([v, t]) => el('option', { value: v, selected: String(l[c.k] ?? '') === v, title: t }, v || '·')))
    : el('input', { value: l[c.k] ?? '', 'aria-label': tx(`${c.titre}, ligne ${i + 1}`, `${c.titre}, line ${i + 1}`), class: c.code ? 'code' : '', maxlength: c.max, style: `width:calc(${c.l}ch + 22px);font-family:var(--f-code);font-size:.85rem`,
      oninput: (e) => { const v = c.code ? e.target.value.toUpperCase() : e.target.value; l[c.k] = c.nombre ? (v === '' ? '' : Number(v)) : v; changer(); } }))),
    el('td', {}, el('button', { class: 'mini', type: 'button', 'aria-label': tx(`Supprimer la ligne ${i + 1} (${legende})`, `Delete line ${i + 1} (${legende})`), onclick: () => { lignes.splice(i, 1); changer(true); } }, '×'))));
  return el('div', { class: 'table-defile' }, el('table', {},
    el('thead', {}, el('tr', {}, colonnes.map((c) => el('th', { title: c.aide || '' }, c.titre)), el('th', {}, ''))),
    el('tbody', {}, rangs)));
}
function vueEtats() {
  const imp = impressions();
  if (!imp.length) return [
    el('p', { class: 'aide' }, tx('Un état s\'imprime dans un fichier d\'impression (utilisation I). Il se décrit comme au manuel : des libellés numérotés (les lignes fixes), des structures (les rubriques à placer sur une ligne et d\'où elles viennent), puis la composition : des catégories de lignes, éditées chacune sous sa condition.', 'A report is printed to a print file (usage I). It is described as in the manual: numbered labels (the fixed lines), structures (the elements to place on a line and where they come from), then the layout: categories of lines, each printed under its condition.')),
    el('p', { class: 'aide' }, tx('Sans état, le programme d\'édition imprime une édition simplifiée sur la SYSOUT (onglet Traitement).', 'Without a report, the report program prints a simple listing on SYSOUT (Processing tab).')),
    el('div', { class: 'actions' },
      el('button', { class: 'bouton', type: 'button', onclick: () => { ajouterEtat(); changer(true); } }, tx('Ajouter un état', 'Add a report')),
      el('button', { class: 'bouton', type: 'button', onclick: () => { if (confirm(tx('Remplacer votre programme par l\'exemple d\'état ?', 'Replace your program with the report example?'))) { modele = normaliser(structuredClone(exempleEtat)); structCourante = 0; appelDonnees = null; sourceModifie = false; changer(true); } } }, tx('Repartir de l\'exemple d\'état', 'Start again from the report example'))),
  ];
  return imp.flatMap((a) => {
    const code = a.code + (a.argument[0] || '');
    let et = modele.etats.find((x) => x.code === code);
    if (!et) return [el('p', { class: 'message erreur' }, tx(`L'état ${code} n'est pas décrit.`, `Report ${code} is not described.`)), el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { modele.etats.push({ code, nom: code, libelles: [], structures: [], categories: [] }); changer(true); } }, tx(`Décrire l'état ${code}`, `Describe report ${code}`)))];
    et.libelles ||= []; et.structures ||= []; et.categories ||= [];
    const L = Number(et.longueur || ETAT_DEFAUT.longueur);
    const nombre = (k, titre, min, max, aide) => el('label', { class: 'champ', title: aide }, titre, el('input', { class: 'code', type: 'number', min, max, value: et[k] ?? ETAT_DEFAUT[k], onchange: (e) => { et[k] = Number(e.target.value); changer(true); } }));
    const regle = Array.from({ length: L }, (_, i) => ((i + 1) % 10 === 0 ? String((i + 1) / 10 % 10) : (i + 1) % 5 === 0 ? '+' : '.')).join('');
    const libelles = et.libelles.map((l, i) => el('div', { style: 'display:flex;gap:6px;align-items:center' },
      el('span', { class: 'code', style: 'width:2ch;font-size:.85rem' }, String(i + 1).padStart(2, '0')),
      el('input', { value: l.texte, maxlength: L, 'aria-label': tx(`Libellé ${i + 1}`, `Label ${i + 1}`), style: `font-family:var(--f-code);font-size:.85rem;padding-left:8px;padding-right:8px;width:calc(${L}ch + 18px);max-width:100%`, oninput: (e) => { l.texte = e.target.value; changer(); } }),
      i === et.libelles.length - 1 ? el('button', { class: 'mini', type: 'button', 'aria-label': tx(`Supprimer le libellé ${i + 1}`, `Delete label ${i + 1}`), onclick: () => { et.libelles.pop(); changer(true); } }, '×') : null));
    et.libelles.forEach((l, i) => { l.numero = String(i + 1).padStart(2, '0'); });
    const modes = { '': '', ...MODES_REMPLISSAGE };
    const types = Object.fromEntries(Object.entries(TYPES_LIGNE).map(([k, v]) => [k, v]));
    return [
      el('p', { class: 'aide' }, tx(`État ${code}, imprimé dans ${a.fichier} : la fonction F8${a.argument[0] || '?'} du programme. Les compteurs 5-${a.code}00-${a.argument[0] || 'e'}CL (ligne dans la page), CLM (lignes par page), CP (page) et CE (lignes écrites) servent dans les conditions et les sources ; CATX est la catégorie en cours d'édition.`, `Report ${code}, printed to ${a.fichier}: function F8${a.argument[0] || '?'} of the program. The counters 5-${a.code}00-${a.argument[0] || 'e'}CL (line in the page), CLM (lines per page), CP (page) and CE (lines written) can be used in conditions and sources; CATX is the category being printed.`)),
      el('fieldset', {}, el('legend', {}, tx('Caractéristiques (-D)', 'Characteristics (-D)')),
        el('div', { class: 'ligne-champs', style: 'grid-template-columns: minmax(0,2fr) repeat(3, minmax(0,1fr)); align-items:end' },
          el('label', { class: 'champ' }, tx('Nom', 'Name'), el('input', { value: et.nom || '', oninput: (e) => { et.nom = e.target.value; changer(); } })),
          nombre('longueur', tx('Longueur de ligne', 'Line length'), 1, 264),
          nombre('lignesPage', tx('Lignes par page', 'Lines per page'), 1, 99),
          nombre('postes', tx('Postes catégories', 'Category entries'), 0, 999, tx('0 : chaque ligne est éditée tout de suite (édition directe) ; sinon, les catégories sont chargées puis éditées', '0: each line is printed at once (direct printing); otherwise the categories are loaded then printed'))),
        el('label', { class: 'champ' }, tx('Condition d\'édition de l\'état (COBOL, sans IF ni point)', 'Report printing condition (COBOL, no IF or period)'), el('input', { class: 'code', value: et.condition || '', oninput: (e) => { et.condition = e.target.value.toUpperCase(); changer(); } }))),
      el('fieldset', {}, el('legend', {}, tx('Libellés (-L)', 'Labels (-L)')),
        el('p', { class: 'aide' }, tx('Les lignes fixes, numérotées de 01 sans trou : seul le dernier se supprime, comme au manuel (les catégories désignent les libellés par leur numéro).', 'The fixed lines, numbered from 01 with no gap: only the last one can be deleted, as in the manual (the categories refer to labels by number).')),
        el('pre', { class: 'brut', 'aria-hidden': 'true', style: 'margin:0 0 0 calc(2ch + 6px + 9px);padding:0;border:0;background:none;font-family:var(--f-code);font-size:.85rem;overflow:hidden' }, regle),
        ...libelles,
        el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { et.libelles.push({ numero: '', texte: '' }); changer(true); } }, tx('Ajouter un libellé', 'Add a label')))),
      el('fieldset', {}, el('legend', {}, 'Structures (-CE)'),
        el('p', { class: 'aide' }, tx('Les rubriques d\'une ligne : structure (01, 02…), rubrique, colonne de début, mode (M mouvement, T à totaliser, + ou R calcul, U par vous), puis la source : W le type de zone (blanc FD, 1 traitement, 2 mise à jour, 5 compteurs, * expression libre) et FFNNRUBRIQ (fichier, segment, rubrique ; un littéral entre quotes). Une ligne suite (*) porte une opération (+ - * /) ou la suite de la condition. Une rubrique hors dictionnaire prend un format (ZZ9, X(10)…).', 'The elements of a line: structure (01, 02…), element, start column, mode (M move, T to total, + or R computation, U by you), then the source: W the field type (blank FD, 1 processing, 2 update, 5 counters, * free expression) and FFNNELEMNT (file, segment, element; or a quoted literal). A continuation line (*) carries an operation (+ - * /) or the rest of the condition. An element outside the dictionary takes a format (ZZ9, X(10)…).')),
        grille(et.structures, [
          { k: 'st', titre: 'ST', l: 2, max: 2, code: true }, { k: 'rubrique', titre: tx('Rubrique', 'Element'), l: 6, max: 6, code: true },
          { k: 'suite', titre: 'S', l: 1, max: 1, aide: tx('* : ligne suite', '*: continuation line') }, { k: 'debut', titre: tx('Déb.', 'Start'), l: 3, max: 3, nombre: true },
          { k: 'mode', titre: 'O', liste: modes }, { k: 'w', titre: 'W', l: 1, max: 1, code: true },
          { k: 'source', titre: 'Source', l: 14, code: false }, { k: 'indice', titre: tx('Ind.', 'Idx.'), l: 4, max: 6, code: true },
          { k: 'condition', titre: 'Condition', l: 22 }, { k: 'format', titre: 'Format', l: 10, max: 30, code: true },
        ], tx('structures', 'structures')),
        el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { const d = et.structures.at(-1); et.structures.push({ st: d?.st || '01', rubrique: '', debut: '', mode: '', w: '1', source: '' }); changer(true); } }, tx('Ajouter une rubrique', 'Add an element')))),
      el('fieldset', {}, el('legend', {}, tx('Composition (-D) : catégories', 'Layout (-D): categories')),
        el('p', { class: 'aide' }, tx('Une catégorie (2 caractères) regroupe des lignes éditées sous la même condition, dans l\'ordre des codes (les lettres avant les chiffres). Chaque ligne : numéro, type (blanc, I itérative, A / Z en-tête et fin de page, * ligne détail totalisée, T détail jamais éditée, 0 total général, 1 à 3 total de rupture), répétitions (I), structure, libellé, saut avant la ligne (00 surimpression, * saut absolu : nouvelle page à 01), traitement à exécuter avant (une de vos fonctions), condition.', 'A category (2 characters) groups lines printed under the same condition, in code order (letters before digits). Each line: number, type (blank, I iterative, A / Z page header and footer, * totaled detail line, T detail never printed, 0 grand total, 1 to 3 break total), repeats (I), structure, label, skip before the line (00 overprint, * absolute skip: new page at 01), processing to run before (one of your functions), condition.')),
        grille(et.categories, [
          { k: 'ct', titre: 'CT', l: 2, max: 2, code: true }, { k: 'nlg', titre: 'NLG', l: 3, max: 3, code: true },
          { k: 'type', titre: 'T', liste: types }, { k: 'car', titre: 'CAR', l: 3, max: 30, code: true, aide: tx('répétitions d\'une catégorie itérative : nombre ou indice', 'repeats of an iterative category: number or index') },
          { k: 'st', titre: 'ST', l: 2, max: 2, code: true }, { k: 'lb', titre: 'LB', l: 2, max: 2, code: true },
          { k: 'saut', titre: 'SAU', l: 2, max: 2, code: true }, { k: 'sautType', titre: '*', l: 1, max: 1, aide: tx('* : saut absolu', '*: absolute skip') },
          { k: 'fosf', titre: 'FOSF', l: 4, max: 4, code: true }, { k: 'condition', titre: 'Condition', l: 26 },
        ], tx('catégories', 'categories')),
        el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { const d = et.categories.at(-1); et.categories.push({ ct: d?.ct || 'AA', nlg: String(Number(d?.nlg || 90) + 10).padStart(3, '0'), saut: '01' }); changer(true); } }, tx('Ajouter une ligne', 'Add a line')))),
    ];
  });
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
      el('td', {}, el('button', { class: 'mini', type: 'button', 'aria-label': tx(`Supprimer la ligne ${i + 1}`, `Delete line ${i + 1}`), onclick: () => { lignes.splice(i, 1); changer(true); } }, '×')));
  });
  const ajouter = (l) => { lignes.push(l); changer(true); };
  const der = lignes.at(-1) || { fo: '30', sf: '10' };
  const maj = estMiseAJour(modele);
  const zones = zonesTraitees(modele).join(' ; ');
  return [
    el('p', { class: 'aide' }, tx('Vos traitements, sans écrire de COBOL. Une sous-fonction commence par une ligne N : titre, niveau (05, 10…), type (BL bloc, IT si, EL sinon, DW tant que) et condition. Les lignes suivantes portent un opérateur : M déplacer, A ajouter, S soustraire, C calculer (C W-X = A + B), P appeler une fonction, MES afficher, ECR écrire un fichier, SQL accéder à une table (SQL CL R, voir l\'onglet Base), SCO / SRO valider / annuler, COB ligne COBOL, * commentaire.', 'Your processing, without writing COBOL. A sub-function starts with an N line: title, level (05, 10…), type (BL block, IT if, EL else, DW while) and condition. The next lines carry an operator: M move, A add, S subtract, C compute (C W-X = A + B), P perform a function, MES display, ECR write a file, SQL access a table (SQL CL R, see the Database tab), SCO / SRO commit / roll back, COB COBOL line, * comment.')),
    el('p', { class: 'aide' }, tx(`Rubriques : ${zones} ; W- zone de travail (déclarée d'après le dictionnaire)${maj ? '' : ', D- ligne de détail'}. ${maj ? 'Synchronisation : CL-CF1 = 1 si le principal participe au tour, MV-CF1 pour le mouvement, MV-DE1 = 1 au dernier mouvement de l\'indicatif.' : 'Ruptures : RTP1 = 1 au premier d\'un groupe, RTD1 = 1 au dernier.'} Conditions : = # > < >= <=, ET, OU.`, `Elements: ${zones}; W- work field (declared from the dictionary)${maj ? '' : ', D- detail line'}. ${maj ? 'Matching: CL-CF1 = 1 if the master takes part in the round, MV-CF1 for the transaction, MV-DE1 = 1 on the last transaction of the key.' : 'Control breaks: RTP1 = 1 on the first of a group, RTD1 = 1 on the last.'} Conditions: = # > < >= <=, ET (and), OU (or).`)),
    el('p', { class: 'aide' }, tx(`Fonctions générées : ${fonctionsGenerees(modele).map((f) => `F${f}`).join(' ')} ; ajoutez-y des sous-fonctions (par exemple ${maj ? '20' : '8E'} 10) ou créez les vôtres, placées selon leur code (les lettres passent avant les chiffres : 8E avant 80).`, `Generated functions: ${fonctionsGenerees(modele).map((f) => `F${f}`).join(' ')}; add sub-functions to them (for example ${maj ? '20' : '8E'} 10) or create your own, placed by their code (letters come before digits: 8E before 80).`)),
    el('div', { class: 'table-defile' }, el('table', { style: 'min-width:760px' },
      el('thead', {}, el('tr', {}, ...['FO', 'SF', 'OPE', tx('Opérandes / titre', 'Operands / title'), 'NV', 'TC', 'Condition', ''].map((x) => el('th', {}, x)))),
      el('tbody', {}, rangs))),
    el('div', { class: 'actions' },
      el('button', { class: 'bouton', type: 'button', onclick: () => ajouter({ fo: der.fo, sf: String(Number(der.sf || 0) + 10).padStart(2, '0').slice(-2), ope: 'N', opes: tx('Nouvelle sous-fonction', 'New sub-function'), niv: '05', tc: 'BL', cond: '' }) }, tx('Ajouter une sous-fonction', 'Add a sub-function')),
      el('button', { class: 'bouton', type: 'button', onclick: () => ajouter({ fo: der.fo, sf: der.sf, ope: 'M', opes: '' }) }, tx('Ajouter une ligne', 'Add a line'))),
    el('p', { class: 'aide', id: 'infos-fonctions' }),
  ];
}
function majInfos() {
  const p = $('#infos-fonctions'); if (!p) return;
  try {
    const fns = analyserLignes(modele.lignes);
    p.textContent = [...fns.keys()].map((fo) => `F${fo} : ${placeFonction(fo, modele)}`).join(' · ') || tx('Aucune fonction.', 'No function.');
  } catch (e) { p.textContent = e.message; }
}

let db = null;
function vueBase() {
  const tables = modele.tables;
  const erreur = (() => { try { verifierTables(modele); return null; } catch (e) { return e.message; } })();
  const blocs = tables.map((tb, i) => {
    const contenu = el('div', { class: 'champ' }, el('span', { class: 'aide' }, db ? '' : tx('Contenu : chargement de la base…', 'Content: loading the database…')));
    if (db) {
      try {
        const { lignes } = contenuTable(db, tb);
        contenu.replaceChildren(el('span', { class: 'aide' }, tx(`${lignes.length} ligne(s) en base`, `${lignes.length} row(s) in the database`)), el('div', { class: 'table-defile' }, el('table', {},
          el('thead', {}, el('tr', {}, tb.rubriques.map((c) => el('th', { style: rub(c)?.type === '9' ? 'text-align:right' : '' }, c)))),
          el('tbody', {}, lignes.map((l) => el('tr', {}, l.map((v, k) => el('td', { class: 'code', style: rub(tb.rubriques[k])?.type === '9' ? 'text-align:right' : '' }, formaterValeur(rub(tb.rubriques[k]), v)))))))));
      } catch { contenu.replaceChildren(el('span', { class: 'aide' }, tx('Table pas encore créée : elle le sera à la prochaine exécution.', 'Table not created yet: it will be on the next run.'))); }
    }
    return el('fieldset', { style: 'min-width:0' }, el('legend', {}, `Table ${tb.nom || '?'}`),
      el('div', { class: 'ligne-champs', style: 'grid-template-columns: 4em minmax(0,1fr) auto; align-items:end' },
        el('label', { class: 'champ' }, 'Code', el('input', { class: 'code', value: tb.code, maxlength: 2, oninput: (e) => { tb.code = e.target.value.toUpperCase(); changer(); } })),
        el('label', { class: 'champ' }, tx('Nom de la table', 'Table name'), el('input', { class: 'code', value: tb.nom, maxlength: 18, oninput: (e) => { tb.nom = e.target.value.toUpperCase(); changer(); } })),
        el('button', { class: 'mini', type: 'button', 'aria-label': tx(`Supprimer la description de ${tb.nom}`, `Delete the description of ${tb.nom}`), onclick: () => { tables.splice(i, 1); changer(true); } }, '×')),
      el('label', { class: 'champ' }, tx('Rubriques (colonnes)', 'Elements (columns)'), el('input', { class: 'code', value: tb.rubriques.join(' '), onchange: (e) => { tb.rubriques = e.target.value.toUpperCase().split(/\s+/).filter(Boolean); changer(true); } })),
      el('label', { class: 'champ' }, tx('Clé', 'Key'), el('input', { class: 'code', value: tb.cle.join(' '), onchange: (e) => { tb.cle = e.target.value.toUpperCase().split(/\s+/).filter(Boolean); changer(true); } })),
      contenu,
      el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', disabled: !db, onclick: () => { try { viderTable(db, tb); sauverBase(db); } catch {} afficher(); } }, tx('Vider la table', 'Empty the table'))));
  });
  if (!db) ouvrirBase().then((d) => { db = d; try { synchroniser(db, modele); sauverBase(db); } catch {} if (vue === 'base') afficher(); }, () => {});
  const s = structureLue();
  return [
    el('p', { class: 'aide' }, tx('Une base SQLite tourne dans le navigateur, à la manière de DB2. Chaque table a un code de deux lettres : ses colonnes s\'utilisent dans le langage structuré sous la forme CL-NOMCLI. Le programme généré y accède par des EXEC SQL, et SQLCODE dit le résultat : 0 trouvé, 100 absent ou fin de curseur, -803 clé en double.', 'A SQLite database runs in the browser, DB2 style. Each table has a two-letter code: its columns are used in the structured code as CL-CUSTNM. The generated program reaches it through EXEC SQL, and SQLCODE gives the result: 0 found, 100 not found or end of cursor, -803 duplicate key.')),
    el('p', { class: 'aide' }, tx('Opérateur SQL, suivi du code de la table et du type d\'accès (codes VisualAge Pacbase) : ', 'SQL operator, followed by the table code and the access type (VisualAge Pacbase codes): ') + Object.entries(OPERATEURS_BASE).map(([o, t]) => `${o} : ${t}`).join(' · ')),
    el('p', { class: 'aide' }, tx('La base garde ses lignes d\'une exécution à l\'autre (dans ce navigateur, partagée avec le terminal). Un arrêt anormal annule tout ce que le programme a écrit. Changer les colonnes ou la clé recrée la table, vide.', 'The database keeps its rows from one run to the next (in this browser, shared with the terminal). An abnormal end rolls back everything the program wrote. Changing the columns or the key recreates the table, empty.')),
    el('p', { class: 'aide' }, tx('Des écrans de saisie peuvent aussi lire et mettre à jour ces tables : ils se décrivent et s\'exécutent au ', 'Entry screens can also read and update these tables: they are described and run at the '),
      el('a', { href: tx('/atelier-cobol/terminal', '/en/cobol-workshop/terminal') }, tx('terminal', 'terminal')), tx(' (choix O, puis -CE, -CS, -SIM et -TP).', ' (option O, then -CE, -CS, -SIM and -TP).')),
    ...(erreur ? [el('p', { class: 'message erreur' }, erreur)] : []),
    ...blocs,
    el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { let n = 1; while (tables.some((x) => x.code === `T${n}`)) n++; tables.push({ code: `T${n}`, nom: `TABLE${n}`, rubriques: [...s.rubriques], cle: [s.rubriques[0]] }); changer(true); } }, tx('Ajouter une table', 'Add a table'))),
  ];
}

// Données : un onglet par fichier lu (le résultat est produit par le programme).
const appelsLus = () => modele.appels.filter((a) => a.utilisation !== 'R' && a.utilisation !== 'I');
function contenuBrut(a) {
  const f = fichiersEntree(modele);
  return f[a.fichier] || tx('(vide)', '(empty)');
}
function vueDon() {
  const lus = appelsLus();
  const a = lus.find((x) => x.code === appelDonnees) || lus[0];
  if (!a) return [el('p', { class: 'aide' }, tx('Aucun fichier en entrée : appelez-en un dans l\'onglet Fichiers.', 'No input file: call one in the Files tab.'))];
  appelDonnees = a.code;
  const s = structDe(a.structure);
  a.donnees ||= [];
  let brut = '', erreur = null;
  try { brut = contenuBrut(a); } catch (e) { erreur = e.message; }
  const lignes = a.donnees.map((l, i) => el('tr', {},
    s.rubriques.map((c) => el('td', {}, el('input', { class: rub(c).type === 'X' ? '' : 'code', value: l[c] ?? '', 'aria-label': tx(`${c}, ligne ${i + 1}`, `${c}, line ${i + 1}`), inputmode: rub(c).type === '9' ? 'decimal' : 'text',
      oninput: (e) => { l[c] = e.target.value; changer(); majBrut(); } }))),
    el('td', {}, el('button', { class: 'mini', type: 'button', 'aria-label': tx(`Supprimer la ligne ${i + 1}`, `Delete line ${i + 1}`), onclick: () => { a.donnees.splice(i, 1); changer(true); } }, '×'))));
  const trier = a.argument?.length && a.utilisation !== 'C'
    ? el('button', { class: 'bouton', type: 'button', onclick: () => {
      const cle = (l) => a.argument.map((c) => { const r = rub(c); const v = String(l[c] ?? '').trim(); return r.type === '9' ? v.padStart(r.longueur, '0') : v.padEnd(r.longueur); }).join('');
      a.donnees = a.donnees.map((l, i) => ({ l, i })).sort((x, y) => (cle(x.l) < cle(y.l) ? -1 : cle(x.l) > cle(y.l) ? 1 : x.i - y.i)).map((x) => x.l);
      changer(true);
    } }, tx(`Trier sur ${a.argument.join(' ')}`, `Sort on ${a.argument.join(' ')}`)) : null;
  return [
    lus.length > 1 ? el('div', { class: 'fichiers', style: 'padding:0' }, lus.map((x) => el('button', { class: 'bouton', type: 'button', 'aria-pressed': String(x === a), onclick: () => { appelDonnees = x.code; afficher(); } }, `${x.code} ${x.fichier}`))) : null,
    el('p', { class: 'aide' }, tx(`Le contenu du fichier ${a.fichier} (${a.code}), saisi colonne par colonne. Montants avec un point décimal, signe moins accepté pour les rubriques signées.${a.utilisation === 'M' ? ' Une case vide ne modifie pas la rubrique.' : ''}${a.utilisation === 'C' ? '' : ' Le fichier doit être trié sur son argument.'}`, `The content of file ${a.fichier} (${a.code}), entered column by column. Amounts with a decimal point, minus sign accepted for signed elements.${a.utilisation === 'M' ? ' An empty cell does not change the element.' : ''}${a.utilisation === 'C' ? '' : ' The file must be sorted on its argument.'}`)),
    el('div', { class: 'table-defile' }, el('table', {},
      el('thead', {}, el('tr', {}, s.rubriques.map((c) => el('th', {}, c)), el('th', {}, ''))),
      el('tbody', {}, lignes))),
    el('div', { class: 'actions' }, el('button', { class: 'bouton', type: 'button', onclick: () => { a.donnees.push({}); changer(true); } }, tx('Ajouter une ligne', 'Add a line')), trier),
    el('div', { class: 'champ' }, el('span', { class: 'aide' }, tx('Le fichier tel que le programme le lit :', 'The file as the program reads it:')), el('pre', { class: 'brut', id: 'brut' }, erreur ? `⚠ ${erreur}` : brut)),
  ];
}
function majBrut() {
  const b = $('#brut'); if (!b) return;
  const a = appelsLus().find((x) => x.code === appelDonnees);
  try { b.textContent = a ? contenuBrut(a) : ''; } catch (e) { b.textContent = `⚠ ${e.message}`; }
}

function afficher() {
  const v = $('#vue');
  v.replaceChildren(...({ dico: vueDico, struct: vueStruct, cd: vueAppels, trt: vueTrt, etats: vueEtats, spe: vueSpe, don: vueDon, base: vueBase }[vue])());
  majInfos();
  document.querySelectorAll('.onglets button').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.vue === vue)));
  majCompteurs();
}
// le nombre d'éléments de chaque onglet, et l'état du modèle en bas du panneau
function majCompteurs() {
  const n = {
    dico: modele.dictionnaire.length, struct: modele.structures.length, cd: modele.appels.length,
    don: modele.appels.reduce((x, a) => x + (a.donnees || []).length, 0), spe: modele.lignes.length, etats: modele.etats.length, base: modele.tables.length,
  };
  document.querySelectorAll('[data-compte]').forEach((c) => { c.textContent = n[c.dataset.compte] ? String(n[c.dataset.compte]) : ''; });
  const v = $('#verdict-modele');
  let erreur = null;
  try { generer(modele); } catch (e) { erreur = e.message; }
  v.className = `verdict-modele${erreur ? ' incomplet' : ''}`;
  v.textContent = erreur ? tx(`À compléter : ${erreur}`, `Still to complete: ${erreur}`) : sourceModifie
    ? tx('Programme complet. Le source, modifié à la main, ne suit plus le modèle.', 'Program complete. The source, edited by hand, no longer follows the model.')
    : tx('Programme complet : le source se régénère à chaque modification.', 'Program complete: the source is regenerated on every change.');
}
document.querySelectorAll('.onglets button').forEach((b) => b.addEventListener('click', () => { vue = b.dataset.vue; afficher(); }));

// ---------- Source ----------
const zoneSource = $('#source');
function regenerer() {
  try {
    zoneSource.value = generer(modele);
    sourceModifie = false;
    $('#regenerer').hidden = true;
    pastille('ok', tx(`Généré · ${zoneSource.value.split('\n').length} lignes`, `Generated · ${zoneSource.value.split('\n').length} lines`));
  } catch (e) {
    pastille('incomplet', tx('Modèle incomplet', 'Incomplete model'));
  }
  $('#titre-programme').textContent = modele.traitement.programme || '';
  $('#sous-titre-programme').textContent = modele.traitement.titre || '';
  majSource();
  majCompteurs();
}
function pastille(genre, texte) {
  const p = $('#etat-source');
  p.className = `pastille ${genre}`;
  p.textContent = texte;
}
// le plan : divisions et fonctions (F01, F20, F8E…), pour sauter dans le source
function majPlan() {
  const lignes = zoneSource.value.split('\n');
  const entrees = [];
  lignes.forEach((l, i) => {
    const d = /^.{7}([A-Z-]+) DIVISION\./.exec(l);
    if (d) { entrees.push([d[1], i]); return; }
    const f = /^.{7}(F[0-9A-Z]{2})\.(?: |$)/.exec(l);
    if (f) entrees.push([f[1], i]);
  });
  $('#plan').replaceChildren(...entrees.map(([nom, i]) => el('button', { type: 'button', class: 'plan-entree', onclick: () => allerALigne(i + 1) },
    el('span', {}, nom), el('small', {}, String(i + 1)))));
}
function allerALigne(n) {
  const hauteur = parseFloat(getComputedStyle(zoneSource).lineHeight) || 20;
  $('#feuille').scrollTo({ top: Math.max(0, (n - 2) * hauteur), behavior: 'smooth' });
  const lignes = zoneSource.value.split('\n');
  const debut = lignes.slice(0, n - 1).reduce((x, l) => x + l.length + 1, 0);
  zoneSource.focus({ preventScroll: true });
  zoneSource.setSelectionRange(debut, debut + (lignes[n - 1] || '').length);
}
function majSource() {
  const n = zoneSource.value.split('\n').length;
  $('#gouttiere').textContent = Array.from({ length: n }, (_, i) => String(i + 1).padStart(4, ' ')).join('\n');
  zoneSource.style.height = 'auto';
  zoneSource.style.height = `${zoneSource.scrollHeight + 4}px`;
  $('#note-source').textContent = String(n);
  majPlan();
}
zoneSource.addEventListener('input', () => {
  sourceModifie = true;
  pastille('modifie', tx('Modifié à la main', 'Edited by hand'));
  $('#regenerer').hidden = false;
  majSource();
  majCompteurs();
});
$('#regenerer').addEventListener('click', () => { regenerer(); });
$('#copier').addEventListener('click', async (e) => {
  const b = e.currentTarget;
  try { await navigator.clipboard.writeText(zoneSource.value); b.textContent = tx('Copié', 'Copied'); }
  catch { zoneSource.select(); b.textContent = tx('Texte sélectionné', 'Text selected'); }
  setTimeout(() => (b.textContent = tx('Copier', 'Copy')), 1600);
});
$('#regle').textContent = Array.from({ length: 80 }, (_, i) => ((i + 1) % 10 === 0 ? String((i + 1) / 10 % 10) : (i + 1) % 5 === 0 ? '+' : '.')).join('');

function texteTable(tb) {
  const { lignes } = contenuTable(db, tb);
  const larg = tb.rubriques.map((c, k) => Math.max(c.length, ...lignes.map((l) => formaterValeur(rub(c), l[k]).length)));
  const cel = (c, t, k) => (rub(c)?.type === '9' ? t.padStart(larg[k]) : t.padEnd(larg[k]));
  return [tx(`${tb.nom} : ${lignes.length} ligne(s), clé ${tb.cle.join(' ')}`, `${tb.nom}: ${lignes.length} row(s), key ${tb.cle.join(' ')}`), '', tb.rubriques.map((c, k) => cel(c, c, k)).join('  '),
    ...lignes.map((l) => l.map((v, k) => cel(tb.rubriques[k], formaterValeur(rub(tb.rubriques[k]), v), k)).join('  '))].join('\n');
}
// Source, SYSOUT, fichiers produits : un seul panneau visible
let sortieVue = 'source';
function montrer(id) {
  sortieVue = id;
  for (const b of document.querySelectorAll('.vues [role="tab"]')) {
    const actif = b.dataset.sortie === id;
    b.setAttribute('aria-selected', String(actif));
    b.tabIndex = actif ? 0 : -1;
    document.getElementById(b.getAttribute('aria-controls')).hidden = !actif;
  }
}
document.querySelectorAll('.vues [role="tab"]').forEach((b) => b.addEventListener('click', () => montrer(b.dataset.sortie)));
document.querySelector('.vues').addEventListener('keydown', (e) => {
  if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
  const ids = ['source', 'sysout', 'fichiers'];
  const i = (ids.indexOf(sortieVue) + (e.key === 'ArrowRight' ? 1 : ids.length - 1)) % ids.length;
  montrer(ids[i]);
  document.querySelector(`.vues [data-sortie="${ids[i]}"]`).focus();
});

async function executer(e) {
  const demande = !!e; // un clic sur Exécuter montre le résultat ; le lancement au chargement laisse le source
  const msg = $('#message');
  msg.replaceChildren();
  let entrees = {};
  try { entrees = fichiersEntree(modele); } catch (e) { msg.append(el('p', { class: 'message erreur' }, tx(`Données : ${e.message}`, `Data: ${e.message}`))); if (demande) montrer('sysout'); return; }
  try {
    const t0 = performance.now();
    const pgm = new Programme(zoneSource.value);
    let recreees = [];
    if (pgm.utiliseSql()) {
      if (!db) { msg.append(el('p', { class: 'message' }, tx('Ouverture de la base…', 'Opening the database…'))); db = await ouvrirBase(); msg.replaceChildren(); }
      recreees = synchroniser(db, modele);
    }
    const t1 = performance.now();
    const r = pgm.executer({ fichiers: entrees, db });
    const t2 = performance.now();
    if (db) sauverBase(db);
    const ecrits = Object.fromEntries(Object.entries(r.fichiers).filter(([nom]) => !(nom in entrees) && !nom.endsWith('.TMP')));
    derniersFichiers = { SYSOUT: r.sortie, ...ecrits };
    if (r.sql.executees) for (const tb of modele.tables) { try { derniersFichiers[`Table ${tb.nom}`] = texteTable(tb); } catch {} }
    msg.append(el('p', { class: 'message ok' }, el('strong', {}, tx('Fin normale (STOP RUN)', 'Normal end (STOP RUN)')),
      el('span', {}, tx(`${r.instructions} instructions`, `${r.instructions} statements`)),
      r.sql.executees ? el('span', {}, tx(`${r.sql.executees} ordres SQL, ${r.sql.erreurs.length} en erreur`, `${r.sql.executees} SQL statements, ${r.sql.erreurs.length} in error`)) : null,
      el('span', {}, tx(`analyse ${(t1 - t0).toFixed(1)} ms, exécution ${(t2 - t1).toFixed(1)} ms`, `parse ${(t1 - t0).toFixed(1)} ms, run ${(t2 - t1).toFixed(1)} ms`))));
    for (const nom of recreees) msg.append(el('p', { class: 'message' }, tx(`Table ${nom} recréée vide : sa description avait changé.`, `Table ${nom} recreated empty: its description had changed.`)));
    for (const x of r.sql.erreurs) msg.append(el('p', { class: 'message erreur' }, tx(`SQLCODE ${x.code}, ligne ${x.ligne} : ${x.message}`, `SQLCODE ${x.code}, line ${x.ligne}: ${x.message}`)));
    if (vue === 'base') afficher();
    document.dispatchEvent(new CustomEvent('atelier:execute', { detail: { sortie: r.sortie, modele } }));
  } catch (e) {
    derniersFichiers = {};
    msg.append(el('p', { class: 'message erreur' }, tx(`Erreur, ${e.message}${db ? ' La base n\'a pas été modifiée.' : ''}`, `Error, ${e.message}${db ? ' The database was not changed.' : ''}`)));
    if (e.ligne) msg.append(el('p', { class: 'message' }, el('button', { class: 'bouton', type: 'button', onclick: () => surligner(e.ligne) }, tx(`Voir la ligne ${e.ligne} du source`, `Show line ${e.ligne} of the source`))));
    document.dispatchEvent(new CustomEvent('atelier:execute', { detail: { sortie: null, modele } }));
  }
  const autres = Object.keys(derniersFichiers).filter((nom) => nom !== 'SYSOUT');
  if (!autres.includes(fichierAffiche)) fichierAffiche = autres[0] || null;
  // un état imprimé se montre d'abord quand la SYSOUT est vide
  const etat = impressions().find((x) => x.fichier in derniersFichiers);
  if (etat && !(derniersFichiers.SYSOUT || '').trim()) fichierAffiche = etat.fichier;
  afficherFichiers();
  if (demande) montrer(etat && !(derniersFichiers.SYSOUT || '').trim() ? 'fichiers' : 'sysout');
}
function afficherFichiers() {
  const sysout = derniersFichiers.SYSOUT;
  $('#sysout').textContent = sysout ?? '';
  const n = sysout ? sysout.replace(/\n$/, '').split('\n').length : 0;
  $('#note-sysout').textContent = n ? tx(`${n} lignes`, `${n} lines`) : '';
  const autres = Object.keys(derniersFichiers).filter((nom) => nom !== 'SYSOUT');
  $('#note-fichiers').textContent = autres.length ? String(autres.length) : '';
  $('#fichiers').replaceChildren(...autres.map((nom) => el('button', { class: 'bouton', type: 'button', 'aria-pressed': String(nom === fichierAffiche), onclick: () => { fichierAffiche = nom; afficherFichiers(); } }, nom)));
  const t = derniersFichiers[fichierAffiche] ?? '';
  $('#contenu-fichier').textContent = !autres.length ? tx('Aucun fichier produit par la dernière exécution.', 'No file produced by the last run.')
    : impressions().some((x) => x.fichier === fichierAffiche) ? listing(t) : t;
}
function surligner(ligne) {
  montrer('source');
  allerALigne(ligne);
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
      el('p', {}, tx(`Défi n°${id} : ${d.titre}. Le relever remplace votre programme, qui reste récupérable.`, `Challenge #${id}: ${d.titre}. Taking it on replaces your program, which you can get back.`)),
      el('button', { class: 'bouton principal', type: 'button', onclick: () => {
        try {
          const actuel = localStorage.getItem(CLE);
          if (actuel && !JSON.parse(actuel).defi) localStorage.setItem(CLE_PRECEDENT, actuel);
          localStorage.setItem(CLE, JSON.stringify({ ...structuredClone(d.modele), defi: id }));
        } catch {
          bandeau.replaceChildren(el('p', {}, tx('Votre navigateur bloque le stockage local : impossible d\'ouvrir le défi ici.', 'Your browser blocks local storage: the challenge cannot be opened here.')));
          return;
        }
        oublierFragment();
        location.reload();
      } }, tx('Relever le défi', 'Take the challenge')),
      el('button', { class: 'bouton', type: 'button', onclick: () => { oublierFragment(); bandeau.hidden = true; } }, tx('Plus tard', 'Later')),
    );
    bandeau.hidden = false;
  }
  const d = DEFIS[modele.defi];
  const zone = document.getElementById('defi');
  if (!d || !zone) return;
  const verdict = el('p', { class: 'verdict', role: 'status' }, tx('Exécutez votre programme : la SYSOUT est comparée à celle attendue.', 'Run your program: the SYSOUT is compared with the expected one.'));
  const actions = el('div', { class: 'actions' });
  const quitter = el('button', { class: 'bouton', type: 'button', onclick: () => {
    let precedent = null;
    try { precedent = localStorage.getItem(CLE_PRECEDENT); } catch {}
    if (precedent) { try { localStorage.setItem(CLE, precedent); localStorage.removeItem(CLE_PRECEDENT); } catch {} location.reload(); return; }
    delete modele.defi; sauver(); zone.hidden = true;
  } }, tx('Quitter le défi', 'Leave the challenge'));
  zone.replaceChildren(
    el('h2', {}, tx(`Défi n°${modele.defi} : ${d.titre}`, `Challenge #${modele.defi}: ${d.titre}`)),
    el('p', {}, d.enonce[0]),
    el('ol', {}, ...d.enonce.slice(1, -1).map((x) => el('li', {}, x))),
    el('p', {}, d.enonce.at(-1)),
    el('p', {}, tx('Les écrans, les fonctions et les ruptures sont rappelés dans ', 'The screens, functions and control breaks are summed up in '), el('a', { href: zone.dataset.memo || tx('/atelier-cobol/memo', '/en/cobol-workshop/memo'), target: '_blank', rel: 'noopener' }, tx('le mémo de l\'atelier', 'the workshop cheat sheet')), '.'),
    el('details', {}, el('summary', {}, tx('Voir la SYSOUT attendue', 'See the expected SYSOUT')), el('pre', { class: 'sysout' }, d.attendu)),
    verdict, actions,
  );
  actions.append(quitter);
  zone.hidden = false;
  document.addEventListener('atelier:execute', ({ detail }) => {
    actions.replaceChildren(quitter);
    verdict.className = 'verdict';
    if (detail.sortie === null) { verdict.textContent = tx('Le programme s\'arrête en erreur : corrigez-le, puis exécutez-le à nouveau.', 'The program stops with an error: fix it, then run it again.'); return; }
    const r = comparer(detail.sortie, d.attendu);
    if (!r.reussi) {
      verdict.replaceChildren(tx(`Pas encore. Ligne ${r.ligne}, attendu : `, `Not yet. Line ${r.ligne}, expected: `), el('code', {}, r.attendue || tx('(ligne vide)', '(empty line)')), tx(', obtenu : ', ', got: '), el('code', {}, r.obtenue || tx('(ligne vide)', '(empty line)')), '.');
      return;
    }
    verdict.className = 'verdict reussi';
    verdict.textContent = tx('Défi réussi ✓ Votre SYSOUT est identique à celle attendue.', 'Challenge passed ✓ Your SYSOUT is identical to the expected one.');
    actions.prepend(el('button', { class: 'bouton principal', type: 'button', onclick: async () => {
      const lien = await lienDe(modele);
      const url = new URL(zone.dataset.contact || '/contact', location.origin);
      url.search = new URLSearchParams({ objet: 'autre', defi: modele.defi, programme: lien }).toString();
      location.href = url.href;
    } }, tx('Envoyer ma solution', 'Send my solution')));
  });
}

afficher();
regenerer();
// le programme écrit dans la base : on ne le lance pas tout seul au chargement
if (modele.tables.length && /EXEC SQL/.test(zoneSource.value)) $('#sysout').textContent = tx('Cliquez sur Exécuter : le programme met à jour la base à chaque lancement.', 'Click Run: the program updates the database on every run.');
else executer();
