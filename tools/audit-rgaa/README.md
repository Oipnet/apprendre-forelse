# Outillage des tests automatisés RGAA

Scripts Playwright + axe-core pour l'étape 2 de l'audit RGAA 4.1.2 (échantillon : `../echantillon.md`).
À lancer depuis une machine qui joint le site (le Mac d'Arnaud), pas depuis le cloud.

## Installation (une fois)

    npm install
    npx playwright install chromium firefox webkit

## Comptes connectés (P10, P12, P13) — en préprod

Les pages connectées sont auditées sur https://preprod.forelse.fr (choix d'Arnaud), protégée par basic auth.
Les identifiants passent par l'environnement, jamais par un fichier :

    export PREPROD_USER=forelse PREPROD_MDP='…'
    node connexion.mjs apprenant   # se connecter à la main, puis Entrée
    node connexion.mjs chef

Comptes à créer en préprod par l'interface : un chef (inscription puis /ecoles-et-entreprises/classe-gratuite),
puis un apprenant inscrit avec le lien d'invitation /inscription?code=… de la classe. Les courriels arrivent dans mailpit.

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

## Ce que devrait annoncer un lecteur d'écran (pré-remplissage des fiches manuelles)

    node restitution.mjs                 # même échantillon, mêmes sessions .auth/
    node restitution.mjs --pages P11

Pour chaque page : titre annoncé, liste des titres, liens à intitulé ambigu, suite de tabulation avec l'annonce
calculée de chaque élément (rôle, nom, état, zone, visible ou non), et l'arbre d'accessibilité complet (ariaSnapshot
de Playwright). Sortie : `resultats/restitution-<horodatage>/`. À comparer à l'écoute avec `../tests-manuels-court.md`.

## Restitution réelle par VoiceOver (Guidepup, macOS seulement)

    npx @guidepup/setup@0.29.3 setup      # une fois : autorise le pilotage de VoiceOver
    npx @guidepup/setup@0.29.3 install    # une fois par projet : réglages VoiceOver de Guidepup
    # macOS : donner au Terminal l'« Accès complet au disque » (Confidentialité et sécurité), puis quitter et rouvrir le Terminal,
    # sinon « Failed to mount Guidepup preferences … Operation not permitted ».
    node voiceover.mjs --pages P11  # ne plus toucher au Mac pendant le passage

Prérequis et détails en tête de `voiceover.mjs` (réglage Tab de Safari, autorisations du Terminal). Le script ouvre
chaque page publique dans Safari, démarre VoiceOver et note ce qu'il dit : arrivée, titres (VO + Cmd + H), suite de Tab.
Sur P11, il entre dans l'éditeur, lit trois lignes, teste Tab, la sortie par Ctrl + M puis Ctrl + Maj + M, et enregistre
les annonces des 30 s qui suivent le lancement des tests. Sortie : `resultats/voiceover-<horodatage>/`.
Non testé depuis le cloud (pas de macOS) : premier passage à surveiller.
