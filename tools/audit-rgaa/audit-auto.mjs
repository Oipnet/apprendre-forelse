// Tests automatisés RGAA 4.1.2 (étape 2 de l'audit) : axe-core + relevés Playwright.
// Usage : node audit-auto.mjs [--pages P01,P02] [--base https://…] [--navigateur chromium|firefox|webkit]
// Les résultats vont dans resultats/<horodatage>/ : un dossier par page (JSON bruts), captures/ et resume.md.
// Rien ici ne permet de conclure « conforme » : ce sont des indices à qualifier à la main.
import { chromium, firefox, webkit } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => {
  if (a.startsWith('--')) acc.push([a.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : true]);
  return acc;
}, []));
const ici = new URL('.', import.meta.url).pathname;
const conf = JSON.parse(readFileSync(join(ici, 'pages.json')));
const base = (args.base || conf.base).replace(/\/$/, '');
// Pages connectées : préprod, protégée par basic auth (identifiants en variables d'environnement, jamais dans un fichier).
const baseConnectee = (args['base-connectee'] || conf.baseConnectee || base).replace(/\/$/, '');
const identifiants = process.env.PREPROD_USER ? { username: process.env.PREPROD_USER, password: process.env.PREPROD_MDP || '' } : undefined;
const racine = (p) => (p.role ? baseConnectee : base);
const filtre = args.pages ? String(args.pages).split(',') : null;
const pages = conf.pages.filter((p) => (filtre ? filtre.includes(p.id) : !p.inactif));
const moteur = { chromium, firefox, webkit }[args.navigateur || 'chromium'];
const horodatage = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');
const sortie = join(ici, 'resultats', horodatage);
const captures = join(sortie, 'captures');
mkdirSync(captures, { recursive: true });

// Affichages testés. zoom200 = 1280 px à 200 % (640 px CSS) ; reflow320 = 1280 px à 400 % (critère 10.11).
const AFFICHAGES = {
  desktop: { viewport: { width: 1280, height: 800 } },
  mobile: { viewport: { width: 375, height: 812 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
  zoom200: { viewport: { width: 640, height: 400 }, deviceScaleFactor: 2 },
  reflow320: { viewport: { width: 320, height: 256 }, deviceScaleFactor: 4 },
};
// RGAA 4.1.2 s'aligne sur WCAG 2.1 A/AA : pas de règles WCAG 2.2.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'];

// Correspondance indicative règle axe → critère RGAA, à confirmer pour chaque constat.
const RGAA = {
  'area-alt': '1.1', 'image-alt': '1.1', 'input-image-alt': '1.1', 'object-alt': '1.1', 'role-img-alt': '1.1', 'svg-img-alt': '1.1',
  'image-redundant-alt': '1.3', 'frame-title': '2.1', 'color-contrast': '3.2', 'link-in-text-block': '10.6',
  'audio-caption': '4.1', 'video-caption': '4.3', 'td-headers-attr': '5.7', 'th-has-data-cells': '5.7', 'table-duplicate-name': '5.4',
  'link-name': '6.2', 'identical-links-same-purpose': '6.1', 'label-content-name-mismatch': '6.1',
  'aria-allowed-attr': '7.1', 'aria-allowed-role': '7.1', 'aria-command-name': '7.1', 'aria-hidden-body': '7.1', 'aria-input-field-name': '7.1',
  'aria-meter-name': '7.1', 'aria-progressbar-name': '7.1', 'aria-prohibited-attr': '7.1', 'aria-required-attr': '7.1', 'aria-required-children': '7.1',
  'aria-required-parent': '7.1', 'aria-roles': '7.1', 'aria-toggle-field-name': '7.1', 'aria-tooltip-name': '7.1', 'aria-valid-attr': '7.1',
  'aria-valid-attr-value': '7.1', 'aria-dialog-name': '7.1', 'aria-text': '7.1', 'aria-treeitem-name': '7.1', 'nested-interactive': '7.1',
  'button-name': '7.1', 'scrollable-region-focusable': '7.3', 'duplicate-id': '8.2', 'duplicate-id-aria': '8.2', 'duplicate-id-active': '8.2',
  'html-has-lang': '8.3', 'html-lang-valid': '8.4', 'html-xml-lang-mismatch': '8.4', 'document-title': '8.5', 'valid-lang': '8.8',
  'heading-order': '9.1', 'empty-heading': '9.1', 'page-has-heading-one': '9.1', 'p-as-heading': '9.1',
  'landmark-banner-is-top-level': '9.2', 'landmark-complementary-is-top-level': '9.2', 'landmark-contentinfo-is-top-level': '9.2',
  'landmark-main-is-top-level': '9.2', 'landmark-no-duplicate-banner': '9.2', 'landmark-no-duplicate-contentinfo': '9.2',
  'landmark-no-duplicate-main': '9.2', 'landmark-one-main': '9.2', 'landmark-unique': '9.2', 'region': '9.2',
  'list': '9.3', 'listitem': '9.3', 'definition-list': '9.3', 'dlitem': '9.3', 'meta-viewport': '10.4', 'meta-viewport-large': '10.4',
  'aria-hidden-focus': '10.8', 'avoid-inline-spacing': '10.12', 'label': '11.1', 'select-name': '11.1', 'input-button-name': '11.9',
  'label-title-only': '11.2', 'form-field-multiple-labels': '11.2', 'autocomplete-valid': '11.13', 'bypass': '12.7', 'skip-link': '12.7',
  'tabindex': '12.8', 'focus-order-semantics': '12.8', 'meta-refresh': '13.1', 'meta-refresh-no-exceptions': '13.1',
  'blink': '13.8', 'marquee': '13.8', 'css-orientation-lock': '13.9',
};
const slug = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40);

async function ouvrir(context, p) {
  const page = await context.newPage();
  const rep = await page.goto(racine(p) + p.url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  if (p.attente) await page.waitForTimeout(p.attente);
  return { page, statut: rep?.status() };
}

async function capturerNoeud(page, cible, fichier) {
  try {
    const loc = page.locator(Array.isArray(cible) ? cible.join(' ') : cible).first();
    await loc.scrollIntoViewIfNeeded({ timeout: 3000 });
    await loc.screenshot({ path: fichier, timeout: 5000 });
    return true;
  } catch { return false; }
}

async function axe(page, p, nom) {
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  const violations = [];
  for (const v of r.violations) {
    const crit = RGAA[v.id] || 'a-qualifier';
    const noeuds = [];
    for (const [i, n] of v.nodes.entries()) {
      const fichier = `${p.id}-crit-${crit.replace('.', '-')}-${v.id}-${nom}-${i + 1}.png`;
      const ok = i < 10 && await capturerNoeud(page, n.target, join(captures, fichier));
      noeuds.push({ cible: n.target, html: n.html.slice(0, 300), resume: n.failureSummary, capture: ok ? fichier : null });
    }
    violations.push({ regle: v.id, critereRgaa: crit, impact: v.impact, aide: v.help, doc: v.helpUrl, wcag: v.tags.filter((t) => t.startsWith('wcag')), noeuds });
  }
  return { violations, incomplets: r.incomplete.map((v) => ({ regle: v.id, critereRgaa: RGAA[v.id] || 'a-qualifier', aide: v.help, nb: v.nodes.length, cibles: v.nodes.slice(0, 15).map((n) => n.target) })) };
}

// Structure (8.3 à 8.8, 9.1, 9.2, 12.7), images (1.x), formulaires (11.x), composants dynamiques (7.x).
const releverStructure = (page) => page.evaluate(() => {
  const txt = (el) => (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 120);
  const sel = (el) => el.id ? `#${el.id}` : el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
  const visible = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const nomAccessible = (el) => el.getAttribute('aria-label') || (el.getAttribute('aria-labelledby') || '').split(/\s+/).map((id) => document.getElementById(id)?.textContent?.trim()).filter(Boolean).join(' ') || null;
  return {
    titre: document.title,
    lang: document.documentElement.getAttribute('lang'),
    changementsLangue: [...document.querySelectorAll('body [lang]')].map((el) => ({ el: sel(el), lang: el.lang, texte: txt(el).slice(0, 60) })),
    blocsCodeSansLang: document.querySelectorAll('pre:not([lang]), code:not([lang])').length,
    titres: [...document.querySelectorAll('h1,h2,h3,h4,h5,h6,[role=heading]')].map((h) => ({ niveau: h.getAttribute('aria-level') || h.tagName[1], texte: txt(h), visible: visible(h) })),
    regions: [...document.querySelectorAll('header,nav,main,aside,footer,[role=banner],[role=navigation],[role=main],[role=complementary],[role=contentinfo],[role=search],form[aria-label],section[aria-label],section[aria-labelledby]')]
      .map((el) => ({ el: el.tagName.toLowerCase(), role: el.getAttribute('role'), nom: nomAccessible(el), dansMain: !!el.closest('main') && el.tagName !== 'MAIN' })),
    liensEvitement: [...document.querySelectorAll('a[href^="#"]')].slice(0, 5).map((a) => ({ texte: txt(a), href: a.getAttribute('href'), cibleExiste: !!document.getElementById(a.getAttribute('href').slice(1)) })),
    images: [...document.querySelectorAll('img,[role=img],svg,input[type=image],canvas')].map((el) => ({
      el: el.tagName.toLowerCase(), src: (el.getAttribute('src') || '').slice(0, 80), alt: el.getAttribute('alt'), role: el.getAttribute('role'),
      ariaHidden: el.getAttribute('aria-hidden') || (el.closest('[aria-hidden=true]') ? 'hérité' : null), nom: nomAccessible(el),
      titleSvg: el.tagName === 'svg' ? el.querySelector('title')?.textContent || null : undefined, focusable: el.getAttribute('focusable'), visible: visible(el),
      dansLien: el.closest('a,button') ? txt(el.closest('a,button')) || '(lien sans texte)' : null,
    })),
    formulaires: [...document.querySelectorAll('input:not([type=hidden]),select,textarea')].map((el) => {
      const labels = [...(el.labels || [])].map(txt);
      return {
        el: el.tagName.toLowerCase(), type: el.type, nom: el.name, id: el.id, labels, ariaLabel: el.getAttribute('aria-label'), title: el.getAttribute('title'),
        placeholder: el.getAttribute('placeholder'), autocomplete: el.getAttribute('autocomplete'), required: el.required, ariaRequired: el.getAttribute('aria-required'),
        ariaDescribedby: el.getAttribute('aria-describedby'), ariaInvalid: el.getAttribute('aria-invalid'), fieldset: el.closest('fieldset')?.querySelector('legend')?.textContent?.trim() || null, visible: visible(el),
      };
    }),
    dynamiques: {
      ariaLive: [...document.querySelectorAll('[aria-live],[role=status],[role=alert],[role=log],output')].map((el) => ({ el: sel(el), live: el.getAttribute('aria-live'), role: el.getAttribute('role'), atomic: el.getAttribute('aria-atomic') })),
      dialogues: [...document.querySelectorAll('dialog,[role=dialog],[role=alertdialog]')].map((el) => ({ el: sel(el), modal: el.getAttribute('aria-modal'), nom: nomAccessible(el), ouvert: el.open ?? visible(el) })),
      ariaExpanded: [...document.querySelectorAll('[aria-expanded]')].map((el) => ({ el: sel(el), texte: txt(el), expanded: el.getAttribute('aria-expanded'), controls: el.getAttribute('aria-controls') })),
      onglets: document.querySelectorAll('[role=tablist]').length, details: document.querySelectorAll('details').length,
      iframes: [...document.querySelectorAll('iframe')].map((f) => ({ src: (f.src || '').slice(0, 80), title: f.title || null })),
      editeurs: [...document.querySelectorAll('.monaco-editor,.cm-editor,[role=textbox][aria-multiline=true],textarea')].map((el) => ({ el: sel(el), role: el.getAttribute('role'), nom: nomAccessible(el), roledescription: el.getAttribute('aria-roledescription') })),
    },
  };
});

// Contraste d'un élément (fond résolu en remontant les ancêtres opaques ; images de fond non gérées → « à vérifier »).
const CONTRASTE = `(() => {
  const rgb = (c) => { const m = c.match(/rgba?\\(([^)]+)\\)/); if (!m) return null; const [r, g, b, a = 1] = m[1].split(/[ ,\\/]+/).filter(Boolean).map(Number); return { r, g, b, a }; };
  const lum = ({ r, g, b }) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const melange = (h, b) => ({ r: h.r * h.a + b.r * (1 - h.a), g: h.g * h.a + b.g * (1 - h.a), b: h.b * h.a + b.b * (1 - h.a), a: 1 });
  window.__rgaaContraste = (el) => {
    const s = getComputedStyle(el); let fg = rgb(s.color); let image = false; const couches = [];
    for (let n = el; n; n = n.parentElement) { const ns = getComputedStyle(n); if (ns.backgroundImage !== 'none') image = true; const bg = rgb(ns.backgroundColor); if (bg && bg.a > 0) { couches.push(bg); if (bg.a >= 1) break; } }
    let fond = { r: 255, g: 255, b: 255, a: 1 }; for (const c of couches.reverse()) fond = melange(c, fond);
    if (fg.a < 1) fg = melange(fg, fond);
    const [l1, l2] = [lum(fg), lum(fond)].sort((a, b) => b - a);
    const taille = parseFloat(s.fontSize); const gras = parseInt(s.fontWeight, 10) >= 700;
    const grand = taille >= 24 || (gras && taille >= 18.66);
    return { ratio: Math.round(((l1 + 0.05) / (l2 + 0.05)) * 100) / 100, seuil: grand ? 3 : 4.5, couleur: s.color, fond: 'rgb(' + [fond.r, fond.g, fond.b].map(Math.round).join(',') + ')', imageDeFond: image, taille, gras };
  };
})()`;

// Navigation au clavier (12.8 ordre, 10.7 focus visible, 12.9 piège) : suite de Tab puis quelques Maj+Tab.
async function clavier(page, p) {
  await page.evaluate(CONTRASTE);
  // Style de repos de chaque élément focalisable, pour détecter un focus sans changement visible.
  await page.evaluate(() => {
    const props = ['outlineStyle', 'outlineWidth', 'outlineColor', 'boxShadow', 'backgroundColor', 'color', 'borderColor', 'textDecorationLine'];
    window.__rgaaRepos = new Map();
    document.querySelectorAll('a[href],button,input,select,textarea,[tabindex],summary,[contenteditable]').forEach((el) => {
      const s = getComputedStyle(el); window.__rgaaRepos.set(el, Object.fromEntries(props.map((k) => [k, s[k]])));
    });
    window.__rgaaProps = props;
  });
  const decrire = () => page.evaluate(() => {
    const el = document.activeElement;
    if (!el || el === document.body) return { el: 'body' };
    const s = getComputedStyle(el); const repos = window.__rgaaRepos.get(el);
    const focus = Object.fromEntries(window.__rgaaProps.map((k) => [k, s[k]]));
    const change = repos ? window.__rgaaProps.filter((k) => repos[k] !== focus[k]) : ['(élément créé après chargement)'];
    const outline = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0;
    const r = el.getBoundingClientRect();
    return {
      el: el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''), role: el.getAttribute('role'), texte: (el.getAttribute('aria-label') || el.innerText || el.value || el.getAttribute('title') || '').replace(/\s+/g, ' ').trim().slice(0, 80),
      tabindex: el.getAttribute('tabindex'), focusVisibleCss: el.matches(':focus-visible'), changementsAuFocus: change, outline,
      indiceFocusInvisible: !outline && change.length === 0, horsEcran: r.bottom < 0 || r.right < 0 || r.left > innerWidth + 1 || r.width === 0,
      contraste: el.innerText?.trim() ? window.__rgaaContraste(el) : null, rect: { x: Math.round(r.x), y: Math.round(r.y), l: Math.round(r.width), h: Math.round(r.height) },
      dansIframe: false,
    };
  });
  const suite = [];
  let memeDeSuite = 0;
  for (let i = 0; i < 200; i++) {
    await page.keyboard.press('Tab');
    const d = await decrire();
    const prec = suite.at(-1);
    if (prec && prec.el === d.el && prec.texte === d.texte) { memeDeSuite++; } else memeDeSuite = 0;
    d.rang = i + 1;
    if (d.indiceFocusInvisible || d.horsEcran) {
      const fichier = `${p.id}-crit-10-7-focus-${String(d.rang).padStart(3, '0')}-${slug(d.texte || d.el)}.png`;
      await page.screenshot({ path: join(captures, fichier) }).catch(() => {});
      d.capture = fichier;
    }
    suite.push(d);
    if (memeDeSuite >= 3) { d.alerte = 'Focus bloqué sur le même élément après 4 Tab : piège possible (12.9).'; break; }
    if (i > 2 && (d.el === 'body' || (d.el === suite[0].el && d.texte === suite[0].texte))) break; // tour complet
  }
  const retour = [];
  for (let i = 0; i < 8; i++) { await page.keyboard.press('Shift+Tab'); retour.push(await decrire()); }
  return { suite, retourMajTab: retour.map((d) => `${d.el} ${d.texte}`) };
}

// Contraste au survol des liens et boutons (3.2 sur l'état survolé).
async function survol(page) {
  await page.evaluate(CONTRASTE);
  const n = await page.locator('a[href]:visible, button:visible').count();
  const res = [];
  for (let i = 0; i < Math.min(n, 60); i++) {
    const loc = page.locator('a[href]:visible, button:visible').nth(i);
    try {
      await loc.hover({ timeout: 1500 });
      const c = await loc.evaluate((el) => (el.innerText?.trim() ? { texte: el.innerText.trim().slice(0, 60), ...window.__rgaaContraste(el) } : null));
      if (c && c.ratio < c.seuil) res.push(c);
    } catch { /* masqué ou recouvert */ }
  }
  await page.mouse.move(0, 0);
  return res;
}

// Espacement du texte (10.12) : CSS du critère injecté, puis recherche de texte rogné.
async function espacement(page, p) {
  await page.addStyleTag({ content: '* { line-height: 1.5 !important; letter-spacing: 0.12em !important; word-spacing: 0.16em !important; } p { margin-bottom: 2em !important; }' });
  await page.waitForTimeout(500);
  const rognes = await page.evaluate(() => [...document.querySelectorAll('body *')].filter((el) => {
    const s = getComputedStyle(el); if (!el.innerText?.trim() || el.children.length > 3) return false;
    const coupe = /(hidden|clip)/.test(s.overflow + s.overflowX + s.overflowY) || s.textOverflow === 'ellipsis';
    return coupe && (el.scrollHeight > el.clientHeight + 2 || el.scrollWidth > el.clientWidth + 2) && el.clientWidth > 0;
  }).slice(0, 30).map((el) => ({ el: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/)[0] : ''), texte: el.innerText.trim().slice(0, 60) })));
  const fichier = `${p.id}-crit-10-12-espacement.png`;
  await page.screenshot({ path: join(captures, fichier), fullPage: true });
  return { rognes, capture: fichier };
}

// Reflow (10.11) : défilement horizontal à 320 px et éléments qui débordent.
const reflow = (page) => page.evaluate(() => {
  const largeur = document.documentElement.clientWidth;
  const deborde = [...document.querySelectorAll('body *')].filter((el) => { const r = el.getBoundingClientRect(); return r.right > largeur + 1 && r.width > 0 && getComputedStyle(el).position !== 'fixed'; })
    .filter((el, _, tous) => !tous.some((a) => a !== el && a.contains(el))).slice(0, 20)
    .map((el) => ({ el: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/)[0] : ''), droite: Math.round(el.getBoundingClientRect().right), defileSeul: ['auto', 'scroll'].includes(getComputedStyle(el).overflowX) }));
  return { largeur, largeurDocument: document.documentElement.scrollWidth, defilementHorizontal: document.documentElement.scrollWidth > largeur + 1, deborde };
});

const browser = await moteur.launch();
const bilan = [];
for (const p of pages) {
  if (String(p.url).includes('ID_A_REMPLIR')) { console.warn(`${p.id} ignorée : renseigner l'URL dans pages.json`); continue; }
  const etat = p.role ? join(ici, '.auth', `${p.role}.json`) : undefined;
  if (etat && !existsSync(etat)) { console.warn(`${p.id} ignorée : lancer d'abord « node connexion.mjs ${p.role} »`); continue; }
  const dossier = join(sortie, p.id); mkdirSync(dossier, { recursive: true });
  const ligne = { id: p.id, titre: p.titre, url: racine(p) + p.url, affichages: {} };
  console.log(`${p.id} ${p.titre}…`);
  for (const [nom, opts] of Object.entries(AFFICHAGES)) {
    const context = await browser.newContext({ ...opts, locale: 'fr-FR', storageState: etat, reducedMotion: 'no-preference', httpCredentials: p.role ? identifiants : undefined });
    try {
      const { page, statut } = await ouvrir(context, p);
      await page.screenshot({ path: join(captures, `${p.id}-apercu-${nom}.png`), fullPage: nom !== 'reflow320' });
      const res = { statut, url: page.url(), axe: await axe(page, p, nom) };
      if (nom === 'desktop') {
        res.structure = await releverStructure(page);
        res.survol = await survol(page);
        await page.reload({ waitUntil: 'domcontentloaded' }); await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {}); if (p.attente) await page.waitForTimeout(p.attente);
        res.clavier = await clavier(page, p);
        res.espacement = await espacement(page, p);
      }
      if (nom === 'reflow320') res.reflow = await reflow(page);
      writeFileSync(join(dossier, `${nom}.json`), JSON.stringify(res, null, 2));
      ligne.affichages[nom] = res;
    } catch (e) {
      ligne.affichages[nom] = { erreur: e.message };
      console.error(`  ${nom} : ${e.message}`);
    } finally { await context.close(); }
  }
  bilan.push(ligne);
}
await browser.close();

// Résumé lisible.
const md = [`# Tests automatisés RGAA — ${horodatage}`, '', `Base : ${base} (pages connectées : ${baseConnectee}) · navigateur : ${args.navigateur || 'chromium'} · axe-core via @axe-core/playwright (WCAG 2.1 A/AA + bonnes pratiques).`, '',
  '> Indices automatiques à qualifier : aucun critère ne peut être déclaré conforme sur cette seule base.', ''];
for (const l of bilan) {
  md.push(`## ${l.id} — ${l.titre}`, '', l.url, '');
  md.push('| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |', '|---|---|---|---|');
  for (const [nom, r] of Object.entries(l.affichages)) {
    if (r.erreur) { md.push(`| ${nom} | erreur | ${r.erreur.replace(/\|/g, '/').slice(0, 120)} | |`); continue; }
    md.push(`| ${nom} | ${r.statut} | ${r.axe.violations.length} / ${r.axe.violations.reduce((s, v) => s + v.noeuds.length, 0)} | ${r.axe.incomplets.length} |`);
  }
  const d = l.affichages.desktop;
  const toutes = Object.entries(l.affichages).flatMap(([nom, r]) => (r.axe?.violations || []).map((v) => ({ ...v, nom })));
  if (toutes.length) {
    md.push('', '| Critère | Règle axe | Impact | Affichages | Nœuds | Exemple |', '|---|---|---|---|---|---|');
    const parRegle = Object.values(toutes.reduce((acc, v) => { (acc[v.regle] ||= { ...v, noms: [] }).noms.push(v.nom); return acc; }, {}));
    for (const v of parRegle) md.push(`| ${v.critereRgaa} | ${v.regle} | ${v.impact} | ${v.noms.join(', ')} | ${v.noeuds.length} | \`${String(v.noeuds[0]?.cible).slice(0, 60)}\` |`);
  }
  if (d && !d.erreur) {
    const s = d.structure; const c = d.clavier;
    md.push('', `- Titre : « ${s.titre} » · lang : ${s.lang} · changements de langue : ${s.changementsLangue.length} · blocs de code sans lang : ${s.blocsCodeSansLang}`);
    md.push(`- Titres : ${s.titres.map((h) => 'h' + h.niveau).join(' ')}`);
    md.push(`- Régions : ${[...new Set(s.regions.map((r) => r.role || r.el))].join(', ')} · liens d'évitement : ${s.liensEvitement.map((a) => `« ${a.texte} » → ${a.href}${a.cibleExiste ? '' : ' (cible absente)'}`).join(' ; ') || 'aucun'}`);
    md.push(`- Images : ${s.images.length} (img sans alt : ${s.images.filter((i) => i.el === 'img' && i.alt === null).length}, svg sans aria-hidden ni nom : ${s.images.filter((i) => i.el === 'svg' && !i.ariaHidden && !i.nom && !i.titleSvg).length})`);
    md.push(`- Champs : ${s.formulaires.length} (sans étiquette : ${s.formulaires.filter((f) => f.visible && !f.labels.length && !f.ariaLabel).length}, sans autocomplete : ${s.formulaires.filter((f) => f.visible && !f.autocomplete).length})`);
    md.push(`- Zones live : ${s.dynamiques.ariaLive.length} · dialogues : ${s.dynamiques.dialogues.length} · aria-expanded : ${s.dynamiques.ariaExpanded.length} · éditeurs : ${s.dynamiques.editeurs.length}`);
    md.push(`- Clavier : ${c.suite.length} arrêts de tabulation, focus peut-être invisible : ${c.suite.filter((x) => x.indiceFocusInvisible).length}, hors écran : ${c.suite.filter((x) => x.horsEcran).length}, contraste au focus insuffisant : ${c.suite.filter((x) => x.contraste && x.contraste.ratio < x.contraste.seuil).length}${c.suite.some((x) => x.alerte) ? ' · **piège possible**' : ''}`);
    md.push(`- Survol : ${d.survol.length} contraste(s) insuffisant(s) · espacement 10.12 : ${d.espacement.rognes.length} texte(s) peut-être rogné(s)`);
  }
  const r320 = l.affichages.reflow320;
  if (r320?.reflow) md.push(`- Reflow 320 px : ${r320.reflow.defilementHorizontal ? `**défilement horizontal** (document ${r320.reflow.largeurDocument} px)` : 'pas de défilement horizontal'} · éléments qui débordent : ${r320.reflow.deborde.length}`);
  md.push('');
}
writeFileSync(join(sortie, 'resume.md'), md.join('\n'));
console.log(`\nTerminé : ${join(sortie, 'resume.md')}`);
