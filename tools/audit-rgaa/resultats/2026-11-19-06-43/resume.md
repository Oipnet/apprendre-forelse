# Tests automatisés RGAA — 2026-11-19-06-43

Base : https://apprendre.forelse.fr (pages connectées : https://preprod.forelse.fr) · navigateur : chromium · axe-core via @axe-core/playwright (WCAG 2.1 A/AA + bonnes pratiques).

> Indices automatiques à qualifier : aucun critère ne peut être déclaré conforme sur cette seule base.

## P10 — Chapitre de cours

https://preprod.forelse.fr/parcours/symfony-pour-dev-php/chapitre/premiers-pas

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 2 / 2 | 2 |
| mobile | 200 | 2 / 2 | 3 |
| zoom200 | 200 | 2 / 2 | 3 |
| reflow320 | 200 | 2 / 2 | 3 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 6.1 | label-content-name-mismatch | serious | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `.py-1` |
| 10.6 | link-in-text-block | serious | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `.site-moteur > a` |

- Titre : « Formation Symfony en ligne pour les devs PHP | Forelse » · lang : fr · changements de langue : 2 · blocs de code sans lang : 0
- Titres : h1 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2
- Régions : header, nav, search, main, aside, footer · liens d'évitement : « Aller au contenu » → #contenu ; « 01 Premiers pas à la taverne 8 » → #chapitre-1 ; « 02 La cave et le garde-manger 6 » → #chapitre-2 ; « 03 Les parchemins de la taverne 6 » → #chapitre-3 ; « 04 Les clés de la taverne 6 » → #chapitre-4
- Images : 3 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 2 (sans étiquette : 0, sans autocomplete : 1)
- Zones live : 1 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 76 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 9 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 359 px) · éléments qui débordent : 1

## P12 — Mon compte

https://preprod.forelse.fr/compte

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 2 / 2 | 1 |
| mobile | 200 | 2 / 2 | 2 |
| zoom200 | 200 | 2 / 2 | 2 |
| reflow320 | 200 | 2 / 2 | 2 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 6.1 | label-content-name-mismatch | serious | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `.py-1` |
| 10.6 | link-in-text-block | serious | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `.site-moteur > a` |

- Titre : « Mon compte · Forelse » · lang : fr · changements de langue : 0 · blocs de code sans lang : 0
- Titres : h1 h2 h2 h2 h2 h2
- Régions : header, nav, search, main, aside, footer · liens d'évitement : « Aller au contenu » → #contenu
- Images : 3 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 8 (sans étiquette : 0, sans autocomplete : 1)
- Zones live : 0 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 51 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 1 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 359 px) · éléments qui débordent : 1

## P13 — Espace chef de cohorte

https://preprod.forelse.fr/cohorte/3

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 4 / 14 | 0 |
| mobile | 200 | 4 / 16 | 0 |
| zoom200 | 200 | 4 / 16 | 0 |
| reflow320 | 200 | 4 / 16 | 1 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 9.2 | landmark-one-main | moderate | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `html` |
| 6.2 | link-name | serious | desktop (1), mobile (2), zoom200 (2), reflow320 (2) | `.content-top > .dropdown-settings.dropdown > .dropdown-setti` |
| 10.4 | meta-viewport | moderate | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `meta[name="viewport"]` |
| 9.2 | region | moderate | desktop (11), mobile (12), zoom200 (12), reflow320 (12) | `.content-header` |

- Titre : « Classe test RGAA » · lang : fr · changements de langue : 0 · blocs de code sans lang : 1
- Titres : h1 h2 h2 h2
- Régions : aside, header, nav · liens d'évitement : «  » → # (cible absente) ; «  » → # (cible absente) ; « Clair » → # (cible absente) ; « Sombre » → # (cible absente) ; « Automatique » → # (cible absente)
- Images : 22 (img sans alt : 0, svg sans aria-hidden ni nom : 22)
- Champs : 53 (sans étiquette : 0, sans autocomplete : 53)
- Zones live : 0 · dialogues : 0 · aria-expanded : 7 · éditeurs : 0
- Clavier : 67 arrêts de tabulation, focus peut-être invisible : 2, hors écran : 6, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 0 texte(s) peut-être rogné(s)
- Reflow 320 px : pas de défilement horizontal · éléments qui débordent : 0
