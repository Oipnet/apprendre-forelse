// Régénère gnucobol.json, les sorties de référence de tests/cobol.test.ts, avec le vrai compilateur GnuCOBOL.
// À lancer après un changement du générateur : node tests/cobol/regenerer-attendus.mjs (cobc requis, apt install gnucobol).
// En anglais : COBOL_LANGUE=en node tests/cobol/regenerer-attendus.mjs écrit gnucobol-en.json (cas de cas-en.js).
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { generer, fichiersEntree, fichiersSortie } from '../../src/cobol/generateur.js';
const anglais = process.env.COBOL_LANGUE === 'en';
const { cas } = anglais ? { cas: (await import('./cas-en.js')).casEn } : await import('./cas.js');

const dossier = fs.mkdtempSync(path.join(os.tmpdir(), 'cobol-'));
const attendus = {};
cas.forEach(([nom, modele], i) => {
  const d = path.join(dossier, String(i));
  fs.mkdirSync(d);
  fs.writeFileSync(path.join(d, 'P.cbl'), generer(modele));
  for (const [f, c] of Object.entries(fichiersEntree(modele))) fs.writeFileSync(path.join(d, f), c);
  execFileSync('cobc', ['-x', '-o', path.join(d, 'p'), path.join(d, 'P.cbl')], { stdio: ['ignore', 'ignore', 'inherit'] });
  let sortie = execFileSync(path.join(d, 'p'), { cwd: d, stdio: ['ignore', 'pipe', 'ignore'] }).toString();
  for (const so of fichiersSortie(modele)) sortie += `--- ${so.fichier}\n` + fs.readFileSync(path.join(d, so.fichier), 'utf8');
  attendus[nom] = sortie;
});
if (anglais) {
  fs.writeFileSync(new URL('./gnucobol-en.json', import.meta.url), JSON.stringify({ compilateur: execFileSync('cobc', ['--version']).toString().split('\n')[0], attendus }, null, 2) + '\n');
  console.log(`${cas.length} sorties de référence en anglais écrites.`);
  process.exit(0);
}
// Sources écrits à la main (tests/cobol/sources) : ce que l'interpréteur doit savoir lire, au-delà du générateur.
// La SYSOUT puis chaque fichier écrit, octet pour octet (sauts de page et retours chariot compris).
const sources = {};
const dossierSources = new URL('./sources/', import.meta.url);
for (const nom of fs.readdirSync(dossierSources).filter((f) => f.endsWith('.cbl')).sort()) {
  const d = path.join(dossier, nom);
  fs.mkdirSync(d);
  fs.copyFileSync(new URL(nom, dossierSources), path.join(d, 'S.cbl'));
  execFileSync('cobc', ['-x', '-o', path.join(d, 's'), path.join(d, 'S.cbl')], { stdio: ['ignore', 'ignore', 'inherit'] });
  const sortie = execFileSync(path.join(d, 's'), { cwd: d, stdio: ['ignore', 'pipe', 'ignore'] }).toString('latin1');
  const fichiers = {};
  for (const f of fs.readdirSync(d).filter((f) => !['S.cbl', 's'].includes(f)).sort()) fichiers[f] = fs.readFileSync(path.join(d, f), 'latin1');
  sources[nom] = { sortie, fichiers };
}
fs.writeFileSync(new URL('./gnucobol.json', import.meta.url), JSON.stringify({ compilateur: execFileSync('cobc', ['--version']).toString().split('\n')[0], attendus, sources }, null, 2) + '\n');
console.log(`${cas.length} sorties de référence et ${Object.keys(sources).length} sources écrits.`);
