// Les cas comparés à GnuCOBOL pour l'atelier en anglais (COBOL_LANGUE=en) : les trois exemples et le défi, dont les
// codes, les textes et les lignes de la SYSOUT changent de langue. Le reste du générateur ne dépend pas de la langue.
import { exemple, exempleMiseAJour, exempleEtat, normaliser } from '../../src/cobol/generateur.js';
import { DEFIS } from '../../src/cobol/defis.js';
import { SOLUTIONS } from './solutions.js';

// sans la base, que GnuCOBOL ne compile pas (même découpe que cas.js)
const copie = (o) => {
  const m = JSON.parse(JSON.stringify(o));
  m.tables = [];
  m.lignes = (m.lignes || []).filter((l) => !(l.fo === '30' || (l.fo === '20' && l.sf !== '10')));
  return m;
};
export const casEn = [
  ['exemple (sans la base)', copie(exemple)],
  ['mise a jour', copie(exempleMiseAJour)],
  ['etat', copie(exempleEtat)],
  ...Object.entries(DEFIS).flatMap(([id, d]) => [
    [`defi ${id} : depart`, normaliser(d.modele)],
    [`defi ${id} : solution`, SOLUTIONS[id](normaliser(d.modele))],
  ]),
];
