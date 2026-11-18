// Ce qu'un lecteur d'écran devrait annoncer, page par page : l'arbre d'accessibilité (rôles, noms, états) et la
// suite de tabulation avec l'annonce de chaque élément. Sert à pré-remplir les fiches de tests manuels : l'auditeur
// confirme ou infirme à l'écoute, au lieu de tout découvrir. Ce n'est PAS une restitution réelle (NVDA, VoiceOver).
//
//   node restitution.mjs                 # tout l'échantillon actif
//   node restitution.mjs --pages P06,P11
//   node restitution.mjs --navigateur firefox
//
// Sortie : resultats/restitution-<horodatage>/<Pxx>.md, et restitution.md qui les regroupe.
import { chromium, firefox, webkit } from 'playwright';
import { readFileSync, mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ici = dirname(fileURLToPath(import.meta.url));
const conf = JSON.parse(readFileSync(join(ici, 'pages.json'), 'utf8'));
const arg = (nom) => { const i = process.argv.indexOf(nom); return i > 0 ? process.argv[i + 1] : undefined; };
const filtre = arg('--pages')?.split(',');
const moteur = { chromium, firefox, webkit }[arg('--navigateur') ?? 'chromium'];
const pages = conf.pages.filter((p) => !p.inactif && (!filtre || filtre.includes(p.id)));
const identifiants = process.env.PREPROD_USER ? { username: process.env.PREPROD_USER, password: process.env.PREPROD_MDP ?? '' } : undefined;
const sortie = join(ici, 'resultats', `restitution-${new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19)}`);
mkdirSync(sortie, { recursive: true });

// L'annonce de l'élément qui a le focus : rôle, nom, états, comme le calcule le navigateur.
async function annonceDuFocus(page) {
  const focus = page.locator(':focus');
  if ((await focus.count()) === 0) return null;
  const snap = (await focus.first().ariaSnapshot().catch(() => '')).split('\n')[0].replace(/^- /, '').replace(/:$/, '').trim();
  const lieu = await focus.first().evaluate((el) => {
    const r = el.getBoundingClientRect();
    const zone = el.closest('header,nav,main,aside,footer,[role=dialog],dialog');
    return { visible: r.width > 0 && r.height > 0, zone: zone ? zone.tagName.toLowerCase() + (zone.getAttribute('aria-label') ? ` « ${zone.getAttribute('aria-label')} »` : '') : '' };
  });
  return { annonce: snap || '(aucune annonce calculée)', ...lieu };
}

const navigateur = await moteur.launch();
const recap = [];
for (const p of pages) {
  const base = p.role ? conf.baseConnectee : conf.base;
  const etat = p.role ? join(ici, '.auth', `${p.role}.json`) : undefined;
  if (etat && !existsSync(etat)) { console.warn(`${p.id} : session ${p.role} absente (node connexion.mjs ${p.role}), page ignorée`); continue; }
  const context = await navigateur.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fr-FR', storageState: etat, httpCredentials: p.role ? identifiants : undefined });
  const page = await context.newPage();
  await page.goto(base + p.url, { waitUntil: 'load' });
  await page.waitForTimeout(p.attente ?? 1500);

  const titre = await page.title();
  const langue = await page.evaluate(() => document.documentElement.lang);
  const arbre = await page.locator('body').ariaSnapshot();
  const titres = await page.locator('h1,h2,h3,h4,h5,h6,[role=heading]').evaluateAll((els) => els.filter((e) => e.checkVisibility?.() ?? true).map((e) => `${'  '.repeat((+e.tagName[1] || +e.getAttribute('aria-level') || 2) - 1)}- h${e.tagName[1] || e.getAttribute('aria-level')} ${e.textContent.trim().replace(/\s+/g, ' ')}`));
  const liens = await page.getByRole('link').evaluateAll((els) => els.filter((e) => e.checkVisibility?.() ?? true).map((e) => (e.getAttribute('aria-label') || e.textContent).trim().replace(/\s+/g, ' ')));
  const ambigus = [...new Set(liens.filter((t) => t.length < 4 || /^(ici|lire|voir|plus|suite|découvrir|en savoir plus|→|←)$/i.test(t)))];

  // Suite de tabulation : 60 pas au plus, puis un retour Maj+Tab.
  await page.evaluate(() => { document.activeElement?.blur(); window.getSelection()?.removeAllRanges(); });
  const suite = [];
  for (let i = 0; i < 60; i++) {
    await page.keyboard.press('Tab');
    const a = await annonceDuFocus(page);
    if (!a) break;
    if (suite.length > 2 && suite[0].annonce === a.annonce && suite[0].zone === a.zone) break; // revenu au début
    suite.push(a);
  }

  const md = [
    `# ${p.id} · ${p.titre}`,
    '',
    `URL : ${base + p.url}  `,
    `Titre de la page (annoncé à l'arrivée) : **${titre}**  `,
    `Langue : \`${langue}\``,
    '',
    '## Titres (ce que donne la liste des titres)',
    titres.join('\n') || '_aucun_',
    '',
    '## Liens à intitulé court ou ambigu hors contexte',
    ambigus.length ? ambigus.map((t) => `- « ${t} »`).join('\n') : '_aucun_',
    '',
    '## Suite de tabulation : ce que devrait annoncer chaque Tab',
    '| # | Annonce attendue (rôle, nom, état) | Zone | Visible |',
    '|---|---|---|---|',
    ...suite.map((s, i) => `| ${i + 1} | ${s.annonce.replace(/\|/g, '\\|')} | ${s.zone} | ${s.visible ? 'oui' : '**non**'} |`),
    '',
    '## Arbre d\'accessibilité complet',
    '```yaml',
    arbre,
    '```',
    '',
  ].join('\n');
  writeFileSync(join(sortie, `${p.id}.md`), md);
  recap.push(md);
  console.log(`${p.id} : ${suite.length} arrêts de tabulation, ${titres.length} titres`);
  await context.close();
}
await navigateur.close();
writeFileSync(join(sortie, 'restitution.md'), recap.join('\n\n---\n\n'));
console.log(`Résultats : ${sortie}`);
