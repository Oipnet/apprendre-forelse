# Tests automatisés RGAA — 2026-11-19-06-13

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

| Critère | Règle axe | Impact | Affichages | Nœuds | Exemple |
|---|---|---|---|---|---|
| 7.1 | aria-allowed-role | minor | desktop, mobile, zoom200, reflow320 | 1 | `#lp-panel-apprendre-php-8` |
| 10.6 | link-in-text-block | serious | desktop, mobile, zoom200, reflow320 | 2 | `.fl-lead > a[href$="auto-hebergement"]` |
| 7.3 | scrollable-region-focusable | serious | mobile, reflow320 | 1 | `pre` |

- Titre : « Exercices Symfony, Laravel et PHP dans le navigateur » · lang : fr · changements de langue : 2 · blocs de code sans lang : 1
- Titres : h1 h2 h2 h2 h2 h3 h3 h3 h3 h2 h2 h3 h3 h3 h3 h2 h2 h3 h3 h3 h2 h2 h2 h2 h2 h2
- Régions : header, nav, search, main, footer · liens d'évitement : « Aller au contenu » → #contenu
- Images : 6 (img sans alt : 0, svg sans aria-hidden ni nom : 0)
- Champs : 2 (sans étiquette : 0, sans autocomplete : 1)
- Zones live : 0 · dialogues : 0 · aria-expanded : 0 · éditeurs : 0
- Clavier : 58 arrêts de tabulation, focus peut-être invisible : 0, hors écran : 0, contraste au focus insuffisant : 0
- Survol : 0 contraste(s) insuffisant(s) · espacement 10.12 : 1 texte(s) peut-être rogné(s)
- Reflow 320 px : **défilement horizontal** (document 385 px) · éléments qui débordent : 8
