# P01 · Accueil

URL : https://apprendre.forelse.fr/  
Titre de la page (annoncé à l'arrivée) : **Exercices Symfony, Laravel et PHP dans le navigateur**  
Langue : `fr`

## Titres (ce que donne la liste des titres)
- h1 Ici, on apprend à coder en codant.
  - h2 On code dès la première minute
  - h2 Tout tourne dans l'onglet
  - h2 Un projet qui grandit
  - h2 Un exercice, en quatre temps
    - h3 Lisez la consigne
    - h3 Écrivez le code
    - h3 Regardez l'aperçu
    - h3 Validez les objectifs
  - h2 Chaque parcours raconte l'histoire d'un projet
  - h2 Les parcours disponibles. D'autres suivent.
    - h3 Apprendre PHP 8
    - h3 En préparation
  - h2 Un mentor qui donne des pistes, jamais la solution
  - h2 Que vous codiez déjà ou pas encore
    - h3 Développeur en poste
    - h3 Étudiant en informatique
    - h3 Équipe ou école
  - h2 Derrière Forelse, il y a Arnaud
  - h2 Le premier chapitre de chaque parcours est gratuit. Créez votre compte : votre progression vous suit.
  - h2 Questions fréquentes
  - h2 Apprendre
  - h2 Forelse
  - h2 Informations

## Liens à intitulé court ou ambigu hors contexte
_aucun_

## Suite de tabulation : ce que devrait annoncer chaque Tab
| # | Annonce attendue (rôle, nom, état) | Zone | Visible |
|---|---|---|---|
| 1 | link "Aller au contenu" |  | oui |
| 2 | link "Forelse apprendre" | header | oui |
| 3 | text: Parcours | nav « Navigation principale » | oui |
| 4 | link "Pratique" | nav « Navigation principale » | oui |
| 5 | link "Bac à sable" | nav « Navigation principale » | oui |
| 6 | link "Blog" | nav « Navigation principale » | oui |
| 7 | link "Formations" | nav « Navigation principale » | oui |
| 8 | (aucune annonce calculée) | header | oui |
| 9 | link "Connexion" | header | oui |
| 10 | link "Commencer gratuitement" | header | oui |
| 11 | link "Essayer un exercice" | main | oui |
| 12 | tab "Apprendre PHP 8 6 chap. · 39 ex." [selected] | main | oui |
| 13 | tabpanel "Apprendre PHP 8 6 chap. · 39 ex." | main | oui |
| 14 | link "Apprendre PHP 8" | main | oui |
| 15 | link "Acheter" | main | oui |
| 16 | link "Variable" | main | oui |
| 17 | link "Chaîne de caractères" | main | oui |
| 18 | link "Débogage" | main | oui |
| 19 | link "Composer" | main | oui |
| 20 | link "JSON" | main | oui |
| 21 | link "Dépendance" | main | oui |
| 22 | link "PSR-4" | main | oui |
| 23 | link "Dépendance" | main | oui |
| 24 | link "Composer" | main | oui |
| 25 | link "Routeur" | main | oui |
| 26 | link "Gabarit" | main | oui |
| 27 | link "L'offre écoles et entreprises" | main | oui |
| 28 | link "le faire tourner chez vous" | main | oui |
| 29 | link "Découvrir mon parcours et mes expertises" | main | oui |
| 30 | link "Essayer un exercice, sans compte" | main | oui |
| 31 | link "Créer un compte" | main | oui |
| 32 | text: Ça tourne vraiment dans le navigateur ? | main | oui |
| 33 | text: Quels parcours ? | main | oui |
| 34 | text: Combien ça coûte ? | main | oui |
| 35 | text: Je bloque sur un exercice, que se passe-t-il ? | main | oui |
| 36 | text: Quelle différence avec les tutoriels vidéo ? | main | oui |
| 37 | text: Est-ce à jour ? | main | oui |
| 38 | text: Puis-je l'héberger moi-même ? | main | oui |
| 39 | link "Forelse apprendre" | footer | oui |
| 40 | link "English version" | footer | oui |
| 41 | link "Les parcours" | nav « Pied de page » | oui |
| 42 | link "Pratique" | nav « Pied de page » | oui |
| 43 | link "Le bac à sable" | nav « Pied de page » | oui |
| 44 | link "Les notions" | nav « Pied de page » | oui |
| 45 | link "Le classement" | nav « Pied de page » | oui |
| 46 | link "Le blog" | nav « Pied de page » | oui |
| 47 | link "Questions fréquentes" | nav « Pied de page » | oui |
| 48 | link "Écoles et entreprises" | nav « Pied de page » | oui |
| 49 | link "Formations et audits" | nav « Pied de page » | oui |
| 50 | link "Auto-hébergement" | nav « Pied de page » | oui |
| 51 | link "Qui suis-je" | nav « Pied de page » | oui |
| 52 | link "Contact" | nav « Pied de page » | oui |
| 53 | link "Connexion" | nav « Pied de page » | oui |
| 54 | link "Mentions légales" | nav « Pied de page » | oui |
| 55 | link "Confidentialité" | nav « Pied de page » | oui |
| 56 | link "Conditions de vente" | nav « Pied de page » | oui |
| 57 | link "code source" | footer | oui |

## Arbre d'accessibilité complet
```yaml
- link "Aller au contenu":
  - /url: "#contenu"
- banner:
  - link "Forelse apprendre":
    - /url: /
  - navigation "Navigation principale":
    - group: Parcours
    - link "Pratique":
      - /url: /pratique
    - link "Bac à sable":
      - /url: /bac-a-sable
    - link "Blog":
      - /url: /blog
    - link "Formations":
      - /url: /formations
  - group
  - link "Connexion":
    - /url: /connexion
  - link "Commencer gratuitement":
    - /url: /inscription
- main:
  - text: PHP, Symfony, Laravel, et la suite
  - heading "Ici, on apprend à coder en codant." [level=1]:
    - text: Ici, on apprend à coder
    - emphasis: en codant
    - text: .
  - paragraph: PHP, Symfony et Laravel tournent dans votre navigateur. Vous écrivez le code, la page se recharge, puis les tests vous disent si c'est bon. Rien à installer, aucune vidéo à regarder.
  - link "Essayer un exercice":
    - /url: /pratique/symfony-8-2-validation-securite
  - text: sans compte, promis
  - paragraph: Le premier chapitre de chaque parcours est gratuit avec un compte.
  - 'figure "Un exercice : le code, l''aperçu de la page et les objectifs validés"':
    - text: "MenuController.php index.html.twig Aperçu à jour // Bruna veut une carte visible à l'adresse /menu #[Route('/menu', name: 'app_menu')] public function index(): Response { return $this->render('menu/index.html.twig', [ 'taverne' => 'La Taverne du Dragon Ivre', 'platDuJour' => 'Ragoût de sanglier', ]); }▌ localhost/menu La Taverne du Dragon Ivre Plat du jour"
    - strong: Ragoût de sanglier
    - text: Hydromel de la maison 3 po Objectifs La carte est accessible Le plat du jour est affiché Les prix sont en pièces d'or 2 / 3 validés + 100 XP
  - paragraph: Le code à gauche, la page à droite. Vous changez une ligne, l'aperçu se recharge. Un clic lance les tests.
  - heading "On code dès la première minute" [level=2]
  - paragraph: Chaque notion s'apprend en l'écrivant, dans un éditeur avec complétion, face à un aperçu qui réagit à chaque ligne.
  - heading "Tout tourne dans l'onglet" [level=2]
  - paragraph: PHP, Symfony et Laravel s'exécutent dans le navigateur. Il n'y a ni Docker ni environnement à réparer avant d'écrire la première ligne.
  - heading "Un projet qui grandit" [level=2]
  - paragraph: Chaque parcours suit le même projet du premier au dernier chapitre. Le code écrit au début sert encore à la fin.
  - text: Comment ça marche
  - heading "Un exercice, en quatre temps" [level=2]
  - list:
    - listitem:
      - heading "Lisez la consigne" [level=3]
      - paragraph: "Un besoin concret, posé par un personnage du parcours : une carte des plats, une réservation, un registre des employés."
    - listitem:
      - heading "Écrivez le code" [level=3]
      - paragraph: Dans un éditeur avec complétion et coloration, sur les fichiers d'un vrai projet. La documentation officielle est à un clic.
    - listitem:
      - heading "Regardez l'aperçu" [level=3]
      - paragraph: Votre page se recharge à chaque modification. Une erreur s'affiche ; avec un compte, le mentor vous l'explique en français.
    - listitem:
      - heading "Validez les objectifs" [level=3]
      - paragraph: Des tests automatisés vérifient chaque objectif. Bloqué ? Un indice gradué, jamais la solution d'entrée de jeu.
  - text: Le fil rouge
  - heading "Chaque parcours raconte l'histoire d'un projet" [level=2]
  - paragraph: Dans le parcours Symfony, Bruna vient de reprendre la Taverne du Dragon Ivre et n'a jamais eu de site. Dans le parcours PHP, Madame Lou, la factrice de Saint-Glinglin, veut mettre la gazette du village en ligne. Chaque exercice part d'une de leurs demandes.
  - paragraph: Le code du premier exercice sert encore au dernier chapitre. Vous apprenez comme on développe pour de vrai, sur un projet qui grandit, qu'on relit et qu'on fait évoluer.
  - img "Enseigne de la Taverne du Dragon Ivre, fil rouge du premier parcours": La Taverne du Dragon Ivre Fondée au chapitre 1
  - text: Les parcours
  - heading "Les parcours disponibles. D'autres suivent." [level=2]
  - tablist "Choisir un parcours":
    - tab "Apprendre PHP 8 6 chap. · 39 ex." [selected]
    - tab "Symfony 12 chap. · 74 ex."
    - tab "Découvrir Goravel 4 chap. · 13 ex."
  - tabpanel "Apprendre PHP 8 6 chap. · 39 ex.":
    - heading "Apprendre PHP 8" [level=3]:
      - link "Apprendre PHP 8":
        - /url: /parcours/apprendre-php-8
    - paragraph: Vous débutez en PHP ? Apprenez les bases du langage, les objets, l'autoload de Composer et un routeur, sans framework, en mettant en ligne la gazette d'un petit village.
    - text: 6 chapitres · 39 exercices · environ 13 h 29,00 €
    - strong: 19,00 €
    - text: TTC, accès à vie Prix fondateur encore 100 places
    - link "Acheter":
      - /url: /connexion?suite=/parcours/apprendre-php-8/acheter
    - text: Premier chapitre gratuit
    - list:
      - listitem:
        - text: 01 La une du jour gratuit · 6 ex. · ≈ 1 h 45 echo
        - link "Variable":
          - /url: /notions/variable
        - link "Chaîne de caractères":
          - /url: /notions/chaine-de-caracteres
        - text: Nombre Concaténation
      - listitem: 02 La rédaction s'organise 9 ex. · ≈ 3 h 15 Classe Objet Constructeur Arguments nommés readonly
      - listitem:
        - text: 03 Trop de require 4 ex. · ≈ 1 h 30 require_once __DIR__ Organisation du code
        - link "Débogage":
          - /url: /notions/debogage
        - text: Message d'erreur
      - listitem:
        - text: 04 Composer prend le relais 4 ex. · ≈ 1 h
        - link "Composer":
          - /url: /notions/composer
        - text: composer.json
        - link "JSON":
          - /url: /notions/json
        - link "Dépendance":
          - /url: /notions/dependance
        - link "PSR-4":
          - /url: /notions/psr-4
      - listitem:
        - text: 05 Chaque article a son adresse 9 ex. · ≈ 3 h composer require
        - link "Dépendance":
          - /url: /notions/dependance
        - text: vendor/
        - link "Composer":
          - /url: /notions/composer
        - link "Routeur":
          - /url: /notions/routeur
      - listitem:
        - text: 06 La Gazette est en ligne 7 ex. · ≈ 3 h
        - link "Gabarit":
          - /url: /notions/gabarit
        - text: <?= ?> ob_start() extract() require
  - heading "En préparation" [level=3]
  - list:
    - listitem:
      - text: Sécuriser une application Symfony Bientôt
      - paragraph: Vous avez fait le parcours Symfony ? Apprenez à sécuriser une application réelle en réparant, faille après faille, la boutique en ligne de la Brasserie Lacombe, piratée la veille de votre arrivée.
    - listitem:
      - text: L'architecture hexagonale avec Symfony 8 Bientôt
      - paragraph: "Vous connaissez Symfony ? Apprenez l'architecture hexagonale sans dogme, en sortant pas à pas le site de réservation des Canoës de la Brune de son contrôleur obèse : ports, adaptateurs, cas d'usage et tests à chaque pas."
    - listitem:
      - text: Laravel pour les devs PHP Bientôt
      - paragraph: Vous maîtrisez PHP et la POO ? Apprenez Laravel en construisant, chapitre après chapitre, le site qui ramènera Plok, extraterrestre égaré, sur sa planète.
    - listitem:
      - text: Symfony et l'IA Bientôt
      - paragraph: "Vous connaissez Symfony ? Branchez un modèle d'IA dans une vraie application avec Symfony AI : agent, sortie structurée, outils, RAG et serveur MCP, en construisant l'assistant du service client du Comptoir."
    - listitem:
      - text: Apprendre le TDD en PHP Bientôt
      - paragraph: "Écrivez le test d'abord : rouge, vert, refacto. Apprenez le TDD avec PHP 8.5 et PHPUnit 12, des premiers tests aux doublures et au code hérité, en remplaçant le vieux tableur de paie d'une fruitière à comté."
    - listitem:
      - text: Docker pour les devs PHP Bientôt
      - paragraph: Votre application PHP marche sur votre machine ? Apprenez Docker en l'embarquant, conteneur après conteneur, du premier docker run jusqu'à la mise en production, sur les quais de la criée de Port-Bigorneau.
    - listitem:
      - text: Nuxt pour les devs Vue Bientôt
      - paragraph: Vous connaissez Vue ? Apprenez ce que Nuxt y ajoute (pages, rendu serveur, partie serveur) en passant sur Nuxt le site du refuge du Pic Tordu, à 2 350 mètres, où le réseau passe mal.
  - paragraph:
    - strong: Une fiche de cours à la fin de chaque chapitre
    - text: ", en page web et en PDF. À la fin du parcours, le livret complet : votre mémo, écrit à mesure que vous l'avez appris."
  - text: L'IA à sa juste place
  - heading "Un mentor qui donne des pistes, jamais la solution" [level=2]
  - paragraph: Un modèle qui écrit la solution ne vous apprend rien. Ici, l'IA intervient seulement là où un bon mentor interviendrait, quel que soit le parcours. Les indices sont ouverts à tous ; la revue et les explications accompagnent les apprenants connectés.
  - paragraph:
    - strong: Des indices gradués.
    - text: Une piste, puis une direction, puis un extrait. Vous décidez jusqu'où aller.
  - paragraph:
    - strong: Une revue de code.
    - text: Quand les tests passent, un regard sur ce qui pourrait être plus idiomatique.
  - paragraph:
    - strong: Des erreurs expliquées.
    - text: Une exception traduite en cause probable, sans vous donner la correction.
  - text: Pour qui
  - heading "Que vous codiez déjà ou pas encore" [level=2]
  - heading "Développeur en poste" [level=3]
  - paragraph: Vous ajoutez une corde à votre arc, avec les bonnes pratiques d'emblée plutôt qu'en revue de code. Aujourd'hui PHP 8 et Symfony, bientôt Laravel, Nuxt et Docker.
  - heading "Étudiant en informatique" [level=3]
  - paragraph: Vous avez vu la théorie en cours. Il vous manque la pratique sur un vrai projet, celle qu'on attend en stage ou en alternance.
  - heading "Équipe ou école" [level=3]
  - paragraph:
    - text: "Vous formez plusieurs personnes et voulez suivre leur progression : un lien d'invitation, les parcours de votre choix, un tableau de suivi."
    - link "L'offre écoles et entreprises":
      - /url: /ecoles-et-entreprises
    - text: .
  - text: Qui est derrière
  - heading "Derrière Forelse, il y a Arnaud" [level=2]
  - paragraph:
    - text: "Conçu par Arnaud Pointet, développeur indépendant (Forelse, Bordeaux), qui écrit chaque exercice comme il aurait voulu apprendre. Le moteur de la plateforme est sous licence open source (AGPL) : son code est publié, pour le lire ou"
    - link "le faire tourner chez vous":
      - /url: /auto-hebergement
    - text: .
  - paragraph:
    - link "Découvrir mon parcours et mes expertises":
      - /url: /auteurs/arnaud-pointet
  - text: Prêt à commencer ?
  - 'heading "Le premier chapitre de chaque parcours est gratuit. Créez votre compte : votre progression vous suit." [level=2]'
  - link "Essayer un exercice, sans compte":
    - /url: /pratique/symfony-8-2-validation-securite
  - link "Créer un compte":
    - /url: /inscription
  - heading "Questions fréquentes" [level=2]
  - group:
    - text: Ça tourne vraiment dans le navigateur ?
    - paragraph: Oui. Le langage et le framework du parcours s'exécutent en WebAssembly, dans un onglet. Rien ne part sur un serveur pour exécuter votre code.
  - group: Quels parcours ?
  - group: Combien ça coûte ?
  - group: Je bloque sur un exercice, que se passe-t-il ?
  - group: Quelle différence avec les tutoriels vidéo ?
  - group: Est-ce à jour ?
  - group: Puis-je l'héberger moi-même ?
- contentinfo:
  - link "Forelse apprendre":
    - /url: /
  - paragraph: Apprendre à développer en codant dans le navigateur, sans vidéo ni installation.
  - paragraph:
    - link "English version":
      - /url: https://apprendre.forelse.fr/en
  - navigation "Pied de page":
    - heading "Apprendre" [level=2]
    - list:
      - listitem:
        - link "Les parcours":
          - /url: /parcours
      - listitem:
        - link "Pratique":
          - /url: /pratique
      - listitem:
        - link "Le bac à sable":
          - /url: /bac-a-sable
      - listitem:
        - link "Les notions":
          - /url: /notions
      - listitem:
        - link "Le classement":
          - /url: /classement
      - listitem:
        - link "Le blog":
          - /url: /blog
      - listitem:
        - link "Questions fréquentes":
          - /url: /#questions
    - heading "Forelse" [level=2]
    - list:
      - listitem:
        - link "Écoles et entreprises":
          - /url: /ecoles-et-entreprises
      - listitem:
        - link "Formations et audits":
          - /url: /formations
      - listitem:
        - link "Auto-hébergement":
          - /url: /auto-hebergement
      - listitem:
        - link "Qui suis-je":
          - /url: /auteurs/arnaud-pointet
      - listitem:
        - link "Contact":
          - /url: /contact
      - listitem:
        - link "Connexion":
          - /url: /connexion
    - heading "Informations" [level=2]
    - list:
      - listitem:
        - link "Mentions légales":
          - /url: /mentions-legales
      - listitem:
        - link "Confidentialité":
          - /url: /confidentialite
      - listitem:
        - link "Conditions de vente":
          - /url: /cgv
  - paragraph: Les langages et frameworks cités sont des marques de leurs titulaires respectifs ; ce site n'est affilié à aucun d'eux.
  - paragraph:
    - text: Moteur 3.40.0 · AGPL-3.0 ·
    - link "code source":
      - /url: https://github.com/oipnet/apprendre-forelse
```
