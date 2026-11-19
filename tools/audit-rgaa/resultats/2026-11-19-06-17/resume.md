# Tests automatisés RGAA — 2026-11-19-06-17

Base : https://apprendre.forelse.fr (pages connectées : https://preprod.forelse.fr) · navigateur : chromium · axe-core via @axe-core/playwright (WCAG 2.1 A/AA + bonnes pratiques).

> Indices automatiques à qualifier : aucun critère ne peut être déclaré conforme sur cette seule base.

## P01 — Accueil

https://apprendre.forelse.fr/

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 2 / 3 | 3 |
| mobile | 200 | 3 / 4 | 4 |
| zoom200 | 200 | 2 / 3 | 4 |
| reflow320 | 200 | 3 / 5 | 4 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 7.1 | aria-allowed-role | minor | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `#lp-panel-apprendre-php-8` |
| 10.6 | link-in-text-block | serious | desktop (2), mobile (2), zoom200 (2), reflow320 (2) | `.fl-lead > a[href$="auto-hebergement"]` |
| 7.3 | scrollable-region-focusable | serious | mobile (1), reflow320 (2) | `pre` |

- Titre : « Exercices Symfony, Laravel et PHP dans le navigateur » · lang : fr · changements de langue : 2 · blocs de code sans lang : 1
- Titres : h1 h2 h2 h2 h2 h3 h3 h3 h3 h2 h2 h3 h3 h3 h3 h2 h2 h3 h3 h3 h2 h2 h2 h2 h2 h2
- Régions : header, nav, search, main, footer · liens d'évitement : « Aller au contenu » → #contenu
- Images : 6 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 2 (sans étiquette : 0, sans autocomplete : 1)
- Zones live : 0 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 58 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 1 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 385 px) · éléments qui débordent : 8

## P02 — Contact

https://apprendre.forelse.fr/contact

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 1 / 3 | 1 |
| mobile | 200 | 1 / 3 | 2 |
| zoom200 | 200 | 1 / 3 | 2 |
| reflow320 | 200 | 1 / 3 | 2 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 10.6 | link-in-text-block | serious | desktop (3), mobile (3), zoom200 (3), reflow320 (3) | `li:nth-child(2) > a[href$="ecoles-et-entreprises"]` |

- Titre : « Contact | Forelse » · lang : fr · changements de langue : 2 · blocs de code sans lang : 0
- Titres : h1 h2 h2 h2
- Régions : header, nav, search, main, footer · liens d'évitement : « Aller au contenu » → #contenu
- Images : 3 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 7 (sans étiquette : 1, sans autocomplete : 3)
- Zones live : 0 · dialogues : 0 · aria-expanded : 0 · éditeurs : 1
- Clavier : 37 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 1 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 385 px) · éléments qui débordent : 1

## P03 — Mentions légales

https://apprendre.forelse.fr/mentions-legales

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 1 / 1 | 1 |
| mobile | 200 | 1 / 1 | 2 |
| zoom200 | 200 | 1 / 1 | 2 |
| reflow320 | 200 | 1 / 1 | 2 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 10.6 | link-in-text-block | serious | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `.site-moteur > a` |

- Titre : « Mentions légales | Forelse » · lang : fr · changements de langue : 2 · blocs de code sans lang : 0
- Titres : h1 h2 h2 h2 h2 h2 h2 h2 h2
- Régions : header, nav, search, main, footer · liens d'évitement : « Aller au contenu » → #contenu
- Images : 3 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 2 (sans étiquette : 0, sans autocomplete : 1)
- Zones live : 0 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 33 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 1 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 385 px) · éléments qui débordent : 1

## P05 — Recherche

https://apprendre.forelse.fr/recherche?q=doctrine

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 1 / 1 | 1 |
| mobile | 200 | 1 / 1 | 2 |
| zoom200 | 200 | 1 / 1 | 2 |
| reflow320 | 200 | 1 / 1 | 2 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 10.6 | link-in-text-block | serious | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `.site-moteur > a` |

- Titre : « « doctrine » : recherche · Forelse » · lang : fr · changements de langue : 0 · blocs de code sans lang : 0
- Titres : h1 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2
- Régions : header, nav, search, main, footer · liens d'évitement : « Aller au contenu » → #contenu
- Images : 3 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 3 (sans étiquette : 0, sans autocomplete : 1)
- Zones live : 1 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 68 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 2 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 385 px) · éléments qui débordent : 1

## P06 — Connexion

https://apprendre.forelse.fr/connexion

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 1 / 2 | 1 |
| mobile | 200 | 1 / 2 | 2 |
| zoom200 | 200 | 1 / 2 | 2 |
| reflow320 | 200 | 1 / 2 | 2 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 10.6 | link-in-text-block | serious | desktop (2), mobile (2), zoom200 (2), reflow320 (2) | `.text-sm.text-ink-muted > a[rel="nofollow"][href$="inscripti` |

- Titre : « Connexion · Forelse » · lang : fr · changements de langue : 0 · blocs de code sans lang : 0
- Titres : h1 h2 h2 h2
- Régions : header, nav, search, main, footer · liens d'évitement : « Aller au contenu » → #contenu
- Images : 3 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 5 (sans étiquette : 0, sans autocomplete : 2)
- Zones live : 0 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 24 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 1 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 385 px) · éléments qui débordent : 1

## P07 — Inscription

https://apprendre.forelse.fr/inscription

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 1 / 3 | 1 |
| mobile | 200 | 1 / 3 | 2 |
| zoom200 | 200 | 1 / 3 | 2 |
| reflow320 | 200 | 1 / 3 | 2 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 10.6 | link-in-text-block | serious | desktop (3), mobile (3), zoom200 (3), reflow320 (3) | `.text-sm.text-ink-muted > a[href$="confidentialite"]` |

- Titre : « Créer un compte · Forelse » · lang : fr · changements de langue : 0 · blocs de code sans lang : 0
- Titres : h1 h2 h2 h2
- Régions : header, nav, search, main, footer · liens d'évitement : « Aller au contenu » → #contenu
- Images : 3 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 5 (sans étiquette : 0, sans autocomplete : 1)
- Zones live : 0 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 35 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 1 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 385 px) · éléments qui débordent : 1

## P08 — Liste des parcours

https://apprendre.forelse.fr/parcours

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 1 / 2 | 2 |
| mobile | 200 | 1 / 2 | 3 |
| zoom200 | 200 | 1 / 2 | 3 |
| reflow320 | 200 | 1 / 2 | 3 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 10.6 | link-in-text-block | serious | desktop (2), mobile (2), zoom200 (2), reflow320 (2) | `.mt-10:nth-child(6) > a[href$="pratique"]` |

- Titre : « Apprendre PHP, Symfony et Goravel en codant : les parcours » · lang : fr · changements de langue : 2 · blocs de code sans lang : 0
- Titres : h1 h2 h2 h2 h2 h2 h2 h2
- Régions : header, nav, search, main, footer · liens d'évitement : « Aller au contenu » → #contenu
- Images : 3 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 2 (sans étiquette : 0, sans autocomplete : 1)
- Zones live : 0 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 84 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 1 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 385 px) · éléments qui débordent : 1

## P09 — Page d'un parcours

https://apprendre.forelse.fr/parcours/symfony-pour-dev-php

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 1 / 1 | 2 |
| mobile | 200 | 1 / 1 | 3 |
| zoom200 | 200 | 1 / 1 | 3 |
| reflow320 | 200 | 1 / 1 | 3 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 10.6 | link-in-text-block | serious | desktop (1), mobile (1), zoom200 (1), reflow320 (1) | `.site-moteur > a` |

- Titre : « Formation Symfony en ligne pour les devs PHP | Forelse » · lang : fr · changements de langue : 2 · blocs de code sans lang : 0
- Titres : h1 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2 h2
- Régions : header, nav, search, main, aside, footer · liens d'évitement : « Aller au contenu » → #contenu ; « 01 Premiers pas à la taverne 8 » → #chapitre-1 ; « 02 La cave et le garde-manger 6 » → #chapitre-2 ; « 03 Les parchemins de la taverne 6 » → #chapitre-3 ; « 04 Les clés de la taverne 6 » → #chapitre-4
- Images : 3 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 2 (sans étiquette : 0, sans autocomplete : 1)
- Zones live : 0 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 77 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 9 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 385 px) · éléments qui débordent : 1

## P11 — Exercice dans le navigateur

https://apprendre.forelse.fr/pratique/doctrine-onetomany-deux-cotes

| Affichage | HTTP | Violations axe (règles / nœuds) | À vérifier (axe incomplete) |
|---|---|---|---|
| desktop | 200 | 4 / 20 | 4 |
| mobile | 200 | 3 / 14 | 2 |
| zoom200 | 200 | 2 / 3 | 2 |
| reflow320 | 200 | 1 / 1 | 2 |

| Critère | Règle axe | Impact | Affichages (nœuds) | Exemple |
|---|---|---|---|---|
| 3.2 | color-contrast | serious | desktop (14), mobile (12), zoom200 (2) | `.mtk7:nth-child(1)` |
| 9.1 | heading-order | moderate | desktop (1) | `#frame,#preview,div[data-toggle-selector="#trace-html-1"] > ` |
| 9.2 | landmark-unique | moderate | desktop (2) | `.topbar` |
| 7.3 | scrollable-region-focusable | serious | desktop (3) | `.notranslate` |
| 11.1 | label | critical | mobile (1) | `.iPadShowKeyboard` |
| 9.2 | region | moderate | mobile (1), zoom200 (1), reflow320 (1) | `.small-screen-note > span` |

- Titre : « Symfony : tenir les deux côtés d'une relation OneToMany » · lang : fr · changements de langue : 0 · blocs de code sans lang : 16
- Titres : h1 h2 h3 h3 h3 h3 h2 h2
- Régions : header, nav, main, aside, section · liens d'évitement : « Aller au contenu » → #contenu
- Images : 4 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 8 (sans étiquette : 1, sans autocomplete : 2)
- Zones live : 7 · dialogues : 2 · aria-expanded : 5 · éditeurs : 5
- Clavier : 4 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 4, contraste au focus insuffisant : 0 · **piège possible**
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 3 texte(s) peut-être rogné(s)
- Reflow 320 px : pas de défilement horizontal · éléments qui débordent : 3
