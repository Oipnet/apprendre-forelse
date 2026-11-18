// Enregistre une session connectée pour un rôle (apprenant ou chef), sans stocker de mot de passe.
// Usage : node connexion.mjs apprenant   (puis node connexion.mjs chef)
// Une fenêtre s'ouvre sur /connexion : connecte-toi à la main avec le compte de test du rôle,
// puis reviens dans le terminal et appuie sur Entrée. La session est écrite dans .auth/<role>.json.
import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { createInterface } from 'node:readline/promises';

const role = process.argv[2];
if (!['apprenant', 'chef'].includes(role)) {
  console.error('Usage : node connexion.mjs apprenant|chef');
  process.exit(1);
}
const { base, baseConnectee } = JSON.parse(readFileSync(new URL('./pages.json', import.meta.url)));
mkdirSync(new URL('./.auth/', import.meta.url), { recursive: true });

const browser = await chromium.launch({ headless: false });
// Préprod protégée par basic auth : PREPROD_USER=forelse PREPROD_MDP=… node connexion.mjs apprenant
const httpCredentials = process.env.PREPROD_USER ? { username: process.env.PREPROD_USER, password: process.env.PREPROD_MDP || '' } : undefined;
const context = await browser.newContext({ locale: 'fr-FR', httpCredentials });
const page = await context.newPage();
await page.goto(`${baseConnectee || base}/connexion`);
const rl = createInterface({ input: process.stdin, output: process.stdout });
await rl.question(`Connecte-toi avec le compte « ${role} » dans la fenêtre, puis appuie sur Entrée ici… `);
rl.close();
await context.storageState({ path: new URL(`./.auth/${role}.json`, import.meta.url).pathname });
console.log(`Session enregistrée dans .auth/${role}.json (à ne jamais partager ni committer).`);
await browser.close();
