import { Ecran3270, COLONNES } from './terminal3270.js';
import { exemple, generer, fichierDonnees, longueurFichier, picStockage, operateurs, rupturesDe, MAX_RUPTURES, analyserLignes, placeFonction, RESERVEES, OPERATEURS_STRUCTURES, verifierTables } from './generateur.js';
import { Programme } from './interpreteur.js';
import { bandeauReception, montrerLien } from './partage.js';
import { ouvrirBase, sauverBase, synchroniser, contenuTable, viderTable, reinitialiserBase, formaterValeur } from './base.js';

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
  // modèle d'avant la base : on ajoute la table d'exemple et, si les fonctions sont libres, son traitement
  modele.tables = structuredClone(exemple.tables);
  for (const d of exemple.dictionnaire) if (!modele.dictionnaire.some((x) => x.code === d.code)) modele.dictionnaire.push({ ...d });
  const libre = !modele.lignes.some((l) => l.fo === '30' || (l.fo === '22' && l.sf >= '20'));
  if (libre) modele.lignes.push(...structuredClone(exemple.lignes.filter((l) => l.fo === '30' || (l.fo === '22' && l.sf >= '20'))));
}
// opérateurs de base de la première v0.5 -> opérateur SQL de Pacbase
{ const anciens = { INS: 'W', LEC: 'R', MAJ: 'RW', SUP: 'D', OUV: 'P', SUI: 'RN', FER: 'CL' };
  for (const l of modele.lignes || []) { const a = anciens[(l.ope || '').toUpperCase()]; if (a) { l.opes = `${(l.opes || '').trim()} ${a}`; l.ope = 'SQL'; } } }
if (!modele.traitement.ruptures) { modele.traitement.ruptures = rupturesDe(modele.traitement); delete modele.traitement.rupture; }
const sauver = () => { try { localStorage.setItem(CLE, JSON.stringify(modele)); } catch {} };
// base SQLite : chargée à la demande, l'écran courant est redessiné quand elle est prête
let db = null, erreurBase = null;
function base(vues) {
  if (db || erreurBase) return db;
  ouvrirBase().then((d) => { db = d; synchroniser(db, modele); sauverBase(db); }, (e) => { erreurBase = e; })
    .then(() => { if (vues.includes(app.courant?.vue)) app.afficher(); });
  return null;
}
const S = () => modele.structures[0];
const T = () => modele.traitement;
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
    lab(1, 44, pad(`PGM ${T().programme}  ENR ${S().code}`, 35), 'bleu'),
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
  const cibles = { M: 'menu', R: 'rubriques', E: 'enreg', T: 'traitement', D: 'donnees', G: 'source', X: 'execution', P: 'specifique', S: 'sorties', B: 'base', Q: 'requetes', AIDE: 'aide', '?': 'aide' };
  if (mot === 'PARTAGE') {
    montrerLien(document.getElementById('lien-partage'), modele).then((copie) => app.afficher({ texte: copie ? 'LIEN DE PARTAGE COPIÉ. IL EST AUSSI AFFICHÉ SOUS LE TERMINAL.' : 'LIEN DE PARTAGE AFFICHÉ SOUS LE TERMINAL.', erreur: false }));
    return true;
  }
  if (mot === 'FIN') {
    modele = structuredClone(exemple); sauver(); app.pile = [];
    reinitialiserBase().then(() => { if (db) { synchroniser(db, modele); sauverBase(db); } });
    app.ouvrir('menu', {}, false); app.afficher({ texte: 'MODÈLE REMIS À L\'EXEMPLE DE DÉPART, BASE VIDÉE.', erreur: false });
    return true;
  }
  if (!cibles[mot]) { app.afficher({ texte: `CHOIX ${mot} INCONNU. CHOIX POSSIBLES : M R E T P D S B Q G X PARTAGE AIDE FIN`, erreur: true }, 'CHOIX'); return true; }
  app.ouvrir(cibles[mot], { filtre: reste.join(' ') });
  return true;
}

const VUES = {};

VUES.menu = {
  dessin() {
    const lignes = [
      ['R', 'RUBRIQUES', 'Dictionnaire des données : code, format, libellé'],
      ['E', 'ENREGISTREMENT', `Composition de ${S().code} : ordre et positions`],
      ['T', 'TRAITEMENT', `Batch ${T().programme} : filtres, rupture, colonnes, totaux`],
      ['P', 'TRAITEMENTS', 'Langage structuré : fonctions et opérateurs'],
      ['D', 'DONNÉES', `Lignes du fichier ${T().fichier}`],
      ['S', 'SORTIES', 'Fichiers produits par le traitement'],
      ['B', 'BASE', 'Tables de la base ; Q pour interroger en SQL'],
      ['G', 'GÉNÉRATION', 'Source COBOL généré, en consultation'],
      ['X', 'EXÉCUTION', 'Compile, lance le batch et affiche la SYSOUT'],
    ];
    const champs = [...cadre('M00', 'MENU GÉNÉRAL', 'PF1 AIDE  PF3 RETOUR  PF12 MENU  ENTRÉE VALIDER')];
    champs.push(lab(3, 6, 'Tapez un code sur la ligne CHOIX ou en face d\'une ligne, puis Entrée.', 'bleu'));
    lignes.forEach(([c, t, d], i) => {
      champs.push(zone(4 + i * 2, 6, 1, `S${i}`), lab(4 + i * 2, 9, c, 'blanc'), lab(4 + i * 2, 12, pad(t, 16), 'turquoise'), lab(4 + i * 2, 29, d, 'bleu'));
    });
    champs.push(lab(21, 6, `Rubriques : ${modele.dictionnaire.length}   Lignes de données : ${modele.donnees.length}   Tables : ${(modele.tables || []).length}`, 'vert'));
    return { champs, curseur: 'CHOIX' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const codes = ['R', 'E', 'T', 'P', 'D', 'S', 'B', 'G', 'X'];
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
  const s = S(), t = T();
  const r = (c) => (c === ancien ? nouveau : c);
  s.rubriques = s.rubriques.map(r); t.colonnes = t.colonnes.map(r); t.totaux = t.totaux.map(r);
  t.filtres.forEach((f) => (f.rubrique = r(f.rubrique))); t.ruptures = t.ruptures.map(r);
  modele.donnees.forEach((l) => { if (ancien in l) { l[nouveau] = l[ancien]; delete l[ancien]; } });
}
function nettoyer() {
  const codes = new Set(modele.dictionnaire.map((d) => d.code));
  const s = S(), t = T();
  s.rubriques = s.rubriques.filter((c) => codes.has(c));
  const dansS = new Set(s.rubriques);
  t.colonnes = t.colonnes.filter((c) => dansS.has(c));
  t.totaux = t.totaux.filter((c) => dansS.has(c) && rub(c).type === '9');
  t.filtres = t.filtres.filter((f) => dansS.has(f.rubrique));
  t.ruptures = t.ruptures.filter((c, i, a) => dansS.has(c) && a.indexOf(c) === i);
  for (const tb of modele.tables || []) { tb.rubriques = tb.rubriques.filter((c) => codes.has(c)); tb.cle = tb.cle.filter((c) => tb.rubriques.includes(c)); }
}

// ----- enregistrement : lignes numérotées, on renumérote pour réordonner -----
VUES.enreg = {
  dessin() {
    const champs = [...cadre('E01', `COMPOSITION DE L'ENREGISTREMENT`, 'PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER   ACTION : S SUPPR   NUMÉRO : DÉPLACER')];
    champs.push(lab(3, 1, 'CODE ENREG.', 'blanc'), zone(3, 13, 12, 'ENR', S().code), lab(3, 27, 'LIBELLÉ', 'blanc'), zone(3, 35, 30, 'LIB', S().libelle, { majuscules: false }));
    champs.push(lab(5, 1, 'A NUM RUBRIQUE     R  LIBELLÉ                  PIC                POSITIONS', 'blanc'));
    let pos = 1;
    const n = S().rubriques.length;
    for (let i = 0; i < 15; i++) {
      const l = 6 + i, c = S().rubriques[i], d = c && rub(c);
      let info = '';
      if (d) { const lg = longueurFichier(d); info = `${pad(d.libelle, 24)} ${pad(picStockage(d) + (d.signe ? ' S' : ''), 18)} ${lpad(pos, 3)} À ${lpad(pos + lg - 1, 3)}`; pos += lg; }
      const nr = c ? T().ruptures.indexOf(c) + 1 : 0;
      champs.push(zone(l, 1, 1, `A${i}`), zone(l, 3, 3, `N${i}`, d ? String((i + 1) * 10).padStart(3, '0') : '', { num: true }), zone(l, 7, 12, `R${i}`, c || ''), zone(l, 20, 1, `K${i}`, nr ? String(nr) : '', { num: true }), lab(l, 23, info, 'bleu'));
    }
    champs.push(lab(21, 1, `${n} rubrique(s), ${pos - 1} car. R = niveau de rupture : 1 le plus large, puis 2, 3.`, 'bleu'));
    return { champs, curseur: n < 15 ? `N${n}` : 'A0' };
  },
  gerer(touche, v, mod) {
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const s = S();
    const enr = (v.ENR || '').trim();
    if (!/^[A-Z][A-Z0-9-]{0,11}$/.test(enr)) return { erreur: 'CODE ENREGISTREMENT INVALIDE.', champ: 'ENR' };
    const lignes = [];
    for (let i = 0; i < 15; i++) {
      const code = (v[`R${i}`] || '').trim();
      const action = (v[`A${i}`] || '').trim();
      if (!code || action === 'S') continue;
      if (!rub(code)) return { erreur: `RUBRIQUE ${code} ABSENTE DU DICTIONNAIRE (CHOIX R POUR LA CRÉER).`, champ: `R${i}` };
      const num = Number((v[`N${i}`] || '').trim() || (i + 1) * 10);
      if (!Number.isFinite(num)) return { erreur: 'NUMÉRO INVALIDE.', champ: `N${i}` };
      if (lignes.some((x) => x.code === code)) return { erreur: `RUBRIQUE ${code} EN DOUBLE.`, champ: `R${i}` };
      const k = (v[`K${i}`] || '').trim();
      if (k && !/^[1-3]$/.test(k)) return { erreur: 'NIVEAU DE RUPTURE : 1, 2 OU 3.', champ: `K${i}` };
      if (k && lignes.some((x) => x.k === k)) return { erreur: `DEUX RUBRIQUES ONT LE NIVEAU DE RUPTURE ${k}.`, champ: `K${i}` };
      lignes.push({ num, i, code, k });
    }
    const niveaux = lignes.filter((x) => x.k).sort((a, b) => a.k - b.k);
    if (niveaux.some((x, j) => Number(x.k) !== j + 1)) return { erreur: 'LES NIVEAUX DE RUPTURE SE SUIVENT : 1, PUIS 2, PUIS 3.', champ: `K${niveaux.find((x, j) => Number(x.k) !== j + 1).i}` };
    lignes.sort((a, b) => a.num - b.num || a.i - b.i);
    const ruptAvant = JSON.stringify(T().ruptures);
    T().ruptures = niveaux.map((x) => x.code);
    const avant = JSON.stringify([s.code, s.libelle, s.rubriques]);
    s.code = enr; T().structure = enr; s.libelle = v.LIB || '';
    s.rubriques = lignes.map((x) => x.code);
    nettoyer(); sauver();
    return { message: { texte: avant === JSON.stringify([s.code, s.libelle, s.rubriques]) && ruptAvant === JSON.stringify(T().ruptures) ? 'AUCUNE MODIFICATION.' : 'ENREGISTREMENT MIS À JOUR, RENUMÉROTÉ DE 10 EN 10.', erreur: false } };
  },
};

// ----- traitement : formulaire -----
VUES.traitement = {
  dessin() {
    const t = T();
    const champs = [...cadre('T01', 'DESCRIPTION DU TRAITEMENT', 'PF1 AIDE  PF3 RETOUR  ENTRÉE VALIDER')];
    champs.push(
      lab(3, 1, 'PROGRAMME ......', 'blanc'), zone(3, 18, 8, 'PGM', t.programme),
      lab(3, 30, 'FICHIER EN ENTRÉE ..', 'blanc'), zone(3, 51, 20, 'FIC', t.fichier),
      lab(4, 1, 'TITRE DE L\'ÉTAT ', 'blanc'), zone(4, 18, 50, 'TIT', t.titre),
      lab(6, 1, 'FILTRES (TOUS VRAIS)        RUBRIQUE     OPÉR.   VALEUR', 'blanc'),
    );
    for (let i = 0; i < 4; i++) {
      const f = t.filtres[i];
      champs.push(lab(7 + i, 1, `  ${i + 1}`, 'bleu'), zone(7 + i, 29, 12, `FR${i}`, f?.rubrique), zone(7 + i, 42, 5, `FO${i}`, f?.op), zone(7 + i, 50, 20, `FV${i}`, f?.valeur));
    }
    champs.push(
      lab(11, 6, `Opérateurs : ${operateurs.join('  ')}`, 'bleu'),
      lab(13, 1, 'RUPTURES .......', 'blanc'), lab(13, 18, pad(t.ruptures.length ? t.ruptures.map((c, i) => `${i + 1}:${c}`).join('  ') + '   (écran E, colonne R)' : 'aucune (écran E, colonne R)', 60), 'turquoise'),
      lab(14, 1, 'COLONNES .......', 'blanc'), zone(14, 18, 60, 'COL', t.colonnes.join(' ')),
      lab(15, 1, 'TOTAUX .........', 'blanc'), zone(15, 18, 60, 'TOT', t.totaux.join(' ')),
      lab(16, 1, 'DÉTAIL (O/N) ...', 'blanc'), zone(16, 18, 1, 'DET', t.detail ? 'O' : 'N'),
      lab(16, 30, 'COMPTER (O/N) ..', 'blanc'), zone(16, 47, 1, 'CPT', t.compter ? 'O' : 'N'),
      lab(18, 1, `Rubriques de ${S().code} : ${S().rubriques.join(' ')}`.slice(0, 78), 'bleu'),
    );
    return { champs, curseur: 'PGM' };
  },
  gerer(touche, v) {
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const dansS = (c) => S().rubriques.includes(c);
    const pgm = (v.PGM || '').trim();
    if (!/^[A-Z][A-Z0-9]{0,7}$/.test(pgm)) return { erreur: 'PROGRAMME : UNE LETTRE PUIS 7 LETTRES OU CHIFFRES MAX.', champ: 'PGM' };
    const fic = (v.FIC || '').trim();
    if (!/^[A-Z0-9][A-Z0-9.]*$/.test(fic)) return { erreur: 'NOM DE FICHIER INVALIDE.', champ: 'FIC' };
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
    Object.assign(T(), { programme: pgm, fichier: fic, titre: v.TIT || '', filtres,  colonnes: col, totaux: tot, detail: v.DET.trim() === 'O', compter: v.CPT.trim() === 'O' });
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
    const champs = [...cadre('D01', `DONNÉES DU FICHIER ${T().fichier}`, 'PF1 AIDE  PF3 RETOUR  PF7/8 PAGES  ENTRÉE VALIDER   ACTION : S SUPPR  D DUPLIQ')];
    let image = [];
    try { image = fichierDonnees(modele).split('\n').filter((l, i, a) => i < a.length - 1); } catch (e) { ctx.message = { texte: e.message.toUpperCase().slice(0, 78), erreur: true }; }
    // réglette : premier caractère de chaque rubrique
    let reg = '', pos = 0, noms = '';
    for (const c of S().rubriques) {
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
    const n = Math.min(PAR_PAGE, Math.max(0, image.length - debut));
    return { champs, curseur: n < PAR_PAGE ? `L${n}` : 'A0' };
  },
  gerer(touche, v, mod, ctx) {
    const nb = modele.donnees.length;
    if (touche === 'PF7') { if (ctx.page === 0) return { erreur: 'DÉJÀ EN PREMIÈRE PAGE.' }; ctx.page--; return; }
    if (touche === 'PF8') { if ((ctx.page + 1) * PAR_PAGE > nb) return { erreur: 'DÉJÀ EN DERNIÈRE PAGE.' }; ctx.page++; return; }
    if (touche !== 'ENTREE') return { erreur: `TOUCHE ${touche} SANS EFFET SUR CET ÉCRAN.` };
    const debut = ctx.page * PAR_PAGE;
    const garder = modele.donnees.map((l) => ({ ...l }));
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
      for (const c of S().rubriques) {
        const r = rub(c), lg = longueurFichier(r);
        const morceau = (brut.padEnd(p + lg)).slice(p, p + lg);
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
    modele.donnees = [...garder.filter((_, k) => !supprimer.has(k)), ...ajouts];
    sauver();
    return { message: { texte: `${changes} LIGNE(S) MISE(S) À JOUR. ${modele.donnees.length} LIGNE(S) DANS LE FICHIER.`, erreur: false } };
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
      const r = new Programme(source).executer({ fichiers: { [T().fichier]: fichierDonnees(modele) }, db });
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
  for (const so of r.ok ? modele.sorties || [] : []) {
    const contenu = (r.fichiers?.[so.fichier] || '').replace(/\n$/, '');
    const enr = contenu ? contenu.split('\n') : [];
    lignes.push({ texte: '' }, { texte: `FICHIER ${so.fichier} (${so.code}) : ${enr.length} ENREGISTREMENT(S)`, couleur: 'blanc' });
    lignes.push({ texte: so.rubriques.map((c) => pad(c, longueurFichier(rub(c)) - 1) + ' ').join(''), couleur: 'turquoise' });
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
        zone(r, 3, 2, `F${i}`, l.fo, { num: true }),
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
      const court = (fo) => { const n = Number(fo); return n <= 4 ? 'init' : n <= 24 ? 'fin' : n <= 79 ? 'avant état' : n <= 89 ? 'avant détail' : n <= 94 ? 'après détail' : 'sur appel P'; };
      infos = 'Fonctions : ' + [...fns.keys()].map((fo) => `F${fo} ${court(fo)}`).join(', ');
    } catch (e) { infos = e.message; }
    champs.push(
      lab(18, 1, pad('OPE : N titre  M déplacer  A ajouter  S soustraire  C calculer  P appeler', 78), 'bleu'),
      lab(19, 1, pad('      MES afficher  ECR écrire  SQL table accès  SCO SRO valider/annuler  COB *', 78), 'bleu'),
      lab(20, 1, pad(`FO 02-04 init  21-24 fin  25-79 avant état  81-89 avant détail  TC BL IT EL DW`, 78), 'bleu'),
      lab(21, 1, pad(infos || `Page ${ctx.page + 1}. Rubriques : E- courant, P- précédent, S- suivant, W- travail, D- détail`, 78), 'turquoise'),
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
      if (!/^\d{1,2}$/.test(l.fo)) return { erreur: 'FO : NUMÉRO DE FONCTION SUR 2 CHIFFRES.', champ: `F${i}` };
      l.fo = l.fo.padStart(2, '0');
      if (RESERVEES.includes(l.fo)) return { erreur: `F${l.fo} EST GÉNÉRÉE PAR L'ATELIER : PRENEZ UN AUTRE NUMÉRO.`, champ: `F${i}` };
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
    modele.lignes = reelles.map((l, i) => ({ l, i })).sort((x, y) => x.l.fo.localeCompare(y.l.fo) || x.l.sf.localeCompare(y.l.sf) || (y.l.ope === 'N') - (x.l.ope === 'N') || !x.l.ope - !y.l.ope || x.i - y.i).map((x) => x.l);
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
      lab(18, 3, 'M E-NOMCLI RL-NOMCLI    remplit une rubrique de l\'enregistrement RL', 'turquoise'),
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
      ['vert', 'M menu  R rubriques  E enregistrement  T traitement  P traitements'],
      ['vert', 'D données  S sorties  B base  Q SQL  G source  X exécution  FIN départ'],
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
