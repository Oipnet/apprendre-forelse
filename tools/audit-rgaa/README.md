# Outillage des tests automatisés RGAA

Scripts Playwright + axe-core pour l'étape 2 de l'audit RGAA 4.1.2 (échantillon : `../echantillon.md`).
À lancer depuis une machine qui joint le site (le Mac d'Arnaud), pas depuis le cloud.

## Installation (une fois)

    npm install
    npx playwright install chromium firefox webkit

## Comptes connectés (P10, P12, P13)

    node connexion.mjs apprenant   # se connecter à la main, puis Entrée
    node connexion.mjs chef

Les sessions vont dans `.auth/` (jamais committé, aucun mot de passe stocké). Renseigner l'id de la cohorte de test dans `pages.json` (P13).

## Lancer

    node audit-auto.mjs --pages P01          # une page
    node audit-auto.mjs                      # tout l'échantillon actif
    node audit-auto.mjs --navigateur firefox

Pour chaque page : 4 affichages (desktop 1280, mobile 375, zoom 200 % = 640 px, reflow 320 px) passés à axe-core
(WCAG 2.1 A/AA + bonnes pratiques, correspondance indicative vers les critères RGAA), puis en desktop :
structure (titre, langue, titres, régions, liens d'évitement), images et SVG, champs (étiquettes, autocomplete),
composants dynamiques (aria-live, dialogues, aria-expanded, éditeur), contrastes au survol et au focus,
suite de tabulation et Maj+Tab (focus peut-être invisible, hors écran, piège), espacement du texte (10.12).

Sortie : `resultats/<horodatage>/` avec `resume.md`, un dossier JSON par page et `captures/`
(`P01-crit-3-2-color-contrast-desktop-1.png`, `P01-crit-10-7-focus-012-….png`).

Ce sont des indices : un critère n'est jamais déclaré conforme sur cette seule base. Entrée, Échap et flèches
se testent composant par composant (étape 3 pour l'éditeur, fiches manuelles pour le reste).
