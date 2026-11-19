// Restitution RÉELLE par VoiceOver (macOS + Safari), pilotée par Guidepup : ce que VoiceOver dit, mot pour mot.
// Complète restitution.mjs (annonces calculées) : ici, c'est le vrai lecteur d'écran qui parle.
//
// Préparation, une fois :
//   npm install                       (installe @guidepup/guidepup, voir package.json)
//   npx @guidepup/setup@0.29.3 install           (une fois par projet : réglages VoiceOver de Guidepup)
//   npx @guidepup/setup@0.29.3 setup             (autorise le pilotage de VoiceOver par AppleScript ; suivre ses consignes)
//   Safari > Réglages > Avancés : cocher « Appuyer sur Tab pour mettre en évidence chaque élément d'une page web »
//   Réglages Système > Confidentialité et sécurité > Accessibilité et Automatisation : autoriser le Terminal
//
// Lancer (ne plus toucher au Mac pendant le passage : le script prend le clavier) :
//   node voiceover.mjs                       # pages publiques de l'échantillon (sans rôle connecté)
//   node voiceover.mjs --pages P11           # la page d'exercice, avec le scénario de l'éditeur
//
// Sortie : resultats/voiceover-<horodatage>/<Pxx>.md : titres annoncés, suite de Tab annoncée, et pour P11 le
// scénario de l'éditeur (entrée, lecture, sortie Ctrl+M puis Ctrl+Maj+M, lancement des tests et annonces live).
import { voiceOver } from '@guidepup/guidepup';
import { execFileSync } from 'node:child_process';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ici = dirname(fileURLToPath(import.meta.url));
const conf = JSON.parse(readFileSync(join(ici, 'pages.json'), 'utf8'));
const arg = (nom) => { const i = process.argv.indexOf(nom); return i > 0 ? process.argv[i + 1] : undefined; };
const filtre = arg('--pages')?.split(',');
// --sans-editeur : relevé des titres et de la tabulation seulement (sans les raccourcis Ctrl/Cmd du scénario Monaco).
const sansEditeur = process.argv.includes('--sans-editeur');
const pages = conf.pages.filter((p) => !p.inactif && !p.role && (!filtre || filtre.includes(p.id)));
const sortie = join(ici, 'resultats', `voiceover-${new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19)}`);
mkdirSync(sortie, { recursive: true });
const pause = (ms) => new Promise((r) => setTimeout(r, ms));
const echappe = (s) => String(s ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ');

// Remet Safari au premier plan : sans lui, les touches partent vers une autre app (Cmd+Tab, Mission Control…).
function activerSafari() {
  execFileSync('osascript', ['-e', 'tell application "Safari" to activate']);
}

function ouvrirDansSafari(url) {
  execFileSync('open', ['-a', 'Safari', url]);
  execFileSync('osascript', ['-e', 'tell application "Safari" to activate']);
}

// Répète une commande et note ce que VoiceOver dit, jusqu'à revenir sur une annonce déjà entendue en tête.
async function releve(n, action) {
  const dit = [];
  for (let i = 0; i < n; i++) {
    if (i % 5 === 0) activerSafari();
    try { await action(); } catch (e) {
      // Une commande refusée (raccourci VoiceOver, AppleScript) ne doit pas faire perdre le reste du relevé.
      const cause = e.cause?.message || e.cause?.cause?.message || '';
      console.error(`  erreur à l'étape ${i + 1} : ${e.message} ${cause}`);
      dit.push(`(erreur : ${e.message} ${cause})`.replace(/\s+/g, ' ').slice(0, 300));
      break;
    }
    await pause(400);
    const phrase = await voiceOver.lastSpokenPhrase();
    if (dit.length > 2 && phrase === dit[0]) break;
    dit.push(phrase);
  }
  return dit;
}

async function scenarioEditeur(md, sauver) {
  md.push('', '## Scénario de l\'éditeur de code', '');
  // Atteindre l'éditeur au Tab : on s'arrête sur la zone d'édition de Monaco.
  let trouve = false;
  for (let i = 0; i < 60 && !trouve; i++) {
    await voiceOver.press('Tab'); await pause(400);
    const p = await voiceOver.lastSpokenPhrase();
    if (/édition|edit|Editor content|zone de texte|text area/i.test(p)) { trouve = true; md.push(`- Entrée dans l'éditeur (Tab n° ${i + 1}) : « ${echappe(p)} »`); }
  }
  sauver();
  if (!trouve) { md.push('- **Éditeur non atteint en 60 Tab.**'); return; }
  activerSafari();
  for (const touche of ['ArrowDown', 'ArrowDown', 'ArrowDown']) {
    await voiceOver.press(touche); await pause(500);
    md.push(`- Flèche bas : « ${echappe(await voiceOver.lastSpokenPhrase())} »`);
  }
  await voiceOver.press('Tab'); await pause(500);
  md.push(`- Tab dans l'éditeur (attendu : indentation, pas de sortie) : « ${echappe(await voiceOver.lastSpokenPhrase())} »`);
  await voiceOver.press('Command+Z'); await pause(300);
  sauver();
  // Sortie : le raccourci affiché dans l'aide, puis celui que Monaco attend sous macOS.
  for (const raccourci of ['Control+M', 'Control+Shift+M']) {
    activerSafari();
    await voiceOver.press(raccourci); await pause(500);
    const apres = await voiceOver.lastSpokenPhrase();
    await voiceOver.press('Tab'); await pause(600);
    const ou = await voiceOver.lastSpokenPhrase();
    md.push(`- ${raccourci.replace('Control', 'Ctrl')} puis Tab : annonce après le raccourci « ${echappe(apres)} », puis « ${echappe(ou)} »`);
    if (!/édition|edit|Editor content/i.test(ou)) { md.push(`  → **sortie de l'éditeur obtenue avec ${raccourci.replace('Control', 'Ctrl')}**`); break; }
  }
  sauver();
  // Lancer les tests depuis l'éditeur et écouter les annonces automatiques (régions live).
  activerSafari();
  await voiceOver.press('Shift+Tab'); await pause(400);
  await voiceOver.clearSpokenPhraseLog();
  await voiceOver.press('Command+Enter');
  await pause(30000);
  const annonces = await voiceOver.spokenPhraseLog();
  md.push('', '### Ce que VoiceOver dit dans les 30 s après Cmd + Entrée (lancement des tests)', '', ...annonces.map((a) => `- « ${echappe(a)} »`));
}

await voiceOver.start();
try {
  for (const p of pages) {
    const url = conf.base + p.url;
    console.log(`${p.id} ${url}`);
    ouvrirDansSafari(url);
    await pause(p.attente ?? 4000);
    await voiceOver.clearSpokenPhraseLog();
    const fichier = join(sortie, `${p.id}.md`);
    // Écrit au fil de l'eau : un relevé interrompu garde ce qui a déjà été entendu.
    const sauver = () => writeFileSync(fichier, md.join('\n'));
    const md = [`# ${p.id} · ${p.titre} : restitution VoiceOver réelle`, '', `URL : ${url}  `, `Relevé : ${new Date().toLocaleString('fr-FR')} · Safari + VoiceOver (macOS)`, ''];

    // Arrivée sur la page : ce que VoiceOver annonce de lui-même.
    await pause(1500);
    md.push('## Annonce à l\'arrivée', '', ...(await voiceOver.spokenPhraseLog()).slice(0, 6).map((a) => `- « ${echappe(a)} »`), '');
    sauver();

    // Titres, avec VO + Cmd + H.
    const titres = await releve(25, () => voiceOver.perform(voiceOver.keyboardCommands.findNextHeading));
    md.push('## Titres (VO + Cmd + H)', '', ...titres.map((t, i) => `${i + 1}. « ${echappe(t)} »`), '');
    sauver();

    // Suite de tabulation.
    ouvrirDansSafari(url); await pause(p.attente ?? 4000);
    const tabs = await releve(50, () => voiceOver.press('Tab'));
    md.push('## Suite de tabulation (Tab)', '', '| # | Ce que dit VoiceOver |', '|---|---|', ...tabs.map((t, i) => `| ${i + 1} | ${echappe(t)} |`), '');
    sauver();

    if (p.id === 'P11' && !sansEditeur) { ouvrirDansSafari(url); await pause(p.attente ?? 15000); await scenarioEditeur(md, sauver); }

    sauver();
  }
} finally {
  await voiceOver.stop();
}
console.log(`Résultats : ${sortie}`);
