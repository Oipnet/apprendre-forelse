// Les défis du vieux : un lien de départ (#defi=01) charge un modèle préparé et la SYSOUT attendue. Après chaque
// exécution, la SYSOUT est comparée ligne à ligne (espaces de fin ignorés). Rien n'est envoyé ni mesuré : seul le
// bouton « Envoyer ma solution » ouvre le formulaire de contact, prérempli avec le lien du programme.

import { EN, t as tx, traduireModele } from './langue.js';

const VENTES = [
  ['12', 'DURAND', '1250.00'], ['7', 'MOREAU', '430.50'], ['12', 'DURAND', '89.90'], ['3', 'LAMBERT', '0'],
  ['7', 'MOREAU', '2100.00'], ['3', 'LAMBERT', '760.00'], ['21', 'ROUSSEL', '315.25'], ['12', 'DURAND', '1499.99'],
  ['21', 'ROUSSEL', '0'], ['3', 'LAMBERT', '980.40'], ['7', 'MOREAU', '12.00'],
].map(([VENDEUR, NOMVEN, MTVENTE]) => ({ VENDEUR, NOMVEN, MTVENTE }));

const DEFIS_FR = {
  '01': {
    titre: 'L\'état des ventes du mois',
    enonce: [
      'Nous sommes en 2005. Le directeur commercial veut son état des ventes du mois sur son bureau demain matin, à 8 h. Le fichier VENTES.DAT est là, une ligne par vente. Le dictionnaire et l\'enregistrement sont prêts : à vous d\'écrire l\'état.',
      'Seules les ventes de plus de 0 comptent (les ventes à 0 sont ignorées).',
      'Les ventes sont regroupées par vendeur, avec le détail de chaque vente (vendeur, nom, montant).',
      'Chaque vendeur a son sous-total du montant et son nombre de ventes.',
      'L\'état se termine par le total général.',
      'Tout se fait avec les écrans Fichiers et Traitement, sans langage structuré.',
    ],
    modele: {
      version: 3,
      dictionnaire: [
        { code: 'VENDEUR', libelle: 'Vendeur', type: '9', longueur: 3 },
        { code: 'NOMVEN', libelle: 'Nom du vendeur', type: 'X', longueur: 15 },
        { code: 'MTVENTE', libelle: 'Montant', type: '9', longueur: 7, decimales: 2, signe: true },
      ],
      structures: [{ code: 'VENTE', libelle: 'Vente', rubriques: ['VENDEUR', 'NOMVEN', 'MTVENTE'] }],
      appels: [{ code: 'VE', utilisation: 'C', structure: 'VENTE', fichier: 'VENTES.DAT', argument: [], rupture: 0, donnees: VENTES }],
      traitement: { programme: 'DEFI01', titre: 'ETAT DES VENTES DU MOIS', filtres: [], detail: false, colonnes: [], totaux: [], compter: false },
      sorties: [],
      tables: [],
      lignes: [],
    },
    attendu: '', // plus bas
  },
};

// La SYSOUT de la solution de référence, produite par GnuCOBOL (le test de l'atelier vérifie qu'elle n'a pas bougé).
DEFIS_FR['01'].attendu = [
    'ETAT DES VENTES DU MOIS',
    'VENDEUR  NOM DU VENDEUR     MONTANT',
    '-----------------------------------',
    'VENDEUR : 003',
    '      3  LAMBERT             760.00',
    '      3  LAMBERT             980.40',
    '  TOTAL VENDEUR  NB :       2  MTVENTE :       1740.40',
    '',
    'VENDEUR : 007',
    '      7  MOREAU              430.50',
    '      7  MOREAU             2100.00',
    '      7  MOREAU               12.00',
    '  TOTAL VENDEUR  NB :       3  MTVENTE :       2542.50',
    '',
    'VENDEUR : 012',
    '     12  DURAND             1250.00',
    '     12  DURAND               89.90',
    '     12  DURAND             1499.99',
    '  TOTAL VENDEUR  NB :       3  MTVENTE :       2839.89',
    '',
    'VENDEUR : 021',
    '     21  ROUSSEL             315.25',
    '  TOTAL VENDEUR  NB :       1  MTVENTE :        315.25',
    '',
    '-----------------------------------',
    'TOTAL GENERAL  NB :       9  MTVENTE :       7438.04',
    'LUS / RETENUS      11 /       9',
  ].join('\n');

// Les défis en anglais : même modèle, codes et textes traduits ; l'attendue est celle de la même solution de
// référence passée par GnuCOBOL en anglais (tests/cobol/gnucobol-en.json).
const DEFIS_EN = {
  '01': {
    titre: 'The monthly sales report',
    enonce: [
      'It\'s 2005. The sales director wants the monthly sales report on his desk tomorrow morning at 8. The SALES.DAT file is there, one line per sale. The dictionary and the record are ready: the report is up to you.',
      'Only sales above 0 count (zero-value sales are ignored).',
      'Sales are grouped by seller, with the detail of each sale (seller, name, amount).',
      'Each seller has a subtotal of the amount and a count of sales.',
      'The report ends with the grand total.',
      'Everything is done with the Files and Processing screens, without structured code.',
    ],
    modele: traduireModele(DEFIS_FR['01'].modele, {
      Vendeur: 'Seller', 'Nom du vendeur': 'Seller name', Montant: 'Amount', Vente: 'Sale', 'VENTES.DAT': 'SALES.DAT',
      'ETAT DES VENTES DU MOIS': 'MONTHLY SALES REPORT', DEFI01: 'CHALL01',
    }, { VENDEUR: 'SELLER', NOMVEN: 'SELNAM', MTVENTE: 'AMOUNT', VENTE: 'SALE' }),
    attendu: '', // plus bas
  },
};
DEFIS_EN['01'].attendu = [
    'MONTHLY SALES REPORT',
    'SELLER  SELLER NAME         AMOUNT',
    '----------------------------------',
    'SELLER : 003',
    '     3  LAMBERT             760.00',
    '     3  LAMBERT             980.40',
    '  SELLER TOTAL  COUNT :       2  AMOUNT :       1740.40',
    '',
    'SELLER : 007',
    '     7  MOREAU              430.50',
    '     7  MOREAU             2100.00',
    '     7  MOREAU               12.00',
    '  SELLER TOTAL  COUNT :       3  AMOUNT :       2542.50',
    '',
    'SELLER : 012',
    '    12  DURAND             1250.00',
    '    12  DURAND               89.90',
    '    12  DURAND             1499.99',
    '  SELLER TOTAL  COUNT :       3  AMOUNT :       2839.89',
    '',
    'SELLER : 021',
    '    21  ROUSSEL             315.25',
    '  SELLER TOTAL  COUNT :       1  AMOUNT :        315.25',
    '',
    '----------------------------------',
    'GRAND TOTAL  COUNT :       9  AMOUNT :       7438.04',
    'READ / KEPT        11 /       9',
  ].join('\n');

export const DEFIS = EN ? DEFIS_EN : DEFIS_FR;

export function defiDuFragment(hash = location.hash) {
  const m = /^#defi=(\w+)$/.exec(hash || '');
  return m && DEFIS[m[1]] ? m[1] : null;
}

const net = (texte) => String(texte ?? '').replace(/\r/g, '').split('\n').map((l) => l.replace(/\s+$/, '')).join('\n').replace(/\n+$/, '');

// Compare une SYSOUT à l'attendue : { reussi } ou { reussi: false, ligne, attendue, obtenue } (première différence).
export function comparer(sortie, attendu) {
  const a = net(attendu).split('\n');
  const o = net(sortie).split('\n');
  for (let i = 0; i < Math.max(a.length, o.length); i++) {
    if (a[i] !== o[i]) return { reussi: false, ligne: i + 1, attendue: a[i] ?? tx('(fin de la SYSOUT)', '(end of SYSOUT)'), obtenue: o[i] ?? tx('(fin de la SYSOUT)', '(end of SYSOUT)') };
  }
  return { reussi: true };
}
