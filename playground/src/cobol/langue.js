// La langue de l'atelier : celle de la page (<html lang>), français par défaut, anglais sous /en/cobol-workshop.
// Les textes sont écrits par paires, t('français', 'anglais'), là où ils servent : le contexte reste sous les yeux.
// Hors navigateur (tests, génération des attendus), COBOL_LANGUE=en (variable d'environnement ou globale) force
// l'anglais. La langue est fixée au chargement : les exemples et les défis sont choisis une fois pour toutes.
const forcee = globalThis.COBOL_LANGUE ?? globalThis.process?.env?.COBOL_LANGUE;
export const EN = forcee ? forcee === 'en' : typeof document !== 'undefined' && /^en\b/i.test(document.documentElement?.lang || '');
export const t = (fr, en) => (EN ? en : fr);

// Un modèle d'exemple passé en anglais : les textes d'abord (valeurs exactes : libellés, titres, noms de fichiers),
// puis les codes, comme mots entiers, partout (clés des données comprises : { NUMCLI: '7' } devient { CUSTNO: '7' }).
export function traduireModele(modele, textes, codes) {
  const re = new RegExp(`\\b(${Object.keys(codes).join('|')})\\b`, 'g');
  const code = (s) => s.replace(re, (c) => codes[c]);
  const tr = (v) => {
    if (typeof v === 'string') return code(textes[v] ?? v);
    if (Array.isArray(v)) return v.map(tr);
    if (v && typeof v === 'object') return Object.fromEntries(Object.entries(v).map(([k, x]) => [code(k), tr(x)]));
    return v;
  };
  return tr(modele);
}

// Le stockage du navigateur est commun aux deux langues : chacune garde son programme, sa base et ses requêtes.
export const cle = (nom) => `atelier-cobol-${nom}${EN ? '-en' : ''}`;
