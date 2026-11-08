// Générateur COBOL : dictionnaire de rubriques + structure + description d'un batch d'édition
// -> programme COBOL (format fixe) compilable par GnuCOBOL.

import { preparerEtats, emettreEtats, etatsAppeles } from './etats.js';
import { EN, t as tx, traduireModele } from './langue.js';

const DONNEES_FACTURES = [
    { REGION: 'NOR', NUMCLI: '12', NOMCLI: 'DUPONT', MTFACT: '1250.50' },
    { REGION: 'SUD', NUMCLI: '7', NOMCLI: 'MARTIN', MTFACT: '-80' },
    { REGION: 'NOR', NUMCLI: '31', NOMCLI: 'LEROY', MTFACT: '312' },
    { REGION: 'EST', NUMCLI: '44', NOMCLI: 'GARNIER', MTFACT: '0' },
    { REGION: 'SUD', NUMCLI: '2', NOMCLI: 'BERNARD', MTFACT: '99.99' },
    { REGION: 'OUE', NUMCLI: '18', NOMCLI: 'FOURNIER', MTFACT: '-15.20' },
    { REGION: 'NOR', NUMCLI: '27', NOMCLI: 'GIRAUD', MTFACT: '48.75' },
  ];

const exempleFr = {
  version: 3,
  dictionnaire: [
    { code: 'REGION', libelle: 'Région', type: 'X', longueur: 3 },
    { code: 'NUMCLI', libelle: 'Client', type: '9', longueur: 6 },
    { code: 'NOMCLI', libelle: 'Nom du client', type: 'X', longueur: 20 },
    { code: 'MTFACT', libelle: 'Montant', type: '9', longueur: 7, decimales: 2, signe: true },
    { code: 'NBREL', libelle: 'Nombre de relances', type: '9', longueur: 5 },
    { code: 'NBFACT', libelle: 'Nombre de factures', type: '9', longueur: 3 },
    { code: 'ACTION', libelle: 'Action (C, M, A)', type: 'X', longueur: 1 },
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
  // Écrans de saisie (voir dialogue.js) : le Dialogue CL, une fiche client et une liste, sur la table CLIENT.
  ecrans: [
    {
      code: 'CL0010', nom: 'Fiche client',
      zones: [
        { nlg: '100', pos: 'A', lg: '1', col: '2', nat: 'L', libelle: 'CL0010' },
        { nlg: '110', pos: 'A', lg: '1', col: '28', nat: 'L', libelle: 'FICHE CLIENT' },
        { nlg: '200', rub: 'ACTION', pos: 'A', lg: '4', col: '2', nat: 'V', pres: 'N', trt: 'I' },
        { nlg: '210', rub: 'NUMCLI', pos: 'A', lg: '6', col: '2', nat: 'V', pres: 'S', presence: 'R', segMaj: 'CL00', segSrc: 'CL00' },
        { nlg: '220', rub: 'NOMCLI', lg: '1', nat: 'V', pres: 'S', presence: 'R', segMaj: 'CL00', segSrc: 'CL00' },
        { nlg: '230', rub: 'MTFACT', lg: '1', nat: 'V', pres: 'S', segMaj: 'CL00', segSrc: 'CL00' },
        { nlg: '240', rub: 'NBFACT', lg: '1', nat: 'V', pres: 'S', segMaj: 'CL00', segSrc: 'CL00' },
        { nlg: '800', rub: 'LIERR', pos: 'A', lg: '22', col: '2', nat: 'P', pres: 'F' },
        { nlg: '900', pos: 'A', lg: '24', col: '2', nat: 'L', libelle: 'ENTREE VALIDER  PF2 LISTE' },
        { nlg: '910', pos: 'A', lg: '24', col: '29', nat: 'L', libelle: 'PF3 FIN  EFFACER SORTIR' },
        { nlg: '950', rub: 'PFKEY', maj: 'G', segMaj: 'CL0020', segSrc: '02' },
        { nlg: '951', maj: 'G', segMaj: '', segSrc: '03' },
      ],
      segments: [
        { seg: 'CL00', cat: '', recep: 'T', affich: 'A', alim: '0010-NUMCLI', cle: 'NUMCLI', org: 'S' },
      ],
    },
    {
      code: 'CL0020', nom: 'Liste des clients',
      zones: [
        { nlg: '100', pos: 'A', lg: '1', col: '2', nat: 'L', libelle: 'CL0020' },
        { nlg: '110', pos: 'A', lg: '1', col: '28', nat: 'L', libelle: 'LISTE DES CLIENTS' },
        { nlg: '120', pos: 'A', lg: '3', col: '2', nat: 'L', libelle: 'A CLIENT  NOM DU CLIENT' },
        { nlg: '130', pos: 'A', lg: '3', col: '35', nat: 'L', libelle: 'MONTANT  NB FACT.' },
        { nlg: '200', rub: 'LIGNES', pos: 'A', lg: '4', col: '2', nat: 'R', rv: '12' },
        { nlg: '210', rub: 'ACTION', pos: 'A', lg: '4', col: '2', nat: 'V', pres: 'F', trt: 'I' },
        { nlg: '220', rub: 'NUMCLI', col: '1', nat: 'V', pres: 'F', segMaj: 'CL00', segSrc: 'CL00' },
        { nlg: '230', rub: 'NOMCLI', col: '2', nat: 'V', pres: 'F', segMaj: 'CL00', segSrc: 'CL00' },
        { nlg: '240', rub: 'MTFACT', col: '2', nat: 'V', pres: 'F', segMaj: 'CL00', segSrc: 'CL00' },
        { nlg: '250', rub: 'NBFACT', col: '3', nat: 'V', pres: 'F', segMaj: 'CL00', segSrc: 'CL00' },
        { nlg: '300', rub: 'FIN', pos: 'A', lg: '22', col: '2', nat: 'Z' },
        { nlg: '310', rub: 'LIERR', pos: 'A', lg: '22', col: '2', nat: 'P', pres: 'F' },
        { nlg: '400', pos: 'A', lg: '24', col: '2', nat: 'L', libelle: 'PF3 FICHE  PF8 SUITE' },
        { nlg: '410', pos: 'A', lg: '24', col: '24', nat: 'L', libelle: 'ENTREE VALIDER  EFFACER SORTIR' },
        { nlg: '950', rub: 'PFKEY', maj: 'G', segMaj: 'CL0010', segSrc: '03' },
        { nlg: '951', trt: 'O', maj: 'V', segMaj: 'S', segSrc: '08' },
      ],
      segments: [
        { seg: 'CL00', cat: 'R', recep: 'T', affich: 'A', alim: '0020-NUMCLI', cle: 'NUMCLI', org: 'S' },
      ],
    },
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
const exempleMiseAJourFr = {
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

// Un état à la façon du manuel : chiffre d'affaires par région, en-tête de page, ligne détail
// totalisée, total par région (rupture 1) et total général.
const exempleEtatFr = {
  version: 3,
  dictionnaire: exempleFr.dictionnaire.slice(0, 4),
  structures: [exempleFr.structures[0]],
  appels: [
    { code: 'FA', utilisation: 'C', structure: 'FACTURE', fichier: 'FACTURES.DAT', argument: ['REGION'], rupture: 1, donnees: DONNEES_FACTURES },
    { code: 'ET', utilisation: 'I', fichier: 'ETAT.TXT', argument: ['1'] },
  ],
  traitement: { programme: 'ETATCA', titre: 'CHIFFRE D AFFAIRES PAR REGION' },
  // État ET1 : libellés (-L), structures (-CE), composition (-D)
  etats: [{
    code: 'ET1', nom: 'CHIFFRE D AFFAIRES PAR REGION', longueur: 60, lignesPage: 14, postes: 100,
    libelles: [
      { numero: '01', texte: 'CHIFFRE D\'AFFAIRES PAR REGION                    PAGE' },
      { numero: '02', texte: 'REGION   CLIENT  NOM                             MONTANT' },
      { numero: '03', texte: '------------------------------------------------------------' },
      { numero: '04', texte: '         TOTAL REGION' },
      { numero: '05', texte: 'TOTAL GENERAL' },
    ],
    structures: [
      { st: '01', rubrique: 'PAGE', debut: 56, w: '5', source: 'ET001CP', format: 'ZZ9' },
      { st: '02', rubrique: 'REGION', debut: 1, w: '1', source: 'FA00', condition: "CATX NOT = 'TT'" },
      { st: '02', rubrique: 'NUMCLI', debut: 10, w: '1', source: 'FA00', condition: "CATX = 'DD'" },
      { st: '02', rubrique: 'NOMCLI', debut: 18, w: '1', source: 'FA00', condition: "CATX = 'DD'" },
      { st: '02', rubrique: 'MTFACT', debut: 48, mode: 'T', w: '1', source: 'FA00' },
    ],
    categories: [
      { ct: 'AA', nlg: '100', st: '01', lb: '01', saut: '01', sautType: '*', condition: '5-ET00-1CL NOT < 5-ET00-1CLM' },
      { ct: 'AA', nlg: '110', lb: '02', saut: '02' },
      { ct: 'AA', nlg: '120', lb: '03', saut: '01' },
      { ct: 'DD', nlg: '100', type: '*', st: '02', saut: '01' },
      { ct: 'TR', nlg: '100', type: '1', st: '02', lb: '04', saut: '01', condition: "RTD1 = '1'" },
      { ct: 'TR', nlg: '110', saut: '01' },
      { ct: 'TT', nlg: '100', type: '0', st: '02', lb: '05', saut: '01', condition: "FA-FI = '1'" },
    ],
  }],
  sorties: [],
  tables: [],
  lignes: [],
};

// Les exemples en anglais : mêmes programmes, codes et textes traduits (voir langue.js).
const CODES_EN = { NUMCLI: 'CUSTNO', NOMCLI: 'CUSTNM', MTFACT: 'AMOUNT', NBREL: 'NBREM', NBFACT: 'NBINV', FACTURE: 'INVOICE', CLIENT: 'CUSTOMER', MVTCLI: 'CUSTTRAN', CODMVT: 'TRCODE', PLAFOND: 'CRLIMIT' };
const TEXTES_EN = {
  'Région': 'Region', Client: 'Customer', 'Nom du client': 'Customer name', Montant: 'Amount', 'Nombre de relances': 'Number of reminders',
  'Nombre de factures': 'Number of invoices', Facture: 'Invoice', 'FACTURES.DAT': 'INVOICES.DAT', 'RELANCES.DAT': 'REMINDER.DAT',
  'CHIFFRE D AFFAIRES PAR REGION': 'SALES BY REGION', 'Montants négatifs à relancer': 'Negative amounts to chase',
  "'** A RELANCER' D-NOMCLI": "'** TO CHASE' D-NOMCLI", 'Bilan des relances': 'Reminder summary',
  "'FACTURES A RELANCER : ' W-NBREL": "'INVOICES TO CHASE : ' W-NBREL", 'Clients en base': 'Customers in the database',
  "'CLIENTS EN BASE :'": "'CUSTOMERS IN THE DATABASE :'", 'Pour chaque client lu': 'For each customer read', 'Fin de la liste': 'End of the list',
  'Cumul par client dans la base': 'Running total per customer', 'Client absent : on le crée': 'Missing customer: create it',
  'Client connu : on cumule': 'Known customer: add to it',
  'Plafond de crédit': 'Credit limit', 'Code mouvement': 'Transaction code', 'Mouvement client': 'Customer transaction',
  'CLIENTS.DAT': 'CUSTOMERS.DAT', 'MOUVEMENTS.DAT': 'TRANSACT.DAT', 'CLIENTS.NEW': 'CUSTOMERS.NEW', 'MISE A JOUR DES CLIENTS': 'CUSTOMER UPDATE',
  INCONNU: 'UNKNOWN', 'ETAT.TXT': 'REPORT.TXT',
  'Action (C, M, A)': 'Action (C, M, D)', 'Fiche client': 'Customer record', 'FICHE CLIENT': 'CUSTOMER RECORD',
  'ENTREE VALIDER  PF2 LISTE': 'ENTER CONFIRM  PF2 LIST', 'PF3 FIN  EFFACER SORTIR': 'PF3 END  CLEAR EXIT',
  'Liste des clients': 'Customer list', 'LISTE DES CLIENTS': 'CUSTOMER LIST', 'A CLIENT  NOM DU CLIENT': 'A CUSTNO  CUSTOMER NAME',
  'MONTANT  NB FACT.': 'AMOUNT   NB INV.', 'PF3 FICHE  PF8 SUITE': 'PF3 RECORD  PF8 NEXT',
  'ENTREE VALIDER  EFFACER SORTIR': 'ENTER CONFIRM  CLEAR EXIT',
  "CHIFFRE D'AFFAIRES PAR REGION                    PAGE": `${'SALES BY REGION'.padEnd(49)}PAGE`,
  'REGION   CLIENT  NOM                             MONTANT': 'REGION   CUST.   NAME                            AMOUNT',
  '         TOTAL REGION': '         REGION TOTAL', 'TOTAL GENERAL': 'GRAND TOTAL',
};
// en anglais, l'annulation est une suppression : D (delete) plutôt que A
const annulationEn = (m) => {
  const mv = m.appels.find((a) => a.utilisation === 'M');
  mv.mouvement.annulation = 'D';
  for (const d of mv.donnees) if (d[mv.mouvement.rubrique] === 'A') d[mv.mouvement.rubrique] = 'D';
  return m;
};
export const exemple = EN ? traduireModele(exempleFr, TEXTES_EN, CODES_EN) : exempleFr;
export const exempleMiseAJour = EN ? annulationEn(traduireModele(exempleMiseAJourFr, TEXTES_EN, CODES_EN)) : exempleMiseAJourFr;
export const exempleEtat = EN ? traduireModele(exempleEtatFr, TEXTES_EN, CODES_EN) : exempleEtatFr;

const OPS = ['=', 'NOT =', '>', '<', '>=', '<='];
export const operateurs = OPS;

const ident = (s) => String(s).toUpperCase().replace(/[^A-Z0-9-]/g, '-').replace(/^-+|-+$/g, '').slice(0, 24) || 'X';
const litteral = (s) => '"' + String(s).replace(/"/g, '""') + '"';

export function rubrique(model, code) {
  const r = model.dictionnaire.find((d) => d.code === code);
  if (!r) throw new Error(tx(`Rubrique inconnue : ${code}`, `Unknown data element: ${code}`));
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
export function picEdition(ent, dec, signe) {
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
  if (!m) throw new Error(tx(`Valeur numérique invalide pour ${r.code} : "${v}"`, `Invalid numeric value for ${r.code}: "${v}"`));
  const negatif = m[1] === '-';
  const ent = (m[2] || '0').replace(/^0+(?=\d)/, '');
  const frac = (m[3] || '').padEnd(dec, '0').slice(0, dec);
  const chiffres = (ent + frac).padStart(r.longueur, '0');
  if (chiffres.length > r.longueur) throw new Error(tx(`Valeur trop grande pour ${r.code} : "${v}"`, `Value too large for ${r.code}: "${v}"`));
  return r.signe ? (negatif ? '-' : '+') + chiffres : chiffres;
}


// ---- Émission en format fixe ----
export class Source {
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
    if (t.length > 72) throw new Error(tx(`Ligne COBOL trop longue (${t.length}) : ${t}`, `COBOL line too long (${t.length}): ${t}`));
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
    if (!OPS.includes(f.op)) throw new Error(tx(`Opérateur inconnu : ${f.op}`, `Unknown operator: ${f.op}`));
    const r = rubrique(model, f.rubrique);
    const val = r.type === 'X' ? litteral(f.valeur) : String(Number(String(f.valeur).replace(',', '.')));
    if (r.type === '9' && Number.isNaN(Number(val))) throw new Error(tx(`Valeur de filtre invalide : ${f.valeur}`, `Invalid filter value: ${f.valeur}`));
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
// préfixe ses zones, comme dans le programme généré par IBM : dd00-RUBRIQ la zone lue (avec rupture,
// l'enregistrement lu d'avance), 1-dd00-RUBRIQ l'enregistrement traité quand le fichier a des ruptures,
// 2-dd00-RUBRIQ le principal mis à jour ; dd-FT (fin de travail), dd-FI (fin de lecture),
// dd-PEn / dd-DEn (premier / dernier au niveau n), dd-CFn (participe à l'itération), dd-OCn (existe).
export const UTILISATIONS = {
  C: tx('consulté : lu en séquence et édité dans l\'état', 'consulted: read in sequence and listed in the report'),
  P: tx('principal : mis à jour par les mouvements', 'master: updated by the transactions'),
  M: tx('mouvements : mettent à jour le principal', 'transactions: they update the master'),
  R: tx('résultat : le principal après mise à jour', 'result: the master after the update'),
  I: tx('impression : l\'état (argument : sa lettre) y est écrit', 'print: the report (argument: its letter) is written to it'),
};
const CODES_RESERVES = ['ED', 'LC', 'LG', 'RT', 'NB', 'IN', 'SQ', 'R1', 'R2', 'R3', 'MI'];
export const MOUVEMENT_DEFAUT = { creation: 'C', modification: 'M', annulation: EN ? 'D' : 'A' };

// Ordre des codes de fonction : celui de l'EBCDIC, les lettres avant les chiffres (0A < 00 < 8E < 80).
const rang = (c) => (/[A-Z]/.test(c) ? c.charCodeAt(0) - 65 : 26 + Number(c));
export const comparerCodes = (a, b) => rang(a[0]) - rang(b[0]) || rang(a[1]) - rang(b[1]);
export const normaliserCode = (c) => {
  const v = String(c ?? '').trim().toUpperCase();
  return /^\d$/.test(v) ? `0${v}` : v;
};

// Les zones à utiliser dans les traitements, fichier par fichier (aide des écrans).
export function zonesTraitees(modele) {
  return (modele.appels || []).filter((a) => a.utilisation !== 'R').map((a) => {
    const dd = String(a.code || '').toUpperCase();
    if (a.utilisation === 'P') return tx(`1-${dd}00- principal lu, 2-${dd}00- mis à jour`, `1-${dd}00- master read, 2-${dd}00- updated`);
    if (a.utilisation === 'M' || Number(a.rupture || 0) > 0) return tx(`1-${dd}00- traité, ${dd}00- suivant (lu d'avance)`, `1-${dd}00- current, ${dd}00- next (read ahead)`);
    return tx(`${dd}00- traité`, `${dd}00- current`);
  });
}

export const estMiseAJour = (model) => (model.appels || []).some((a) => a.utilisation === 'P');
export const appelEdite = (model) => (model.appels || []).find((a) => a.utilisation === 'C');

// Fonctions générées selon le genre de programme (édition ou mise à jour).
export function fonctionsGenerees(model) {
  // un état appelé (fichier I) donne sa fonction F8e ; sans état, l'édition simplifiée est en F8E
  const etats = etatsAppeles(model).map((x) => `8${x.e}`);
  if (estMiseAJour(model)) return ['01', '05', '10', '20', '22', '24', '26', '30', '33', '39', '70', '73', '76', ...etats, '90'].sort(comparerCodes);
  const c = appelEdite(model);
  const rupture = c && Number(c.rupture || 0) > 0;
  return ['01', '05', ...(rupture ? ['10'] : []), '20', ...(rupture ? ['22'] : []), ...(etats.length ? etats : ['8E']), '90'].sort(comparerCodes);
}

const ROLES_GENERES = EN ? {
  '01': 'initialization', '05': 'read without control break', '10': 'read with control break', '20': 'end of processing',
  '22': 'last-record flags', '24': 'configuration and occurrence', '26': 'overall control breaks',
  '30': 'control variables', '33': 'transaction code', '39': 'data element presence',
  '70': 'matching check', '73': 'update',
  '76': 'error recording', '8E': 'report listing', '90': 'writes',
} : {
  '01': 'initialisations', '05': 'lecture sans rupture', '10': 'lecture avec rupture', '20': 'fin de traitement',
  '22': 'calcul des derniers', '24': 'configuration et occurrence', '26': 'ruptures totales',
  '30': 'variables de contrôle', '33': 'code mouvement', '39': 'présence des rubriques',
  '70': 'contrôle de correspondance', '73': 'mise à jour',
  '76': 'mémorisation des erreurs', '8E': 'édition de l\'état', '90': 'écritures',
};

// Où s'exécute une fonction, selon son code.
export function placeFonction(fo, model) {
  const code = normaliserCode(fo);
  const gen = fonctionsGenerees(model);
  const etat = etatsAppeles(model).find((x) => `8${x.e}` === code);
  if (etat) return tx(`générée (édition de l'état ${etat.code}) : appelez vos traitements par la colonne FOSF des catégories`, `generated (listing of report ${etat.code}): call your code from the FOSF column of the categories`);
  if (gen.includes(code)) return tx(`générée (${ROLES_GENERES[code]}) : vos sous-fonctions s'y ajoutent${code === '8E' ? ', avant l\'impression du détail' : code === '20' ? ', avant la fermeture des fichiers' : ''}`,
    `generated (${ROLES_GENERES[code]}): your sub-functions are added to it${code === '8E' ? ', before the detail line is printed' : code === '20' ? ', before the files are closed' : ''}`);
  const avant = (x) => comparerCodes(code, x) < 0;
  if (avant('01')) return tx('avant l\'initialisation, une fois', 'once, before initialization');
  if (avant('05')) return tx('une fois, après l\'initialisation', 'once, after initialization');
  if (avant('20')) return tx('à chaque itération, après la lecture (aussi au dernier passage, fin de fichier)', 'on every iteration, after the read (also on the last pass, at end of file)');
  if (avant('90')) {
    if (gen.includes('8E')) return avant('8E') ? tx('à chaque enregistrement, avant l\'état', 'for every record, before the report') : tx('à chaque enregistrement, après l\'impression', 'for every record, after printing');
    return tx('à chaque itération (indicatif en cours)', 'on every iteration (current key)');
  }
  return tx('après la fin d\'itération : appelée seulement par P', 'after the end of the iteration: only called by P');
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
    if (!/^[0-9A-Z]{2}$/.test(fo)) throw new Error(tx(`Code de fonction invalide : ${l.fo} (deux caractères, 0A à 99)`, `Invalid function code: ${l.fo} (two characters, 0A to 99)`));
    if (!/^[0-9A-Z]{2}$/.test(sf)) throw new Error(tx(`Fonction ${fo} : code de sous-fonction invalide : ${l.sf}`, `Function ${fo}: invalid sub-function code: ${l.sf}`));
    if (!fonctions.has(fo)) fonctions.set(fo, []);
    const sous = fonctions.get(fo);
    let cur = sous.find((x) => x.sf === sf);
    if (!cur) { cur = { sf, niv: '05', tc: 'BL', cond: '', titre: '', lignes: [] }; sous.push(cur); }
    if ((l.ope || '').toUpperCase() === 'N') {
      cur.titre = l.opes || '';
      cur.niv = (l.niv || '05').trim() || '05';
      cur.tc = ((l.tc || 'BL').trim() || 'BL').toUpperCase();
      cur.cond = l.cond || '';
      if (!['BL', 'IT', 'EL', 'DW'].includes(cur.tc)) throw new Error(tx(`Fonction ${fo}${sf} : type ${cur.tc} inconnu (BL IT EL DW)`, `Function ${fo}${sf}: unknown type ${cur.tc} (BL IT EL DW)`));
      if (['IT', 'DW'].includes(cur.tc) && !cur.cond.trim()) throw new Error(tx(`Fonction ${fo}${sf} : ${cur.tc} demande une condition`, `Function ${fo}${sf}: ${cur.tc} needs a condition`));
      if (!/^\d\d$/.test(cur.niv)) throw new Error(tx(`Fonction ${fo}${sf} : niveau ${cur.niv} invalide (05, 10, 15...)`, `Function ${fo}${sf}: invalid level ${cur.niv} (05, 10, 15...)`));
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
  m.etats ||= [];
  m.ecrans ||= [];
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
  if (a.utilisation === 'R' || a.utilisation === 'I') return null;
  const s = model.structures.find((x) => x.code === a.structure);
  if (!s) throw new Error(tx(`Fichier ${a.code} : enregistrement ${a.structure || '(aucun)'} inconnu`, `File ${a.code}: unknown record ${a.structure || '(none)'}`));
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
  for (const a of model.appels) if (a.utilisation !== 'R' && a.utilisation !== 'I') f[a.fichier] = contenuAppel(model, a);
  return f;
}
// Contenu du fichier lu par un programme d'édition (ou du premier fichier lu).
export function fichierDonnees(modele) {
  const model = normaliser(modele);
  const a = appelEdite(model) || model.appels.find((x) => x.utilisation !== 'R' && x.utilisation !== 'I');
  return a ? contenuAppel(model, a) : '';
}
// Fichiers écrits par le programme (sorties, résultat de la mise à jour), pour les montrer après la SYSOUT.
export function fichiersSortie(modele) {
  const model = normaliser(modele);
  return [...model.appels.filter((a) => a.utilisation === 'R' || a.utilisation === 'I').map((a) => ({ code: a.code, fichier: a.fichier, ...(a.utilisation === 'I' ? { etat: true } : {}) })),
    ...model.sorties.map((s) => ({ code: s.code, fichier: s.fichier }))];
}

// ================= Vérification de l'appel des fichiers =================
function verifierAppels(model) {
  const appels = model.appels;
  if (!appels.length) throw new Error(tx('Aucun fichier appelé : appelez au moins un fichier en entrée', 'No file called: call at least one input file'));
  const codes = new Set();
  for (const a of appels) {
    if (!/^[A-Z][A-Z0-9]$/.test(a.code)) throw new Error(tx(`Fichier appelé : code ${a.code || '(vide)'} invalide (2 caractères, une lettre en tête)`, `Called file: invalid code ${a.code || '(empty)'} (2 characters, starting with a letter)`));
    if (CODES_RESERVES.includes(a.code)) throw new Error(tx(`Fichier appelé : le code ${a.code} est réservé`, `Called file: code ${a.code} is reserved`));
    if (codes.has(a.code)) throw new Error(tx(`Deux fichiers appelés ont le code ${a.code}`, `Two called files have the code ${a.code}`));
    codes.add(a.code);
    if (!(a.utilisation in UTILISATIONS)) throw new Error(tx(`Fichier ${a.code} : utilisation ${a.utilisation} inconnue (${Object.keys(UTILISATIONS).join(' ')})`, `File ${a.code}: unknown usage ${a.utilisation} (${Object.keys(UTILISATIONS).join(' ')})`));
    if (!/^[A-Z0-9][A-Z0-9.]*$/.test(a.fichier || '')) throw new Error(tx(`Fichier ${a.code} : nom de fichier invalide`, `File ${a.code}: invalid file name`));
    if (a.utilisation !== 'R' && a.utilisation !== 'I' && !model.structures.some((s) => s.code === a.structure)) throw new Error(tx(`Fichier ${a.code} : enregistrement ${a.structure || '(aucun)'} inconnu`, `File ${a.code}: unknown record ${a.structure || '(none)'}`));
  }
  for (const x of [...model.sorties, ...model.tables]) if (codes.has(x.code)) throw new Error(tx(`Le code ${x.code} sert à la fois à un fichier appelé et à un fichier en sortie ou une table`, `Code ${x.code} is used both by a called file and by an output file or a table`));
  const noms = appels.map((a) => a.fichier);
  if (new Set(noms).size !== noms.length) throw new Error(tx('Deux fichiers appelés portent le même nom', 'Two called files have the same name'));
  const de = (u) => appels.filter((a) => a.utilisation === u);
  const [C, P, M, R] = ['C', 'P', 'M', 'R'].map(de);
  const struct = (a) => model.structures.find((s) => s.code === a.structure);
  const verifierArgument = (a, n) => {
    if (a.argument.length < n) throw new Error(tx(`Fichier ${a.code} : l'argument doit compter au moins ${n} rubrique(s)`, `File ${a.code}: the argument needs at least ${n} data element(s)`));
    if (a.argument.length > MAX_RUPTURES) throw new Error(tx(`Fichier ${a.code} : ${MAX_RUPTURES} rubriques d'argument au plus`, `File ${a.code}: at most ${MAX_RUPTURES} argument data elements`));
    if (new Set(a.argument).size !== a.argument.length) throw new Error(tx(`Fichier ${a.code} : une rubrique figure deux fois dans l'argument`, `File ${a.code}: a data element appears twice in the argument`));
    for (const c of a.argument) if (!struct(a).rubriques.includes(c)) throw new Error(tx(`Fichier ${a.code} : la rubrique ${c} de l'argument n'est pas dans l'enregistrement ${a.structure}`, `File ${a.code}: argument data element ${c} is not in record ${a.structure}`));
  };
  if (P.length) {
    if (C.length) throw new Error(tx('Un programme de mise à jour (fichier P) ne consulte pas de fichier C : l\'état est réservé aux programmes d\'édition', 'An update program (P file) does not read a C file: the simple listing is for report programs'));
    if (P.length > 1) throw new Error(tx('Un seul fichier principal (P) par programme', 'Only one master file (P) per program'));
    if (M.length !== 1) throw new Error(tx('Il faut un et un seul fichier de mouvements (M) pour mettre à jour le principal', 'Exactly one transaction file (M) is needed to update the master'));
    if (R.length !== 1) throw new Error(tx('Il faut un fichier résultat (R) : le principal après mise à jour', 'A result file (R) is needed: the master after the update'));
    const [p, mv] = [P[0], M[0]];
    const n = Number(p.synchro || p.argument.length);
    if (!(n >= 1)) throw new Error(tx(`Fichier ${p.code} : il faut au moins une rubrique d'argument pour synchroniser`, `File ${p.code}: at least one argument data element is needed to match the files`));
    verifierArgument(p, n); verifierArgument(mv, n);
    for (let k = 0; k < n; k++) {
      const [rp, rm] = [rubrique(model, p.argument[k]), rubrique(model, mv.argument[k])];
      for (const [a, r] of [[p, rp], [mv, rm]]) {
        if (r.type === '9' && (r.signe || r.decimales)) throw new Error(tx(`Fichier ${a.code} : la rubrique d'argument ${r.code} doit être un entier non signé`, `File ${a.code}: argument data element ${r.code} must be an unsigned integer`));
      }
      if (longueurFichier(rp) !== longueurFichier(rm)) throw new Error(tx(`Argument de niveau ${k + 1} : ${rp.code} (${p.code}) et ${rm.code} (${mv.code}) doivent avoir la même longueur`, `Argument level ${k + 1}: ${rp.code} (${p.code}) and ${rm.code} (${mv.code}) must have the same length`));
    }
    const mo = mv.mouvement;
    const rc = mo.rubrique ? rubrique(model, mo.rubrique) : null;
    if (!rc || !struct(mv).rubriques.includes(rc.code)) throw new Error(tx(`Fichier ${mv.code} : indiquez la rubrique du code mouvement (dans l'enregistrement ${mv.structure})`, `File ${mv.code}: choose the transaction code data element (in record ${mv.structure})`));
    if (rc.type !== 'X' || rc.longueur !== 1) throw new Error(tx(`Fichier ${mv.code} : le code mouvement ${rc.code} doit être alphanumérique d'un caractère`, `File ${mv.code}: transaction code ${rc.code} must be one alphanumeric character`));
    const vals = [mo.creation, mo.modification, mo.annulation].map((v) => String(v || '').toUpperCase());
    if (vals.some((v) => !/^[A-Z0-9]$/.test(v)) || new Set(vals).size !== 3) throw new Error(tx(`Fichier ${mv.code} : les codes création, modification et annulation doivent être trois caractères différents`, `File ${mv.code}: the create, modify and delete codes must be three different characters`));
    for (const c of struct(mv).rubriques) {
      if (c === rc.code || mv.argument.slice(0, n).includes(c)) continue;
      if (!struct(p).rubriques.includes(c)) throw new Error(tx(`Fichier ${mv.code} : la rubrique ${c} n'est pas dans l'enregistrement principal ${p.structure} : impossible de la reporter`, `File ${mv.code}: data element ${c} is not in master record ${p.structure}: it cannot be carried over`));
    }
    return { mode: 'maj', p, mv, r: R[0], n };
  }
  if (M.length || R.length) throw new Error(tx('Des mouvements (M) ou un résultat (R) sans fichier principal (P)', 'Transactions (M) or a result (R) without a master file (P)'));
  if (C.length !== 1) throw new Error(tx('Un programme d\'édition lit un et un seul fichier consulté (C)', 'A report program reads exactly one consulted file (C)'));
  const c = C[0];
  const rupture = Number(c.rupture || 0);
  if (!(rupture >= 0 && rupture <= MAX_RUPTURES)) throw new Error(tx(`Fichier ${c.code} : ${MAX_RUPTURES} niveaux de rupture au plus`, `File ${c.code}: at most ${MAX_RUPTURES} control break levels`));
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
    if (!/^[A-Z][A-Z0-9]$/.test(so.code || '')) throw new Error(tx(`Fichier en sortie : code ${so.code || '(vide)'} invalide (2 caractères, une lettre en tête)`, `Output file: invalid code ${so.code || '(empty)'} (2 characters, starting with a letter)`));
    if (CODES_RESERVES.includes(so.code)) throw new Error(tx(`Fichier en sortie : le code ${so.code} est réservé`, `Output file: code ${so.code} is reserved`));
    if (!/^[A-Z0-9][A-Z0-9.]*$/.test(so.fichier || '')) throw new Error(tx(`Fichier en sortie ${so.code} : nom de fichier invalide`, `Output file ${so.code}: invalid file name`));
    if (!so.rubriques?.length) throw new Error(tx(`Fichier en sortie ${so.code} : aucune rubrique`, `Output file ${so.code}: no data element`));
    so.rubriques.forEach((c) => rubrique(model, c));
  }
  if (new Set(sorties.map((x) => x.code)).size !== sorties.length) throw new Error(tx('Deux fichiers en sortie ont le même code', 'Two output files have the same code'));
  const tables = model.tables;
  verifierTables(model, tables);
  const cd = verifierAppels(model);
  // états (fichiers d'impression I) : niveaux de rupture du programme = RTD1 à RTDn
  const etats = preparerEtats(model, cd.mode === 'maj' ? cd.n + 1 : cd.rupture);
  const ed = emettreEtats(etats);
  const simplifiee = !etats.length; // sans état décrit, l'édition simplifiée sur la SYSOUT (F8E)
  const src = new Source();
  const dico = new Map(model.dictionnaire.map((d) => [d.code, d]));
  const struct = (a) => model.structures.find((s) => s.code === a.structure);
  const rubs = (a) => struct(a).rubriques.map((c) => rubrique(model, c));
  const K = (r) => ident(r.code);
  const generees = fonctionsGenerees(model);

  // ---------- langage structuré : analyse ----------
  const fonctions = analyserLignes(model.lignes);
  for (const [fo, sous] of fonctions) {
    const etat = etats.find((x) => `8${x.e}` === fo);
    if (etat) throw new Error(tx(`Fonction ${fo} : elle est générée pour l'état ${etat.code} ; écrivez vos lignes dans une autre fonction et appelez-la par la colonne FOSF d'une catégorie`, `Function ${fo}: it is generated for report ${etat.code}; write your lines in another function and call it from the FOSF column of a category`));
    if (generees.includes(fo) && sous.some((x) => x.sf === '99')) throw new Error(tx(`Fonction ${fo} : la sous-fonction 99 est générée (fin de la fonction) : prenez un autre code`, `Function ${fo}: sub-function 99 is generated (end of the function): pick another code`));
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
    const exiger = (n, forme, en) => { if (mots.length < n) throw new Error(tx(`${ou} : ${ope} attend ${forme}`, `${ou}: ${ope} expects ${en}`)); };
    switch (ope) {
      case '*': src.com(l.opes || ''); return;
      case 'M': exiger(2, 'une source et au moins une cible', 'a source and at least one target'); src.instr(indent, `MOVE ${mots[0]} TO ${mots.slice(1).join(' ')}`); return;
      case 'A': exiger(2, 'une valeur et une cible', 'a value and a target'); src.instr(indent, `ADD ${mots.slice(0, -1).join(' ')} TO ${mots.at(-1)}`); return;
      case 'S': exiger(2, 'une valeur et une cible', 'a value and a target'); src.instr(indent, `SUBTRACT ${mots.slice(0, -1).join(' ')} FROM ${mots.at(-1)}`); return;
      case 'C': {
        const k = mots.indexOf('=');
        if (k !== 1 || mots.length < 3) throw new Error(tx(`${ou} : C s'écrit « C CIBLE = expression » (espaces autour des opérateurs)`, `${ou}: C is written "C TARGET = expression" (spaces around the operators)`));
        src.instr(indent, `COMPUTE ${mots[0]} = ${mots.slice(2).join(' ')}`);
        return;
      }
      case 'P': {
        exiger(1, 'un code de fonction', 'a function code');
        const fo = normaliserCode(mots[0].replace(/^F/, ''));
        if (!fonctions.has(fo)) throw new Error(tx(`${ou} : P ${mots[0]} : la fonction ${fo} n'existe pas`, `${ou}: P ${mots[0]}: function ${fo} does not exist`));
        if (generees.includes(fo)) throw new Error(tx(`${ou} : P ${mots[0]} : la fonction ${fo} est générée, elle ne s'appelle pas`, `${ou}: P ${mots[0]}: function ${fo} is generated, it cannot be called`));
        src.instr(indent, `PERFORM F${fo} THRU F${fo}-FN`);
        return;
      }
      case 'MES': {
        exiger(1, 'au moins un élément à afficher', 'at least one item to display');
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
        exiger(1, 'le code d\'un fichier en sortie', 'the code of an output file');
        const so = sorties.find((x) => x.code === mots[0].toUpperCase());
        if (!so) throw new Error(tx(`${ou} : ECR ${mots[0]} : aucun fichier en sortie de ce code`, `${ou}: ECR ${mots[0]}: no output file with this code`));
        src.instr(indent, `WRITE ${so.code}-ENR`);
        return;
      }
      case 'SQL': {
        exiger(2, 'le code de la table puis le type d\'accès (R RU W RW D P RN CL)', 'the table code then the access type (R RU W RW D P RN CL)');
        const tb = tables.find((x) => x.code === mots[0].toUpperCase());
        if (!tb) throw new Error(tx(`${ou} : SQL ${mots[0]} : aucune table de ce code`, `${ou}: SQL ${mots[0]}: no table with this code`));
        const acces = mots[1].toUpperCase();
        if (!(acces in ACCES_SQL)) throw new Error(tx(`${ou} : accès ${acces} inconnu (${Object.keys(ACCES_SQL).join(' ')})`, `${ou}: unknown access ${acces} (${Object.keys(ACCES_SQL).join(' ')})`));
        for (const x of sqlAcces(acces, tb)) src.instr(indent, x);
        return;
      }
      case 'SCO': src.instr(indent, 'EXEC SQL COMMIT END-EXEC'); return;
      case 'SRO': src.instr(indent, 'EXEC SQL ROLLBACK END-EXEC'); return;
      default: throw new Error(tx(`${ou} : opérateur ${ope || '(vide)'} inconnu (${OPERATEURS_STRUCTURES.join(' ')})`, `${ou}: unknown operator ${ope || '(empty)'} (${OPERATEURS_STRUCTURES.join(' ')})`));
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
      const ou = tx(`fonction ${fo} sous-fonction ${sf.sf}`, `function ${fo} sub-function ${sf.sf}`);
      const niv = Number(sf.niv || 5);
      while (pile.length && pile.at(-1).niv >= niv) fermer(pile.at(-1).niv === niv ? sf : null);
      if (sf.tc === 'EL' && !(precedente && precedente.tc === 'IT' && Number(precedente.niv || 5) === niv)) throw new Error(tx(`${ou} : EL doit suivre une sous-fonction IT de même niveau`, `${ou}: EL must follow an IT sub-function of the same level`));
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
    if (titreF) l.push(tx(`      * Fonction ${fo} : ${titreF}`, `      * Function ${fo} : ${titreF}`).normalize('NFD').replace(/[^\x20-\x7e]/g, '').slice(0, 72));
    return [...l, ...corps(fo), `       F${fo}-FN. EXIT.`];
  };

  const enregistrement = (nom, a, niveau = '01', pref = `${nom}-`) => {
    src.a(`${niveau}  ${nom}.`);
    for (const r of rubs(a)) {
      const signe = r.type === '9' && r.signe ? ' SIGN LEADING SEPARATE' : '';
      src.b(`05 ${pref}${K(r)} PIC ${picStockage(r)}${signe}.`);
    }
  };

  const longueurEnr = (a) => rubs(a).reduce((n, r) => n + longueurFichier(r), 0);

  const sousFonctions = (fo) => { for (const l of corps(fo)) src.lignes.push(l); };
  const res = cd.mode === 'maj' ? genererMiseAJour() : genererEdition();
  return res;

  // ---------- parties communes ----------
  function entete(commentaires) {
    src.a('IDENTIFICATION DIVISION.');
    src.a(`PROGRAM-ID. ${pgm}.`);
    src.com(tx(`Programme genere : ${t.titre || pgm}`, `Generated program : ${t.titre || pgm}`).normalize('NFD').replace(/[^\x20-\x7e]/g, '').slice(0, 60));
    for (const c of commentaires) src.com(c);
    src.a('ENVIRONMENT DIVISION.');
    if (etats.length) ed.specialNames(src);
    src.a('INPUT-OUTPUT SECTION.');
    src.a('FILE-CONTROL.');
  }
  function selectSorties() {
    ed.selects(src, litteral);
    for (const so of sorties) {
      src.b(`SELECT ${so.code}-FICHIER ASSIGN TO ${litteral(so.fichier)}`);
      src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
    }
  }
  function fdSorties() {
    ed.fds(src);
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
      src.com(tx('Zones de travail, au format des rubriques du dictionnaire', 'Work areas, in the format of the dictionary elements'));
      for (const [nom, r] of travail) src.a(`01  ${nom} PIC ${picStockage(r)} VALUE ${r.type === 'X' ? 'SPACES' : 'ZERO'}.`);
    }
    if (tables.length) {
      src.com(tx('Base de donnees : code retour et zones d echange des tables', 'Database : return code and table host variables'));
      src.a('01  SQLCODE PIC S9(9) VALUE ZERO.');
      for (const tb of tables) {
        src.com(tx(`Table ${tb.nom} (code ${tb.code}), cle ${tb.cle.join(' ')}`, `Table ${tb.nom} (code ${tb.code}), key ${tb.cle.join(' ')}`));
        src.a(`01  ${tb.code}-LIGNE.`);
        for (const c of tb.rubriques) src.b(`05 ${tb.code}-${ident(c)} PIC ${picStockage(rubrique(model, c))}.`);
      }
      for (const tb of tables) {
        src.instr(0, `EXEC SQL DECLARE C-${tb.code} CURSOR FOR SELECT ${tb.rubriques.join(', ')} FROM ${tb.nom} ORDER BY ${tb.cle.join(', ')} END-EXEC.`);
      }
    }
    if (editees.size) {
      src.com(tx('Zones d affichage des nombres (MES)', 'Display areas for numbers (MES)'));
      for (const [nom, r] of editees) src.a(`01  ${nom} PIC ${picEdition(r.longueur - (r.decimales || 0), r.decimales || 0, true)}.`);
    }
  }
  // Déroulement : fonctions générées et fonctions de l'utilisateur, dans l'ordre de leurs codes.
  function derouler(generes) {
    for (const [fo, f] of Object.entries(ed.fonctions())) generes[fo] = () => f(src);
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
    if (simplifiee) for (const r of totaux) if (r.type !== '9') throw new Error(tx(`Total impossible sur une rubrique alphanumérique : ${r.code}`, `Cannot total an alphanumeric data element: ${r.code}`));
    for (const r of [...(simplifiee ? [...colonnes, ...totaux] : []), ...(t.filtres || []).map((f) => rubrique(model, f.rubrique))]) {
      if (!struct(c).rubriques.includes(r.code)) throw new Error(tx(`La rubrique ${r.code} n'est pas dans l'enregistrement ${c.structure} du fichier ${dd}`, `Data element ${r.code} is not in record ${c.structure} of file ${dd}`));
    }
    const lu = N ? `${dd}-TRIE` : `${dd}-FICHIER`;  // le fichier que lit l'itération
    const cour = N ? `1-${dd}00` : `${dd}00`;        // l'enregistrement traité

    const edition = simplifiee ? 'F8E' : etats.map((x) => `F8${x.e}`).join(' ');
    entete(EN ? [
      'F01 initialization, F05 start of iteration,',
      `${N ? 'F10 read with control break, ' : ''}F20 end of processing,`,
      `${N ? 'F22 last-record flags, ' : ''}${edition} listing, F90 writes,`,
      'F9099-ITER-FN end of iteration.',
      'The other functions are yours.',
    ] : [
      'F01 initialisations, F05 debut d iteration,',
      `${N ? 'F10 lecture avec rupture, ' : ''}F20 fin de traitement,`,
      `${N ? 'F22 calcul des derniers, ' : ''}${edition} edition, F90 ecritures,`,
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
      src.com(tx(`Fichier ${dd} : ${dd}00 lu d avance, 1-${dd}00 en traitement`, `File ${dd} : ${dd}00 read ahead, 1-${dd}00 current`));
      enregistrement(`1-${dd}00`, c);
    }
    variablesConditionnelles({ niveaux: N, aRupture: N ? [[dd, N]] : [], tous: [dd] });
    src.a(`01  5-${dd}00.`);
    src.b(`05 5-${dd}00-CPTENR PIC 9(9) VALUE ZERO.`);
    src.a('01  NB-LUS PIC 9(9) VALUE ZERO.');
    if (ed.aDesIndices()) {
      src.a('01  INDICES COMPUTATIONAL SYNC.');
      ed.indices(src);
    }
    if (!simplifiee) {
      ed.working(src);
      zonesCommunes();
    } else editionSimplifiee();
    function editionSimplifiee() {
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
    src.com(tx('Lignes de l etat', 'Listing lines'));
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
      const champs = [{ valeur: n === 'G' ? tx('TOTAL GENERAL', 'GRAND TOTAL') : tx(`${marge(i)}  TOTAL ${ruptures[i].code}`, `${marge(i)}  ${ruptures[i].code} TOTAL`) }];
      if (t.compter) champs.push({ valeur: tx('  NB : ', '  COUNT : ') }, { nom: `L${n}-NB`, pic: 'Z(6)9' });
      for (const r of totaux) {
        const ent = r.longueur - (r.decimales || 0) + 4;
        champs.push({ valeur: `  ${r.code} : ` }, { nom: `L${n}-${ident(r.code)}`, pic: picEdition(ent, r.decimales || 0, true) });
      }
      emettreLigneEdition(src, `L-TOTAL-${n}`, champs);
    }
    src.a('01  L-COMPTES.');
    src.b(tx('05 FILLER PIC X(14) VALUE "LUS / RETENUS ".', '05 FILLER PIC X(14) VALUE "READ / KEPT   ".'));
    src.b('05 LC-LUS PIC Z(6)9.');
    src.b('05 FILLER PIC X(3) VALUE " / ".');
    src.b('05 LC-RETENUS PIC Z(6)9.');
    }

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
      for (const code of [dd, ...ed.codes(), ...sorties.map((x) => x.code)].sort(comparerCodes)) {
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
      for (const code of [dd, ...ed.codes(), ...sorties.map((x) => x.code)].sort(comparerCodes)) {
        src.a(`F20${code}. CLOSE ${code === dd ? lu : `${code}-FICHIER`}.`);
        src.a(`F20${code}-FN. EXIT.`);
      }
    };
    src.a('PROCEDURE DIVISION.');
    derouler({
      '01': () => {
        src.a('F01.');
        if (simplifiee) {
          src.b('DISPLAY L-TITRE');
          src.b('DISPLAY L-ENTETE');
          src.b('DISPLAY L-TRAIT.');
        }
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
          src.com(tx('Sans synchronisation : ruptures totales = celles du fichier', 'No matching : overall breaks = those of the file'));
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
        if (simplifiee) {
          if (t.compter) src.b('MOVE G-NB TO LG-NB');
          for (const r of totaux) src.b(`MOVE G-${ident(r.code)} TO LG-${ident(r.code)}`);
          src.b('DISPLAY L-TRAIT');
          src.b('DISPLAY L-TOTAL-G');
          src.b('MOVE NB-LUS TO LC-LUS');
          src.b(`MOVE 5-${dd}00-CPTENR TO LC-RETENUS`);
          src.b('DISPLAY L-COMPTES.');
        }
        sousFonctions('20');
        fermetures();
        src.a('F2099. STOP RUN.');
        src.a('F2099-FN. EXIT.');
        src.a('F20-FN. EXIT.');
      },
      ...(simplifiee ? { '8E': f8e } : {}),
      '90': finIteration,
    });
    return src.texte();
    function f8e() {
      {
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
      }
    }
  }
  // ================= Programme de mise à jour (principal P, mouvements M, résultat R) =================
  // Squelette du chapitre 6 : F10 lecture avec rupture du principal et des mouvements, F22 derniers,
  // F24 configuration (indicatif minimum ddIND, dd-CFn) et occurrence (dd-OCn, 2-dd00), F26 ruptures
  // totales, F30 variables de contrôle, F33 code mouvement (I02), F39 présence des rubriques, F70
  // correspondance (GR-PR 8 ou 9), F73 mise à jour de 2-dd00, F76 erreurs et reprise de l'état
  // initial (GM-ER), F90 écriture du résultat.
  // Écarts assumés : les deux fichiers sont triés avant lecture ; chaque enregistrement mouvement est un
  // mouvement (un niveau de rupture de plus que la synchronisation, sans rubrique) ; pas de contrôle de
  // contenu (F45) ; le compte rendu est affiché sur la SYSOUT au lieu d'un fichier d'utilisation E.
  function genererMiseAJour() {
    const { p, mv, r: rs, n: N } = cd;
    const [PP, MM, RR] = [p.code, mv.code, rs.code];
    const argP = p.argument.slice(0, N).map((x) => rubrique(model, x));
    const argM = mv.argument.slice(0, N).map((x) => rubrique(model, x));
    const mo = mv.mouvement;
    const rc = rubrique(model, mo.rubrique);
    const codes = [mo.creation, mo.modification, mo.annulation].map((v) => String(v).toUpperCase());
    // rubriques du mouvement reportées sur le principal (ni argument, ni code mouvement)
    const reportees = rubs(mv).filter((r) => r.code !== rc.code && !argM.includes(r));
    const titre = (t.titre || pgm).normalize('NFD').replace(/[^\x20-\x7e]/g, '').toUpperCase();
    const niveaux = [...Array(N).keys()].map((i) => i + 1);
    const NM = N + 1; // niveau du mouvement
    const entrees = [p, mv].sort((a, b) => comparerCodes(a.code, b.code));
    const fichiersOuverts = [PP, MM, RR, ...ed.codes(), ...sorties.map((x) => x.code)].sort(comparerCodes);
    const lu = (a) => `${a.code}-TRIE`;
    const arg = (a) => (a === p ? argP : argM);

    entete(EN ? [
      'Update : F01 initialization, F10 read with control break,',
      'F20 end, F22 last records, F24 configuration, F26 overall',
      'breaks, F30 control, F33 transaction code, F39 presence,',
      'F70 matching, F73 update, F76 errors and recovery,',
      'F90 writes.',
    ] : [
      'Mise a jour : F01 initialisations, F10 lecture avec rupture,',
      'F20 fin, F22 derniers, F24 configuration, F26 ruptures',
      'totales, F30 controle, F33 code mouvement, F39 presence,',
      'F70 correspondance, F73 mise a jour, F76 erreurs et reprise,',
      'F90 ecritures.',
    ]);
    for (const a of entrees) {
      src.b(`SELECT ${a.code}-FICHIER ASSIGN TO ${litteral(a.fichier)}`);
      src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
      src.b(`SELECT ${a.code}-TRIE ASSIGN TO "${a.code}TRIE.TMP"`);
      src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
      src.b(`SELECT ${a.code}-TRAVAIL ASSIGN TO "${a.code}TRAV.TMP".`);
    }
    src.b(`SELECT ${RR}-FICHIER ASSIGN TO ${litteral(rs.fichier)}`);
    src.b('    ORGANIZATION IS LINE SEQUENTIAL.');
    selectSorties();
    src.a('DATA DIVISION.');
    src.a('FILE SECTION.');
    for (const a of entrees) {
      src.a(`FD  ${a.code}-FICHIER.`);
      src.a(`01  ${a.code}-ENR PIC X(${longueurEnr(a)}).`);
      src.a(`SD  ${a.code}-TRAVAIL.`);
      enregistrement(`${a.code}-TRV`, a);
      src.a(`FD  ${a.code}-TRIE.`);
      enregistrement(`${a.code}00`, a);
    }
    src.a(`FD  ${RR}-FICHIER.`);
    enregistrement(`${RR}00`, p);
    fdSorties();
    src.a('WORKING-STORAGE SECTION.');
    src.com(tx(`Principal : 1-${PP}00 en traitement, 2-${PP}00 mis a jour`, `Master : 1-${PP}00 current, 2-${PP}00 updated`));
    enregistrement(`1-${PP}00`, p);
    enregistrement(`2-${PP}00`, p);
    src.com(tx(`Mouvement en traitement ; zones X pour tester la presence`, `Current transaction ; X fields to test presence`));
    src.a(`01  1-${MM}00.`);
    for (const r of rubs(mv)) {
      const signe = r.type === '9' && r.signe ? ' SIGN LEADING SEPARATE' : '';
      src.b(`05 1-${MM}00-${K(r)} PIC ${picStockage(r)}${signe}.`);
      if (r.type === '9') src.b(`05 1-${MM}00-${K(r)}X REDEFINES 1-${MM}00-${K(r)} PIC X(${longueurFichier(r)}).`);
    }
    src.com(tx('Indicatifs lus (ddIND) et indicatif de l iteration (IND)', 'Keys read (ddIND) and key of the iteration (IND)'));
    for (const a of entrees) {
      src.a(`01  ${a.code}IND.`);
      arg(a).forEach((r, i) => {
        src.b(`05 ${a.code}IND${i + 1}.`);
        src.i(1, `10 ${a.code}-IN-${K(r)} PIC ${picStockage(r)}.`);
      });
    }
    src.a('01  IND.');
    argP.forEach((r, i) => src.b(`05 IND${i + 1} PIC X(${longueurFichier(r)}).`));
    variablesConditionnelles({
      niveaux: NM,
      aRupture: entrees.map((a) => [a.code, a === mv ? NM : N]),
      synchro: entrees.map((a) => [a.code, N]),
      occurrence: [[PP, N]],
      tous: entrees.map((a) => a.code),
    });
    src.a('01  INDICES COMPUTATIONAL SYNC.');
    src.b('05 I01 PIC S9(4) VALUE +1.');
    src.b('05 I02 PIC S9(4) VALUE +4.');
    ed.indices(src);
    src.com(tx('Erreurs : EN-EI identification, PR-00 presence des rubriques,', 'Errors : EN-EI identification, PR-00 element presence,'));
    src.com(tx('GR-PR et GR-EG le mouvement, GM-ER le groupe de mouvements', 'GR-PR and GR-EG the transaction, GM-ER the group'));
    src.a('01  EN-PRR.');
    src.b('05 EN-EI PIC X.');
    src.b('05 PR-00.');
    for (const r of rubs(mv)) src.i(1, `10 PR-00-${K(r)} PIC X.`);
    src.a('01  GR-PRR.');
    src.b('05 GR-PR PIC X OCCURS 1.');
    src.b('05 GR-EG PIC X VALUE \'1\'.');
    src.a('01  GR-PRE PIC X VALUE \'0\'.');
    src.a('01  GM-ER PIC X VALUE \'0\'.');
    for (const c of [PP, MM, RR]) {
      src.a(`01  5-${c}00.`);
      src.b(`05 5-${c}00-CPTENR PIC 9(9) VALUE ZERO.`);
    }
    src.a('01  NB-REJETES PIC 9(9) VALUE ZERO.');
    if (etats.length) ed.working(src);
    zonesCommunes();
    src.com(tx('Lignes du compte rendu', 'Run report lines'));
    emettreLigneEdition(src, 'L-TITRE', [{ valeur: titre }]);
    emettreLigneEdition(src, 'L-ERREUR', [
      { valeur: tx('MVT ', 'TRN ') },
      ...argM.flatMap((r, i) => [...(i ? [{ blanc: 1 }] : []), { nom: `LE-A${i + 1}`, pic: `X(${longueurFichier(r)})` }]),
      { blanc: 1 }, { nom: 'LE-CODE', pic: 'X' }, { valeur: ' : ' }, { nom: 'LE-MESSAGE', pic: 'X(40)' },
    ]);
    emettreLigneEdition(src, 'L-REPRISE', [{ valeur: tx('INDICATIF ', 'KEY ') }, { nom: 'LR-IND', pic: `X(${argP.reduce((s, r) => s + longueurFichier(r), 0)})` }, { valeur: tx(' : AUCUNE MISE A JOUR', ' : NO UPDATE') }]);
    emettreLigneEdition(src, 'L-COMPTE', [{ nom: 'LC-LIBELLE', pic: 'X(20)' }, { valeur: ' : ' }, { nom: 'LC-NOMBRE', pic: 'Z(6)9' }]);

    const compte = (libelle, zone) => {
      src.b(`MOVE ${litteral(libelle)} TO LC-LIBELLE`);
      src.b(`MOVE ${zone} TO LC-NOMBRE`);
      src.b('DISPLAY L-COMPTE');
    };
    const si = (cond, sortie) => { src.instr(0, `IF ${cond}`); src.b(`    NEXT SENTENCE ELSE GO TO ${sortie}.`); };

    src.a('PROCEDURE DIVISION.');
    derouler({
      '01': () => {
        src.a('F01.');
        src.b('DISPLAY L-TITRE.');
        for (const code of fichiersOuverts) {
          const a = entrees.find((x) => x.code === code);
          src.a(`F01${code}.`);
          if (a) {
            src.b(`SORT ${code}-TRAVAIL`);
            for (const r of arg(a)) src.b(`    ON ASCENDING KEY ${code}-TRV-${K(r)}`);
            if (a === mv) src.b('    WITH DUPLICATES IN ORDER');
            src.b(`    USING ${code}-FICHIER GIVING ${code}-TRIE.`);
            src.b(`OPEN INPUT ${lu(a)}.`);
            src.a(`F01${code}-10. READ ${lu(a)} AT END`);
            src.b(`    MOVE 1 TO ${code}-FI.`);
          } else src.b(`OPEN OUTPUT ${code}-FICHIER.`);
          src.a(`F01${code}-FN. EXIT.`);
        }
        sousFonctions('01');
        src.a('F01-FN. EXIT.');
      },
      '05': () => {
        src.a('F05. EXIT.');
        sousFonctions('05');
        src.a('F05-FN. EXIT.');
      },
      '10': () => {
        src.a('F10. EXIT.');
        for (const a of entrees) {
          const dd = a.code;
          src.a(`F10${dd}.`);
          si(a === p ? `RTD${N} = '1' AND ${dd}-CF${N} = '1'` : `${dd}-CF${N} = '1'`, `F10${dd}-FN`);
          src.a(`F10${dd}-10. MOVE ${dd}-DE TO ${dd}-PE.`);
          src.b(`IF ${dd}-FI = '1'`);
          src.b(`    MOVE HIGH-VALUE TO ${dd}IND`);
          src.b(`    MOVE 1 TO ${dd}-FT GO TO F10${dd}-FN.`);
          src.b(`MOVE ${dd}00 TO 1-${dd}00.`);
          for (const r of arg(a)) src.b(`MOVE ${dd}00-${K(r)} TO ${dd}-IN-${K(r)}.`);
          src.b(`ADD 1 TO 5-${dd}00-CPTENR.`);
          src.b(`READ ${lu(a)} AT END`);
          src.b(`    MOVE 1 TO ${dd}-FI.`);
          src.a(`F10${dd}-FN. EXIT.`);
        }
        sousFonctions('10');
        src.a('F10-FN. EXIT.');
      },
      '20': () => {
        src.a('F20. IF FT = ALL \'1\'');
        src.b('NEXT SENTENCE ELSE GO TO F20-FN.');
        src.b('DISPLAY " "');
        compte(tx('PRINCIPAL LU', 'MASTER READ'), `5-${PP}00-CPTENR`);
        compte(tx('MOUVEMENTS LUS', 'TRANS. READ'), `5-${MM}00-CPTENR`);
        compte(tx('MOUVEMENTS REJETES', 'TRANS. REJECTED'), 'NB-REJETES');
        compte(tx('RESULTAT ECRIT', 'RESULT WRITTEN'), `5-${RR}00-CPTENR`);
        src.lignes[src.lignes.length - 1] += '.';
        sousFonctions('20');
        for (const code of fichiersOuverts) {
          const a = entrees.find((x) => x.code === code);
          src.a(`F20${code}. CLOSE ${a ? lu(a) : `${code}-FICHIER`}.`);
          src.a(`F20${code}-FN. EXIT.`);
        }
        src.a('F2099. STOP RUN.');
        src.a('F2099-FN. EXIT.');
        src.a('F20-FN. EXIT.');
      },
      '22': () => {
        src.a('F22. EXIT.');
        for (const a of entrees) {
          const dd = a.code;
          const n = a === mv ? NM : N;
          src.a(`F22${dd}. MOVE ZERO TO ${dd}-DE.`);
          src.b(`IF ${dd}-FI = '1' GO TO F22${dd}-1.`);
          arg(a).forEach((r, i) => {
            src.b(`IF ${dd}00-${K(r)} NOT = 1-${dd}00-${K(r)}`);
            src.b(`    GO TO F22${dd}-${i + 1}.`);
          });
          if (a === mv) src.com(tx('Chaque enregistrement est un mouvement a lui seul', 'Each record is a transaction on its own'));
          src.b(a === mv ? `GO TO F22${dd}-${NM}.` : `GO TO F22${dd}-FN.`);
          for (let i = 1; i <= n; i++) src.a(`F22${dd}-${i}. MOVE 1 TO ${dd}-DE${i}.`);
          src.a(`F22${dd}-FN. EXIT.`);
        }
        sousFonctions('22');
        src.a('F22-FN. EXIT.');
      },
      '24': () => {
        src.a('F24. MOVE ZERO TO VCF MOVE HIGH-VALUE TO IND.');
        for (const a of entrees) src.b(`IF IND > ${a.code}IND MOVE ${a.code}IND TO IND.`);
        for (const a of entrees) {
          const dd = a.code;
          src.a(`F24${dd}. IF ${dd}IND1 = IND1`);
          src.b(`    MOVE 1 TO ${dd}-CF1.`);
          for (let i = 2; i <= N; i++) {
            src.b(`IF ${dd}IND${i} = IND${i}`);
            src.b(`    MOVE ${dd}-CF${i - 1} TO ${dd}-CF${i}.`);
          }
          src.a(`F24${dd}-FN. EXIT.`);
        }
        for (const i of niveaux) {
          const sf = `F240${i}`;
          src.a(`${sf}.`);
          si(`RTD${i} = '1'`, `${sf}-FN`);
          src.b(`MOVE ${PP}-CF${i} TO ${PP}-OC${i}.`);
          if (i === N) {
            src.b(`IF ${PP}-CF${i} NOT = '1'`);
            src.b(`    MOVE SPACE TO 2-${PP}00`);
            src.b('ELSE');
            src.b(`    MOVE 1-${PP}00 TO 2-${PP}00.`);
          }
          src.a(`${sf}-FN. EXIT.`);
        }
        sousFonctions('24');
        src.a('F24-FN. EXIT.');
      },
      '26': () => {
        src.a('F26. MOVE RTD TO RTP. MOVE ZERO TO RTD.');
        src.b('MOVE NRD TO NRP. MOVE ZERO TO NRD.');
        for (const i of niveaux) {
          for (const a of entrees) src.b(`IF (${a.code}-CF${i} = '0' OR ${a.code}-DE${i} = '1' AND ${a.code}-CF${N} = '1')`);
          src.b(`MOVE ${i} TO NRD GO TO F26-${i}.`);
        }
        src.b(`IF ${MM}-CF${N} = '0' OR ${MM}-DE${NM} = '1'`);
        src.b(`MOVE ${NM} TO NRD GO TO F26-${NM}.`);
        src.b('GO TO F26-FN.');
        for (let i = 1; i <= NM; i++) src.a(`F26-${i}. MOVE 1 TO RTD${i}.`);
        src.a('F26-FN. EXIT.');
      },
      '30': () => {
        src.a('F30.');
        si(`${MM}-CF${N} = '1'`, 'F30-FN');
        src.b('MOVE ZERO TO EN-PRR.');
        src.b(`IF ${MM}-PE${NM} = '1'`);
        src.b('MOVE ZERO TO GR-PRR MOVE 1 TO GR-EG.');
        src.b(`IF RTP${N} = '1'`);
        src.b('MOVE 0 TO GM-ER.');
        sousFonctions('30');
        src.a('F30-FN. EXIT.');
      },
      '33': () => {
        src.a('F33.');
        si(`${MM}-CF${N} = '1'`, 'F33-FN');
        src.com(tx('Un seul type d enregistrement : rang 1', 'A single record type : rank 1'));
        src.a('F33AA. MOVE 1 TO I01.');
        src.a('F33AA-01. IF EN-EI = \'0\' MOVE 1 TO GR-PR (I01).');
        src.a('F33AA-FN. EXIT.');
        src.a('F33BB.');
        codes.forEach((v, i) => {
          src.b(`IF 1-${MM}00-${K(rc)} = ${litteral(v)}`);
          src.b(`MOVE ${i + 1} TO I02 GO TO F33BB-FN.`);
        });
        src.b('MOVE 6 TO EN-EI.');
        src.a('F33BB-FN. EXIT.');
        sousFonctions('33');
        src.a('F33-FN. EXIT.');
      },
      '39': () => {
        src.a('F39.');
        si(`${MM}-CF${N} = '1' AND EN-EI = '0'`, 'F39-FN');
        src.a('F3900.');
        for (const r of rubs(mv)) {
          src.b(`IF 1-${MM}00-${K(r)}${r.type === '9' ? 'X' : ''} NOT = SPACE`);
          src.b(`MOVE 1 TO PR-00-${K(r)}.`);
        }
        src.a('F3900-FN. EXIT.');
        sousFonctions('39');
        src.a('F39-FN. EXIT.');
      },
      '70': () => {
        src.a('F70.');
        si(`${MM}-CF${N} = '1' AND EN-EI = '0'`, 'F70-FN');
        si(`${MM}-PE${NM} = '1'`, 'F70-FN');
        src.a(`F70${PP}. IF I02 = 1 AND ${PP}-OC${N} = '1'`);
        src.b('MOVE 8 TO GR-PR (I01).');
        src.b(`IF I02 NOT = 1 AND ${PP}-OC${N} = '0'`);
        src.b('MOVE 9 TO GR-PR (I01).');
        src.a(`F70${PP}-FN. EXIT.`);
        sousFonctions('70');
        src.a('F70-FN. EXIT.');
      },
      '73': () => {
        src.a('F73.');
        si(`${MM}-CF${N} = '1' AND EN-EI = '0'`, 'F73-FN');
        src.b('IF GR-PR (I01) NOT = \'1\' GO TO F73-FN.');
        src.a('F7301.');
        src.b(`IF I02 = 3 MOVE 0 TO ${PP}-OC${N}`);
        src.b('GO TO F7301-FN.');
        src.b(`IF I02 = 1 MOVE 1 TO ${PP}-OC${N}.`);
        argP.forEach((r, i) => src.b(`MOVE 1-${MM}00-${K(argM[i])} TO 2-${PP}00-${K(r)}.`));
        for (const r of reportees) {
          src.b(`IF PR-00-${K(r)} = '1'`);
          src.b(`MOVE 1-${MM}00-${K(r)} TO 2-${PP}00-${K(r)}.`);
        }
        src.a('F7301-FN. EXIT.');
        sousFonctions('73');
        src.a('F73-FN. EXIT.');
      },
      '76': () => {
        src.a('F76.');
        si(`${MM}-CF${N} = '1'`, 'F76-FN');
        src.a('F76-A. IF EN-EI NOT = \'0\' MOVE EN-EI TO GR-EG');
        src.b('GO TO F76-C. MOVE GR-PR (I01) TO GR-PRE.');
        src.b('IF GR-PRE < \'0\' OR GR-PRE > \'1\'');
        src.b('MOVE GR-PRE TO GR-EG GO TO F76-C.');
        src.a('F76-C. IF GR-EG NOT = \'1\' MOVE \'1\' TO GM-ER.');
        src.com(tx('Compte rendu sur la SYSOUT : le mouvement en erreur', 'Run report on SYSOUT : the transaction in error'));
        src.a('F76-CR.');
        si('GR-EG NOT = \'1\'', 'F76-CR-FN');
        src.b('ADD 1 TO NB-REJETES.');
        argM.forEach((r, i) => src.b(`MOVE 1-${MM}00-${K(r)}${r.type === '9' ? 'X' : ''} TO LE-A${i + 1}.`));
        src.b(`MOVE 1-${MM}00-${K(rc)} TO LE-CODE.`);
        src.b('MOVE SPACE TO LE-MESSAGE.');
        for (const [code, message] of [['6', tx('CODE MOUVEMENT INCONNU', 'UNKNOWN TRANSACTION CODE')], ['8', tx('CREATION : INDICATIF DEJA PRESENT', 'CREATE : KEY ALREADY EXISTS')], ['9', tx('INDICATIF ABSENT DU PRINCIPAL', 'KEY NOT IN THE MASTER FILE')]]) {
          src.b(`IF GR-EG = '${code}'`);
          src.b(`MOVE "${message}" TO LE-MESSAGE.`);
        }
        src.b('DISPLAY L-ERREUR.');
        src.a('F76-CR-FN. EXIT.');
        src.a(`F76${PP}.`);
        si(`RTD${N} = '1' AND GM-ER = '1'`, `F76${PP}-FN`);
        src.b(`MOVE ${PP}-CF${N} TO ${PP}-OC${N}.`);
        src.b(`IF ${PP}-CF${N} = '1'`);
        src.b(`MOVE 1-${PP}00 TO 2-${PP}00.`);
        src.b('MOVE IND TO LR-IND.');
        src.b('DISPLAY L-REPRISE.');
        src.a(`F76${PP}-FN. EXIT.`);
        sousFonctions('76');
        src.a('F76-FN. EXIT.');
      },
      '90': () => {
        src.a('F90. EXIT.');
        src.a(`F90${RR}.`);
        si(`${PP}-OC${N} = '1' AND RTD${N} = '1'`, `F90${RR}-FN`);
        src.b(`MOVE 2-${PP}00 TO ${RR}00.`);
        src.b(`WRITE ${RR}00.`);
        src.a(`F90${RR}-99. ADD 1 TO 5-${RR}00-CPTENR.`);
        src.a(`F90${RR}-FN. EXIT.`);
        sousFonctions('90');
        src.a('F90-FN. EXIT.');
        src.a('F9099-ITER-FN. GO TO F05.');
      },
    });
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
      if (j >= c.length) throw new Error(tx(`${ou} : littéral non fermé dans la condition`, `${ou}: unclosed literal in the condition`));
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
  R: tx('lire par la clé (SELECT)', 'read by key (SELECT)'),
  RU: tx('lire pour mettre à jour (SELECT FOR UPDATE)', 'read for update (SELECT FOR UPDATE)'),
  W: tx('insérer (INSERT)', 'insert (INSERT)'),
  RW: tx('mettre à jour par la clé (UPDATE)', 'update by key (UPDATE)'),
  D: tx('supprimer par la clé (DELETE)', 'delete by key (DELETE)'),
  DC: tx('déclarer le curseur (déjà fait par le générateur)', 'declare the cursor (already done by the generator)'),
  P: tx('ouvrir le curseur : toute la table, dans l\'ordre de la clé (OPEN)', 'open the cursor: the whole table, in key order (OPEN)'),
  RN: tx('ligne suivante du curseur (FETCH)', 'next row of the cursor (FETCH)'),
  CL: tx('fermer le curseur (CLOSE)', 'close the cursor (CLOSE)'),
};
export const OPERATEURS_BASE = { ...Object.fromEntries(Object.entries(ACCES_SQL).map(([k, v]) => [`SQL CL ${k}`, v])), SCO: tx('valider (COMMIT)', 'commit (COMMIT)'), SRO: tx('annuler (ROLLBACK)', 'roll back (ROLLBACK)') };

export function verifierTables(model, tables = model.tables || []) {
  const codes = new Set((model.sorties || []).map((x) => x.code));
  const noms = new Set();
  for (const tb of tables) {
    if (!/^[A-Z][A-Z0-9]$/.test(tb.code || '')) throw new Error(tx(`Table : code ${tb.code || '(vide)'} invalide (2 caractères, une lettre en tête)`, `Table: invalid code ${tb.code || '(empty)'} (2 characters, starting with a letter)`));
    if (['ED', 'LC', 'LG', 'RT', 'NB', 'IN', 'SQ'].includes(tb.code)) throw new Error(tx(`Table : le code ${tb.code} est réservé`, `Table: code ${tb.code} is reserved`));
    if (codes.has(tb.code)) throw new Error(tx(`Table ${tb.code} : code déjà pris par un fichier en sortie ou une autre table`, `Table ${tb.code}: code already used by an output file or another table`));
    codes.add(tb.code);
    if (!/^[A-Z][A-Z0-9_]{0,17}$/.test(tb.nom || '')) throw new Error(tx(`Table ${tb.code} : nom invalide (lettres, chiffres, _ ; 18 au plus)`, `Table ${tb.code}: invalid name (letters, digits, _; 18 at most)`));
    if (noms.has(tb.nom)) throw new Error(tx(`Deux tables s'appellent ${tb.nom}`, `Two tables are named ${tb.nom}`));
    noms.add(tb.nom);
    if (!tb.rubriques?.length) throw new Error(tx(`Table ${tb.code} : aucune rubrique`, `Table ${tb.code}: no data element`));
    tb.rubriques.forEach((c) => rubrique(model, c));
    if (new Set(tb.rubriques).size !== tb.rubriques.length) throw new Error(tx(`Table ${tb.code} : rubrique en double`, `Table ${tb.code}: duplicate data element`));
    if (!tb.cle?.length) throw new Error(tx(`Table ${tb.code} : il faut une clé`, `Table ${tb.code}: a key is needed`));
    const k = tb.cle.find((c) => !tb.rubriques.includes(c));
    if (k) throw new Error(tx(`Table ${tb.code} : la clé ${k} doit être une rubrique de la table`, `Table ${tb.code}: key ${k} must be a data element of the table`));
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
      if (!autres.length) throw new Error(tx(`SQL ${tb.code} RW ${tb.code} : la table n'a que des rubriques de clé, rien à mettre à jour`, `SQL ${tb.code} RW ${tb.code}: the table only has key elements, nothing to update`));
      return [`EXEC SQL UPDATE ${tb.nom} SET ${liste(autres.map((c) => `${c} = ${hv(c)}`))} ${ou} END-EXEC`];
    case 'D': return [`EXEC SQL DELETE FROM ${tb.nom} ${ou} END-EXEC`];
    case 'DC': return ['CONTINUE'];
    case 'P': return [`EXEC SQL OPEN C-${tb.code} END-EXEC`];
    case 'RN': return [`EXEC SQL FETCH C-${tb.code} INTO ${liste(tb.rubriques.map(hv))} END-EXEC`];
    case 'CL': return [`EXEC SQL CLOSE C-${tb.code} END-EXEC`];
  }
}
