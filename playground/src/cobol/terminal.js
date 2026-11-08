import { Ecran3270, COLONNES } from './terminal3270.js';
import {
  exemple, exempleMiseAJour, exempleEtat, generer, fichiersEntree, fichiersSortie, longueurFichier, picStockage, operateurs, MAX_RUPTURES, analyserLignes,
  OPERATEURS_STRUCTURES, verifierTables, normaliser, zonesTraitees, UTILISATIONS, MOUVEMENT_DEFAUT, estMiseAJour, appelEdite, fonctionsGenerees, comparerCodes,
} from './generateur.js';
import { Programme } from './interpreteur.js';
import { ETAT_DEFAUT, listing } from './etats.js';
import { bandeauReception, montrerLien } from './partage.js';
import { ouvrirBase, sauverBase, synchroniser, contenuTable, viderTable, reinitialiserBase, formaterValeur } from './base.js';
import { EN, cle, t as tx } from './langue.js';

// oui / non des écrans : O en français, Y en anglais (les deux sont acceptés)
const OUI = tx('O', 'Y');
const estOui = (v) => ['O', 'Y'].includes(String(v ?? '').trim());

const CLE = cle('modele-v1');
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
const sauver = () => { try { localStorage.setItem(CLE, JSON.stringify(modele)); } catch {} };
// base SQLite : chargée à la demande, l'écran courant est redessiné quand elle est prête
let db = null, erreurBase = null;
function base(vues) {
  if (db || erreurBase) return db;
  ouvrirBase().then((d) => { db = d; synchroniser(db, modele); sauverBase(db); }, (e) => { erreurBase = e; })
    .then(() => { if (vues.includes(app.courant?.vue)) app.afficher(); });
  return null;
}
const T = () => modele.traitement;
const structDe = (code) => modele.structures.find((x) => x.code === code);
const appel = (u) => modele.appels.find((a) => a.utilisation === u);
// l'enregistrement lu par le traitement : celui du fichier édité (C) ou du principal (P)
const structureLue = () => structDe((appelEdite(modele) || appel('P') || modele.appels[0])?.structure) || modele.structures[0];
// enregistrement affiché par l'écran E (E code pour en choisir un autre) et fichier de l'écran D
let structCourante = null, appelDonnees = null;
const S = () => structDe(structCourante) || structureLue();
const appelsLus = () => modele.appels.filter((a) => a.utilisation !== 'R' && a.utilisation !== 'I');
const appelD = () => appelsLus().find((a) => a.code === appelDonnees) || appelsLus()[0];
const rub = (c) => modele.dictionnaire.find((d) => d.code === c);

// ---------- construction des écrans ----------
const lab = (l, c, texte, couleur, extra = {}) => ({ l, c, texte, protege: true, couleur, ...extra });
const zone = (l, c, long, nom, texte = '', extra = {}) => ({ l, c, long, nom, texte: String(texte ?? ''), ...extra });
const pad = (s, n) => String(s ?? '').padEnd(n).slice(0, n);
const lpad = (s, n) => String(s ?? '').padStart(n).slice(-n);

function cadre(id, titre, touches, choix = '') {
  const d = new Date();
  const h = `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getFullYear()).slice(2)} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  return [
    lab(0, 1, tx('ATELIER COBOL', 'COBOL WORKSHOP'), 'blanc'),
    lab(0, 16, pad(`${id}  ${titre}`, 48), 'turquoise'),
    lab(0, 79 - h.length, h, 'bleu'),
    lab(1, 1, tx('CHOIX ===>', 'OPTION ==>'), 'blanc'),
    zone(1, 12, 30, 'CHOIX', choix),
    lab(1, 44, pad(`PGM ${T().programme}  ${modele.appels.map((a) => `${a.code}/${a.utilisation}`).join(' ')}`, 35), 'bleu'),
    lab(23, 1, pad(touches, 78), 'turquoise'),
  ];
}

// ---------- application ----------
const tube = document.getElementById('tube');
const ecran = new Ecran3270(tube, { saisie: document.getElementById('capture'), surAttention: (t, v, m, cur) => app.attention(t, v, m, cur) });

const app = {
  pile: [],
  courant: null,
  ouvrir(vue, args = {}, empiler = true) {
    if (empiler && this.courant) this.pile.push(this.courant);
    this.courant = { vue, args, page: args.page || 0 };
    this.afficher();
  },
  afficher(message, curseur) {
    const v = VUES[this.courant.vue];
    const { champs, curseur: c0 } = v.dessin(this.courant);
    ecran.definir(champs, { curseur: curseur ?? c0, message: message || this.courant.message });
    this.courant.message = null;
  },
  retour() {
    const p = this.pile.pop();
    if (!p) return this.afficher({ texte: tx('DÉJÀ AU MENU. ÉCRIVEZ FIN DANS CHOIX POUR TOUT EFFACER ET REPARTIR.', 'ALREADY AT THE MENU. TYPE END IN OPTION TO CLEAR EVERYTHING AND START AGAIN.'), erreur: false });
    this.courant = p;
    this.afficher();
  },
  attention(touche, valeurs, modifies) {
    // le champ CHOIX est prioritaire, comme une commande
    const choix = (valeurs.CHOIX || '').trim();
    if (choix && touche === 'ENTREE') {
      const r = commande(choix);
      if (r) return;
    }
    const v = VUES[this.courant.vue];
    if (touche === 'PF3') return this.retour();
    if (touche === 'PF12') { this.pile = []; return this.ouvrir('menu', {}, false); }
    if (touche === 'PF1') return this.ouvrir('aide');
    if (touche === 'EFFACER') return this.afficher({ texte: tx('ÉCRAN EFFACÉ : RIEN N\'EST ENREGISTRÉ. ENTRÉE POUR RÉAFFICHER.', 'SCREEN CLEARED: NOTHING IS SAVED. ENTER TO REDISPLAY.'), erreur: false });
    const r = v.gerer ? v.gerer(touche, valeurs, modifies, this.courant) : null;
    if (r?.erreur) {
      // l'écran garde la saisie de l'utilisateur, le champ fautif est signalé
      this.afficher({ texte: r.erreur, erreur: true });
      for (const x of ecran.champs) if (x.nom && x.nom in valeurs && x.nom !== 'CHOIX') { x.valeur = valeurs[x.nom]; x.modifie = modifies.has(x.nom); }
      const f = r.champ && ecran.champs.find((x) => x.nom === r.champ);
      if (f) { f.erreur = true; ecran.curseur = f.pos; }
      ecran.dessiner();
      return;
    }
    if (r?.fait) return;
    this.afficher(r?.message);
  },
};

// les commandes anglaises sont des synonymes : END, END UPD, END REPORT, SHARE, HELP
const SYNONYMES = { END: 'FIN', SHARE: 'PARTAGE', HELP: 'AIDE', UPD: 'MAJ', REPORT: 'ETAT' };
function commande(texte) {
  const [mot, ...reste] = texte.toUpperCase().split(/\s+/).map((m) => SYNONYMES[m] || m);
  if (mot === 'K') etatCourant(true);
  const cibles = { K: 'etatD', '-D': 'etatD', '-L': 'etatL', '-CE': 'etatCE', M: 'menu', R: 'rubriques', E: 'enreg', C: 'appels', T: 'traitement', D: 'donnees', G: 'source', X: 'execution', P: 'specifique', S: 'sorties', B: 'base', Q: 'requetes', AIDE: 'aide', '?': 'aide' };
  if (mot === 'PARTAGE') {
    montrerLien(document.getElementById('lien-partage'), modele).then((copie) => app.afficher({ texte: copie ? tx('LIEN DE PARTAGE COPIÉ. IL EST AUSSI AFFICHÉ SOUS LE TERMINAL.', 'SHARE LINK COPIED. IT IS ALSO SHOWN BELOW THE TERMINAL.') : tx('LIEN DE PARTAGE AFFICHÉ SOUS LE TERMINAL.', 'SHARE LINK SHOWN BELOW THE TERMINAL.'), erreur: false }));
    return true;
  }
  if (mot === 'FIN') {
    modele = normaliser(structuredClone(reste[0] === 'MAJ' ? exempleMiseAJour : reste[0] === 'ETAT' ? exempleEtat : exemple)); structCourante = appelDonnees = null; sauver(); app.pile = [];
    reinitialiserBase().then(() => { if (db) { synchroniser(db, modele); sauverBase(db); } });
    app.ouvrir('menu', {}, false); app.afficher({ texte: reste[0] === 'MAJ' ? tx('EXEMPLE DE MISE À JOUR CHARGÉ, BASE VIDÉE.', 'UPDATE EXAMPLE LOADED, DATABASE EMPTIED.') : reste[0] === 'ETAT' ? tx('EXEMPLE D\'ÉTAT CHARGÉ (CHOIX K), BASE VIDÉE.', 'REPORT EXAMPLE LOADED (OPTION K), DATABASE EMPTIED.') : tx('MODÈLE REMIS À L\'EXEMPLE DE DÉPART, BASE VIDÉE. FIN MAJ : EXEMPLE DE MISE À JOUR.', 'MODEL RESET TO THE STARTING EXAMPLE, DATABASE EMPTIED. END UPD: UPDATE EXAMPLE.'), erreur: false });
    return true;
  }
  if (!cibles[mot]) { app.afficher({ texte: tx(`CHOIX ${mot} INCONNU. POSSIBLES : M R E C T P D K -L -CE -D S B Q G X PARTAGE AIDE FIN`, `UNKNOWN OPTION ${mot}. VALID: M R E C T P D K -L -CE -D S B Q G X SHARE HELP END`), erreur: true }, 'CHOIX'); return true; }
  if (mot === 'E' && reste[0]) {
    const code = reste[0].replace(/[^A-Z0-9-]/g, '').slice(0, 12);
    if (!/^[A-Z]/.test(code)) { app.afficher({ texte: tx('E CODE : UNE LETTRE EN TÊTE.', 'E CODE: MUST START WITH A LETTER.'), erreur: true }, 'CHOIX'); return true; }
    if (!structDe(code)) { modele.structures.push({ code, libelle: '', rubriques: [] }); sauver(); }
    structCourante = code;
  } else if (mot === 'E') structCourante = null;
  if (mot === 'D' && reste[0]) {
    if (!appelsLus().some((a) => a.code === reste[0])) { app.afficher({ texte: tx(`D ${reste[0]} : AUCUN FICHIER EN ENTRÉE DE CE CODE (CHOIX C).`, `D ${reste[0]}: NO INPUT FILE WITH THIS CODE (OPTION C).`), erreur: true }, 'CHOIX'); return true; }
    appelDonnees = reste[0];
  }
  app.ouvrir(cibles[mot], { filtre: reste.join(' ') });
  return true;
}

const VUES = {};

VUES.menu = {
  dessin() {
    const lignes = [
      ['R', tx('RUBRIQUES', 'DATA ELEMENTS'), tx('Dictionnaire des données : code, format, libellé', 'Data dictionary: code, format, label')],
      ['E', tx('ENREGISTREMENTS', 'RECORDS'), tx(`${modele.structures.map((x) => x.code).join(' ')} : ordre et positions (E code)`, `${modele.structures.map((x) => x.code).join(' ')}: order and positions (E code)`)],
      ['C', tx('APPEL FICHIERS', 'FILE CALLS'), `${modele.appels.map((a) => `${a.code} ${a.utilisation} ${a.fichier}`).join('  ')}`.slice(0, 49)],
      ['T', tx('TRAITEMENT', 'PROCESSING'), estMiseAJour(modele) ? tx(`Mise à jour ${T().programme} : titre du compte rendu`, `Update ${T().programme}: run report title`) : tx(`Batch ${T().programme} : filtres, colonnes, totaux`, `Batch ${T().programme}: filters, columns, totals`)],
      ['P', tx('TRAITEMENTS', 'STRUCT. CODE'), tx('Langage structuré : fonctions et opérateurs', 'Structured code: functions and operators')],
      ['D', tx('DONNÉES', 'DATA'), tx(`Lignes des fichiers en entrée (D code) : ${appelsLus().map((a) => a.code).join(' ')}`, `Lines of the input files (D code): ${appelsLus().map((a) => a.code).join(' ')}`)],
      ['K', tx('ÉTATS', 'REPORTS'), (() => { const x = etatCourant(); return x ? tx(`${x.et.code} : -D composition, -L libellés, -CE structures`, `${x.et.code}: -D layout, -L labels, -CE structures`) : tx('Libellés, structures, catégories (crée l\'état)', 'Labels, structures, categories (creates it)'); })()],
      ['S', tx('SORTIES', 'OUTPUTS'), tx('Fichiers produits par le traitement', 'Files produced by the program')],
      ['B', tx('BASE', 'DATABASE'), tx('Tables de la base ; Q pour interroger en SQL', 'Database tables; Q to query in SQL')],
      ['G', tx('GÉNÉRATION', 'GENERATION'), tx('Source COBOL généré, en consultation', 'Generated COBOL source, read only')],
      ['X', tx('EXÉCUTION', 'EXECUTION'), tx('Compile, lance le batch et affiche la SYSOUT', 'Compiles, runs the batch and shows the SYSOUT')],
    ];
    const champs = [...cadre('M00', tx('MENU GÉNÉRAL', 'MAIN MENU'), tx('PF1 AIDE  PF3 RETOUR  PF12 MENU  ENTRÉE VALIDER', 'PF1 HELP  PF3 BACK  PF12 MENU  ENTER CONFIRM'))];
    champs.push(lab(3, 6, tx('Tapez un code sur la ligne CHOIX ou en face d\'une ligne, puis Entrée.', 'Type a code on the OPTION line or next to a line, then Enter.'), 'bleu'));
    lignes.forEach(([c, t, d], i) => {
      const l = 5 + i + (i > 2 ? 1 : 0) + (i > 6 ? 1 : 0); // trois groupes : modèle, traitement, résultat
      champs.push(zone(l, 6, 1, `S${i}`), lab(l, 9, c, 'blanc'), lab(l, 12, pad(t, 16), 'turquoise'), lab(l, 29, pad(d, 49), 'bleu'));
    });
    const nbDonnees = modele.appels.reduce((n, a) => n + (a.donnees || []).length, 0);
    champs.push(lab(21, 6, tx(`Rubriques : ${modele.dictionnaire.length}   Lignes de données : ${nbDonnees}   Tables : ${modele.tables.length}`, `Data elements: ${modele.dictionnaire.length}   Data lines: ${nbDonnees}   Tables: ${modele.tables.length}`), 'vert'));
    return { champs, curseur: 'CHOIX' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const codes = ['R', 'E', 'C', 'T', 'P', 'D', 'K', 'S', 'B', 'G', 'X'];
    const i = codes.findIndex((_, k) => (v[`S${k}`] || '').trim());
    if (i >= 0) { commande(codes[i]); return { fait: true }; }
    return { message: { texte: tx('INDIQUEZ UN CHOIX.', 'ENTER AN OPTION.'), erreur: false } };
  },
};

// ----- rubriques : liste modifiable, codes action en début de ligne -----
const PAR_PAGE = 14;
VUES.rubriques = {
  dessin(ctx) {
    const champs = [...cadre('R01', tx('RUBRIQUES DU DICTIONNAIRE', 'DICTIONARY DATA ELEMENTS'), tx('PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  ENTRÉE VALIDER   ACTION : S SUPPR  D DUPLIQ', 'PF1 HELP  PF3 BACK  PF7/8 PAGES  ENTER CONFIRM   ACTION: S DELETE  D DUPLICATE'))];
    champs.push(lab(3, 1, tx('A CODE         LIBELLÉ                   T  LONG DÉC S  PIC', 'A CODE         LABEL                     T  LEN  DEC S  PIC'), 'blanc'));
    const liste = modele.dictionnaire;
    const debut = ctx.page * PAR_PAGE;
    for (let i = 0; i < PAR_PAGE; i++) {
      const l = 4 + i, d = liste[debut + i];
      champs.push(
        zone(l, 1, 1, `A${i}`),
        zone(l, 3, 12, `C${i}`, d?.code),
        zone(l, 16, 25, `L${i}`, d?.libelle, { majuscules: false }),
        zone(l, 42, 1, `T${i}`, d?.type),
        zone(l, 45, 4, `G${i}`, d ? lpad(d.longueur, 4) : '', { num: true }),
        zone(l, 50, 3, `D${i}`, d?.type === '9' ? lpad(d.decimales || 0, 3) : '', { num: true }),
        zone(l, 54, 1, `S${i}`, d?.type === '9' ? (d.signe ? OUI : 'N') : ''),
        lab(l, 57, d ? pad(picStockage(d), 22) : ' '.repeat(22), 'bleu'),
      );
    }
    const pages = Math.max(1, Math.ceil((liste.length + 1) / PAR_PAGE));
    champs.push(lab(19, 1, tx(`Page ${ctx.page + 1}/${pages}. Lignes vides : création. T = X (texte) ou 9 (nombre), S = signé O/N.`, `Page ${ctx.page + 1}/${pages}. Empty lines: create. T = X (text) or 9 (number), S = signed Y/N.`), 'bleu'));
    return { champs, curseur: liste.length - debut < PAR_PAGE && liste.length - debut >= 0 ? `C${Math.max(0, liste.length - debut)}` : 'A0' };
  },
  gerer(touche, v, mod, ctx) {
    const liste = modele.dictionnaire;
    if (touche === 'PF7') { if (ctx.page === 0) return { erreur: tx('DÉJÀ EN PREMIÈRE PAGE.', 'ALREADY ON THE FIRST PAGE.') }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * PAR_PAGE > liste.length) return { erreur: tx('DÉJÀ EN DERNIÈRE PAGE.', 'ALREADY ON THE LAST PAGE.') }; ctx.page++; return; }
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const debut = ctx.page * PAR_PAGE;
    const nouvelle = liste.map((d) => ({ ...d }));
    const supprimer = new Set();
    const ajouts = [];
    let changes = 0;
    for (let i = 0; i < PAR_PAGE; i++) {
      const touche = [`A${i}`, `C${i}`, `L${i}`, `T${i}`, `G${i}`, `D${i}`, `S${i}`].some((n) => mod.has(n));
      if (!touche) continue;
      const idx = debut + i;
      const existe = idx < liste.length;
      const action = (v[`A${i}`] || '').trim();
      const code = (v[`C${i}`] || '').trim().replace(/\s+/g, '');
      if (action === 'S') { if (existe) { supprimer.add(idx); changes++; } continue; }
      if (!code) { if (existe) return { erreur: tx('CODE OBLIGATOIRE. POUR SUPPRIMER, METTEZ S EN ACTION.', 'CODE REQUIRED. TO DELETE, PUT S IN ACTION.'), champ: `C${i}` }; continue; }
      if (!/^[A-Z][A-Z0-9-]{0,11}$/.test(code)) return { erreur: tx('CODE : UNE LETTRE PUIS LETTRES, CHIFFRES OU TIRETS (12 MAX).', 'CODE: A LETTER THEN LETTERS, DIGITS OR HYPHENS (12 MAX).'), champ: `C${i}` };
      const type = (v[`T${i}`] || 'X').trim() || 'X';
      if (!['X', '9'].includes(type)) return { erreur: tx('TYPE : X POUR DU TEXTE, 9 POUR UN NOMBRE.', 'TYPE: X FOR TEXT, 9 FOR A NUMBER.'), champ: `T${i}` };
      const longueur = Number((v[`G${i}`] || '').trim());
      if (!Number.isInteger(longueur) || longueur < 1 || longueur > (type === '9' ? 18 : 200)) return { erreur: tx(`LONGUEUR : DE 1 À ${type === '9' ? 18 : 200}.`, `LENGTH: FROM 1 TO ${type === '9' ? 18 : 200}.`), champ: `G${i}` };
      const dec = type === '9' ? Number((v[`D${i}`] || '0').trim() || 0) : 0;
      if (!Number.isInteger(dec) || dec < 0 || dec >= longueur) return { erreur: tx('DÉCIMALES : MOINS QUE LA LONGUEUR.', 'DECIMALS: LESS THAN THE LENGTH.'), champ: `D${i}` };
      const signe = (v[`S${i}`] || 'N').trim() || 'N';
      if (type === '9' && !['O', 'Y', 'N'].includes(signe)) return { erreur: tx('SIGNÉ : O OU N.', 'SIGNED: Y OR N.'), champ: `S${i}` };
      const r = { code, libelle: v[`L${i}`] || '', type, longueur };
      if (type === '9') { if (dec) r.decimales = dec; if (estOui(signe)) r.signe = true; }
      if (action === 'D' && existe) { ajouts.push({ ...r, code: dupliquerCode(code, nouvelle, ajouts) }); changes++; }
      if (existe) {
        const ancien = liste[idx].code;
        if (ancien !== code) {
          if (liste.some((x, k) => k !== idx && x.code === code)) return { erreur: tx(`LA RUBRIQUE ${code} EXISTE DÉJÀ.`, `DATA ELEMENT ${code} ALREADY EXISTS.`), champ: `C${i}` };
          renommer(ancien, code);
        }
        nouvelle[idx] = r; changes++;
      } else {
        if ([...nouvelle, ...ajouts].some((x) => x.code === code)) return { erreur: tx(`LA RUBRIQUE ${code} EXISTE DÉJÀ.`, `DATA ELEMENT ${code} ALREADY EXISTS.`), champ: `C${i}` };
        ajouts.push(r); changes++;
      }
    }
    if (!changes) return { message: { texte: tx('AUCUNE MODIFICATION.', 'NO CHANGE.'), erreur: false } };
    modele.dictionnaire = [...nouvelle.filter((_, k) => !supprimer.has(k)), ...ajouts];
    nettoyer(); sauver();
    return { message: { texte: tx(`${changes} LIGNE(S) MISE(S) À JOUR.`, `${changes} LINE(S) UPDATED.`), erreur: false } };
  },
};
function dupliquerCode(code, ...listes) {
  let n = 2;
  const base = code.slice(0, 10);
  while (listes.flat().some((x) => x.code === `${base}${n}`)) n++;
  return `${base}${n}`;
}
function renommer(ancien, nouveau) {
  const t = T();
  const r = (c) => (c === ancien ? nouveau : c);
  for (const x of modele.structures) x.rubriques = x.rubriques.map(r);
  t.colonnes = t.colonnes.map(r); t.totaux = t.totaux.map(r);
  t.filtres.forEach((f) => (f.rubrique = r(f.rubrique)));
  for (const a of modele.appels) {
    a.argument = (a.argument || []).map(r);
    if (a.mouvement) a.mouvement.rubrique = r(a.mouvement.rubrique);
    (a.donnees || []).forEach((l) => { if (ancien in l) { l[nouveau] = l[ancien]; delete l[ancien]; } });
  }
  for (const so of modele.sorties) so.rubriques = so.rubriques.map(r);
  for (const tb of modele.tables) { tb.rubriques = tb.rubriques.map(r); tb.cle = tb.cle.map(r); }
}
function nettoyer() {
  const codes = new Set(modele.dictionnaire.map((d) => d.code));
  for (const x of modele.structures) x.rubriques = x.rubriques.filter((c, i, l) => codes.has(c) && l.indexOf(c) === i);
  const p = appel('P');
  for (const a of modele.appels) {
    if (a.utilisation === 'R') { a.structure = p?.structure; continue; }
    if (a.utilisation === 'I') { delete a.structure; a.argument = (a.argument || []).slice(0, 1); continue; }
    const dansA = new Set(structDe(a.structure)?.rubriques || []);
    a.argument = (a.argument || []).filter((c, i, l) => dansA.has(c) && l.indexOf(c) === i).slice(0, MAX_RUPTURES);
    if (a.utilisation === 'C') a.rupture = Math.min(Number(a.rupture || 0), a.argument.length);
    if (a.mouvement && !dansA.has(a.mouvement.rubrique)) a.mouvement.rubrique = '';
  }
  const t = T();
  const dansS = new Set(structureLue()?.rubriques || []);
  t.colonnes = (t.colonnes || []).filter((c) => dansS.has(c));
  t.totaux = (t.totaux || []).filter((c) => dansS.has(c) && rub(c).type === '9');
  t.filtres = (t.filtres || []).filter((f) => dansS.has(f.rubrique));
  for (const tb of modele.tables) { tb.rubriques = tb.rubriques.filter((c) => codes.has(c)); tb.cle = tb.cle.filter((c) => tb.rubriques.includes(c)); }
}

// ----- enregistrement : lignes numérotées, on renumérote pour réordonner -----
VUES.enreg = {
  dessin() {
    const champs = [...cadre('E01', tx(`COMPOSITION DE L'ENREGISTREMENT`, 'RECORD LAYOUT'), tx('PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER   ACTION : S SUPPR   NUMÉRO : DÉPLACER', 'PF1 HELP  PF3 BACK  ENTER CONFIRM   ACTION: S DELETE   NUMBER: MOVE'))];
    champs.push(lab(3, 1, tx('CODE ENREG.', 'RECORD CODE'), 'blanc'), zone(3, 13, 12, 'ENR', S().code), lab(3, 27, tx('LIBELLÉ', 'LABEL'), 'blanc'), zone(3, 35, 30, 'LIB', S().libelle, { majuscules: false }));
    champs.push(lab(5, 1, tx('A NUM RUBRIQUE        LIBELLÉ                  PIC                POSITIONS', 'A NUM ELEMENT         LABEL                    PIC                POSITIONS'), 'blanc'));
    let pos = 1;
    const n = S().rubriques.length;
    for (let i = 0; i < 15; i++) {
      const l = 6 + i, c = S().rubriques[i], d = c && rub(c);
      let info = '';
      if (d) { const lg = longueurFichier(d); info = `${pad(d.libelle, 24)} ${pad(picStockage(d) + (d.signe ? ' S' : ''), 18)} ${lpad(pos, 3)} ${tx('À', '-')} ${lpad(pos + lg - 1, 3)}`; pos += lg; }
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 3, `N${i}`, d ? String((i + 1) * 10).padStart(3, '0') : '', { num: true }), zone(l, 7, 12, `R${i}`, c || ''), lab(l, 23, info, 'bleu'));
    }
    const lu = modele.appels.filter((a) => a.structure === S().code && a.utilisation !== 'R').map((a) => a.code);
    const autresE = modele.structures.filter((x) => x !== S()).map((x) => `E ${x.code}`).join(', ');
    champs.push(lab(21, 1, pad(tx(`${n} rubrique(s), ${pos - 1} car. ${lu.length ? `Lu par ${lu.join(' ')}.` : 'Aucun fichier ne le lit.'} Autres : ${autresE || 'E code pour en créer un'}`, `${n} element(s), ${pos - 1} char. ${lu.length ? `Read by ${lu.join(' ')}.` : 'No file reads it.'} Others: ${autresE || 'E code to create one'}`), 78), 'bleu'));
    return { champs, curseur: n < 15 ? `N${n}` : 'A0' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const s = S();
    const enr = (v.ENR || '').trim();
    if (!/^[A-Z][A-Z0-9-]{0,11}$/.test(enr)) return { erreur: tx('CODE ENREGISTREMENT INVALIDE.', 'INVALID RECORD CODE.'), champ: 'ENR' };
    if (enr !== s.code && structDe(enr)) return { erreur: tx(`L'ENREGISTREMENT ${enr} EXISTE DÉJÀ : CHOIX E ${enr} POUR L'AFFICHER.`, `RECORD ${enr} ALREADY EXISTS: OPTION E ${enr} TO SHOW IT.`), champ: 'ENR' };
    const lignes = [];
    for (let i = 0; i < 15; i++) {
      const code = (v[`R${i}`] || '').trim();
      const action = (v[`A${i}`] || '').trim();
      if (!code || action === 'S') continue;
      if (!rub(code)) return { erreur: tx(`RUBRIQUE ${code} ABSENTE DU DICTIONNAIRE (CHOIX R POUR LA CRÉER).`, `DATA ELEMENT ${code} NOT IN THE DICTIONARY (OPTION R TO CREATE IT).`), champ: `R${i}` };
      const num = Number((v[`N${i}`] || '').trim() || (i + 1) * 10);
      if (!Number.isFinite(num)) return { erreur: tx('NUMÉRO INVALIDE.', 'INVALID NUMBER.'), champ: `N${i}` };
      if (lignes.some((x) => x.code === code)) return { erreur: tx(`RUBRIQUE ${code} EN DOUBLE.`, `DATA ELEMENT ${code} APPEARS TWICE.`), champ: `R${i}` };
      lignes.push({ num, i, code });
    }
    lignes.sort((a, b) => a.num - b.num || a.i - b.i);
    const avant = JSON.stringify([s.code, s.libelle, s.rubriques]);
    for (const a of modele.appels) if (a.structure === s.code) a.structure = enr;
    if (structCourante === s.code) structCourante = enr;
    s.code = enr; s.libelle = v.LIB || '';
    s.rubriques = lignes.map((x) => x.code);
    nettoyer(); sauver();
    return { message: { texte: avant === JSON.stringify([s.code, s.libelle, s.rubriques]) ? tx('AUCUNE MODIFICATION.', 'NO CHANGE.') : tx('ENREGISTREMENT MIS À JOUR, RENUMÉROTÉ DE 10 EN 10. RUPTURES : CHOIX C.', 'RECORD UPDATED, RENUMBERED BY 10. CONTROL BREAKS: OPTION C.'), erreur: false } };
  },
};

// ----- appel des fichiers : une ligne par fichier, comme les lignes -CD -----
const NB_APPELS = 4;
VUES.appels = {
  dessin() {
    const champs = [...cadre('C01', tx('APPEL DES FICHIERS DU PROGRAMME', 'FILES CALLED BY THE PROGRAM'), tx('PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER   ACTION : S SUPPRIMER', 'PF1 HELP  PF3 BACK  ENTER CONFIRM   ACTION: S DELETE'))];
    champs.push(lab(3, 1, tx('A CD U ENREG.       FICHIER        ARGUMENT (NIVEAUX 1 2 3)            NR', 'A CD U RECORD       FILE           ARGUMENT (LEVELS 1 2 3)             NR'), 'blanc'));
    for (let i = 0; i < NB_APPELS; i++) {
      const a = modele.appels[i], l = 4 + i * 3;
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 2, `C${i}`, a?.code), zone(l, 6, 1, `U${i}`, a?.utilisation),
        zone(l, 8, 12, `E${i}`, a?.utilisation === 'R' || a?.utilisation === 'I' ? '' : a?.structure), zone(l, 21, 14, `F${i}`, a?.fichier),
        zone(l, 36, 36, `G${i}`, (a?.argument || []).join(' ')),
        zone(l, 73, 1, `N${i}`, a ? String(a.utilisation === 'C' ? a.rupture || 0 : a.utilisation === 'R' || a.utilisation === 'I' ? '' : a.synchro || (a.argument || []).length) : '', { num: true }));
      const mo = a?.mouvement;
      if (a?.utilisation === 'M') {
        champs.push(lab(l + 1, 8, tx('CODE MVT', 'TRN CODE'), 'blanc'), zone(l + 1, 17, 12, `K${i}`, mo?.rubrique), lab(l + 1, 31, tx('CRÉATION', 'CREATE'), 'blanc'), zone(l + 1, 40, 1, `KC${i}`, mo?.creation),
          lab(l + 1, 43, tx('MODIF.', 'MODIFY'), 'blanc'), zone(l + 1, 50, 1, `KM${i}`, mo?.modification), lab(l + 1, 53, tx('ANNUL.', 'DELETE'), 'blanc'), zone(l + 1, 60, 1, `KA${i}`, mo?.annulation));
      } else if (a) {
        champs.push(lab(l + 1, 8, pad(a.utilisation === 'R' ? tx(`Résultat : même enregistrement que le principal (${appel('P')?.structure || '?'})`, `Result: same record as the master (${appel('P')?.structure || '?'})`) : a.utilisation === 'I' ? tx(`Impression de l'état ${a.code}${a.argument?.[0] || '?'} (argument : sa lettre), décrit par CHOIX K`, `Print of report ${a.code}${a.argument?.[0] || '?'} (argument: its letter), described by OPTION K`) : `${UTILISATIONS[a.utilisation]}`, 70), 'bleu'));
      }
    }
    const complet = tx('Programme complet.', 'Program complete.');
    let etat = complet;
    try { generer(modele); } catch (e) { etat = e.message; }
    champs.push(
      lab(16, 1, tx('U : C consulté  P principal  M mouvements  R résultat  I impression (état)', 'U: C consulted  P master  M transactions  R result  I print (report)'), 'bleu'),
      lab(17, 1, tx('NR : niveaux de rupture (C) ou de synchronisation (P et M, même nombre).', 'NR: control break levels (C) or matching levels (P and M, same number).'), 'bleu'),
      lab(18, 1, tx('Zones : 1-CD00-RUB traité, CD00-RUB lu d\'avance, CD-FT fin, CD-CFn, CD-DEn.', 'Fields: 1-CD00-ELEM current, CD00-ELEM read ahead, CD-FT end, CD-CFn, CD-DEn.'), 'bleu'),
      lab(19, 1, tx('Mouvement : une rubrique vide ne modifie rien. FIN MAJ : exemple complet.', 'Transaction: an empty element changes nothing. END UPD: full example.'), 'bleu'),
      lab(21, 1, pad(etat.toUpperCase(), 78), etat === complet ? 'vert' : 'jaune'),
    );
    return { champs, curseur: 'C0' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const liste = [];
    for (let i = 0; i < NB_APPELS; i++) {
      const a = (v[`A${i}`] || '').trim(), code = (v[`C${i}`] || '').trim(), u = (v[`U${i}`] || '').trim();
      const enr = (v[`E${i}`] || '').trim(), fic = (v[`F${i}`] || '').trim(), arg = (v[`G${i}`] || '').trim().split(/\s+/).filter(Boolean);
      const n = (v[`N${i}`] || '').trim();
      if (a === 'S' || (!code && !u && !enr && !fic && !arg.length)) continue;
      if (a) return { erreur: tx('ACTION : S POUR SUPPRIMER LA LIGNE.', 'ACTION: S TO DELETE THE LINE.'), champ: `A${i}` };
      if (!/^[A-Z][A-Z0-9]$/.test(code)) return { erreur: tx('CODE : 2 CARACTÈRES, UNE LETTRE EN TÊTE (EX. FA).', 'CODE: 2 CHARACTERS, STARTING WITH A LETTER (E.G. FA).'), champ: `C${i}` };
      if (!(u in UTILISATIONS)) return { erreur: tx('UTILISATION : C, P, M, R OU I.', 'USAGE: C, P, M, R OR I.'), champ: `U${i}` };
      if (u === 'I' && (arg.length !== 1 || !/^[A-Z0-9]$/.test(arg[0]))) return { erreur: tx('IMPRESSION : L\'ARGUMENT EST LA LETTRE DE L\'ÉTAT (UN CARACTÈRE).', 'PRINT: THE ARGUMENT IS THE REPORT LETTER (ONE CHARACTER).'), champ: `G${i}` };
      if (u !== 'R' && u !== 'I' && !structDe(enr)) return { erreur: tx(`ENREGISTREMENT ${enr || '?'} INCONNU (CHOIX E ${enr || 'CODE'} POUR LE CRÉER).`, `UNKNOWN RECORD ${enr || '?'} (OPTION E ${enr || 'CODE'} TO CREATE IT).`), champ: `E${i}` };
      if (!/^[A-Z0-9][A-Z0-9.]*$/.test(fic)) return { erreur: tx('NOM DE FICHIER INVALIDE (EX. CLIENTS.DAT).', 'INVALID FILE NAME (E.G. CUSTOMERS.DAT).'), champ: `F${i}` };
      const hors = arg.find((c) => !structDe(enr)?.rubriques.includes(c));
      if (u !== 'R' && u !== 'I' && hors) return { erreur: tx(`ARGUMENT : ${hors} N'EST PAS DANS L'ENREGISTREMENT ${enr}.`, `ARGUMENT: ${hors} IS NOT IN RECORD ${enr}.`), champ: `G${i}` };
      if (arg.length > MAX_RUPTURES) return { erreur: tx(`ARGUMENT : ${MAX_RUPTURES} RUBRIQUES AU PLUS.`, `ARGUMENT: ${MAX_RUPTURES} DATA ELEMENTS AT MOST.`), champ: `G${i}` };
      const niv = n === '' ? arg.length : Number(n);
      if (u !== 'R' && u !== 'I' && !(niv >= 0 && niv <= arg.length)) return { erreur: tx('NR : DE 0 AU NOMBRE DE RUBRIQUES DE L\'ARGUMENT.', 'NR: FROM 0 TO THE NUMBER OF ARGUMENT ELEMENTS.'), champ: `N${i}` };
      const ancien = modele.appels.find((x) => x.code === code) || modele.appels[i] || {};
      const x = { code, utilisation: u, fichier: fic, donnees: ancien.donnees || [] };
      if (u === 'I') {
        x.argument = arg;
        const avant = modele.appels.find((y) => y.code === code && y.utilisation === 'I');
        const et = avant && modele.etats.find((y) => y.code === code + (avant.argument?.[0] || ''));
        if (et) et.code = code + arg[0];
      } else if (u !== 'R') { x.structure = enr; x.argument = arg; }
      if (u === 'C') x.rupture = niv;
      if (u === 'P' || u === 'M') x.synchro = niv;
      if (u === 'M') {
        const k = (v[`K${i}`] || ancien.mouvement?.rubrique || '').trim();
        x.mouvement = { rubrique: k, creation: (v[`KC${i}`] || ancien.mouvement?.creation || MOUVEMENT_DEFAUT.creation).trim(), modification: (v[`KM${i}`] || ancien.mouvement?.modification || MOUVEMENT_DEFAUT.modification).trim(), annulation: (v[`KA${i}`] || ancien.mouvement?.annulation || MOUVEMENT_DEFAUT.annulation).trim() };
      }
      liste.push(x);
    }
    if (!liste.length) return { erreur: tx('AU MOINS UN FICHIER EN ENTRÉE.', 'AT LEAST ONE INPUT FILE.'), champ: 'C0' };
    if (new Set(liste.map((x) => x.code)).size !== liste.length) return { erreur: tx('DEUX FICHIERS ONT LE MÊME CODE.', 'TWO FILES HAVE THE SAME CODE.') };
    modele.appels = liste;
    nettoyer(); sauver();
    try { generer(modele); } catch (e) { return { message: { texte: tx(`ENREGISTRÉ. À COMPLÉTER : ${e.message}`, `SAVED. STILL TO DO: ${e.message}`).toUpperCase().slice(0, 78), erreur: true } }; }
    return { message: { texte: tx(`${liste.length} FICHIER(S) APPELÉ(S). CHOIX D POUR LES DONNÉES, X POUR EXÉCUTER.`, `${liste.length} FILE(S) CALLED. OPTION D FOR THE DATA, X TO RUN.`), erreur: false } };
  },
};

// ----- traitement : formulaire -----
VUES.traitement = {
  dessin() {
    const t = T();
    const champs = [...cadre('T01', tx('DESCRIPTION DU TRAITEMENT', 'PROCESSING DESCRIPTION'), tx('PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER', 'PF1 HELP  PF3 BACK  ENTER CONFIRM'))];
    if (estMiseAJour(modele)) {
      champs.push(lab(3, 1, tx('PROGRAMME ......', 'PROGRAM ........'), 'blanc'), zone(3, 18, 8, 'PGM', t.programme),
        lab(4, 1, tx('TITRE DU C.R. ..', 'REPORT TITLE ...'), 'blanc'), zone(4, 18, 50, 'TIT', t.titre),
        lab(6, 1, tx('Programme de mise à jour : il lit le principal et les mouvements,', 'Update program: it reads the master and the transactions,'), 'bleu'),
        lab(7, 1, tx('les synchronise sur leur argument, applique créations, modifications et', 'matches them on their argument, applies creations, changes and'), 'bleu'),
        lab(8, 1, tx('annulations, écrit le résultat et un compte rendu (mouvements rejetés).', 'deletions, writes the result and a run report (rejected transactions).'), 'bleu'),
        lab(10, 1, tx(`Fonctions générées : ${fonctionsGenerees(modele).map((f) => `F${f}`).join(' ')}`, `Generated functions: ${fonctionsGenerees(modele).map((f) => `F${f}`).join(' ')}`), 'turquoise'));
      return { champs, curseur: 'PGM' };
    }
    champs.push(
      lab(3, 1, tx('PROGRAMME ......', 'PROGRAM ........'), 'blanc'), zone(3, 18, 8, 'PGM', t.programme),
      lab(3, 30, pad(tx(`FICHIER LU : ${(appelEdite(modele) || appel('P'))?.code || '?'} ${(appelEdite(modele) || appel('P'))?.fichier || ''} (CHOIX C)`, `FILE READ: ${(appelEdite(modele) || appel('P'))?.code || '?'} ${(appelEdite(modele) || appel('P'))?.fichier || ''} (OPTION C)`), 48), 'turquoise'),
      lab(4, 1, tx('TITRE DE L\'ÉTAT ', 'LISTING TITLE ..'), 'blanc'), zone(4, 18, 50, 'TIT', t.titre),
      lab(6, 1, tx('FILTRES (TOUS VRAIS)        RUBRIQUE     OPÉR.   VALEUR', 'FILTERS (ALL TRUE)          ELEMENT      OPER.   VALUE'), 'blanc'),
    );
    for (let i = 0; i < 4; i++) {
      const f = t.filtres[i];
      champs.push(lab(7 + i, 1, `  ${i + 1}`, 'bleu'), zone(7 + i, 29, 12, `FR${i}`, f?.rubrique), zone(7 + i, 42, 5, `FO${i}`, f?.op), zone(7 + i, 50, 20, `FV${i}`, f?.valeur));
    }
    champs.push(
      lab(11, 6, tx(`Opérateurs : ${operateurs.join('  ')}`, `Operators: ${operateurs.join('  ')}`), 'bleu'),
      lab(13, 1, tx('RUPTURES .......', 'BREAKS .........'), 'blanc'), lab(13, 18, pad((() => { const c = appelEdite(modele); const r = c ? c.argument.slice(0, c.rupture || 0) : []; return (r.length ? r.map((x, i) => `${i + 1}:${x}`).join('  ') : tx('aucune', 'none')) + tx('   (CHOIX C, argument du fichier)', '   (OPTION C, file argument)'); })(), 60), 'turquoise'),
      lab(14, 1, tx('COLONNES .......', 'COLUMNS ........'), 'blanc'), zone(14, 18, 60, 'COL', t.colonnes.join(' ')),
      lab(15, 1, tx('TOTAUX .........', 'TOTALS .........'), 'blanc'), zone(15, 18, 60, 'TOT', t.totaux.join(' ')),
      lab(16, 1, tx('DÉTAIL (O/N) ...', 'DETAIL (Y/N) ...'), 'blanc'), zone(16, 18, 1, 'DET', t.detail ? OUI : 'N'),
      lab(16, 30, tx('COMPTER (O/N) ..', 'COUNT (Y/N) ....'), 'blanc'), zone(16, 47, 1, 'CPT', t.compter ? OUI : 'N'),
      lab(18, 1, tx(`Rubriques de ${structureLue().code} : ${structureLue().rubriques.join(' ')}`, `Elements of ${structureLue().code}: ${structureLue().rubriques.join(' ')}`).slice(0, 78), 'bleu'),
    );
    return { champs, curseur: 'PGM' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const dansS = (c) => structureLue().rubriques.includes(c);
    const pgm = (v.PGM || '').trim();
    if (!/^[A-Z][A-Z0-9]{0,7}$/.test(pgm)) return { erreur: tx('PROGRAMME : UNE LETTRE PUIS 7 LETTRES OU CHIFFRES MAX.', 'PROGRAM: A LETTER THEN 7 LETTERS OR DIGITS MAX.'), champ: 'PGM' };
    if (estMiseAJour(modele)) {
      Object.assign(T(), { programme: pgm, titre: v.TIT || '' });
      sauver();
      return { message: { texte: tx('TRAITEMENT ENREGISTRÉ. CHOIX G POUR VOIR LE SOURCE, X POUR EXÉCUTER.', 'PROCESSING SAVED. OPTION G TO SEE THE SOURCE, X TO RUN.'), erreur: false } };
    }
    const filtres = [];
    for (let i = 0; i < 4; i++) {
      const r = (v[`FR${i}`] || '').trim(), o = (v[`FO${i}`] || '').trim().replace(/\s+/g, ' '), val = (v[`FV${i}`] || '').trim();
      if (!r && !o && !val) continue;
      if (!dansS(r)) return { erreur: tx(`RUBRIQUE ${r || '?'} ABSENTE DE L'ENREGISTREMENT.`, `DATA ELEMENT ${r || '?'} IS NOT IN THE RECORD.`), champ: `FR${i}` };
      if (!operateurs.includes(o)) return { erreur: tx(`OPÉRATEUR : ${operateurs.join(' ')}`, `OPERATOR: ${operateurs.join(' ')}`), champ: `FO${i}` };
      if (rub(r).type === '9' && !/^[+-]?\d+([.,]\d+)?$/.test(val)) return { erreur: tx(`${r} EST NUMÉRIQUE : VALEUR NUMÉRIQUE ATTENDUE.`, `${r} IS NUMERIC: NUMERIC VALUE EXPECTED.`), champ: `FV${i}` };
      filtres.push({ rubrique: r, op: o, valeur: val });
    }
    const col = (v.COL || '').trim().split(/\s+/).filter(Boolean);
    const mauvaise = col.find((c) => !dansS(c));
    if (mauvaise) return { erreur: tx(`COLONNE ${mauvaise} ABSENTE DE L'ENREGISTREMENT.`, `COLUMN ${mauvaise} IS NOT IN THE RECORD.`), champ: 'COL' };
    if (!col.length) return { erreur: tx('AU MOINS UNE COLONNE.', 'AT LEAST ONE COLUMN.'), champ: 'COL' };
    const tot = (v.TOT || '').trim().split(/\s+/).filter(Boolean);
    const mauvaisT = tot.find((c) => !dansS(c) || rub(c).type !== '9');
    if (mauvaisT) return { erreur: tx(`TOTAL ${mauvaisT} : RUBRIQUE NUMÉRIQUE DE L'ENREGISTREMENT ATTENDUE.`, `TOTAL ${mauvaisT}: A NUMERIC ELEMENT OF THE RECORD IS EXPECTED.`), champ: 'TOT' };
    for (const n of ['DET', 'CPT']) if (!['O', 'Y', 'N'].includes((v[n] || '').trim())) return { erreur: tx('RÉPONDEZ O OU N.', 'ANSWER Y OR N.'), champ: n };
    Object.assign(T(), { programme: pgm, titre: v.TIT || '', filtres, colonnes: col, totaux: tot, detail: estOui(v.DET), compter: estOui(v.CPT) });
    sauver();
    return { message: { texte: tx('TRAITEMENT ENREGISTRÉ. CHOIX G POUR VOIR LE SOURCE, X POUR EXÉCUTER.', 'PROCESSING SAVED. OPTION G TO SEE THE SOURCE, X TO RUN.'), erreur: false } };
  },
};

// ----- données : image brute des lignes du fichier, réglette des rubriques -----
const LARGEUR_DON = 76;
function versValeur(r, brut) {
  if (r.type === 'X') return brut.replace(/ +$/, '');
  let s = brut, neg = false;
  if (r.signe) { neg = s[0] === '-'; s = s.slice(1); }
  s = s.replace(/\D/g, '0').padStart(r.longueur, '0');
  const dec = r.decimales || 0;
  let t = (s.slice(0, s.length - dec).replace(/^0+(?=\d)/, '') || '0') + (dec ? '.' + s.slice(-dec) : '');
  return (neg ? '-' : '') + t;
}
VUES.donnees = {
  dessin(ctx) {
    const a = appelD();
    const champs = [...cadre('D01', tx(`DONNÉES DU FICHIER ${a?.code || ''} ${a?.fichier || ''}`, `DATA OF FILE ${a?.code || ''} ${a?.fichier || ''}`), tx('PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  ENTRÉE VALIDER   ACTION : S SUPPR  D DUPLIQ', 'PF1 HELP  PF3 BACK  PF7/8 PAGES  ENTER CONFIRM   ACTION: S DELETE  D DUPLICATE'))];
    if (!a) { champs.push(lab(3, 1, tx('Aucun fichier en entrée : CHOIX C pour en appeler un.', 'No input file: OPTION C to call one.'), 'jaune')); return { champs, curseur: 'CHOIX' }; }
    let image = [];
    try { image = fichiersEntree(modele)[a.fichier].split('\n').filter((l, i, t) => i < t.length - 1); } catch (e) { ctx.message = { texte: e.message.toUpperCase().slice(0, 78), erreur: true }; }
    // réglette : premier caractère de chaque rubrique
    let reg = '', pos = 0, noms = '';
    for (const c of structDe(a.structure).rubriques) {
      const lg = longueurFichier(rub(c));
      reg += '|' + '.'.repeat(Math.max(0, lg - 1));
      noms += pad(c, lg - 1) + ' ';
      pos += lg;
    }
    const trop = pos > LARGEUR_DON;
    champs.push(lab(3, 1, 'A', 'blanc'), lab(3, 3, pad(noms, LARGEUR_DON), 'turquoise'), lab(4, 3, pad(reg, LARGEUR_DON), 'bleu'));
    const debut = ctx.page * PAR_PAGE;
    for (let i = 0; i < PAR_PAGE; i++) {
      const l = 5 + i;
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, Math.min(pos, LARGEUR_DON), `L${i}`, image[debut + i] ?? '', { majuscules: false }));
    }
    champs.push(lab(20, 1, trop ? tx(`Ligne de ${pos} caractères : seules les ${LARGEUR_DON} premières sont modifiables ici.`, `Line of ${pos} characters: only the first ${LARGEUR_DON} can be edited here.`) : tx(`Page ${ctx.page + 1}. Saisissez chaque ligne telle qu'elle est dans le fichier. Signe en tête (+/-).`, `Page ${ctx.page + 1}. Type each line as it is in the file. Leading sign (+/-).`), trop ? 'jaune' : 'bleu'));
    const autres = appelsLus().filter((x) => x !== a).map((x) => `D ${x.code}`).join(', ');
    champs.push(lab(21, 1, pad(`${a.utilisation === 'M' ? tx('Mouvement : une rubrique laissée en blanc ne modifie rien. ', 'Transaction: an element left blank changes nothing. ') : ''}${a.utilisation !== 'C' ? tx('Trié sur l\'argument. ', 'Sorted on the argument. ') : ''}${autres ? tx(`Autres fichiers : ${autres}`, `Other files: ${autres}`) : ''}`, 78), 'bleu'));
    const n = Math.min(PAR_PAGE, Math.max(0, image.length - debut));
    return { champs, curseur: n < PAR_PAGE ? `L${n}` : 'A0' };
  },
  gerer(touche, v, mod, ctx) {
    const a = appelD();
    if (!a) return { erreur: tx('AUCUN FICHIER EN ENTRÉE (CHOIX C).', 'NO INPUT FILE (OPTION C).') };
    a.donnees ||= [];
    const nb = a.donnees.length;
    if (touche === 'PF7') { if (ctx.page === 0) return { erreur: tx('DÉJÀ EN PREMIÈRE PAGE.', 'ALREADY ON THE FIRST PAGE.') }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * PAR_PAGE > nb) return { erreur: tx('DÉJÀ EN DERNIÈRE PAGE.', 'ALREADY ON THE LAST PAGE.') }; ctx.page++; return; }
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const debut = ctx.page * PAR_PAGE;
    const garder = a.donnees.map((l) => ({ ...l }));
    const supprimer = new Set(), ajouts = [];
    let changes = 0;
    for (let i = 0; i < PAR_PAGE; i++) {
      if (!mod.has(`A${i}`) && !mod.has(`L${i}`)) continue;
      const idx = debut + i, existe = idx < nb;
      const action = (v[`A${i}`] || '').trim();
      const brut = v[`L${i}`] || '';
      if (action === 'S' || (!brut.trim() && existe)) { if (existe) { supprimer.add(idx); changes++; } continue; }
      if (!brut.trim()) continue;
      const ligne = {};
      let p = 0;
      for (const c of structDe(a.structure).rubriques) {
        const r = rub(c), lg = longueurFichier(r);
        const morceau = (brut.padEnd(p + lg)).slice(p, p + lg);
        if (a.utilisation === 'M' && !morceau.trim()) { ligne[c] = ''; p += lg; continue; }
        if (r.type === '9') {
          const corps = r.signe ? morceau.slice(1) : morceau;
          if (r.signe && !['+', '-', ' '].includes(morceau[0])) return { erreur: tx(`${c} (POSITION ${p + 1}) : SIGNE + OU - ATTENDU EN TÊTE.`, `${c} (POSITION ${p + 1}): LEADING + OR - SIGN EXPECTED.`), champ: `L${i}` };
          if (!/^[0-9 ]*$/.test(corps)) return { erreur: tx(`${c} (POSITIONS ${p + 1} À ${p + lg}) : CHIFFRES ATTENDUS, SANS VIRGULE.`, `${c} (POSITIONS ${p + 1} TO ${p + lg}): DIGITS EXPECTED, NO DECIMAL POINT.`), champ: `L${i}` };
        }
        ligne[c] = versValeur(r, morceau);
        p += lg;
      }
      if (existe) garder[idx] = ligne; else ajouts.push(ligne);
      if (action === 'D' && existe) ajouts.push({ ...ligne });
      changes++;
    }
    if (!changes) return { message: { texte: tx('AUCUNE MODIFICATION.', 'NO CHANGE.'), erreur: false } };
    a.donnees = [...garder.filter((_, k) => !supprimer.has(k)), ...ajouts];
    sauver();
    return { message: { texte: tx(`${changes} LIGNE(S) MISE(S) À JOUR. ${a.donnees.length} LIGNE(S) DANS LE FICHIER ${a.code}.`, `${changes} LINE(S) UPDATED. ${a.donnees.length} LINE(S) IN FILE ${a.code}.`), erreur: false } };
  },
};

// ----- consultation (source, sysout) : défilement PF7/PF8, PF10/PF11 à gauche/droite -----
function consultation(id, titre, obtenir) {
  return {
    dessin(ctx) {
      const champs = [...cadre(id, titre, tx('PF1 AIDE  PF3 RETOUR  PF5 RELANCER  PF7 HAUT  PF8 BAS  PF10/PF11 GAUCHE/DROITE', 'PF1 HELP  PF3 BACK  PF5 RERUN  PF7 UP  PF8 DOWN  PF10/PF11 LEFT/RIGHT'))];
      let lignes;
      try { lignes = obtenir(ctx); } catch (e) { lignes = []; ctx.message = { texte: e.message.toUpperCase().slice(0, 78), erreur: true }; }
      ctx.total = lignes.length;
      const haut = ctx.haut ||= 0, dec = ctx.dec ||= 0;
      champs.push(lab(2, 1, tx(`LIGNES ${String(haut + 1).padStart(4, '0')} À ${String(Math.min(lignes.length, haut + 18)).padStart(4, '0')} SUR ${String(lignes.length).padStart(4, '0')}   COLONNE ${dec + 1}`, `LINES ${String(haut + 1).padStart(4, '0')} TO ${String(Math.min(lignes.length, haut + 18)).padStart(4, '0')} OF ${String(lignes.length).padStart(4, '0')}   COLUMN ${dec + 1}`), 'bleu'));
      for (let i = 0; i < 19; i++) {
        const t = lignes[haut + i];
        if (t === undefined) { if (haut + i === lignes.length) champs.push(lab(3 + i, 1, tx('******************************* FIN DES DONNÉES ******************************', '******************************** BOTTOM OF DATA ******************************'), 'bleu')); continue; }
        const couleur = t.couleur || (/^.{6}\*/.test(t.texte) ? 'turquoise' : 'vert');
        champs.push(lab(3 + i, 1, pad(t.texte.slice(dec), 78), couleur));
      }
      return { champs, curseur: 'CHOIX' };
    },
    gerer(touche, v, mod, ctx) {
      const n = ctx.total || 0;
      if (touche === 'PF7') { if (!ctx.haut) return { erreur: tx('DÉJÀ EN HAUT.', 'ALREADY AT THE TOP.') }; ctx.haut = Math.max(0, ctx.haut - 18); return; }
      if (touche === 'PF8') { if (ctx.haut + 18 >= n) return { erreur: tx('DÉJÀ EN BAS.', 'ALREADY AT THE BOTTOM.') }; ctx.haut += 18; return; }
      if (touche === 'PF10') { ctx.dec = Math.max(0, ctx.dec - 40); return; }
      if (touche === 'PF11') { ctx.dec = Math.min(80, ctx.dec + 40); return; }
      if (touche === 'PF5') { ctx.resultat = null; return { message: { texte: tx('RELANCÉ.', 'RERUN.'), erreur: false } }; }
      if (touche === 'ENTREE') return;
      return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    },
  };
}
VUES.source = consultation('G01', tx('SOURCE COBOL GÉNÉRÉ', 'GENERATED COBOL SOURCE'), () => generer(modele).replace(/\n$/, '').split('\n').map((texte) => ({ texte })));
VUES.execution = consultation('X01', tx('EXÉCUTION DU BATCH', 'BATCH EXECUTION'), (ctx) => {
  const avecBase = (modele.tables || []).length > 0;
  if (avecBase && !db) {
    base(['execution']);
    if (erreurBase) throw new Error(tx(`BASE INDISPONIBLE : ${erreurBase.message}`, `DATABASE UNAVAILABLE: ${erreurBase.message}`));
    ctx.message = { texte: tx('CHARGEMENT DE LA BASE DE DONNÉES…', 'LOADING THE DATABASE…'), erreur: false };
    return [{ texte: tx('Ouverture de la base SQLite, quelques secondes au premier lancement.', 'Opening the SQLite database, a few seconds on the first run.'), couleur: 'bleu' }];
  }
  if (!ctx.resultat) {
    const source = generer(modele);
    const t0 = performance.now();
    try {
      const recreees = avecBase ? synchroniser(db, modele) : [];
      const r = new Programme(source).executer({ fichiers: fichiersEntree(modele), db });
      if (avecBase) sauverBase(db);
      const sql = r.sql.executees ? tx(` ${r.sql.executees} ORDRES SQL, ${r.sql.erreurs.length} EN ERREUR.`, ` ${r.sql.executees} SQL STATEMENTS, ${r.sql.erreurs.length} IN ERROR.`) : '';
      ctx.resultat = { ok: true, sortie: r.sortie, fichiers: r.fichiers, sql: r.sql, recreees, info: tx(`FIN NORMALE. ${r.instructions} INSTR. EN ${(performance.now() - t0).toFixed(0)} MS.${sql}`, `NORMAL END. ${r.instructions} STMTS IN ${(performance.now() - t0).toFixed(0)} MS.${sql}`) };
    } catch (e) {
      ctx.resultat = { ok: false, sortie: '', info: tx(`ARRÊT ANORMAL : ${e.message}${avecBase ? ' (BASE INCHANGÉE)' : ''}`, `ABNORMAL END: ${e.message}${avecBase ? ' (DATABASE UNCHANGED)' : ''}`).toUpperCase() };
    }
  }
  const r = ctx.resultat;
  ctx.message = { texte: r.info.slice(0, 78), erreur: !r.ok };
  const lignes = [{ texte: tx(`SYSOUT DE ${T().programme}`, `SYSOUT OF ${T().programme}`), couleur: 'blanc' }, { texte: '' }, ...r.sortie.replace(/\n$/, '').split('\n').map((texte) => ({ texte }))];
  for (const so of r.ok ? fichiersSortie(modele) : []) {
    if (so.etat) {
      lignes.push({ texte: '' }, { texte: tx(`ÉTAT IMPRIMÉ DANS ${so.fichier} (${so.code})`, `REPORT PRINTED TO ${so.fichier} (${so.code})`), couleur: 'blanc' });
      for (const x of listing(r.fichiers?.[so.fichier] || '').replace(/\n$/, '').split('\n')) lignes.push({ texte: x, couleur: x.startsWith('─') ? 'bleu' : undefined });
      continue;
    }
    const contenu = (r.fichiers?.[so.fichier] || '').replace(/\n$/, '');
    const enr = contenu ? contenu.split('\n') : [];
    const rubs = modele.sorties.find((x) => x.code === so.code)?.rubriques || structDe(appel('P')?.structure)?.rubriques || [];
    lignes.push({ texte: '' }, { texte: tx(`FICHIER ${so.fichier} (${so.code}) : ${enr.length} ENREGISTREMENT(S)`, `FILE ${so.fichier} (${so.code}): ${enr.length} RECORD(S)`), couleur: 'blanc' });
    lignes.push({ texte: rubs.map((c) => pad(c, longueurFichier(rub(c)) - 1) + ' ').join(''), couleur: 'turquoise' });
    for (const x of enr) lignes.push({ texte: x });
  }
  if (r.ok && db) {
    for (const e of r.sql?.erreurs || []) lignes.push({ texte: tx(`SQLCODE ${e.code} LIGNE ${e.ligne} : ${e.message}`, `SQLCODE ${e.code} LINE ${e.ligne}: ${e.message}`), couleur: 'rouge' });
    for (const nom of r.recreees || []) lignes.push({ texte: tx(`TABLE ${nom} RECRÉÉE (DESCRIPTION MODIFIÉE) : ELLE ÉTAIT VIDE AU DÉPART.`, `TABLE ${nom} RECREATED (DESCRIPTION CHANGED): IT WAS EMPTY AT THE START.`), couleur: 'jaune' });
    for (const tb of modele.tables || []) lignes.push({ texte: '' }, ...lignesTable(tb));
  }
  return lignes;
});

// contenu d'une table, une colonne par rubrique
function lignesTable(tb) {
  const { lignes } = contenuTable(db, tb);
  const larg = tb.rubriques.map((c) => { const r = rub(c); return Math.max(c.length, r ? r.longueur + (r.decimales ? 1 : 0) + (r.signe ? 1 : 0) : 8); });
  const cellule = (c, v, i) => { const r = rub(c), t = formaterValeur(r, v); return r?.type === '9' ? lpad(t, larg[i]) : pad(t, larg[i]); };
  return [
    { texte: tx(`TABLE ${tb.nom} (${tb.code}) : ${lignes.length} LIGNE(S), CLÉ ${tb.cle.join(' ')}`, `TABLE ${tb.nom} (${tb.code}): ${lignes.length} ROW(S), KEY ${tb.cle.join(' ')}`), couleur: 'blanc' },
    { texte: tb.rubriques.map((c, i) => (rub(c)?.type === '9' ? lpad(c, larg[i]) : pad(c, larg[i]))).join('  '), couleur: 'turquoise' },
    ...lignes.map((l) => ({ texte: l.map((v, i) => cellule(tb.rubriques[i], v, i)).join('  ') })),
  ];
}
VUES.contenu = consultation('B02', tx('CONTENU D\'UNE TABLE', 'TABLE CONTENT'), (ctx) => {
  const tb = (modele.tables || []).find((x) => x.code === ctx.args.table);
  if (!tb) throw new Error(tx('TABLE INCONNUE.', 'UNKNOWN TABLE.'));
  if (!db) {
    base(['contenu']);
    if (erreurBase) throw new Error(tx(`BASE INDISPONIBLE : ${erreurBase.message}`, `DATABASE UNAVAILABLE: ${erreurBase.message}`));
    return [{ texte: tx('Chargement de la base…', 'Loading the database…'), couleur: 'bleu' }];
  }
  return lignesTable(tb);
});

// ----- traitements en langage structuré -----
const LIGNES_P = 14;
VUES.specifique = {
  dessin(ctx) {
    const lignes = modele.lignes ||= [];
    const debut = ctx.page * LIGNES_P;
    const champs = [...cadre('P01', tx('TRAITEMENTS : LANGAGE STRUCTURÉ', 'PROCEDURES: STRUCTURED LANGUAGE'), tx('PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  ENTRÉE VALIDER   ACTION : S  I  D', 'PF1 HELP  PF3 BACK  PF7/8 PAGES  ENTER CONFIRM   ACTION: S  I  D'))];
    champs.push(lab(3, 1, tx('A FO SF OPE OPÉRANDES                            NV TC CONDITION', 'A FO SF OPE OPERANDS                             LV TC CONDITION'), 'blanc'));
    for (let i = 0; i < LIGNES_P; i++) {
      const l = lignes[debut + i] || {}, r = 4 + i;
      const titre = (l.ope || '').toUpperCase() === 'N';
      champs.push(
        zone(r, 1, 1, `A${i}`),
        zone(r, 3, 2, `F${i}`, l.fo),
        zone(r, 6, 2, `S${i}`, l.sf),
        zone(r, 9, 3, `O${i}`, l.ope),
        zone(r, 13, 36, `X${i}`, l.opes, { majuscules: false, couleur: titre ? 'blanc' : undefined }),
        zone(r, 50, 2, `N${i}`, l.niv, { num: true }),
        zone(r, 53, 2, `T${i}`, l.tc),
        zone(r, 56, 23, `C${i}`, l.cond, { majuscules: false }),
      );
    }
    let infos = '';
    try {
      const fns = analyserLignes(lignes);
      const gen = fonctionsGenerees(modele);
      const avant = (fo, x) => comparerCodes(fo, x) < 0;
      const court = (fo) => (gen.includes(fo) ? tx('générée', 'generated') : avant(fo, '01') ? tx('avant init', 'before init') : avant(fo, '05') ? 'init' : avant(fo, '20') ? tx('après lecture', 'after read')
        : avant(fo, '90') ? (gen.includes('8E') ? (avant(fo, '8E') ? tx('avant état', 'before report') : tx('après détail', 'after detail')) : tx('chaque tour', 'each cycle')) : tx('sur appel P', 'on P call'));
      infos = tx('Fonctions : ', 'Functions: ') + [...fns.keys()].map((fo) => `F${fo} ${court(fo)}`).join(', ');
    } catch (e) { infos = e.message; }
    champs.push(
      lab(18, 1, pad(tx('OPE : N titre  M déplacer  A ajouter  S soustraire  C calculer  P appeler', 'OPE: N title  M move  A add  S subtract  C compute  P perform'), 78), 'bleu'),
      lab(19, 1, pad(tx('      MES afficher  ECR écrire  SQL table accès  SCO SRO valider/annuler  COB *', '     MES display  ECR write  SQL table access  SCO SRO commit/rollback  COB *'), 78), 'bleu'),
      lab(20, 1, pad(tx(`FO générées ${fonctionsGenerees(modele).join(' ')} (sous-fonctions)  02-04 init  TC BL IT EL DW`, `FO generated ${fonctionsGenerees(modele).join(' ')} (sub-functions)  02-04 init  TC BL IT EL DW`), 78), 'bleu'),
      lab(21, 1, pad(infos || tx(`Page ${ctx.page + 1}. ${zonesTraitees(modele)[0] || 'FA00- traité'}, W- travail`, `Page ${ctx.page + 1}. ${zonesTraitees(modele)[0] || 'FA00- processed'}, W- working`), 78), 'turquoise'),
    );
    const n = Math.min(LIGNES_P, Math.max(0, lignes.length - debut));
    return { champs, curseur: n < LIGNES_P ? `F${n}` : 'A0' };
  },
  gerer(touche, v, mod, ctx) {
    const lignes = modele.lignes ||= [];
    if (touche === 'PF7') { if (ctx.page === 0) return { erreur: tx('DÉJÀ EN PREMIÈRE PAGE.', 'ALREADY ON THE FIRST PAGE.') }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * LIGNES_P > lignes.length) return { erreur: tx('DÉJÀ EN DERNIÈRE PAGE.', 'ALREADY ON THE LAST PAGE.') }; ctx.page++; return; }
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const debut = ctx.page * LIGNES_P;
    const avant = lignes.slice(0, debut), apres = lignes.slice(debut + LIGNES_P);
    const page = [];
    let change = false;
    for (let i = 0; i < LIGNES_P; i++) {
      const noms = ['A', 'F', 'S', 'O', 'X', 'N', 'T', 'C'].map((p) => `${p}${i}`);
      if (noms.some((x) => mod.has(x))) change = true;
      const a = (v[`A${i}`] || '').trim();
      const l = { fo: (v[`F${i}`] || '').trim(), sf: (v[`S${i}`] || '').trim(), ope: (v[`O${i}`] || '').trim(), opes: v[`X${i}`] || '' };
      const niv = (v[`N${i}`] || '').trim(), tc = (v[`T${i}`] || '').trim(), cond = v[`C${i}`] || '';
      if (a && !['S', 'I', 'D'].includes(a)) return { erreur: tx('ACTION : S SUPPRIMER, I INSÉRER UNE LIGNE APRÈS, D DUPLIQUER.', 'ACTION: S DELETE, I INSERT A LINE AFTER, D DUPLICATE.'), champ: `A${i}` };
      if (a === 'S') continue;
      const vide = !l.fo && !l.sf && !l.ope && !l.opes.trim() && !niv && !tc && !cond.trim();
      if (vide) { if (a === 'I') page.push({ fo: '', sf: '', ope: '', opes: '' }); continue; }
      if (!/^[0-9A-Z]{1,2}$/.test(l.fo)) return { erreur: tx('FO : CODE DE FONCTION SUR 2 CARACTÈRES (0A À 99).', 'FO: 2-CHARACTER FUNCTION CODE (0A TO 99).'), champ: `F${i}` };
      l.fo = l.fo.padStart(2, '0');
      if (fonctionsGenerees(modele).includes(l.fo) && l.sf === '99') return { erreur: tx(`F${l.fo}99 EST GÉNÉRÉE : PRENEZ UNE AUTRE SOUS-FONCTION.`, `F${l.fo}99 IS GENERATED: USE ANOTHER SUB-FUNCTION.`), champ: `S${i}` };
      if (!/^[0-9A-Z]{1,2}$/.test(l.sf)) return { erreur: tx('SF : CODE DE SOUS-FONCTION SUR 2 CARACTÈRES (10, 20...).', 'SF: 2-CHARACTER SUB-FUNCTION CODE (10, 20...).'), champ: `S${i}` };
      l.sf = l.sf.padStart(2, '0');
      const ope = l.ope.toUpperCase();
      if (!OPERATEURS_STRUCTURES.includes(ope)) return { erreur: `OPE : ${OPERATEURS_STRUCTURES.join(' ')}.`, champ: `O${i}` };
      l.ope = ope;
      if (ope === 'N') {
        l.niv = niv || '05'; l.tc = (tc || 'BL').toUpperCase(); l.cond = cond;
        if (!/^\d\d$/.test(l.niv)) return { erreur: tx('NV : NIVEAU SUR 2 CHIFFRES (05, 10, 15...).', 'LV: 2-DIGIT LEVEL (05, 10, 15...).'), champ: `N${i}` };
        if (!['BL', 'IT', 'EL', 'DW'].includes(l.tc)) return { erreur: tx('TC : BL (BLOC), IT (SI), EL (SINON), DW (TANT QUE).', 'TC: BL (BLOCK), IT (IF), EL (ELSE), DW (WHILE).'), champ: `T${i}` };
        if (['IT', 'DW'].includes(l.tc) && !cond.trim()) return { erreur: tx(`${l.tc} DEMANDE UNE CONDITION.`, `${l.tc} NEEDS A CONDITION.`), champ: `C${i}` };
      } else if (niv || tc || cond.trim()) return { erreur: tx('NIVEAU, TYPE ET CONDITION SE METTENT SUR LA LIGNE N DE LA SOUS-FONCTION.', 'LEVEL, TYPE AND CONDITION GO ON THE N LINE OF THE SUB-FUNCTION.'), champ: niv ? `N${i}` : tc ? `T${i}` : `C${i}` };
      page.push(l);
      if (a === 'D') page.push({ ...l });
      if (a === 'I') page.push({ fo: l.fo, sf: l.sf, ope: '', opes: '' });
    }
    if (!change) return { message: { texte: tx('AUCUNE MODIFICATION.', 'NO CHANGE.'), erreur: false } };
    // une ligne insérée reste vide tant qu'on ne l'a pas remplie : on la garde à l'écran
    const nouvelles = [...avant, ...page, ...apres];
    const reelles = nouvelles.filter((l) => l.ope || l.fo); // une ligne insérée garde sa place, même vide
    const ancien = modele.lignes;
    modele.lignes = reelles;
    try { analyserLignes(reelles); generer(modele); } catch (e) {
      modele.lignes = ancien;
      return { erreur: e.message.toUpperCase().slice(0, 78) };
    }
    // tri par fonction / sous-fonction, la ligne N en tête de sa sous-fonction
    modele.lignes = reelles.map((l, i) => ({ l, i })).sort((x, y) => comparerCodes(x.l.fo.padStart(2, '0'), y.l.fo.padStart(2, '0')) || comparerCodes(x.l.sf.padStart(2, '0'), y.l.sf.padStart(2, '0')) || (y.l.ope === 'N') - (x.l.ope === 'N') || !x.l.ope - !y.l.ope || x.i - y.i).map((x) => x.l);
    sauver();
    return { message: { texte: tx(`${modele.lignes.length} LIGNE(S) DE TRAITEMENT. CHOIX G POUR LE COBOL GÉNÉRÉ, X POUR EXÉCUTER.`, `${modele.lignes.length} PROCEDURE LINE(S). OPTION G FOR THE GENERATED COBOL, X TO RUN.`), erreur: false } };
  },
};

// ----- fichiers en sortie -----
VUES.sorties = {
  dessin() {
    const liste = modele.sorties ||= [];
    const champs = [...cadre('S01', tx('FICHIERS EN SORTIE', 'OUTPUT FILES'), tx('PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER   ACTION : S SUPPRIMER', 'PF1 HELP  PF3 BACK  ENTER CONFIRM   ACTION: S DELETE'))];
    champs.push(lab(3, 1, tx('A CO FICHIER          RUBRIQUES (DANS L\'ORDRE DE L\'ENREGISTREMENT)', 'A CO FILE             DATA ELEMENTS (IN RECORD ORDER)'), 'blanc'));
    for (let i = 0; i < 6; i++) {
      const so = liste[i], l = 4 + i * 2;
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 2, `C${i}`, so?.code), zone(l, 6, 16, `F${i}`, so?.fichier), zone(l, 23, 56, `R${i}`, so?.rubriques.join(' ')));
      if (so) {
        let lg = 0; try { lg = so.rubriques.reduce((s, c) => s + longueurFichier(rub(c)), 0); } catch {}
        champs.push(lab(l + 1, 6, pad(tx(`Enregistrement ${so.code}-ENR, ${lg} caractères. Rubriques : ${so.code}-${so.rubriques[0] || ''}…`, `Record ${so.code}-ENR, ${lg} characters. Elements: ${so.code}-${so.rubriques[0] || ''}…`), 72), 'bleu'));
      }
    }
    champs.push(
      lab(17, 1, tx('Pour écrire, dans le langage structuré (CHOIX P) :', 'To write, in the structured language (OPTION P):'), 'bleu'),
      lab(18, 3, pad(tx(`M ${(appelEdite(modele) || appel('P'))?.code || 'FA'}00-NOMCLI RL-NOMCLI  remplit une rubrique de l'enregistrement RL`, `M ${(appelEdite(modele) || appel('P'))?.code || 'FA'}00-CUSTNM RL-CUSTNM  fills an element of record RL`), 76), 'turquoise'),
      lab(19, 3, tx('ECR RL                  écrit l\'enregistrement dans le fichier', 'ECR RL                  writes the record to the file'), 'turquoise'),
      lab(20, 1, tx('Le contenu des fichiers s\'affiche après la SYSOUT (CHOIX X).', 'File contents are shown after the SYSOUT (OPTION X).'), 'bleu'),
    );
    return { champs, curseur: liste.length < 6 ? `C${liste.length}` : 'A0' };
  },
  gerer(touche, v, mod) {
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const liste = [];
    for (let i = 0; i < 6; i++) {
      const a = (v[`A${i}`] || '').trim(), code = (v[`C${i}`] || '').trim(), fichier = (v[`F${i}`] || '').trim();
      const rubs = (v[`R${i}`] || '').trim().split(/\s+/).filter(Boolean);
      if (a === 'S' || (!code && !fichier && !rubs.length)) continue;
      if (a) return { erreur: tx('ACTION : S POUR SUPPRIMER.', 'ACTION: S TO DELETE.'), champ: `A${i}` };
      if (!/^[A-Z][A-Z0-9]$/.test(code)) return { erreur: tx('CODE : 2 CARACTÈRES, UNE LETTRE EN TÊTE (EX. RL).', 'CODE: 2 CHARACTERS, STARTING WITH A LETTER (E.G. RL).'), champ: `C${i}` };
      if (liste.some((x) => x.code === code)) return { erreur: tx(`CODE ${code} DÉJÀ UTILISÉ.`, `CODE ${code} ALREADY USED.`), champ: `C${i}` };
      if (!/^[A-Z0-9][A-Z0-9.]*$/.test(fichier)) return { erreur: tx('NOM DE FICHIER INVALIDE (EX. RELANCES.DAT).', 'INVALID FILE NAME (E.G. REMINDERS.DAT).'), champ: `F${i}` };
      const inconnue = rubs.find((c) => !rub(c));
      if (inconnue) return { erreur: tx(`RUBRIQUE ${inconnue} ABSENTE DU DICTIONNAIRE.`, `DATA ELEMENT ${inconnue} IS NOT IN THE DICTIONARY.`), champ: `R${i}` };
      if (!rubs.length) return { erreur: tx('AU MOINS UNE RUBRIQUE.', 'AT LEAST ONE DATA ELEMENT.'), champ: `R${i}` };
      liste.push({ code, fichier, rubriques: rubs });
    }
    const ancien = modele.sorties;
    modele.sorties = liste;
    try { generer(modele); } catch (e) { modele.sorties = ancien; return { erreur: e.message.toUpperCase().slice(0, 78) }; }
    sauver();
    return { message: { texte: tx(`${liste.length} FICHIER(S) EN SORTIE.`, `${liste.length} OUTPUT FILE(S).`), erreur: false } };
  },
};


// ----- états : -D composition (caractéristiques et catégories), -L libellés, -CE structures -----
// L'état courant : celui du premier fichier d'impression (I) ; K en crée un s'il n'y en a pas.
function etatCourant(creer = false) {
  let a = modele.appels.find((x) => x.utilisation === 'I');
  if (!a && creer) {
    const pris = new Set(modele.appels.map((x) => x.code));
    a = { code: ['ET', 'IM', 'EA'].find((c) => !pris.has(c)) || 'EZ', utilisation: 'I', fichier: tx('ETAT.TXT', 'REPORT.TXT'), argument: ['1'] };
    modele.appels.push(a);
  }
  if (!a) return null;
  if (!a.argument?.length) a.argument = ['1'];
  const code = a.code + a.argument[0];
  let et = modele.etats.find((x) => x.code === code);
  if (!et && creer) { et = { code, nom: T().titre || code, longueur: 80, lignesPage: 60, postes: 100, libelles: [], structures: [], categories: [] }; modele.etats.push(et); sauver(); }
  if (et) { et.libelles ||= []; et.structures ||= []; et.categories ||= []; }
  return et ? { a, et } : null;
}
const sansEtat = (id, titre) => {
  const champs = [...cadre(id, titre, tx('PF1 AIDE  PF3 RETOUR', 'PF1 HELP  PF3 BACK'))];
  champs.push(lab(4, 1, tx('Aucun fichier d\'impression. CHOIX K pour créer l\'état, ou CHOIX C : utilisation I,', 'No print file. OPTION K to create the report, or OPTION C: usage I,'), 'jaune'), lab(5, 1, tx('argument = la lettre de l\'état (ET et 1 donnent l\'état ET1).', 'argument = the report letter (ET and 1 give report ET1).'), 'jaune'));
  return { champs, curseur: 'CHOIX' };
};
const verdictEtat = () => { try { generer(modele); return null; } catch (e) { return e.message; } };
const pied = (champs, l) => { const e = verdictEtat(); champs.push(lab(l, 1, pad((e || tx('Programme complet.', 'Program complete.')).toUpperCase(), 78), e ? 'jaune' : 'vert')); };
const PAR_PAGE_ETAT = 13;

VUES.etatD = {
  dessin(ctx) {
    const x = etatCourant(); if (!x) return sansEtat('K01', tx('COMPOSITION DE L\'ÉTAT (-D)', 'REPORT LAYOUT (-D)'));
    const { a, et } = x;
    const champs = [...cadre('K01', tx(`COMPOSITION DE L'ÉTAT ${et.code} (-D)`, `REPORT ${et.code} LAYOUT (-D)`), tx('PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  ENTRÉE VALIDER  -L LIBELLÉS  -CE STRUCT.', 'PF1 HELP  PF3 BACK  PF7/8 PAGES  ENTER CONFIRM  -L LABELS  -CE STRUCT.'))];
    champs.push(lab(2, 1, tx('NOM', 'NAM'), 'blanc'), zone(2, 5, 30, 'NOM', et.nom, { majuscules: false }), lab(2, 37, tx('LONG.', 'LEN.'), 'blanc'), zone(2, 43, 3, 'LON', et.longueur ?? ETAT_DEFAUT.longueur, { num: true }),
      lab(2, 48, tx('NB LIGNES', 'LINES/PG'), 'blanc'), zone(2, 58, 2, 'NBL', et.lignesPage ?? ETAT_DEFAUT.lignesPage, { num: true }), lab(2, 62, tx('POSTES CAT.', 'CAT. ITEMS'), 'blanc'), zone(2, 74, 3, 'POS', et.postes ?? ETAT_DEFAUT.postes, { num: true }));
    champs.push(lab(3, 1, 'CONDITION', 'blanc'), zone(3, 11, 60, 'CND', et.condition));
    champs.push(lab(4, 1, 'A CT NLG T CAR ST LB SAU * FOSF CONDITION', 'blanc'));
    const liste = et.categories, debut = ctx.page * PAR_PAGE_ETAT;
    for (let i = 0; i < PAR_PAGE_ETAT; i++) {
      const c = liste[debut + i], l = 5 + i;
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 2, `CT${i}`, c?.ct), zone(l, 6, 3, `NL${i}`, c?.nlg), zone(l, 10, 1, `T${i}`, c?.type), zone(l, 12, 3, `CA${i}`, c?.car),
        zone(l, 16, 2, `ST${i}`, c?.st), zone(l, 19, 2, `LB${i}`, c?.lb), zone(l, 22, 2, `SA${i}`, c?.saut), zone(l, 26, 1, `AB${i}`, c?.sautType), zone(l, 28, 4, `FO${i}`, c?.fosf),
        zone(l, 33, 46, `CO${i}`, c?.condition));
    }
    champs.push(lab(18, 1, tx(`Page ${ctx.page + 1}. Fichier ${a.code} (${a.fichier}), fonction F8${a.argument[0]}. POSTES 0 : édition directe.`, `Page ${ctx.page + 1}. File ${a.code} (${a.fichier}), function F8${a.argument[0]}. ITEMS 0: direct print.`), 'bleu'),
      lab(19, 1, tx('T : blanc, I itérative, A/Z en-tête/fin de page, * détail totalisé, T caché', 'T: blank, I iterative, A/Z page header/footer, * totaled detail, T hidden'), 'bleu'),
      lab(20, 1, tx('0 total général, 1-3 total de rupture. SAU 00 surimpression, * saut absolu.', '0 grand total, 1-3 break total. SAU 00 overprint, * absolute skip.'), 'bleu'));
    pied(champs, 21);
    return { champs, curseur: 'NOM' };
  },
  gerer(touche, v, mod, ctx) {
    const x = etatCourant(); if (!x) return { erreur: tx('AUCUN ÉTAT : CHOIX K.', 'NO REPORT: OPTION K.') };
    const { et } = x;
    if (touche === 'PF7') { if (!ctx.page) return { erreur: tx('DÉJÀ EN HAUT.', 'ALREADY AT THE TOP.') }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * PAR_PAGE_ETAT > et.categories.length) return { erreur: tx('DÉJÀ EN BAS.', 'ALREADY AT THE BOTTOM.') }; ctx.page++; return; }
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const nouv = { ...et, nom: v.NOM.trim(), longueur: Number(v.LON) || ETAT_DEFAUT.longueur, lignesPage: Number(v.NBL) || ETAT_DEFAUT.lignesPage, postes: Number(v.POS || 0), condition: v.CND.trim() };
    const debut = ctx.page * PAR_PAGE_ETAT, page = [];
    for (let i = 0; i < PAR_PAGE_ETAT; i++) {
      const g = (k) => (v[`${k}${i}`] || '').trim();
      if (g('A') === 'S') continue;
      if (g('A')) return { erreur: tx('ACTION : S POUR SUPPRIMER LA LIGNE.', 'ACTION: S TO DELETE THE LINE.'), champ: `A${i}` };
      const c = { ct: g('CT'), nlg: g('NL'), type: g('T'), car: g('CA'), st: g('ST'), lb: g('LB'), saut: g('SA'), sautType: g('AB'), fosf: g('FO'), condition: g('CO') };
      if (Object.values(c).every((y) => !y)) continue;
      if (!c.ct) return { erreur: tx('CT : LE CODE DE LA CATÉGORIE (2 CARACTÈRES).', 'CT: THE CATEGORY CODE (2 CHARACTERS).'), champ: `CT${i}` };
      page.push(c);
    }
    nouv.categories = [...et.categories.slice(0, debut), ...page, ...et.categories.slice(debut + PAR_PAGE_ETAT)]
      .sort((p, q) => (p.ct === q.ct ? String(p.nlg).padStart(3, '0').localeCompare(String(q.nlg).padStart(3, '0')) : comparerCodes(p.ct.padEnd(2), q.ct.padEnd(2))));
    Object.assign(et, nouv); sauver();
    const e = verdictEtat();
    return { message: { texte: (e ? tx(`ENREGISTRÉ. À COMPLÉTER : ${e}`, `SAVED. STILL TO COMPLETE: ${e}`) : tx(`${et.categories.length} LIGNE(S) DE COMPOSITION.`, `${et.categories.length} LAYOUT LINE(S).`)).toUpperCase().slice(0, 78), erreur: !!e } };
  },
};

VUES.etatL = {
  dessin(ctx) {
    const x = etatCourant(); if (!x) return sansEtat('L01', tx('LIBELLÉS DE L\'ÉTAT (-L)', 'REPORT LABELS (-L)'));
    const { et } = x;
    const L = Number(et.longueur || ETAT_DEFAUT.longueur), dec = ctx.dec ||= 0, larg = 72;
    const champs = [...cadre('L01', tx(`LIBELLÉS DE L'ÉTAT ${et.code} (-L)`, `REPORT ${et.code} LABELS (-L)`), tx('PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  PF10/11 GAUCHE/DROITE  ENTRÉE VALIDER', 'PF1 HELP  PF3 BACK  PF7/8 PAGES  PF10/11 LEFT/RIGHT  ENTER CONFIRM'))];
    const regle = Array.from({ length: L }, (_, i) => ((i + 1) % 10 === 0 ? String((i + 1) / 10 % 10) : (i + 1) % 5 === 0 ? '+' : '.')).join('');
    champs.push(lab(3, 1, tx(`A LB COLONNES ${dec + 1} À ${Math.min(L, dec + larg)} SUR ${L}`, `A LB COLUMNS ${dec + 1} TO ${Math.min(L, dec + larg)} OF ${L}`), 'blanc'), lab(4, 6, pad(regle.slice(dec, dec + larg), larg), 'bleu'));
    const debut = ctx.page * PAR_PAGE_ETAT;
    for (let i = 0; i < PAR_PAGE_ETAT; i++) {
      const n = debut + i, l = et.libelles[n], r = 5 + i;
      champs.push(zone(r, 1, 1, `A${i}`), lab(r, 3, String(n + 1).padStart(2, '0'), 'turquoise'), zone(r, 6, Math.min(larg, L - dec), `X${i}`, (l?.texte || '').padEnd(L).slice(dec, dec + larg).replace(/ +$/, ''), { majuscules: false }));
    }
    champs.push(lab(19, 1, tx('Numérotés de 01 sans trou : S ne supprime que le dernier. Ligne vide après', 'Numbered from 01 with no gap: S only deletes the last one. Empty line after'), 'bleu'), lab(20, 1, tx('le dernier : création. Les catégories (-D) citent les libellés par numéro.', 'the last one: creation. Categories (-D) refer to labels by number.'), 'bleu'));
    pied(champs, 21);
    return { champs, curseur: `X${Math.max(0, Math.min(PAR_PAGE_ETAT - 1, et.libelles.length - debut))}` };
  },
  gerer(touche, v, mod, ctx) {
    const x = etatCourant(); if (!x) return { erreur: tx('AUCUN ÉTAT : CHOIX K.', 'NO REPORT: OPTION K.') };
    const { et } = x;
    const L = Number(et.longueur || ETAT_DEFAUT.longueur);
    if (touche === 'PF7') { if (!ctx.page) return { erreur: tx('DÉJÀ EN HAUT.', 'ALREADY AT THE TOP.') }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * PAR_PAGE_ETAT > et.libelles.length) return { erreur: tx('DÉJÀ EN BAS.', 'ALREADY AT THE BOTTOM.') }; ctx.page++; return; }
    if (touche === 'PF10') { ctx.dec = Math.max(0, ctx.dec - 66); return; }
    if (touche === 'PF11') { if (ctx.dec + 72 >= L) return { erreur: tx('DÉJÀ À DROITE.', 'ALREADY AT THE RIGHT.') }; ctx.dec += 66; return; }
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const debut = ctx.page * PAR_PAGE_ETAT, libs = et.libelles.map((l) => ({ ...l }));
    let suppr = false;
    for (let i = 0; i < PAR_PAGE_ETAT; i++) {
      const n = debut + i, a = (v[`A${i}`] || '').trim(), t = v[`X${i}`] ?? '';
      if (a === 'S') { if (n !== libs.length - 1) return { erreur: tx('SEUL LE DERNIER LIBELLÉ SE SUPPRIME (LES AUTRES SONT DÉSIGNÉS PAR LEUR NUMÉRO).', 'ONLY THE LAST LABEL CAN BE DELETED (THE OTHERS ARE REFERRED TO BY NUMBER).'), champ: `A${i}` }; suppr = true; continue; }
      if (a) return { erreur: tx('ACTION : S POUR SUPPRIMER LE DERNIER LIBELLÉ.', 'ACTION: S TO DELETE THE LAST LABEL.'), champ: `A${i}` };
      if (n < libs.length) { const avant = libs[n].texte.padEnd(L); libs[n].texte = (avant.slice(0, ctx.dec) + t.padEnd(Math.min(72, L - ctx.dec)) + avant.slice(ctx.dec + 72)).replace(/ +$/, ''); }
      else if (t.trim()) {
        if (n !== libs.length) return { erreur: tx('LES LIBELLÉS SE SUIVENT SANS TROU.', 'LABELS FOLLOW EACH OTHER WITH NO GAP.'), champ: `X${i}` };
        libs.push({ numero: String(n + 1).padStart(2, '0'), texte: (' '.repeat(ctx.dec) + t).replace(/ +$/, '') });
      }
    }
    if (suppr) libs.pop();
    et.libelles = libs.map((l, i) => ({ ...l, numero: String(i + 1).padStart(2, '0') }));
    sauver();
    return { message: { texte: tx(`${et.libelles.length} LIBELLÉ(S).`, `${et.libelles.length} LABEL(S).`), erreur: false } };
  },
};

VUES.etatCE = {
  dessin(ctx) {
    const x = etatCourant(); if (!x) return sansEtat('L02', tx('STRUCTURES DE L\'ÉTAT (-CE)', 'REPORT STRUCTURES (-CE)'));
    const { et } = x;
    const champs = [...cadre('L02', tx(`STRUCTURES DE L'ÉTAT ${et.code} (-CE)`, `REPORT ${et.code} STRUCTURES (-CE)`), tx('PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  ENTRÉE VALIDER  -D COMPOSITION  -L LIBELLÉS', 'PF1 HELP  PF3 BACK  PF7/8 PAGES  ENTER CONFIRM  -D LAYOUT  -L LABELS'))];
    champs.push(lab(3, 1, tx('A ST RUBRIQ S DEB O W SOURCE        IND  CONDITION            FORMAT', 'A ST ELEMNT S BEG O W SOURCE        IDX  CONDITION            FORMAT'), 'blanc'));
    const debut = ctx.page * PAR_PAGE_ETAT;
    for (let i = 0; i < PAR_PAGE_ETAT; i++) {
      const r = et.structures[debut + i], l = 4 + i;
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 2, `ST${i}`, r?.st), zone(l, 6, 6, `RU${i}`, r?.rubrique), zone(l, 13, 1, `SU${i}`, r?.suite), zone(l, 15, 3, `DE${i}`, r?.debut ?? '', { num: true }),
        zone(l, 19, 1, `O${i}`, r?.mode), zone(l, 21, 1, `W${i}`, r?.w), zone(l, 23, 13, `SO${i}`, r?.source), zone(l, 37, 4, `IN${i}`, r?.indice), zone(l, 42, 20, `CO${i}`, r?.condition), zone(l, 63, 14, `FM${i}`, r?.format));
    }
    champs.push(lab(17, 1, tx('O : M mouvement, T à totaliser, + ou R calcul (R arrondi), U par vous.', 'O: M move, T to total, + or R compute (R rounded), U by you.'), 'bleu'),
      lab(18, 1, tx('Ligne suite (S = *) : opération + - * / sur une source, ou suite de condition.', 'Continuation line (S = *): + - * / operation on a source, or condition rest.'), 'bleu'),
      lab(19, 1, tx('W : blanc FD, 1 traitement, 2 mise à jour, 5 compteurs, * expression libre.', 'W: blank FD, 1 processing, 2 update, 5 counters, * free expression.'), 'bleu'),
      lab(20, 1, tx('SOURCE : FFNNRUBRIQ (FA00 : rubrique de même nom) ou un littéral entre quotes.', 'SOURCE: FFNNELEMNT (FA00: element of the same name) or a quoted literal.'), 'bleu'));
    pied(champs, 21);
    return { champs, curseur: `ST${Math.max(0, Math.min(PAR_PAGE_ETAT - 1, et.structures.length - debut))}` };
  },
  gerer(touche, v, mod, ctx) {
    const x = etatCourant(); if (!x) return { erreur: tx('AUCUN ÉTAT : CHOIX K.', 'NO REPORT: OPTION K.') };
    const { et } = x;
    if (touche === 'PF7') { if (!ctx.page) return { erreur: tx('DÉJÀ EN HAUT.', 'ALREADY AT THE TOP.') }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * PAR_PAGE_ETAT > et.structures.length) return { erreur: tx('DÉJÀ EN BAS.', 'ALREADY AT THE BOTTOM.') }; ctx.page++; return; }
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const debut = ctx.page * PAR_PAGE_ETAT, page = [];
    for (let i = 0; i < PAR_PAGE_ETAT; i++) {
      const g = (k) => (v[`${k}${i}`] || '').trim();
      if (g('A') === 'S') continue;
      if (g('A')) return { erreur: tx('ACTION : S POUR SUPPRIMER LA LIGNE.', 'ACTION: S TO DELETE THE LINE.'), champ: `A${i}` };
      const r = { st: g('ST'), rubrique: g('RU'), suite: g('SU'), debut: g('DE') === '' ? '' : Number(g('DE')), mode: g('O'), w: g('W'), source: g('SO'), indice: g('IN'), condition: g('CO'), format: g('FM') };
      if (Object.values(r).every((y) => y === '')) continue;
      if (!r.st) return { erreur: tx('ST : LE NUMÉRO DE LA STRUCTURE (01, 02...).', 'ST: THE STRUCTURE NUMBER (01, 02...).'), champ: `ST${i}` };
      page.push(r);
    }
    et.structures = [...et.structures.slice(0, debut), ...page, ...et.structures.slice(debut + PAR_PAGE_ETAT)];
    sauver();
    const e = verdictEtat();
    return { message: { texte: (e ? tx(`ENREGISTRÉ. À COMPLÉTER : ${e}`, `SAVED. STILL TO COMPLETE: ${e}`) : tx(`${et.structures.length} LIGNE(S) DE STRUCTURE.`, `${et.structures.length} STRUCTURE LINE(S).`)).toUpperCase().slice(0, 78), erreur: !!e } };
  },
};

// ----- requêtes SQL interactives, à la manière des outils DB2 du mainframe -----
const CLE_REQ = cle('requete-v1');
const LIGNES_REQ = 10, LARG_REQ = 72;
let requete;
try { requete = JSON.parse(localStorage.getItem(CLE_REQ)); } catch { requete = null; }
if (!Array.isArray(requete)) requete = EN ? ['SELECT CUSTNO, CUSTNM, AMOUNT, NBINV', '  FROM CUSTOMER', ' WHERE AMOUNT > 0', ' ORDER BY AMOUNT DESC;'] : ['SELECT NUMCLI, NOMCLI, MTFACT, NBFACT', '  FROM CLIENT', ' WHERE MTFACT > 0', ' ORDER BY MTFACT DESC;'];
VUES.requetes = {
  dessin() {
    const champs = [...cadre('Q01', tx('REQUÊTES SQL SUR LA BASE', 'SQL QUERIES ON THE DATABASE'), tx('PF1 AIDE  PF3 RETOUR  ENTRÉE OU PF5 EXÉCUTER  FIN EFFACE LA FIN DE LIGNE', 'PF1 HELP  PF3 BACK  ENTER OR PF5 RUN  END ERASES TO END OF LINE'))];
    champs.push(lab(3, 1, tx('Tapez une ou plusieurs requêtes, terminées par un point-virgule :', 'Type one or more queries, each ending with a semicolon:'), 'blanc'));
    champs.push(lab(4, 1, `   ....+....1....+....2....+....3....+....4....+....5....+....6....+....7..`.slice(0, 78), 'bleu'));
    for (let i = 0; i < LIGNES_REQ; i++) champs.push(lab(5 + i, 1, String(i + 1).padStart(2, '0'), 'bleu'), zone(5 + i, 4, LARG_REQ, `Q${i}`, requete[i] || '', { majuscules: false }));
    const tables = (modele.tables || []).map((t) => `${t.nom} (${t.rubriques.join(', ')})`).join('  ');
    champs.push(
      lab(16, 1, pad(tx(`Tables : ${tables || 'aucune (écran B)'}`, `Tables: ${tables || 'none (screen B)'}`), 78), 'turquoise'),
      lab(17, 1, tx('SELECT, INSERT, UPDATE, DELETE... Les modifications sont validées tout de suite', 'SELECT, INSERT, UPDATE, DELETE... Changes are committed right away'), 'bleu'),
      lab(18, 1, tx('(COMMIT) et gardées dans la base, comme celles des batchs.', '(COMMIT) and kept in the database, like those of the batch jobs.'), 'bleu'),
      lab(19, 1, tx('Exemples : SELECT COUNT(*), SUM(MTFACT) FROM CLIENT;', 'Examples: SELECT COUNT(*), SUM(AMOUNT) FROM CUSTOMER;'), 'bleu'),
      lab(20, 1, tx('           UPDATE CLIENT SET NBFACT = 0 WHERE NUMCLI = 12;', '          UPDATE CUSTOMER SET NBINV = 0 WHERE CUSTNO = 12;'), 'bleu'),
    );
    return { champs, curseur: 'Q0' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE' && touche !== 'PF5') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    requete = Array.from({ length: LIGNES_REQ }, (_, i) => (v[`Q${i}`] || '').replace(/ +$/, ''));
    while (requete.length && !requete.at(-1)) requete.pop();
    try { localStorage.setItem(CLE_REQ, JSON.stringify(requete)); } catch {}
    if (!requete.join('').trim()) return { erreur: tx('TAPEZ UNE REQUÊTE SQL.', 'TYPE AN SQL QUERY.'), champ: 'Q0' };
    app.ouvrir('resultatSql', { texte: requete.map((l) => l + (l.length >= LARG_REQ ? '' : '\n')).join('') }); // une ligne pleine continue sur la suivante
    return { fait: true };
  },
};

// découpe sur les points-virgules hors littéraux
function decouperSql(texte) {
  const ordres = [];
  let cur = '', q = null;
  for (const ch of texte) {
    if (q) { if (ch === q) q = null; cur += ch; continue; }
    if (ch === "'" || ch === '"') { q = ch; cur += ch; continue; }
    if (ch === ';') { if (cur.trim()) ordres.push(cur.trim()); cur = ''; continue; }
    cur += ch;
  }
  if (cur.trim()) ordres.push(cur.trim());
  return ordres;
}
function sqlcodeDe(message) {
  return /UNIQUE|PRIMARY KEY/i.test(message) ? -803 : /no such table/i.test(message) ? -204 : /no such column/i.test(message) ? -206
    : /syntax error|incomplete input|unrecognized token/i.test(message) ? -104 : /NOT NULL/i.test(message) ? -407 : -1;
}
VUES.resultatSql = consultation('Q02', tx('RÉSULTAT DES REQUÊTES', 'QUERY RESULTS'), (ctx) => {
  if (!db) {
    base(['resultatSql']);
    if (erreurBase) throw new Error(tx(`BASE INDISPONIBLE : ${erreurBase.message}`, `DATABASE UNAVAILABLE: ${erreurBase.message}`));
    return [{ texte: tx('Chargement de la base…', 'Loading the database…'), couleur: 'bleu' }];
  }
  if (!ctx.resultat) {
    const lignes = [];
    let modifiee = false, dernierCode = 0;
    const regle = '---------+---------+---------+---------+---------+---------+---------+---------';
    for (const ordre of decouperSql(ctx.args.texte)) {
      for (const l of ordre.split('\n')) lignes.push({ texte: l, couleur: 'blanc' });
      lignes.push({ texte: regle, couleur: 'bleu' });
      try {
        const st = db.prepare(ordre);
        const cols = st.getColumnNames();
        const rangs = [];
        while (st.step()) rangs.push(st.get());
        st.free();
        if (cols.length) {
          const num = cols.map((_, k) => rangs.length > 0 && rangs.every((r) => r[k] === null || typeof r[k] === 'number'));
          const dec = cols.map((c) => rub(c.toUpperCase())?.decimales); // colonne d'une rubrique décimale : ses décimales
          const txt = rangs.map((r) => r.map((x, k) => (x === null ? '-' : typeof x === 'number' && dec[k] ? x.toFixed(dec[k]) : typeof x === 'number' && !Number.isInteger(x) ? String(Number(x.toFixed(6))) : String(x))));
          const larg = cols.map((c, k) => Math.max(c.length, ...txt.map((r) => r[k].length)));
          const aligner = (t, k) => (num[k] ? t.padStart(larg[k]) : t.padEnd(larg[k]));
          lignes.push({ texte: cols.map((c, k) => aligner(c, k)).join('  '), couleur: 'turquoise' });
          lignes.push({ texte: larg.map((n) => '-'.repeat(n)).join('  '), couleur: 'bleu' });
          for (const r of txt) lignes.push({ texte: r.map((t, k) => aligner(t, k)).join('  ') });
          dernierCode = rangs.length ? 0 : 100;
          lignes.push({ texte: tx(`SQLCODE = ${dernierCode}   ${rangs.length} LIGNE(S) LUE(S)`, `SQLCODE = ${dernierCode}   ${rangs.length} ROW(S) READ`), couleur: dernierCode ? 'jaune' : 'vert' });
        } else {
          const n = db.getRowsModified();
          if (/^(CREATE|DROP|ALTER)/i.test(ordre) || (n && /^(INSERT|UPDATE|DELETE)/i.test(ordre))) modifiee = true;
          dernierCode = /^(UPDATE|DELETE)/i.test(ordre) && !n ? 100 : 0;
          lignes.push({ texte: tx(`SQLCODE = ${dernierCode}   ${n} LIGNE(S) MODIFIÉE(S)`, `SQLCODE = ${dernierCode}   ${n} ROW(S) CHANGED`), couleur: dernierCode ? 'jaune' : 'vert' });
        }
      } catch (e) {
        dernierCode = sqlcodeDe(e.message);
        lignes.push({ texte: `SQLCODE = ${dernierCode}   ${e.message}`, couleur: 'rouge' });
      }
      lignes.push({ texte: '' });
    }
    if (modifiee) sauverBase(db);
    ctx.resultat = { lignes, info: tx(`${decouperSql(ctx.args.texte).length} ORDRE(S) EXÉCUTÉ(S). DERNIER SQLCODE = ${dernierCode}.${modifiee ? ' BASE MODIFIÉE ET SAUVÉE.' : ''}`, `${decouperSql(ctx.args.texte).length} STATEMENT(S) RUN. LAST SQLCODE = ${dernierCode}.${modifiee ? ' DATABASE CHANGED AND SAVED.' : ''}`), erreur: dernierCode < 0 };
  }
  ctx.message = { texte: ctx.resultat.info, erreur: ctx.resultat.erreur };
  return ctx.resultat.lignes;
});

// ----- base de données : tables -----
VUES.base = {
  dessin(ctx) {
    const liste = modele.tables ||= [];
    base(['base']);
    const champs = [...cadre('B01', tx('TABLES DE LA BASE DE DONNÉES', 'DATABASE TABLES'), tx('PF3 RETOUR  PF4 REQUÊTES SQL  ENTRÉE VALIDER  ACTION : V VOIR  R VIDER  S SUPPR', 'PF3 BACK  PF4 SQL QUERIES  ENTER CONFIRM  ACTION: V VIEW  R EMPTY  S DELETE'))];
    champs.push(lab(3, 1, tx('A CO TABLE              RUBRIQUES (COLONNES DE LA TABLE)', 'A CO TABLE              DATA ELEMENTS (TABLE COLUMNS)'), 'blanc'));
    for (let i = 0; i < 5; i++) {
      const tb = liste[i], l = 4 + i * 2;
      let n = '';
      if (tb && db) { try { n = tx(`${contenuTable(db, tb).lignes.length} ligne(s) en base`, `${contenuTable(db, tb).lignes.length} row(s) in the database`); } catch { n = tx('pas encore créée', 'not created yet'); } }
      else if (tb) n = erreurBase ? tx('base indisponible', 'database unavailable') : tx('base en chargement', 'database loading');
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 2, `C${i}`, tb?.code), zone(l, 6, 18, `N${i}`, tb?.nom), zone(l, 25, 54, `R${i}`, tb?.rubriques.join(' ')),
        lab(l + 1, 6, tx('CLÉ', 'KEY'), 'blanc'), zone(l + 1, 10, 30, `K${i}`, tb?.cle.join(' ')), lab(l + 1, 42, pad(n, 36), 'vert'));
    }
    champs.push(
      lab(14, 1, tx('Langage structuré (CHOIX P) : SQL CL R, avec les zones CL-xxx de la table CL.', 'Structured language (OPTION P): SQL CL R, with the CL-xxx fields of table CL.'), 'bleu'),
      lab(15, 3, tx('R  lire par la clé    RU lire pour m. à j.  W  insérer     RW mettre à jour', 'R  read by key        RU read for update    W  insert      RW update'), 'turquoise'),
      lab(16, 3, tx('D  supprimer          P  ouvrir le curseur  RN suivante  CL fermer', 'D  delete             P  open the cursor    RN next      CL close'), 'turquoise'),
      lab(17, 3, tx('SCO valider (COMMIT)  SRO annuler (ROLLBACK)', 'SCO commit            SRO rollback'), 'turquoise'),
      lab(18, 1, tx('Après chaque accès, SQLCODE : 0 trouvé, 100 absent ou fin, -803 clé en double.', 'After each access, SQLCODE: 0 found, 100 not found or end, -803 duplicate key.'), 'bleu'),
      lab(19, 1, tx('Lignes gardées d\'une exécution à l\'autre. Arrêt anormal : tout est annulé.', 'Rows are kept from one run to the next. Abnormal end: everything is rolled back.'), 'bleu'),
      lab(20, 1, tx('Changer les colonnes ou la clé d\'une table la recrée vide.', 'Changing the columns or the key of a table recreates it empty.'), 'bleu'),
    );
    return { champs, curseur: liste.length < 5 ? `C${liste.length}` : 'A0' };
  },
  gerer(touche, v, mod, ctx) {
    if (touche === 'PF4') { app.ouvrir('requetes'); return { fait: true }; }
    if (touche !== 'ENTREE') return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) };
    const ancien = modele.tables || [];
    const liste = [], actions = [];
    for (let i = 0; i < 5; i++) {
      const a = (v[`A${i}`] || '').trim(), code = (v[`C${i}`] || '').trim(), nom = (v[`N${i}`] || '').trim();
      const rubs = (v[`R${i}`] || '').trim().split(/\s+/).filter(Boolean), cle = (v[`K${i}`] || '').trim().split(/\s+/).filter(Boolean);
      if (a && !['V', 'R', 'S'].includes(a)) return { erreur: tx('ACTION : V VOIR LE CONTENU, R VIDER LA TABLE, S SUPPRIMER LA DESCRIPTION.', 'ACTION: V VIEW THE CONTENT, R EMPTY THE TABLE, S DELETE THE DESCRIPTION.'), champ: `A${i}` };
      if (a === 'S' || (!code && !nom && !rubs.length && !cle.length)) continue;
      if (!/^[A-Z][A-Z0-9]$/.test(code)) return { erreur: tx('CODE : 2 CARACTÈRES, UNE LETTRE EN TÊTE (EX. CL).', 'CODE: 2 CHARACTERS, STARTING WITH A LETTER (E.G. CL).'), champ: `C${i}` };
      if (!/^[A-Z][A-Z0-9_]{0,17}$/.test(nom)) return { erreur: tx('NOM DE TABLE : LETTRES, CHIFFRES OU _ (EX. CLIENT).', 'TABLE NAME: LETTERS, DIGITS OR _ (E.G. CUSTOMER).'), champ: `N${i}` };
      const inconnue = rubs.find((c) => !rub(c));
      if (inconnue) return { erreur: tx(`RUBRIQUE ${inconnue} ABSENTE DU DICTIONNAIRE.`, `DATA ELEMENT ${inconnue} IS NOT IN THE DICTIONARY.`), champ: `R${i}` };
      if (!rubs.length) return { erreur: tx('AU MOINS UNE RUBRIQUE.', 'AT LEAST ONE DATA ELEMENT.'), champ: `R${i}` };
      if (!cle.length) return { erreur: tx('LA CLÉ : UNE OU PLUSIEURS RUBRIQUES DE LA TABLE.', 'THE KEY: ONE OR MORE DATA ELEMENTS OF THE TABLE.'), champ: `K${i}` };
      const horsTable = cle.find((c) => !rubs.includes(c));
      if (horsTable) return { erreur: tx(`CLÉ ${horsTable} : CE N'EST PAS UNE RUBRIQUE DE LA TABLE.`, `KEY ${horsTable}: NOT A DATA ELEMENT OF THE TABLE.`), champ: `K${i}` };
      const tb = { code, nom, rubriques: rubs, cle };
      liste.push(tb);
      if (a) actions.push([a, tb]);
    }
    try { verifierTables({ ...modele, tables: liste }, liste); } catch (e) { return { erreur: e.message.toUpperCase().slice(0, 78) }; }
    modele.tables = liste;
    try { generer(modele); } catch (e) { modele.tables = ancien; return { erreur: e.message.toUpperCase().slice(0, 78) }; }
    sauver();
    if (actions.length && !db) return { erreur: tx('LA BASE SE CHARGE ENCORE : RÉESSAYEZ DANS UN INSTANT.', 'THE DATABASE IS STILL LOADING: TRY AGAIN IN A MOMENT.') };
    let recreees = [];
    if (db) { recreees = synchroniser(db, modele); sauverBase(db); }
    const voir = actions.find(([a]) => a === 'V');
    for (const [a, tb] of actions) if (a === 'R') viderTable(db, tb);
    if (actions.some(([a]) => a === 'R')) sauverBase(db);
    if (voir) { app.ouvrir('contenu', { table: voir[1].code }); return { fait: true }; }
    const msg = recreees.length ? tx(`TABLE(S) RECRÉÉE(S), VIDES : ${recreees.join(' ')}.`, `TABLE(S) RECREATED, EMPTY: ${recreees.join(' ')}.`) : actions.some(([a]) => a === 'R') ? tx('TABLE(S) VIDÉE(S).', 'TABLE(S) EMPTIED.') : tx(`${liste.length} TABLE(S) DÉCRITE(S).`, `${liste.length} TABLE(S) DESCRIBED.`);
    return { message: { texte: msg, erreur: false } };
  },
};

VUES.aide = {
  dessin() {
    const t = EN ? [
      ['blanc', 'KEYS'],
      ['vert', 'Tab / Shift+Tab .. next / previous field      Shift+Enter . next line'],
      ['vert', 'Enter ............ sends the screen           End ......... erase end of field'],
      ['vert', 'F1 to F12 ........ PF1-PF12 (Shift: PF13-24)  Ins ......... insert mode'],
      ['vert', 'Esc .............. Reset, unlocks keyboard    Shift+Esc ... Clear the screen'],
      ['vert', 'PF3 back  PF7/PF8 previous/next page  PF12 menu  PF1 this help'],
      ['blanc', ''],
      ['blanc', 'OPTION (top line, from any screen)'],
      ['vert', 'M menu  R data elements  E record (E code)  C file calls'],
      ['vert', 'T processing  P procedures  D data  K report (-D -L -CE)  S outputs  B base'],
      ['vert', 'Q SQL  G source  X run  END, END UPD, END REPORT: the examples'],
      ['vert', 'SHARE  copies a link holding the whole program, to send to anyone you like'],
      ['blanc', ''],
      ['blanc', 'ACTION CODES (column A of the lists)'],
      ['vert', 'S delete the line   D duplicate the line   otherwise: change it, then Enter'],
      ['blanc', ''],
      ['blanc', 'KEYBOARD LOCKED (at the bottom: X PROTECTED, X NUM, X FULL)'],
      ['vert', 'Typing outside a field, a letter in a numeric field or a full field in'],
      ['vert', 'insert mode: the terminal refuses the rest. Esc (Reset) to carry on.'],
    ] : [
      ['blanc', 'TOUCHES'],
      ['vert', 'Tab / Maj+Tab .... champ suivant / précédent    Maj+Entrée .. ligne suivante'],
      ['vert', 'Entrée ........... valide l\'écran               Fin ......... efface fin de champ'],
      ['vert', 'F1 à F12 ......... PF1 à PF12 (Maj : PF13-24)   Inser ....... mode insertion'],
      ['vert', 'Échap ............ Réinit, débloque le clavier  Maj+Échap ... Effacer l\'écran'],
      ['vert', 'PF3 retour  PF7/PF8 page préc./suiv.  PF12 menu  PF1 cette aide'],
      ['blanc', ''],
      ['blanc', 'CHOIX (ligne du haut, depuis n\'importe quel écran)'],
      ['vert', 'M menu  R rubriques  E enregistrement (E code)  C appel des fichiers'],
      ['vert', 'T traitement  P traitements  D données  K état (-D -L -CE)  S sorties  B base'],
      ['vert', 'Q SQL  G source  X exécution  FIN, FIN MAJ, FIN ETAT : les exemples'],
      ['vert', 'PARTAGE  copie un lien qui contient tout le programme, à envoyer à qui vous voulez'],
      ['blanc', ''],
      ['blanc', 'CODES ACTION (colonne A des listes)'],
      ['vert', 'S supprimer la ligne   D dupliquer la ligne   sinon : modifiez puis Entrée'],
      ['blanc', ''],
      ['blanc', 'CLAVIER BLOQUÉ (en bas : X PROTÉGÉ, X NUM, X PLEIN)'],
      ['vert', 'Frappe hors d\'un champ, lettre dans un champ numérique ou champ plein en'],
      ['vert', 'insertion : le terminal refuse la suite. Échap (Réinit) pour reprendre.'],
    ];
    return { champs: [...cadre('H01', tx('AIDE', 'HELP'), tx('PF3 RETOUR  PF12 MENU', 'PF3 BACK  PF12 MENU')), ...t.map(([c, x], i) => lab(3 + i, 2, x, c))], curseur: 'CHOIX' };
  },
  gerer(touche) { if (touche === 'ENTREE') return; return { erreur: tx(`TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.`, `KEY ${touche} HAS NO EFFECT ON THIS SCREEN.`) }; },
};

// ----- touches à l'écran (mobile) -----
const REINIT = tx('RÉINIT', 'RESET');
const touches = [[tx('ENTRÉE', 'ENTER'), () => ecran.attention('ENTREE')], ['TAB', () => { ecran.champSuivant(1); ecran.dessiner(); }], ['PF3', () => ecran.attention('PF3')],
  ['PF7', () => ecran.attention('PF7')], ['PF8', () => ecran.attention('PF8')], ['PF1', () => ecran.attention('PF1')], ['PF12', () => ecran.attention('PF12')],
  [tx('EFF FIN', 'ERASE EOF'), () => { ecran.effacerFinDeChamp(); ecran.dessiner(); }], [REINIT, () => { ecran.bloque = null; ecran.dessiner(); }]];
const barre = document.getElementById('touches');
for (const [t, f] of touches) {
  const b = document.createElement('button');
  b.type = 'button'; b.textContent = t;
  b.addEventListener('pointerdown', (e) => e.preventDefault()); // garder le focus sur le terminal
  b.addEventListener('click', () => { if (!ecran.bloque || t === REINIT) f(); document.getElementById('capture').focus({ preventScroll: true }); });
  barre.append(b);
}

app.ouvrir('menu', {}, false);
bandeauReception(document.getElementById('bandeau-partage'));
