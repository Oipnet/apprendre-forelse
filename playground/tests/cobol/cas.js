// Les cas comparés à GnuCOBOL : un modèle par cas, à partir de l'exemple de l'atelier.
import { exemple } from '../../src/cobol/generateur.js';

// GnuCOBOL ne compile pas EXEC SQL sans précompilateur : les cas comparés laissent la base de côté
// (elle est testée à part, sur SQLite : voir tests/cobol.test.ts).
const copie = (o) => {
  const m = JSON.parse(JSON.stringify(o));
  m.tables = [];
  m.lignes = (m.lignes || []).filter((l) => !(l.fo === '30' || (l.fo === '22' && l.sf !== '10')));
  return m;
};
const cas = [];
cas.push(['exemple (sans la base)', copie(exemple)]);
{ const m = copie(exemple); m.traitement.ruptures = []; m.traitement.filtres = []; cas.push(['sans rupture ni filtre', m]); }
{ const m = copie(exemple); m.traitement.detail = false; m.traitement.filtres = [{ rubrique: 'MTFACT', op: '>', valeur: '100' }]; cas.push(['sans detail, filtre > 100', m]); }
{ const m = copie(exemple); m.traitement.filtres = [{ rubrique: 'REGION', op: 'NOT =', valeur: 'EST' }, { rubrique: 'MTFACT', op: '<', valeur: '1000' }]; m.traitement.compter = false; cas.push(['deux filtres, sans comptage', m]); }
{
  const m = copie(exemple);
  m.dictionnaire.push({ code: 'QTE', libelle: 'Quantité', type: '9', longueur: 4 });
  m.dictionnaire.push({ code: 'PU', libelle: 'Prix unitaire', type: '9', longueur: 5, decimales: 3 });
  m.structures[0].rubriques.push('QTE', 'PU');
  m.traitement.ruptures = ['NUMCLI'];
  m.traitement.colonnes = ['NUMCLI', 'REGION', 'QTE', 'PU', 'MTFACT'];
  m.lignes = []; m.sorties = [];
  m.traitement.totaux = ['QTE', 'MTFACT'];
  m.traitement.filtres = [];
  m.donnees = [
    ['NOR', '12', 'DUPONT', '-1250.5', '3', '12.125'],
    ['SUD', '7', 'MARTIN', '-0.01', '0', '0'],
    ['NOR', '12', 'DUPONT', '9999.99', '9999', '99.999'],
    ['EST', '1', 'GARNIER', '0.5', '12', '1'],
  ];
  cas.push(['rupture numerique, decimales 3, negatifs', m]);
}
{
  const m = copie(exemple);
  m.traitement.ruptures = ['REGION', 'NOMCLI'];
  m.donnees.push({ REGION: 'NOR', NUMCLI: '12', NOMCLI: 'DUPONT', MTFACT: '10' }, { REGION: 'SUD', NUMCLI: '7', NOMCLI: 'MARTIN', MTFACT: '-5.5' });
  cas.push(['deux niveaux de rupture', m]);
}
{
  const m = copie(exemple);
  m.dictionnaire.push({ code: 'ANNEE', libelle: 'Année', type: '9', longueur: 4 });
  m.structures[0].rubriques.unshift('ANNEE');
  m.traitement.ruptures = ['ANNEE', 'REGION', 'NUMCLI'];
  m.traitement.detail = false;
  m.donnees = m.donnees.flatMap((l, i) => [{ ...l, ANNEE: '2025' }, { ...l, ANNEE: i % 2 ? '2026' : '2025', MTFACT: '1.01' }]);
  cas.push(['trois niveaux, sans detail', m]);
}
{
  const m = copie(exemple);
  m.dictionnaire.push({ code: 'MAXI', libelle: 'Plus gros montant', type: '9', longueur: 9, decimales: 2, signe: true });
  m.traitement.ruptures = ['REGION', 'NUMCLI'];
  m.lignes.push(
    { fo: '30', sf: '10', ope: 'N', opes: 'Plus gros montant', niv: '05', tc: 'IT', cond: 'E-MTFACT>W-MAXI' },
    { fo: '30', sf: '10', ope: 'M', opes: 'E-MTFACT W-MAXI' },
    { fo: '30', sf: '20', ope: 'N', opes: 'Dernier client de la region', niv: '05', tc: 'IT', cond: 'RTD1=1 ET RTP2=1' },
    { fo: '30', sf: '20', ope: 'MES', opes: "'  (seul client de son groupe : ' E-NOMCLI ')'" },
    { fo: '30', sf: '30', ope: 'N', opes: 'Sinon', niv: '05', tc: 'EL' },
    { fo: '30', sf: '30', ope: '*', opes: 'rien a signaler' },
    { fo: '30', sf: '30', ope: 'C', opes: 'W-NBREL = W-NBREL + 0' },
    { fo: '30', sf: '40', ope: 'N', opes: 'Region # NOR', niv: '05', tc: 'IT', cond: "E-REGION # 'NOR'" },
    { fo: '30', sf: '50', ope: 'N', opes: 'Gros montant', niv: '10', tc: 'IT', cond: 'E-MTFACT >= 99.99 OU E-MTFACT < -50' },
    { fo: '30', sf: '50', ope: 'P', opes: '40' },
    { fo: '40', sf: '10', ope: 'N', opes: 'Signalement', niv: '05', tc: 'BL' },
    { fo: '40', sf: '10', ope: 'MES', opes: "'  >> A SURVEILLER : ' E-MTFACT" },
    { fo: '03', sf: '10', ope: 'N', opes: 'Debut', niv: '05', tc: 'BL' },
    { fo: '03', sf: '10', ope: 'MES', opes: "'DEBUT DU TRAITEMENT'" },
    { fo: '23', sf: '10', ope: 'N', opes: 'Max', niv: '05', tc: 'BL' },
    { fo: '23', sf: '10', ope: 'MES', opes: "'PLUS GROS MONTANT : ' W-MAXI" },
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


export { cas };
