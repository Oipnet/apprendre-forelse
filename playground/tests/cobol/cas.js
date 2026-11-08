// Les cas comparés à GnuCOBOL : un modèle par cas, à partir des exemples de l'atelier.
import { exemple, exempleMiseAJour, normaliser } from '../../src/cobol/generateur.js';
import { DEFIS } from '../../src/cobol/defis.js';

// GnuCOBOL ne compile pas EXEC SQL sans précompilateur : les cas comparés laissent la base de côté
// (elle est testée à part, sur SQLite : voir tests/cobol.test.ts).
const copie = (o) => {
  const m = JSON.parse(JSON.stringify(o));
  m.tables = [];
  m.lignes = (m.lignes || []).filter((l) => !(l.fo === '30' || (l.fo === '20' && l.sf !== '10')));
  return m;
};
const cas = [];
const fa = (m) => m.appels[0];
const ruptures = (m, r) => { fa(m).argument = r; fa(m).rupture = r.length; };
cas.push(['exemple (sans la base)', copie(exemple)]);
{ const m = copie(exemple); ruptures(m, []); m.traitement.filtres = []; cas.push(['sans rupture ni filtre', m]); }
{ const m = copie(exemple); m.traitement.detail = false; m.traitement.filtres = [{ rubrique: 'MTFACT', op: '>', valeur: '100' }]; cas.push(['sans detail, filtre > 100', m]); }
{ const m = copie(exemple); m.traitement.filtres = [{ rubrique: 'REGION', op: 'NOT =', valeur: 'EST' }, { rubrique: 'MTFACT', op: '<', valeur: '1000' }]; m.traitement.compter = false; cas.push(['deux filtres, sans comptage', m]); }
{
  const m = copie(exemple);
  m.dictionnaire.push({ code: 'QTE', libelle: 'Quantité', type: '9', longueur: 4 });
  m.dictionnaire.push({ code: 'PU', libelle: 'Prix unitaire', type: '9', longueur: 5, decimales: 3 });
  m.structures[0].rubriques.push('QTE', 'PU');
  ruptures(m, ['NUMCLI']);
  m.traitement.colonnes = ['NUMCLI', 'REGION', 'QTE', 'PU', 'MTFACT'];
  m.lignes = []; m.sorties = [];
  m.traitement.totaux = ['QTE', 'MTFACT'];
  m.traitement.filtres = [];
  fa(m).donnees = [
    ['NOR', '12', 'DUPONT', '-1250.5', '3', '12.125'],
    ['SUD', '7', 'MARTIN', '-0.01', '0', '0'],
    ['NOR', '12', 'DUPONT', '9999.99', '9999', '99.999'],
    ['EST', '1', 'GARNIER', '0.5', '12', '1'],
  ];
  cas.push(['rupture numerique, decimales 3, negatifs', m]);
}
{
  const m = copie(exemple);
  ruptures(m, ['REGION', 'NOMCLI']);
  fa(m).donnees.push({ REGION: 'NOR', NUMCLI: '12', NOMCLI: 'DUPONT', MTFACT: '10' }, { REGION: 'SUD', NUMCLI: '7', NOMCLI: 'MARTIN', MTFACT: '-5.5' });
  cas.push(['deux niveaux de rupture', m]);
}
{
  const m = copie(exemple);
  m.dictionnaire.push({ code: 'ANNEE', libelle: 'Année', type: '9', longueur: 4 });
  m.structures[0].rubriques.unshift('ANNEE');
  ruptures(m, ['ANNEE', 'REGION', 'NUMCLI']);
  m.traitement.detail = false;
  fa(m).donnees = fa(m).donnees.flatMap((l, i) => [{ ...l, ANNEE: '2025' }, { ...l, ANNEE: i % 2 ? '2026' : '2025', MTFACT: '1.01' }]);
  cas.push(['trois niveaux, sans detail', m]);
}
{
  const m = copie(exemple);
  m.dictionnaire.push({ code: 'MAXI', libelle: 'Plus gros montant', type: '9', longueur: 9, decimales: 2, signe: true });
  ruptures(m, ['REGION', 'NUMCLI']);
  m.lignes.push(
    { fo: '30', sf: '10', ope: 'N', opes: 'Plus gros montant', niv: '05', tc: 'IT', cond: 'FA00-MTFACT>W-MAXI' },
    { fo: '30', sf: '10', ope: 'M', opes: 'FA00-MTFACT W-MAXI' },
    { fo: '30', sf: '20', ope: 'N', opes: 'Dernier client de la region', niv: '05', tc: 'IT', cond: 'RTD1=1 ET RTP2=1' },
    { fo: '30', sf: '20', ope: 'MES', opes: "'  (seul client de son groupe : ' FA00-NOMCLI ')'" },
    { fo: '30', sf: '30', ope: 'N', opes: 'Sinon', niv: '05', tc: 'EL' },
    { fo: '30', sf: '30', ope: '*', opes: 'rien a signaler' },
    { fo: '30', sf: '30', ope: 'C', opes: 'W-NBREL = W-NBREL + 0' },
    { fo: '30', sf: '40', ope: 'N', opes: 'Region # NOR', niv: '05', tc: 'IT', cond: "FA00-REGION # 'NOR'" },
    { fo: '30', sf: '50', ope: 'N', opes: 'Gros montant', niv: '10', tc: 'IT', cond: 'FA00-MTFACT >= 99.99 OU FA00-MTFACT < -50' },
    { fo: '30', sf: '50', ope: 'P', opes: '95' },
    { fo: '95', sf: '10', ope: 'N', opes: 'Signalement', niv: '05', tc: 'BL' },
    { fo: '95', sf: '10', ope: 'MES', opes: "'  >> A SURVEILLER : ' FA00-MTFACT" },
    { fo: '03', sf: '10', ope: 'N', opes: 'Debut', niv: '05', tc: 'BL' },
    { fo: '03', sf: '10', ope: 'MES', opes: "'DEBUT DU TRAITEMENT'" },
    { fo: '20', sf: '50', ope: 'N', opes: 'Max', niv: '05', tc: 'BL' },
    { fo: '20', sf: '50', ope: 'MES', opes: "'PLUS GROS MONTANT : ' W-MAXI" },
  );
  cas.push(['langage structure : IT EL BL, niveaux, P, MES, C', m]);
}
{
  const m = copie(exemple);
  m.dictionnaire.push({ code: 'CPT', libelle: 'Compteur', type: '9', longueur: 3 });
  m.lignes = [
    { fo: '02', sf: '10', ope: 'N', opes: 'Boucle', niv: '05', tc: 'DW', cond: 'W-CPT < 3' },
    { fo: '02', sf: '10', ope: 'A', opes: '1 W-CPT' },
    { fo: '02', sf: '10', ope: 'MES', opes: "'TOUR ' W-CPT" },
  ];
  cas.push(['langage structure : DW', m]);
}
// Programme d'ancien format (avant l'appel des fichiers) : repris tel quel
{
  const m = copie(exemple);
  const c = m.appels[0];
  delete m.appels;
  Object.assign(m.traitement, { structure: c.structure, fichier: c.fichier, ruptures: c.argument });
  m.donnees = c.donnees;
  m.lignes = [
    { fo: '85', sf: '10', ope: 'N', opes: 'Negatifs', niv: '05', tc: 'IT', cond: 'E-MTFACT < 0' },
    { fo: '85', sf: '10', ope: 'M', opes: "'** A RELANCER' D-NOMCLI" },
    { fo: '22', sf: '10', ope: 'MES', opes: "'FIN'" },
    { fo: '12', sf: '10', ope: 'MES', opes: "'APPELEE'" },
    { fo: '30', sf: '10', ope: 'N', opes: 'Appel', niv: '05', tc: 'IT', cond: 'S-REGION = E-REGION' },
    { fo: '30', sf: '10', ope: 'P', opes: '12' },
  ];
  cas.push(['ancien format repris (E-, S-, 85, 22, P 12)', m]);
}
// Mises à jour : principal + mouvements -> résultat
cas.push(['mise a jour : creation, modification, annulation, erreurs', exempleMiseAJour]);
{
  const m = structuredClone(exempleMiseAJour);
  m.appels[1].donnees = [];
  cas.push(['mise a jour sans mouvement', m]);
}
{
  const m = structuredClone(exempleMiseAJour);
  m.appels[0].donnees = [];
  m.appels[1].donnees = m.appels[1].donnees.filter((x) => x.CODMVT === 'C');
  cas.push(['mise a jour : principal vide, creations', m]);
}
{
  const m = structuredClone(exempleMiseAJour);
  m.appels[1].donnees.push({ NUMCLI: '99', CODMVT: 'C', NOMCLI: 'DERNIER', REGION: 'SUD' }, { NUMCLI: '99', CODMVT: 'A' }, { NUMCLI: '99', CODMVT: 'C', NOMCLI: 'RENE' });
  m.appels[0].donnees.push({ NUMCLI: '40', NOMCLI: 'APRES', REGION: 'EST', PLAFOND: '1' });
  m.appels[1].mouvement = { rubrique: 'CODMVT', creation: '1', modification: '2', annulation: '3' };
  m.appels[1].donnees.forEach((x) => { x.CODMVT = { C: '1', M: '2', A: '3' }[x.CODMVT] || x.CODMVT; });
  m.lignes = [
    { fo: '30', sf: '10', ope: 'N', opes: 'Trace', niv: '05', tc: 'IT', cond: "MV-CF1 = 1 ET CL-CF1 = 0" },
    { fo: '30', sf: '10', ope: 'MES', opes: "'SANS PRINCIPAL : ' MV00-NUMCLI" },
    { fo: '20', sf: '10', ope: 'MES', opes: "'FIN DE LA MISE A JOUR'" },
  ];
  cas.push(['mise a jour : codes 1 2 3, plusieurs mouvements, fin de fichier', m]);
}
{
  const m = structuredClone(exempleMiseAJour);
  m.dictionnaire.push({ code: 'AGENCE', libelle: 'Agence', type: 'X', longueur: 2 });
  m.structures[0].rubriques.unshift('AGENCE');
  m.structures[1].rubriques.unshift('AGENCE');
  for (const a of m.appels.slice(0, 2)) { a.argument = ['AGENCE', 'NUMCLI']; a.synchro = 2; }
  m.appels[0].donnees = [
    { AGENCE: 'BX', NUMCLI: '1', NOMCLI: 'A', REGION: 'SUD', PLAFOND: '1' },
    { AGENCE: 'BX', NUMCLI: '5', NOMCLI: 'B', REGION: 'SUD', PLAFOND: '2' },
    { AGENCE: 'PA', NUMCLI: '1', NOMCLI: 'C', REGION: 'NOR', PLAFOND: '3' },
  ];
  m.appels[1].donnees = [
    { AGENCE: 'BX', NUMCLI: '5', CODMVT: 'M', PLAFOND: '20' },
    { AGENCE: 'LY', NUMCLI: '1', CODMVT: 'C', NOMCLI: 'D', REGION: 'EST' },
    { AGENCE: 'PA', NUMCLI: '1', CODMVT: 'A' },
    { AGENCE: 'PA', NUMCLI: '2', CODMVT: 'M', NOMCLI: 'E' },
  ];
  cas.push(['mise a jour : synchro sur deux niveaux', m]);
}
{
  const m = structuredClone(exempleMiseAJour);
  m.appels[1].donnees.reverse();
  cas.push(['mise a jour : mouvements hors ordre (arret)', m]);
}

// Les défis : le modèle de départ, et la solution de référence dont la SYSOUT est l'attendue du défi.
const SOLUTIONS = {
  '01': (m) => {
    Object.assign(m.appels[0], { argument: ['VENDEUR'], rupture: 1 });
    Object.assign(m.traitement, { filtres: [{ rubrique: 'MTVENTE', op: '>', valeur: '0' }], detail: true, colonnes: ['VENDEUR', 'NOMVEN', 'MTVENTE'], totaux: ['MTVENTE'], compter: true });
    return m;
  },
};
for (const [id, d] of Object.entries(DEFIS)) {
  cas.push([`defi ${id} : depart`, normaliser(d.modele)]);
  cas.push([`defi ${id} : solution`, SOLUTIONS[id](normaliser(d.modele))]);
}

export { cas, SOLUTIONS };
