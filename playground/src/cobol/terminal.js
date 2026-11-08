import { Ecran3270, COLONNES } from './terminal3270.js';
import {
  exemple, exempleMiseAJour, generer, fichiersEntree, fichiersSortie, longueurFichier, picStockage, operateurs, MAX_RUPTURES, analyserLignes,
  OPERATEURS_STRUCTURES, verifierTables, normaliser, UTILISATIONS, MOUVEMENT_DEFAUT, estMiseAJour, appelEdite, fonctionsGenerees, comparerCodes,
} from './generateur.js';
import { Programme } from './interpreteur.js';
import { bandeauReception, montrerLien } from './partage.js';
import { ouvrirBase, sauverBase, synchroniser, contenuTable, viderTable, reinitialiserBase, formaterValeur } from './base.js';

const CLE = 'atelier-cobol-modele-v1';
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
const appelsLus = () => modele.appels.filter((a) => a.utilisation !== 'R');
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
    lab(0, 1, 'ATELIER COBOL', 'blanc'),
    lab(0, 16, pad(`${id}  ${titre}`, 48), 'turquoise'),
    lab(0, 79 - h.length, h, 'bleu'),
    lab(1, 1, 'CHOIX ===>', 'blanc'),
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
    if (!p) return this.afficher({ texte: 'DÉJÀ AU MENU. ÉCRIVEZ FIN DANS CHOIX POUR TOUT EFFACER ET REPARTIR.', erreur: false });
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
    if (touche === 'EFFACER') return this.afficher({ texte: 'ÉCRAN EFFACÉ : RIEN N\'EST ENREGISTRÉ. ENTRÉE POUR RÉAFFICHER.', erreur: false });
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

function commande(texte) {
  const [mot, ...reste] = texte.toUpperCase().split(/\s+/);
  const cibles = { M: 'menu', R: 'rubriques', E: 'enreg', C: 'appels', T: 'traitement', D: 'donnees', G: 'source', X: 'execution', P: 'specifique', S: 'sorties', B: 'base', Q: 'requetes', AIDE: 'aide', '?': 'aide' };
  if (mot === 'PARTAGE') {
    montrerLien(document.getElementById('lien-partage'), modele).then((copie) => app.afficher({ texte: copie ? 'LIEN DE PARTAGE COPIÉ. IL EST AUSSI AFFICHÉ SOUS LE TERMINAL.' : 'LIEN DE PARTAGE AFFICHÉ SOUS LE TERMINAL.', erreur: false }));
    return true;
  }
  if (mot === 'FIN') {
    modele = normaliser(structuredClone(reste[0] === 'MAJ' ? exempleMiseAJour : exemple)); structCourante = appelDonnees = null; sauver(); app.pile = [];
    reinitialiserBase().then(() => { if (db) { synchroniser(db, modele); sauverBase(db); } });
    app.ouvrir('menu', {}, false); app.afficher({ texte: reste[0] === 'MAJ' ? 'EXEMPLE DE MISE À JOUR CHARGÉ, BASE VIDÉE.' : 'MODÈLE REMIS À L\'EXEMPLE DE DÉPART, BASE VIDÉE. FIN MAJ : EXEMPLE DE MISE À JOUR.', erreur: false });
    return true;
  }
  if (!cibles[mot]) { app.afficher({ texte: `CHOIX ${mot} INCONNU. POSSIBLES : M R E C T P D S B Q G X PARTAGE AIDE FIN`, erreur: true }, 'CHOIX'); return true; }
  if (mot === 'E' && reste[0]) {
    const code = reste[0].replace(/[^A-Z0-9-]/g, '').slice(0, 12);
    if (!/^[A-Z]/.test(code)) { app.afficher({ texte: 'E CODE : UNE LETTRE EN TÊTE.', erreur: true }, 'CHOIX'); return true; }
    if (!structDe(code)) { modele.structures.push({ code, libelle: '', rubriques: [] }); sauver(); }
    structCourante = code;
  } else if (mot === 'E') structCourante = null;
  if (mot === 'D' && reste[0]) {
    if (!appelsLus().some((a) => a.code === reste[0])) { app.afficher({ texte: `D ${reste[0]} : AUCUN FICHIER EN ENTRÉE DE CE CODE (CHOIX C).`, erreur: true }, 'CHOIX'); return true; }
    appelDonnees = reste[0];
  }
  app.ouvrir(cibles[mot], { filtre: reste.join(' ') });
  return true;
}

const VUES = {};

VUES.menu = {
  dessin() {
    const lignes = [
      ['R', 'RUBRIQUES', 'Dictionnaire des données : code, format, libellé'],
      ['E', 'ENREGISTREMENTS', `${modele.structures.map((x) => x.code).join(' ')} : ordre et positions (E code)`],
      ['C', 'APPEL FICHIERS', `${modele.appels.map((a) => `${a.code} ${a.utilisation} ${a.fichier}`).join('  ')}`.slice(0, 49)],
      ['T', 'TRAITEMENT', estMiseAJour(modele) ? `Mise à jour ${T().programme} : titre du compte rendu` : `Batch ${T().programme} : filtres, colonnes, totaux`],
      ['P', 'TRAITEMENTS', 'Langage structuré : fonctions et opérateurs'],
      ['D', 'DONNÉES', `Lignes des fichiers en entrée (D code) : ${appelsLus().map((a) => a.code).join(' ')}`],
      ['S', 'SORTIES', 'Fichiers produits par le traitement'],
      ['B', 'BASE', 'Tables de la base ; Q pour interroger en SQL'],
      ['G', 'GÉNÉRATION', 'Source COBOL généré, en consultation'],
      ['X', 'EXÉCUTION', 'Compile, lance le batch et affiche la SYSOUT'],
    ];
    const champs = [...cadre('M00', 'MENU GÉNÉRAL', 'PF1 AIDE  PF3 RETOUR  PF12 MENU  ENTRÉE VALIDER')];
    champs.push(lab(3, 6, 'Tapez un code sur la ligne CHOIX ou en face d\'une ligne, puis Entrée.', 'bleu'));
    lignes.forEach(([c, t, d], i) => {
      const l = 5 + i + (i > 2 ? 1 : 0) + (i > 5 ? 1 : 0); // trois groupes : modèle, traitement, résultat
      champs.push(zone(l, 6, 1, `S${i}`), lab(l, 9, c, 'blanc'), lab(l, 12, pad(t, 16), 'turquoise'), lab(l, 29, pad(d, 49), 'bleu'));
    });
    const nbDonnees = modele.appels.reduce((n, a) => n + (a.donnees || []).length, 0);
    champs.push(lab(21, 6, `Rubriques : ${modele.dictionnaire.length}   Lignes de données : ${nbDonnees}   Tables : ${modele.tables.length}`, 'vert'));
    return { champs, curseur: 'CHOIX' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const codes = ['R', 'E', 'C', 'T', 'P', 'D', 'S', 'B', 'G', 'X'];
    const i = codes.findIndex((_, k) => (v[`S${k}`] || '').trim());
    if (i >= 0) { commande(codes[i]); return { fait: true }; }
    return { message: { texte: 'INDIQUEZ UN CHOIX.', erreur: false } };
  },
};

// ----- rubriques : liste modifiable, codes action en début de ligne -----
const PAR_PAGE = 14;
VUES.rubriques = {
  dessin(ctx) {
    const champs = [...cadre('R01', 'RUBRIQUES DU DICTIONNAIRE', 'PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  ENTRÉE VALIDER   ACTION : S SUPPR  D DUPLIQ')];
    champs.push(lab(3, 1, 'A CODE         LIBELLÉ                   T  LONG DÉC S  PIC', 'blanc'));
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
        zone(l, 54, 1, `S${i}`, d?.type === '9' ? (d.signe ? 'O' : 'N') : ''),
        lab(l, 57, d ? pad(picStockage(d), 22) : ' '.repeat(22), 'bleu'),
      );
    }
    const pages = Math.max(1, Math.ceil((liste.length + 1) / PAR_PAGE));
    champs.push(lab(19, 1, `Page ${ctx.page + 1}/${pages}. Lignes vides : création. T = X (texte) ou 9 (nombre), S = signé O/N.`, 'bleu'));
    return { champs, curseur: liste.length - debut < PAR_PAGE && liste.length - debut >= 0 ? `C${Math.max(0, liste.length - debut)}` : 'A0' };
  },
  gerer(touche, v, mod, ctx) {
    const liste = modele.dictionnaire;
    if (touche === 'PF7') { if (ctx.page === 0) return { erreur: 'DÉJÀ EN PREMIÈRE PAGE.' }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * PAR_PAGE > liste.length) return { erreur: 'DÉJÀ EN DERNIÈRE PAGE.' }; ctx.page++; return; }
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
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
      if (!code) { if (existe) return { erreur: 'CODE OBLIGATOIRE. POUR SUPPRIMER, METTEZ S EN ACTION.', champ: `C${i}` }; continue; }
      if (!/^[A-Z][A-Z0-9-]{0,11}$/.test(code)) return { erreur: 'CODE : UNE LETTRE PUIS LETTRES, CHIFFRES OU TIRETS (12 MAX).', champ: `C${i}` };
      const type = (v[`T${i}`] || 'X').trim() || 'X';
      if (!['X', '9'].includes(type)) return { erreur: 'TYPE : X POUR DU TEXTE, 9 POUR UN NOMBRE.', champ: `T${i}` };
      const longueur = Number((v[`G${i}`] || '').trim());
      if (!Number.isInteger(longueur) || longueur < 1 || longueur > (type === '9' ? 18 : 200)) return { erreur: `LONGUEUR : DE 1 À ${type === '9' ? 18 : 200}.`, champ: `G${i}` };
      const dec = type === '9' ? Number((v[`D${i}`] || '0').trim() || 0) : 0;
      if (!Number.isInteger(dec) || dec < 0 || dec >= longueur) return { erreur: 'DÉCIMALES : MOINS QUE LA LONGUEUR.', champ: `D${i}` };
      const signe = (v[`S${i}`] || 'N').trim() || 'N';
      if (type === '9' && !['O', 'N'].includes(signe)) return { erreur: 'SIGNÉ : O OU N.', champ: `S${i}` };
      const r = { code, libelle: v[`L${i}`] || '', type, longueur };
      if (type === '9') { if (dec) r.decimales = dec; if (signe === 'O') r.signe = true; }
      if (action === 'D' && existe) { ajouts.push({ ...r, code: dupliquerCode(code, nouvelle, ajouts) }); changes++; }
      if (existe) {
        const ancien = liste[idx].code;
        if (ancien !== code) {
          if (liste.some((x, k) => k !== idx && x.code === code)) return { erreur: `LA RUBRIQUE ${code} EXISTE DÉJÀ.`, champ: `C${i}` };
          renommer(ancien, code);
        }
        nouvelle[idx] = r; changes++;
      } else {
        if ([...nouvelle, ...ajouts].some((x) => x.code === code)) return { erreur: `LA RUBRIQUE ${code} EXISTE DÉJÀ.`, champ: `C${i}` };
        ajouts.push(r); changes++;
      }
    }
    if (!changes) return { message: { texte: 'AUCUNE MODIFICATION.', erreur: false } };
    modele.dictionnaire = [...nouvelle.filter((_, k) => !supprimer.has(k)), ...ajouts];
    nettoyer(); sauver();
    return { message: { texte: `${changes} LIGNE(S) MISE(S) À JOUR.`, erreur: false } };
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
    const champs = [...cadre('E01', `COMPOSITION DE L'ENREGISTREMENT`, 'PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER   ACTION : S SUPPR   NUMÉRO : DÉPLACER')];
    champs.push(lab(3, 1, 'CODE ENREG.', 'blanc'), zone(3, 13, 12, 'ENR', S().code), lab(3, 27, 'LIBELLÉ', 'blanc'), zone(3, 35, 30, 'LIB', S().libelle, { majuscules: false }));
    champs.push(lab(5, 1, 'A NUM RUBRIQUE        LIBELLÉ                  PIC                POSITIONS', 'blanc'));
    let pos = 1;
    const n = S().rubriques.length;
    for (let i = 0; i < 15; i++) {
      const l = 6 + i, c = S().rubriques[i], d = c && rub(c);
      let info = '';
      if (d) { const lg = longueurFichier(d); info = `${pad(d.libelle, 24)} ${pad(picStockage(d) + (d.signe ? ' S' : ''), 18)} ${lpad(pos, 3)} À ${lpad(pos + lg - 1, 3)}`; pos += lg; }
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 3, `N${i}`, d ? String((i + 1) * 10).padStart(3, '0') : '', { num: true }), zone(l, 7, 12, `R${i}`, c || ''), lab(l, 23, info, 'bleu'));
    }
    const lu = modele.appels.filter((a) => a.structure === S().code && a.utilisation !== 'R').map((a) => a.code);
    champs.push(lab(21, 1, pad(`${n} rubrique(s), ${pos - 1} car. ${lu.length ? `Lu par ${lu.join(' ')}.` : 'Aucun fichier ne le lit.'} Autres : ${modele.structures.filter((x) => x !== S()).map((x) => `E ${x.code}`).join(', ') || 'E code pour en créer un'}`, 78), 'bleu'));
    return { champs, curseur: n < 15 ? `N${n}` : 'A0' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const s = S();
    const enr = (v.ENR || '').trim();
    if (!/^[A-Z][A-Z0-9-]{0,11}$/.test(enr)) return { erreur: 'CODE ENREGISTREMENT INVALIDE.', champ: 'ENR' };
    if (enr !== s.code && structDe(enr)) return { erreur: `L'ENREGISTREMENT ${enr} EXISTE DÉJÀ : CHOIX E ${enr} POUR L'AFFICHER.`, champ: 'ENR' };
    const lignes = [];
    for (let i = 0; i < 15; i++) {
      const code = (v[`R${i}`] || '').trim();
      const action = (v[`A${i}`] || '').trim();
      if (!code || action === 'S') continue;
      if (!rub(code)) return { erreur: `RUBRIQUE ${code} ABSENTE DU DICTIONNAIRE (CHOIX R POUR LA CRÉER).`, champ: `R${i}` };
      const num = Number((v[`N${i}`] || '').trim() || (i + 1) * 10);
      if (!Number.isFinite(num)) return { erreur: 'NUMÉRO INVALIDE.', champ: `N${i}` };
      if (lignes.some((x) => x.code === code)) return { erreur: `RUBRIQUE ${code} EN DOUBLE.`, champ: `R${i}` };
      lignes.push({ num, i, code });
    }
    lignes.sort((a, b) => a.num - b.num || a.i - b.i);
    const avant = JSON.stringify([s.code, s.libelle, s.rubriques]);
    for (const a of modele.appels) if (a.structure === s.code) a.structure = enr;
    if (structCourante === s.code) structCourante = enr;
    s.code = enr; s.libelle = v.LIB || '';
    s.rubriques = lignes.map((x) => x.code);
    nettoyer(); sauver();
    return { message: { texte: avant === JSON.stringify([s.code, s.libelle, s.rubriques]) ? 'AUCUNE MODIFICATION.' : 'ENREGISTREMENT MIS À JOUR, RENUMÉROTÉ DE 10 EN 10. RUPTURES : CHOIX C.', erreur: false } };
  },
};

// ----- appel des fichiers : une ligne par fichier, comme les lignes -CD -----
const NB_APPELS = 4;
VUES.appels = {
  dessin() {
    const champs = [...cadre('C01', 'APPEL DES FICHIERS DU PROGRAMME', 'PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER   ACTION : S SUPPRIMER')];
    champs.push(lab(3, 1, 'A CD U ENREG.       FICHIER        ARGUMENT (NIVEAUX 1 2 3)            NR', 'blanc'));
    for (let i = 0; i < NB_APPELS; i++) {
      const a = modele.appels[i], l = 4 + i * 3;
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 2, `C${i}`, a?.code), zone(l, 6, 1, `U${i}`, a?.utilisation),
        zone(l, 8, 12, `E${i}`, a?.utilisation === 'R' ? '' : a?.structure), zone(l, 21, 14, `F${i}`, a?.fichier),
        zone(l, 36, 36, `G${i}`, (a?.argument || []).join(' ')),
        zone(l, 73, 1, `N${i}`, a ? String(a.utilisation === 'C' ? a.rupture || 0 : a.utilisation === 'R' ? '' : a.synchro || (a.argument || []).length) : '', { num: true }));
      const mo = a?.mouvement;
      if (a?.utilisation === 'M') {
        champs.push(lab(l + 1, 8, 'CODE MVT', 'blanc'), zone(l + 1, 17, 12, `K${i}`, mo?.rubrique), lab(l + 1, 31, 'CRÉATION', 'blanc'), zone(l + 1, 40, 1, `KC${i}`, mo?.creation),
          lab(l + 1, 43, 'MODIF.', 'blanc'), zone(l + 1, 50, 1, `KM${i}`, mo?.modification), lab(l + 1, 53, 'ANNUL.', 'blanc'), zone(l + 1, 60, 1, `KA${i}`, mo?.annulation));
      } else if (a) {
        champs.push(lab(l + 1, 8, pad(a.utilisation === 'R' ? `Résultat : même enregistrement que le principal (${appel('P')?.structure || '?'})` : `${UTILISATIONS[a.utilisation]}`, 70), 'bleu'));
      }
    }
    let etat = 'Programme complet.';
    try { generer(modele); } catch (e) { etat = e.message; }
    champs.push(
      lab(16, 1, 'U : C consulté (édition)  P principal  M mouvements  R résultat (mise à jour)', 'bleu'),
      lab(17, 1, 'NR : niveaux de rupture (C) ou de synchronisation (P et M, même nombre).', 'bleu'),
      lab(18, 1, 'Zones : CD00-RUB courant, 1-CD00-RUB suivant, CD-FT fin, CD-CFn, CD-DEn.', 'bleu'),
      lab(19, 1, 'Mouvement : une rubrique vide ne modifie rien. FIN MAJ : exemple complet.', 'bleu'),
      lab(21, 1, pad(etat.toUpperCase(), 78), etat === 'Programme complet.' ? 'vert' : 'jaune'),
    );
    return { champs, curseur: 'C0' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const liste = [];
    for (let i = 0; i < NB_APPELS; i++) {
      const a = (v[`A${i}`] || '').trim(), code = (v[`C${i}`] || '').trim(), u = (v[`U${i}`] || '').trim();
      const enr = (v[`E${i}`] || '').trim(), fic = (v[`F${i}`] || '').trim(), arg = (v[`G${i}`] || '').trim().split(/\s+/).filter(Boolean);
      const n = (v[`N${i}`] || '').trim();
      if (a === 'S' || (!code && !u && !enr && !fic && !arg.length)) continue;
      if (a) return { erreur: 'ACTION : S POUR SUPPRIMER LA LIGNE.', champ: `A${i}` };
      if (!/^[A-Z][A-Z0-9]$/.test(code)) return { erreur: 'CODE : 2 CARACTÈRES, UNE LETTRE EN TÊTE (EX. FA).', champ: `C${i}` };
      if (!(u in UTILISATIONS)) return { erreur: 'UTILISATION : C, P, M OU R.', champ: `U${i}` };
      if (u !== 'R' && !structDe(enr)) return { erreur: `ENREGISTREMENT ${enr || '?'} INCONNU (CHOIX E ${enr || 'CODE'} POUR LE CRÉER).`, champ: `E${i}` };
      if (!/^[A-Z0-9][A-Z0-9.]*$/.test(fic)) return { erreur: 'NOM DE FICHIER INVALIDE (EX. CLIENTS.DAT).', champ: `F${i}` };
      const hors = arg.find((c) => !structDe(enr)?.rubriques.includes(c));
      if (u !== 'R' && hors) return { erreur: `ARGUMENT : ${hors} N'EST PAS DANS L'ENREGISTREMENT ${enr}.`, champ: `G${i}` };
      if (arg.length > MAX_RUPTURES) return { erreur: `ARGUMENT : ${MAX_RUPTURES} RUBRIQUES AU PLUS.`, champ: `G${i}` };
      const niv = n === '' ? arg.length : Number(n);
      if (u !== 'R' && !(niv >= 0 && niv <= arg.length)) return { erreur: 'NR : DE 0 AU NOMBRE DE RUBRIQUES DE L\'ARGUMENT.', champ: `N${i}` };
      const ancien = modele.appels.find((x) => x.code === code) || modele.appels[i] || {};
      const x = { code, utilisation: u, fichier: fic, donnees: ancien.donnees || [] };
      if (u !== 'R') { x.structure = enr; x.argument = arg; }
      if (u === 'C') x.rupture = niv;
      if (u === 'P' || u === 'M') x.synchro = niv;
      if (u === 'M') {
        const k = (v[`K${i}`] || ancien.mouvement?.rubrique || '').trim();
        x.mouvement = { rubrique: k, creation: (v[`KC${i}`] || ancien.mouvement?.creation || MOUVEMENT_DEFAUT.creation).trim(), modification: (v[`KM${i}`] || ancien.mouvement?.modification || MOUVEMENT_DEFAUT.modification).trim(), annulation: (v[`KA${i}`] || ancien.mouvement?.annulation || MOUVEMENT_DEFAUT.annulation).trim() };
      }
      liste.push(x);
    }
    if (!liste.length) return { erreur: 'AU MOINS UN FICHIER EN ENTRÉE.', champ: 'C0' };
    if (new Set(liste.map((x) => x.code)).size !== liste.length) return { erreur: 'DEUX FICHIERS ONT LE MÊME CODE.' };
    modele.appels = liste;
    nettoyer(); sauver();
    try { generer(modele); } catch (e) { return { message: { texte: `ENREGISTRÉ. À COMPLÉTER : ${e.message}`.toUpperCase().slice(0, 78), erreur: true } }; }
    return { message: { texte: `${liste.length} FICHIER(S) APPELÉ(S). CHOIX D POUR LES DONNÉES, X POUR EXÉCUTER.`, erreur: false } };
  },
};

// ----- traitement : formulaire -----
VUES.traitement = {
  dessin() {
    const t = T();
    const champs = [...cadre('T01', 'DESCRIPTION DU TRAITEMENT', 'PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER')];
    if (estMiseAJour(modele)) {
      champs.push(lab(3, 1, 'PROGRAMME ......', 'blanc'), zone(3, 18, 8, 'PGM', t.programme),
        lab(4, 1, 'TITRE DU C.R. ..', 'blanc'), zone(4, 18, 50, 'TIT', t.titre),
        lab(6, 1, 'Programme de mise à jour : pas d\'état. Il lit le principal et les mouvements,', 'bleu'),
        lab(7, 1, 'les synchronise sur leur argument, applique créations, modifications et', 'bleu'),
        lab(8, 1, 'annulations, écrit le résultat et un compte rendu (mouvements rejetés, comptes).', 'bleu'),
        lab(10, 1, `Fonctions générées : ${fonctionsGenerees(modele).map((f) => `F${f}`).join(' ')}`, 'turquoise'));
      return { champs, curseur: 'PGM' };
    }
    champs.push(
      lab(3, 1, 'PROGRAMME ......', 'blanc'), zone(3, 18, 8, 'PGM', t.programme),
      lab(3, 30, pad(`FICHIER LU : ${(appelEdite(modele) || appel('P'))?.code || '?'} ${(appelEdite(modele) || appel('P'))?.fichier || ''} (CHOIX C)`, 48), 'turquoise'),
      lab(4, 1, estMiseAJour(modele) ? 'TITRE DU C.R. ..' : 'TITRE DE L\'ÉTAT ', 'blanc'), zone(4, 18, 50, 'TIT', t.titre),
      lab(6, 1, 'FILTRES (TOUS VRAIS)        RUBRIQUE     OPÉR.   VALEUR', 'blanc'),
    );
    for (let i = 0; i < 4; i++) {
      const f = t.filtres[i];
      champs.push(lab(7 + i, 1, `  ${i + 1}`, 'bleu'), zone(7 + i, 29, 12, `FR${i}`, f?.rubrique), zone(7 + i, 42, 5, `FO${i}`, f?.op), zone(7 + i, 50, 20, `FV${i}`, f?.valeur));
    }
    champs.push(
      lab(11, 6, `Opérateurs : ${operateurs.join('  ')}`, 'bleu'),
      lab(13, 1, 'RUPTURES .......', 'blanc'), lab(13, 18, pad((() => { const c = appelEdite(modele); const r = c ? c.argument.slice(0, c.rupture || 0) : []; return (r.length ? r.map((x, i) => `${i + 1}:${x}`).join('  ') : 'aucune') + '   (CHOIX C, argument du fichier)'; })(), 60), 'turquoise'),
      lab(14, 1, 'COLONNES .......', 'blanc'), zone(14, 18, 60, 'COL', t.colonnes.join(' ')),
      lab(15, 1, 'TOTAUX .........', 'blanc'), zone(15, 18, 60, 'TOT', t.totaux.join(' ')),
      lab(16, 1, 'DÉTAIL (O/N) ...', 'blanc'), zone(16, 18, 1, 'DET', t.detail ? 'O' : 'N'),
      lab(16, 30, 'COMPTER (O/N) ..', 'blanc'), zone(16, 47, 1, 'CPT', t.compter ? 'O' : 'N'),
      lab(18, 1, `Rubriques de ${structureLue().code} : ${structureLue().rubriques.join(' ')}`.slice(0, 78), 'bleu'),
    );
    return { champs, curseur: 'PGM' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const dansS = (c) => structureLue().rubriques.includes(c);
    const pgm = (v.PGM || '').trim();
    if (!/^[A-Z][A-Z0-9]{0,7}$/.test(pgm)) return { erreur: 'PROGRAMME : UNE LETTRE PUIS 7 LETTRES OU CHIFFRES MAX.', champ: 'PGM' };
    if (estMiseAJour(modele)) {
      Object.assign(T(), { programme: pgm, titre: v.TIT || '' });
      sauver();
      return { message: { texte: 'TRAITEMENT ENREGISTRÉ. CHOIX G POUR VOIR LE SOURCE, X POUR EXÉCUTER.', erreur: false } };
    }
    const filtres = [];
    for (let i = 0; i < 4; i++) {
      const r = (v[`FR${i}`] || '').trim(), o = (v[`FO${i}`] || '').trim().replace(/\s+/g, ' '), val = (v[`FV${i}`] || '').trim();
      if (!r && !o && !val) continue;
      if (!dansS(r)) return { erreur: `RUBRIQUE ${r || '?'} ABSENTE DE L'ENREGISTREMENT.`, champ: `FR${i}` };
      if (!operateurs.includes(o)) return { erreur: `OPÉRATEUR : ${operateurs.join(' ')}`, champ: `FO${i}` };
      if (rub(r).type === '9' && !/^[+-]?\d+([.,]\d+)?$/.test(val)) return { erreur: `${r} EST NUMÉRIQUE : VALEUR NUMÉRIQUE ATTENDUE.`, champ: `FV${i}` };
      filtres.push({ rubrique: r, op: o, valeur: val });
    }
    const col = (v.COL || '').trim().split(/\s+/).filter(Boolean);
    const mauvaise = col.find((c) => !dansS(c));
    if (mauvaise) return { erreur: `COLONNE ${mauvaise} ABSENTE DE L'ENREGISTREMENT.`, champ: 'COL' };
    if (!col.length) return { erreur: 'AU MOINS UNE COLONNE.', champ: 'COL' };
    const tot = (v.TOT || '').trim().split(/\s+/).filter(Boolean);
    const mauvaisT = tot.find((c) => !dansS(c) || rub(c).type !== '9');
    if (mauvaisT) return { erreur: `TOTAL ${mauvaisT} : RUBRIQUE NUMÉRIQUE DE L'ENREGISTREMENT ATTENDUE.`, champ: 'TOT' };
    for (const n of ['DET', 'CPT']) if (!['O', 'N'].includes((v[n] || '').trim())) return { erreur: 'RÉPONDEZ O OU N.', champ: n };
    Object.assign(T(), { programme: pgm, titre: v.TIT || '', filtres, colonnes: col, totaux: tot, detail: v.DET.trim() === 'O', compter: v.CPT.trim() === 'O' });
    sauver();
    return { message: { texte: 'TRAITEMENT ENREGISTRÉ. CHOIX G POUR VOIR LE SOURCE, X POUR EXÉCUTER.', erreur: false } };
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
    const champs = [...cadre('D01', `DONNÉES DU FICHIER ${a?.code || ''} ${a?.fichier || ''}`, 'PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  ENTRÉE VALIDER   ACTION : S SUPPR  D DUPLIQ')];
    if (!a) { champs.push(lab(3, 1, 'Aucun fichier en entrée : CHOIX C pour en appeler un.', 'jaune')); return { champs, curseur: 'CHOIX' }; }
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
    champs.push(lab(20, 1, trop ? `Ligne de ${pos} caractères : seules les ${LARGEUR_DON} premières sont modifiables ici.` : `Page ${ctx.page + 1}. Saisissez chaque ligne telle qu'elle est dans le fichier. Signe en tête (+/-).`, trop ? 'jaune' : 'bleu'));
    const autres = appelsLus().filter((x) => x !== a).map((x) => `D ${x.code}`).join(', ');
    champs.push(lab(21, 1, pad(`${a.utilisation === 'M' ? 'Mouvement : une rubrique laissée en blanc ne modifie rien. ' : ''}${a.utilisation !== 'C' ? 'Trié sur l\'argument. ' : ''}${autres ? `Autres fichiers : ${autres}` : ''}`, 78), 'bleu'));
    const n = Math.min(PAR_PAGE, Math.max(0, image.length - debut));
    return { champs, curseur: n < PAR_PAGE ? `L${n}` : 'A0' };
  },
  gerer(touche, v, mod, ctx) {
    const a = appelD();
    if (!a) return { erreur: 'AUCUN FICHIER EN ENTRÉE (CHOIX C).' };
    a.donnees ||= [];
    const nb = a.donnees.length;
    if (touche === 'PF7') { if (ctx.page === 0) return { erreur: 'DÉJÀ EN PREMIÈRE PAGE.' }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * PAR_PAGE > nb) return { erreur: 'DÉJÀ EN DERNIÈRE PAGE.' }; ctx.page++; return; }
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
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
          if (r.signe && !['+', '-', ' '].includes(morceau[0])) return { erreur: `${c} (POSITION ${p + 1}) : SIGNE + OU - ATTENDU EN TÊTE.`, champ: `L${i}` };
          if (!/^[0-9 ]*$/.test(corps)) return { erreur: `${c} (POSITIONS ${p + 1} À ${p + lg}) : CHIFFRES ATTENDUS, SANS VIRGULE.`, champ: `L${i}` };
        }
        ligne[c] = versValeur(r, morceau);
        p += lg;
      }
      if (existe) garder[idx] = ligne; else ajouts.push(ligne);
      if (action === 'D' && existe) ajouts.push({ ...ligne });
      changes++;
    }
    if (!changes) return { message: { texte: 'AUCUNE MODIFICATION.', erreur: false } };
    a.donnees = [...garder.filter((_, k) => !supprimer.has(k)), ...ajouts];
    sauver();
    return { message: { texte: `${changes} LIGNE(S) MISE(S) À JOUR. ${a.donnees.length} LIGNE(S) DANS LE FICHIER ${a.code}.`, erreur: false } };
  },
};

// ----- consultation (source, sysout) : défilement PF7/PF8, PF10/PF11 à gauche/droite -----
function consultation(id, titre, obtenir) {
  return {
    dessin(ctx) {
      const champs = [...cadre(id, titre, 'PF1 AIDE  PF3 RETOUR  PF5 RELANCER  PF7 HAUT  PF8 BAS  PF10/PF11 GAUCHE/DROITE')];
      let lignes;
      try { lignes = obtenir(ctx); } catch (e) { lignes = []; ctx.message = { texte: e.message.toUpperCase().slice(0, 78), erreur: true }; }
      ctx.total = lignes.length;
      const haut = ctx.haut ||= 0, dec = ctx.dec ||= 0;
      champs.push(lab(2, 1, `LIGNES ${String(haut + 1).padStart(4, '0')} À ${String(Math.min(lignes.length, haut + 18)).padStart(4, '0')} SUR ${String(lignes.length).padStart(4, '0')}   COLONNE ${dec + 1}`, 'bleu'));
      for (let i = 0; i < 19; i++) {
        const t = lignes[haut + i];
        if (t === undefined) { if (haut + i === lignes.length) champs.push(lab(3 + i, 1, '******************************* FIN DES DONNÉES ******************************', 'bleu')); continue; }
        const couleur = t.couleur || (/^.{6}\*/.test(t.texte) ? 'turquoise' : 'vert');
        champs.push(lab(3 + i, 1, pad(t.texte.slice(dec), 78), couleur));
      }
      return { champs, curseur: 'CHOIX' };
    },
    gerer(touche, v, mod, ctx) {
      const n = ctx.total || 0;
      if (touche === 'PF7') { if (!ctx.haut) return { erreur: 'DÉJÀ EN HAUT.' }; ctx.haut = Math.max(0, ctx.haut - 18); return; }
      if (touche === 'PF8') { if (ctx.haut + 18 >= n) return { erreur: 'DÉJÀ EN BAS.' }; ctx.haut += 18; return; }
      if (touche === 'PF10') { ctx.dec = Math.max(0, ctx.dec - 40); return; }
      if (touche === 'PF11') { ctx.dec = Math.min(80, ctx.dec + 40); return; }
      if (touche === 'PF5') { ctx.resultat = null; return { message: { texte: 'RELANCÉ.', erreur: false } }; }
      if (touche === 'ENTREE') return;
      return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    },
  };
}
VUES.source = consultation('G01', 'SOURCE COBOL GÉNÉRÉ', () => generer(modele).replace(/\n$/, '').split('\n').map((texte) => ({ texte })));
VUES.execution = consultation('X01', 'EXÉCUTION DU BATCH', (ctx) => {
  const avecBase = (modele.tables || []).length > 0;
  if (avecBase && !db) {
    base(['execution']);
    if (erreurBase) throw new Error(`BASE INDISPONIBLE : ${erreurBase.message}`);
    ctx.message = { texte: 'CHARGEMENT DE LA BASE DE DONNÉES…', erreur: false };
    return [{ texte: 'Ouverture de la base SQLite, quelques secondes au premier lancement.', couleur: 'bleu' }];
  }
  if (!ctx.resultat) {
    const source = generer(modele);
    const t0 = performance.now();
    try {
      const recreees = avecBase ? synchroniser(db, modele) : [];
      const r = new Programme(source).executer({ fichiers: fichiersEntree(modele), db });
      if (avecBase) sauverBase(db);
      const sql = r.sql.executees ? ` ${r.sql.executees} ORDRES SQL, ${r.sql.erreurs.length} EN ERREUR.` : '';
      ctx.resultat = { ok: true, sortie: r.sortie, fichiers: r.fichiers, sql: r.sql, recreees, info: `FIN NORMALE. ${r.instructions} INSTR. EN ${(performance.now() - t0).toFixed(0)} MS.${sql}` };
    } catch (e) {
      ctx.resultat = { ok: false, sortie: '', info: `ARRÊT ANORMAL : ${e.message}${avecBase ? ' (BASE INCHANGÉE)' : ''}`.toUpperCase() };
    }
  }
  const r = ctx.resultat;
  ctx.message = { texte: r.info.slice(0, 78), erreur: !r.ok };
  const lignes = [{ texte: `SYSOUT DE ${T().programme}`, couleur: 'blanc' }, { texte: '' }, ...r.sortie.replace(/\n$/, '').split('\n').map((texte) => ({ texte }))];
  for (const so of r.ok ? fichiersSortie(modele) : []) {
    const contenu = (r.fichiers?.[so.fichier] || '').replace(/\n$/, '');
    const enr = contenu ? contenu.split('\n') : [];
    const rubs = modele.sorties.find((x) => x.code === so.code)?.rubriques || structDe(appel('P')?.structure)?.rubriques || [];
    lignes.push({ texte: '' }, { texte: `FICHIER ${so.fichier} (${so.code}) : ${enr.length} ENREGISTREMENT(S)`, couleur: 'blanc' });
    lignes.push({ texte: rubs.map((c) => pad(c, longueurFichier(rub(c)) - 1) + ' ').join(''), couleur: 'turquoise' });
    for (const x of enr) lignes.push({ texte: x });
  }
  if (r.ok && db) {
    for (const e of r.sql?.erreurs || []) lignes.push({ texte: `SQLCODE ${e.code} LIGNE ${e.ligne} : ${e.message}`, couleur: 'rouge' });
    for (const nom of r.recreees || []) lignes.push({ texte: `TABLE ${nom} RECRÉÉE (DESCRIPTION MODIFIÉE) : ELLE ÉTAIT VIDE AU DÉPART.`, couleur: 'jaune' });
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
    { texte: `TABLE ${tb.nom} (${tb.code}) : ${lignes.length} LIGNE(S), CLÉ ${tb.cle.join(' ')}`, couleur: 'blanc' },
    { texte: tb.rubriques.map((c, i) => (rub(c)?.type === '9' ? lpad(c, larg[i]) : pad(c, larg[i]))).join('  '), couleur: 'turquoise' },
    ...lignes.map((l) => ({ texte: l.map((v, i) => cellule(tb.rubriques[i], v, i)).join('  ') })),
  ];
}
VUES.contenu = consultation('B02', 'CONTENU D\'UNE TABLE', (ctx) => {
  const tb = (modele.tables || []).find((x) => x.code === ctx.args.table);
  if (!tb) throw new Error('TABLE INCONNUE.');
  if (!db) {
    base(['contenu']);
    if (erreurBase) throw new Error(`BASE INDISPONIBLE : ${erreurBase.message}`);
    return [{ texte: 'Chargement de la base…', couleur: 'bleu' }];
  }
  return lignesTable(tb);
});

// ----- traitements en langage structuré -----
const LIGNES_P = 14;
VUES.specifique = {
  dessin(ctx) {
    const lignes = modele.lignes ||= [];
    const debut = ctx.page * LIGNES_P;
    const champs = [...cadre('P01', 'TRAITEMENTS : LANGAGE STRUCTURÉ', 'PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  ENTRÉE VALIDER   ACTION : S  I  D')];
    champs.push(lab(3, 1, 'A FO SF OPE OPÉRANDES                            NV TC CONDITION', 'blanc'));
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
      const court = (fo) => (gen.includes(fo) ? 'générée' : avant(fo, '01') ? 'avant init' : avant(fo, '05') ? 'init' : avant(fo, '20') ? 'après lecture'
        : avant(fo, '90') ? (gen.includes('8E') ? (avant(fo, '8E') ? 'avant état' : 'après détail') : 'chaque tour') : 'sur appel P');
      infos = 'Fonctions : ' + [...fns.keys()].map((fo) => `F${fo} ${court(fo)}`).join(', ');
    } catch (e) { infos = e.message; }
    champs.push(
      lab(18, 1, pad('OPE : N titre  M déplacer  A ajouter  S soustraire  C calculer  P appeler', 78), 'bleu'),
      lab(19, 1, pad('      MES afficher  ECR écrire  SQL table accès  SCO SRO valider/annuler  COB *', 78), 'bleu'),
      lab(20, 1, pad(`FO générées ${fonctionsGenerees(modele).join(' ')} (sous-fonctions)  02-04 init  TC BL IT EL DW`, 78), 'bleu'),
      lab(21, 1, pad(infos || `Page ${ctx.page + 1}. Rubriques : ${(appelEdite(modele) || appel('P'))?.code || 'FA'}00- courant, 1-xx00- suivant, W- travail, D- détail`, 78), 'turquoise'),
    );
    const n = Math.min(LIGNES_P, Math.max(0, lignes.length - debut));
    return { champs, curseur: n < LIGNES_P ? `F${n}` : 'A0' };
  },
  gerer(touche, v, mod, ctx) {
    const lignes = modele.lignes ||= [];
    if (touche === 'PF7') { if (ctx.page === 0) return { erreur: 'DÉJÀ EN PREMIÈRE PAGE.' }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * LIGNES_P > lignes.length) return { erreur: 'DÉJÀ EN DERNIÈRE PAGE.' }; ctx.page++; return; }
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
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
      if (a && !['S', 'I', 'D'].includes(a)) return { erreur: 'ACTION : S SUPPRIMER, I INSÉRER UNE LIGNE APRÈS, D DUPLIQUER.', champ: `A${i}` };
      if (a === 'S') continue;
      const vide = !l.fo && !l.sf && !l.ope && !l.opes.trim() && !niv && !tc && !cond.trim();
      if (vide) { if (a === 'I') page.push({ fo: '', sf: '', ope: '', opes: '' }); continue; }
      if (!/^[0-9A-Z]{1,2}$/.test(l.fo)) return { erreur: 'FO : CODE DE FONCTION SUR 2 CARACTÈRES (0A À 99).', champ: `F${i}` };
      l.fo = l.fo.padStart(2, '0');
      if (fonctionsGenerees(modele).includes(l.fo) && l.sf === '99') return { erreur: `F${l.fo}99 EST GÉNÉRÉE : PRENEZ UNE AUTRE SOUS-FONCTION.`, champ: `S${i}` };
      if (!/^[0-9A-Z]{1,2}$/.test(l.sf)) return { erreur: 'SF : CODE DE SOUS-FONCTION SUR 2 CARACTÈRES (10, 20...).', champ: `S${i}` };
      l.sf = l.sf.padStart(2, '0');
      const ope = l.ope.toUpperCase();
      if (!OPERATEURS_STRUCTURES.includes(ope)) return { erreur: `OPE : ${OPERATEURS_STRUCTURES.join(' ')}.`, champ: `O${i}` };
      l.ope = ope;
      if (ope === 'N') {
        l.niv = niv || '05'; l.tc = (tc || 'BL').toUpperCase(); l.cond = cond;
        if (!/^\d\d$/.test(l.niv)) return { erreur: 'NV : NIVEAU SUR 2 CHIFFRES (05, 10, 15...).', champ: `N${i}` };
        if (!['BL', 'IT', 'EL', 'DW'].includes(l.tc)) return { erreur: 'TC : BL (BLOC), IT (SI), EL (SINON), DW (TANT QUE).', champ: `T${i}` };
        if (['IT', 'DW'].includes(l.tc) && !cond.trim()) return { erreur: `${l.tc} DEMANDE UNE CONDITION.`, champ: `C${i}` };
      } else if (niv || tc || cond.trim()) return { erreur: 'NIVEAU, TYPE ET CONDITION SE METTENT SUR LA LIGNE N DE LA SOUS-FONCTION.', champ: niv ? `N${i}` : tc ? `T${i}` : `C${i}` };
      page.push(l);
      if (a === 'D') page.push({ ...l });
      if (a === 'I') page.push({ fo: l.fo, sf: l.sf, ope: '', opes: '' });
    }
    if (!change) return { message: { texte: 'AUCUNE MODIFICATION.', erreur: false } };
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
    return { message: { texte: `${modele.lignes.length} LIGNE(S) DE TRAITEMENT. CHOIX G POUR LE COBOL GÉNÉRÉ, X POUR EXÉCUTER.`, erreur: false } };
  },
};

// ----- fichiers en sortie -----
VUES.sorties = {
  dessin() {
    const liste = modele.sorties ||= [];
    const champs = [...cadre('S01', 'FICHIERS EN SORTIE', 'PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER   ACTION : S SUPPRIMER')];
    champs.push(lab(3, 1, 'A CO FICHIER          RUBRIQUES (DANS L\'ORDRE DE L\'ENREGISTREMENT)', 'blanc'));
    for (let i = 0; i < 6; i++) {
      const so = liste[i], l = 4 + i * 2;
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 2, `C${i}`, so?.code), zone(l, 6, 16, `F${i}`, so?.fichier), zone(l, 23, 56, `R${i}`, so?.rubriques.join(' ')));
      if (so) {
        let lg = 0; try { lg = so.rubriques.reduce((s, c) => s + longueurFichier(rub(c)), 0); } catch {}
        champs.push(lab(l + 1, 6, pad(`Enregistrement ${so.code}-ENR, ${lg} caractères. Rubriques : ${so.code}-${so.rubriques[0] || ''}…`, 72), 'bleu'));
      }
    }
    champs.push(
      lab(17, 1, 'Pour écrire, dans le langage structuré (CHOIX P) :', 'bleu'),
      lab(18, 3, pad(`M ${(appelEdite(modele) || appel('P'))?.code || 'FA'}00-NOMCLI RL-NOMCLI  remplit une rubrique de l'enregistrement RL`, 76), 'turquoise'),
      lab(19, 3, 'ECR RL                  écrit l\'enregistrement dans le fichier', 'turquoise'),
      lab(20, 1, 'Le contenu des fichiers s\'affiche après la SYSOUT (CHOIX X).', 'bleu'),
    );
    return { champs, curseur: liste.length < 6 ? `C${liste.length}` : 'A0' };
  },
  gerer(touche, v, mod) {
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const liste = [];
    for (let i = 0; i < 6; i++) {
      const a = (v[`A${i}`] || '').trim(), code = (v[`C${i}`] || '').trim(), fichier = (v[`F${i}`] || '').trim();
      const rubs = (v[`R${i}`] || '').trim().split(/\s+/).filter(Boolean);
      if (a === 'S' || (!code && !fichier && !rubs.length)) continue;
      if (a) return { erreur: 'ACTION : S POUR SUPPRIMER.', champ: `A${i}` };
      if (!/^[A-Z][A-Z0-9]$/.test(code)) return { erreur: 'CODE : 2 CARACTÈRES, UNE LETTRE EN TÊTE (EX. RL).', champ: `C${i}` };
      if (liste.some((x) => x.code === code)) return { erreur: `CODE ${code} DÉJÀ UTILISÉ.`, champ: `C${i}` };
      if (!/^[A-Z0-9][A-Z0-9.]*$/.test(fichier)) return { erreur: 'NOM DE FICHIER INVALIDE (EX. RELANCES.DAT).', champ: `F${i}` };
      const inconnue = rubs.find((c) => !rub(c));
      if (inconnue) return { erreur: `RUBRIQUE ${inconnue} ABSENTE DU DICTIONNAIRE.`, champ: `R${i}` };
      if (!rubs.length) return { erreur: 'AU MOINS UNE RUBRIQUE.', champ: `R${i}` };
      liste.push({ code, fichier, rubriques: rubs });
    }
    const ancien = modele.sorties;
    modele.sorties = liste;
    try { generer(modele); } catch (e) { modele.sorties = ancien; return { erreur: e.message.toUpperCase().slice(0, 78) }; }
    sauver();
    return { message: { texte: `${liste.length} FICHIER(S) EN SORTIE.`, erreur: false } };
  },
};

// ----- requêtes SQL interactives, à la manière des outils DB2 du mainframe -----
const CLE_REQ = 'atelier-cobol-requete-v1';
const LIGNES_REQ = 10, LARG_REQ = 72;
let requete;
try { requete = JSON.parse(localStorage.getItem(CLE_REQ)); } catch { requete = null; }
if (!Array.isArray(requete)) requete = ['SELECT NUMCLI, NOMCLI, MTFACT, NBFACT', '  FROM CLIENT', ' WHERE MTFACT > 0', ' ORDER BY MTFACT DESC;'];
VUES.requetes = {
  dessin() {
    const champs = [...cadre('Q01', 'REQUÊTES SQL SUR LA BASE', 'PF1 AIDE  PF3 RETOUR  ENTRÉE OU PF5 EXÉCUTER  FIN EFFACE LA FIN DE LIGNE')];
    champs.push(lab(3, 1, 'Tapez une ou plusieurs requêtes, terminées par un point-virgule :', 'blanc'));
    champs.push(lab(4, 1, `   ....+....1....+....2....+....3....+....4....+....5....+....6....+....7..`.slice(0, 78), 'bleu'));
    for (let i = 0; i < LIGNES_REQ; i++) champs.push(lab(5 + i, 1, String(i + 1).padStart(2, '0'), 'bleu'), zone(5 + i, 4, LARG_REQ, `Q${i}`, requete[i] || '', { majuscules: false }));
    const tables = (modele.tables || []).map((t) => `${t.nom} (${t.rubriques.join(', ')})`).join('  ');
    champs.push(
      lab(16, 1, pad(`Tables : ${tables || 'aucune (écran B)'}`, 78), 'turquoise'),
      lab(17, 1, 'SELECT, INSERT, UPDATE, DELETE... Les modifications sont validées tout de suite', 'bleu'),
      lab(18, 1, '(COMMIT) et gardées dans la base, comme celles des batchs.', 'bleu'),
      lab(19, 1, 'Exemples : SELECT COUNT(*), SUM(MTFACT) FROM CLIENT;', 'bleu'),
      lab(20, 1, '           UPDATE CLIENT SET NBFACT = 0 WHERE NUMCLI = 12;', 'bleu'),
    );
    return { champs, curseur: 'Q0' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE' && touche !== 'PF5') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    requete = Array.from({ length: LIGNES_REQ }, (_, i) => (v[`Q${i}`] || '').replace(/ +$/, ''));
    while (requete.length && !requete.at(-1)) requete.pop();
    try { localStorage.setItem(CLE_REQ, JSON.stringify(requete)); } catch {}
    if (!requete.join('').trim()) return { erreur: 'TAPEZ UNE REQUÊTE SQL.', champ: 'Q0' };
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
VUES.resultatSql = consultation('Q02', 'RÉSULTAT DES REQUÊTES', (ctx) => {
  if (!db) {
    base(['resultatSql']);
    if (erreurBase) throw new Error(`BASE INDISPONIBLE : ${erreurBase.message}`);
    return [{ texte: 'Chargement de la base…', couleur: 'bleu' }];
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
          lignes.push({ texte: `SQLCODE = ${dernierCode}   ${rangs.length} LIGNE(S) LUE(S)`, couleur: dernierCode ? 'jaune' : 'vert' });
        } else {
          const n = db.getRowsModified();
          if (/^(CREATE|DROP|ALTER)/i.test(ordre) || (n && /^(INSERT|UPDATE|DELETE)/i.test(ordre))) modifiee = true;
          dernierCode = /^(UPDATE|DELETE)/i.test(ordre) && !n ? 100 : 0;
          lignes.push({ texte: `SQLCODE = ${dernierCode}   ${n} LIGNE(S) MODIFIÉE(S)`, couleur: dernierCode ? 'jaune' : 'vert' });
        }
      } catch (e) {
        dernierCode = sqlcodeDe(e.message);
        lignes.push({ texte: `SQLCODE = ${dernierCode}   ${e.message}`, couleur: 'rouge' });
      }
      lignes.push({ texte: '' });
    }
    if (modifiee) sauverBase(db);
    ctx.resultat = { lignes, info: `${decouperSql(ctx.args.texte).length} ORDRE(S) EXÉCUTÉ(S). DERNIER SQLCODE = ${dernierCode}.${modifiee ? ' BASE MODIFIÉE ET SAUVÉE.' : ''}`, erreur: dernierCode < 0 };
  }
  ctx.message = { texte: ctx.resultat.info, erreur: ctx.resultat.erreur };
  return ctx.resultat.lignes;
});

// ----- base de données : tables -----
VUES.base = {
  dessin(ctx) {
    const liste = modele.tables ||= [];
    base(['base']);
    const champs = [...cadre('B01', 'TABLES DE LA BASE DE DONNÉES', 'PF3 RETOUR  PF4 REQUÊTES SQL  ENTRÉE VALIDER  ACTION : V VOIR  R VIDER  S SUPPR')];
    champs.push(lab(3, 1, 'A CO TABLE              RUBRIQUES (COLONNES DE LA TABLE)', 'blanc'));
    for (let i = 0; i < 5; i++) {
      const tb = liste[i], l = 4 + i * 2;
      let n = '';
      if (tb && db) { try { n = `${contenuTable(db, tb).lignes.length} ligne(s) en base`; } catch { n = 'pas encore créée'; } }
      else if (tb) n = erreurBase ? 'base indisponible' : 'base en chargement';
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 2, `C${i}`, tb?.code), zone(l, 6, 18, `N${i}`, tb?.nom), zone(l, 25, 54, `R${i}`, tb?.rubriques.join(' ')),
        lab(l + 1, 6, 'CLÉ', 'blanc'), zone(l + 1, 10, 30, `K${i}`, tb?.cle.join(' ')), lab(l + 1, 42, pad(n, 36), 'vert'));
    }
    champs.push(
      lab(14, 1, 'Langage structuré (CHOIX P) : SQL CL R, avec les zones CL-xxx de la table CL.', 'bleu'),
      lab(15, 3, 'R  lire par la clé    RU lire pour m. à j.  W  insérer     RW mettre à jour', 'turquoise'),
      lab(16, 3, 'D  supprimer          P  ouvrir le curseur  RN suivante  CL fermer', 'turquoise'),
      lab(17, 3, 'SCO valider (COMMIT)  SRO annuler (ROLLBACK)', 'turquoise'),
      lab(18, 1, 'Après chaque accès, SQLCODE : 0 trouvé, 100 absent ou fin, -803 clé en double.', 'bleu'),
      lab(19, 1, 'Lignes gardées d\'une exécution à l\'autre. Arrêt anormal : tout est annulé.', 'bleu'),
      lab(20, 1, 'Changer les colonnes ou la clé d\'une table la recrée vide.', 'bleu'),
    );
    return { champs, curseur: liste.length < 5 ? `C${liste.length}` : 'A0' };
  },
  gerer(touche, v, mod, ctx) {
    if (touche === 'PF4') { app.ouvrir('requetes'); return { fait: true }; }
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const ancien = modele.tables || [];
    const liste = [], actions = [];
    for (let i = 0; i < 5; i++) {
      const a = (v[`A${i}`] || '').trim(), code = (v[`C${i}`] || '').trim(), nom = (v[`N${i}`] || '').trim();
      const rubs = (v[`R${i}`] || '').trim().split(/\s+/).filter(Boolean), cle = (v[`K${i}`] || '').trim().split(/\s+/).filter(Boolean);
      if (a && !['V', 'R', 'S'].includes(a)) return { erreur: 'ACTION : V VOIR LE CONTENU, R VIDER LA TABLE, S SUPPRIMER LA DESCRIPTION.', champ: `A${i}` };
      if (a === 'S' || (!code && !nom && !rubs.length && !cle.length)) continue;
      if (!/^[A-Z][A-Z0-9]$/.test(code)) return { erreur: 'CODE : 2 CARACTÈRES, UNE LETTRE EN TÊTE (EX. CL).', champ: `C${i}` };
      if (!/^[A-Z][A-Z0-9_]{0,17}$/.test(nom)) return { erreur: 'NOM DE TABLE : LETTRES, CHIFFRES OU _ (EX. CLIENT).', champ: `N${i}` };
      const inconnue = rubs.find((c) => !rub(c));
      if (inconnue) return { erreur: `RUBRIQUE ${inconnue} ABSENTE DU DICTIONNAIRE.`, champ: `R${i}` };
      if (!rubs.length) return { erreur: 'AU MOINS UNE RUBRIQUE.', champ: `R${i}` };
      if (!cle.length) return { erreur: 'LA CLÉ : UNE OU PLUSIEURS RUBRIQUES DE LA TABLE.', champ: `K${i}` };
      const horsTable = cle.find((c) => !rubs.includes(c));
      if (horsTable) return { erreur: `CLÉ ${horsTable} : CE N'EST PAS UNE RUBRIQUE DE LA TABLE.`, champ: `K${i}` };
      const tb = { code, nom, rubriques: rubs, cle };
      liste.push(tb);
      if (a) actions.push([a, tb]);
    }
    try { verifierTables({ ...modele, tables: liste }, liste); } catch (e) { return { erreur: e.message.toUpperCase().slice(0, 78) }; }
    modele.tables = liste;
    try { generer(modele); } catch (e) { modele.tables = ancien; return { erreur: e.message.toUpperCase().slice(0, 78) }; }
    sauver();
    if (actions.length && !db) return { erreur: 'LA BASE SE CHARGE ENCORE : RÉESSAYEZ DANS UN INSTANT.' };
    let recreees = [];
    if (db) { recreees = synchroniser(db, modele); sauverBase(db); }
    const voir = actions.find(([a]) => a === 'V');
    for (const [a, tb] of actions) if (a === 'R') viderTable(db, tb);
    if (actions.some(([a]) => a === 'R')) sauverBase(db);
    if (voir) { app.ouvrir('contenu', { table: voir[1].code }); return { fait: true }; }
    const msg = recreees.length ? `TABLE(S) RECRÉÉE(S), VIDES : ${recreees.join(' ')}.` : actions.some(([a]) => a === 'R') ? 'TABLE(S) VIDÉE(S).' : `${liste.length} TABLE(S) DÉCRITE(S).`;
    return { message: { texte: msg, erreur: false } };
  },
};

VUES.aide = {
  dessin() {
    const t = [
      ['blanc', 'TOUCHES'],
      ['vert', 'Tab / Maj+Tab .... champ suivant / précédent    Maj+Entrée .. ligne suivante'],
      ['vert', 'Entrée ........... valide l\'écran               Fin ......... efface fin de champ'],
      ['vert', 'F1 à F12 ......... PF1 à PF12 (Maj : PF13-24)   Inser ....... mode insertion'],
      ['vert', 'Échap ............ Réinit, débloque le clavier  Maj+Échap ... Effacer l\'écran'],
      ['vert', 'PF3 retour  PF7/PF8 page préc./suiv.  PF12 menu  PF1 cette aide'],
      ['blanc', ''],
      ['blanc', 'CHOIX (ligne du haut, depuis n\'importe quel écran)'],
      ['vert', 'M menu  R rubriques  E enregistrement (E code)  C appel des fichiers'],
      ['vert', 'T traitement  P traitements  D données (D code)  S sorties  B base  Q SQL'],
      ['vert', 'G source  X exécution  FIN exemple d\'édition  FIN MAJ exemple de mise à jour'],
      ['vert', 'PARTAGE  copie un lien qui contient tout le programme, à envoyer à qui vous voulez'],
      ['blanc', ''],
      ['blanc', 'CODES ACTION (colonne A des listes)'],
      ['vert', 'S supprimer la ligne   D dupliquer la ligne   sinon : modifiez puis Entrée'],
      ['blanc', ''],
      ['blanc', 'CLAVIER BLOQUÉ (en bas : X PROTÉGÉ, X NUM, X PLEIN)'],
      ['vert', 'Frappe hors d\'un champ, lettre dans un champ numérique ou champ plein en'],
      ['vert', 'insertion : le terminal refuse la suite. Échap (Réinit) pour reprendre.'],
    ];
    return { champs: [...cadre('H01', 'AIDE', 'PF3 RETOUR  PF12 MENU'), ...t.map(([c, x], i) => lab(3 + i, 2, x, c))], curseur: 'CHOIX' };
  },
  gerer(touche) { if (touche === 'ENTREE') return; return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` }; },
};

// ----- touches à l'écran (mobile) -----
const touches = [['ENTRÉE', () => ecran.attention('ENTREE')], ['TAB', () => { ecran.champSuivant(1); ecran.dessiner(); }], ['PF3', () => ecran.attention('PF3')],
  ['PF7', () => ecran.attention('PF7')], ['PF8', () => ecran.attention('PF8')], ['PF1', () => ecran.attention('PF1')], ['PF12', () => ecran.attention('PF12')],
  ['EFF FIN', () => { ecran.effacerFinDeChamp(); ecran.dessiner(); }], ['RÉINIT', () => { ecran.bloque = null; ecran.dessiner(); }]];
const barre = document.getElementById('touches');
for (const [t, f] of touches) {
  const b = document.createElement('button');
  b.type = 'button'; b.textContent = t;
  b.addEventListener('pointerdown', (e) => e.preventDefault()); // garder le focus sur le terminal
  b.addEventListener('click', () => { if (!ecran.bloque || t === 'RÉINIT') f(); document.getElementById('capture').focus({ preventScroll: true }); });
  barre.append(b);
}

app.ouvrir('menu', {}, false);
bandeauReception(document.getElementById('bandeau-partage'));
