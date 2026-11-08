// Régénère gnucobol.json, les sorties de référence de tests/cobol.test.ts, avec le vrai compilateur GnuCOBOL.
// À lancer après un changement du générateur : node tests/cobol/regenerer-attendus.mjs (cobc requis, apt install gnucobol).
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { generer, fichierDonnees } from '../../src/cobol/generateur.js';
import { cas } from './cas.js';

const dossier = fs.mkdtempSync(path.join(os.tmpdir(), 'cobol-'));
const attendus = {};
cas.forEach(([nom, modele], i) => {
  const d = path.join(dossier, String(i));
  fs.mkdirSync(d);
  fs.writeFileSync(path.join(d, 'P.cbl'), generer(modele));
  fs.writeFileSync(path.join(d, modele.traitement.fichier), fichierDonnees(modele));
  execFileSync('cobc', ['-x', '-o', path.join(d, 'p'), path.join(d, 'P.cbl')], { stdio: ['ignore', 'ignore', 'inherit'] });
  let sortie = execFileSync(path.join(d, 'p'), { cwd: d }).toString();
  for (const so of modele.sorties || []) sortie += `--- ${so.fichier}\n` + fs.readFileSync(path.join(d, so.fichier), 'utf8');
  attendus[nom] = sortie;
});
fs.writeFileSync(new URL('./gnucobol.json', import.meta.url), JSON.stringify({ compilateur: execFileSync('cobc', ['--version']).toString().split('\n')[0], attendus }, null, 2) + '\n');
console.log(`${cas.length} sorties de référence écrites.`);
