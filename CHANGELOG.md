# Journal des modifications

Les versions de ce moteur suivent le [versionnage sémantique](https://semver.org/lang/fr/) :
ce qu'un changement **majeur**, **mineur** ou **correctif** engage vis-à-vis des packs de contenu
et des instances auto-hébergées est décrit dans la section « Versionnage » du [README](README.md).

Le format de ce fichier suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## Non publié

Premier chantier de la marque blanche (voir [ANALYSE-MARQUE-BLANCHE.md](ANALYSE-MARQUE-BLANCHE.md),
« niveau 2 ») : **l'habillage**. Ce qui parlait de Forelse en dur dans le moteur devient de la
configuration montée à côté des packs. Une instance change de nom, de couleurs, d'images et de pages
sans fork, sans reconstruire l'image, et sans rien avoir à republier au titre de l'AGPL — parce qu'elle
ne modifie pas de code. Les instances existantes ne changent pas : sans dossier de marque, tout reste
tel quel.

### Ajouté

- **Identité de l'instance** (`BRANDING_DIR`, `/marque` dans l'image) : un dossier monté qui contient
  `marque.yaml` et ses images. Il habille l'en-tête, le pied de page, les `<title>`, la barre de
  l'éditeur, les tableaux de bord, **les emails** (confirmation d'adresse, mot de passe oublié, achat,
  alerte d'inscription), les balises Open Graph et les données structurées. Clés : `name` (obligatoire),
  `chip`, `title`, `tagline`, `url`, `logo`, `icon`, `share`, `colors`, `fonts` et `home`. Exemple
  commenté à copier dans [examples/marque/](examples/marque/marque.yaml), guide dans le
  [README](README.md#habiller-son-instance) et dans
  [auto-hebergement/README.md](auto-hebergement/README.md#votre-marque).
- **Règle « tout ou rien »** : dès qu'un `marque.yaml` existe, plus rien de la marque du moteur n'est
  servi — ni le nom, ni le logo, ni la favicon, ni l'image de partage, ni les trois sections d'accueil
  qui parlent de la Taverne du Dragon Ivre et de l'auteur de Forelse. Une instance ne peut pas se
  retrouver à afficher une marque qui n'est pas la sienne parce qu'elle a oublié une clé. Elle déclare
  les siennes sous `home.showcase`, `home.author` et `home.demo` ; non déclarée, une section n'apparaît
  pas et la page reste cohérente sans elle.
- **Couleurs et polices** (`colors`, `fonts`) : les variables CSS du thème clair, posées après la feuille
  de styles. Les valeurs sont vérifiées à la lecture (hexadécimal, pile de polices) — rien de ce fichier
  ne peut refermer la balise `<style>`. Une valeur mal écrite ou une clé inconnue **arrête la page** avec
  un message qui dit quoi corriger : une instance à moitié habillée serait pire qu'une erreur franche.
  Le thème sombre de l'éditeur reste celui du moteur.
- **Images de marque** servies sur `/marque/logo`, `/marque/icon` et `/marque/share`, avec la date du
  fichier dans l'URL : une image remplacée change d'URL. Le nom du fichier vient du manifeste, jamais de
  l'URL, et un chemin y est refusé.
- **Gabarits de l'instance** : un fichier Twig déposé dans `<marque>/templates/` remplace celui du moteur
  qui porte le même nom (`home.html.twig`, `_footer.html.twig`, `legal/notice.html.twig`…). C'est
  l'échappatoire de l'habillage : ce que `marque.yaml` ne règle pas se réécrit sans toucher au moteur. En
  production, les gabarits sont compilés une fois : redémarrez le conteneur après en avoir déposé un.

### Modifié

- Les **tarifs de cohorte** ne sont plus des paramètres du conteneur mais des variables d'environnement
  (`COHORT_UNIT_PRICE`, `COHORT_TIERS`, au format « effectif:pourcentage ») : une instance a ses prix
  sans reconstruire l'image. Les valeurs par défaut sont inchangées (30 €, puis 70 % à partir de 10 et
  50 % à partir de 30).
- L'atelier échafaude les tests d'un nouvel exercice Symfony dans `tests/Exercice/` (`App\Tests\Exercice`)
  au lieu de `tests/Taverne/` : le squelette servait à tous les packs, il ne porte plus le nom du fil
  rouge d'un seul. Les exercices existants ne sont pas touchés.
- Le message affiché après un changement de mot de passe ne souhaite plus la bienvenue « à la taverne ».

## 1.3.0 — 2026-09-18

### Ajouté

- Mesure d'audience, facultative et éteinte par défaut : [Umami](https://umami.is) tourne à côté de
  l'instance, dans ses propres tables du PostgreSQL déjà là. Sans cookie ni adresse IP conservée : rien à
  faire accepter par un bandeau de consentement, et rien qui parte chez un tiers. Le traceur est servi par
  le site lui-même (`/mesure/traceur.js`, que Caddy relaie à Umami avec son point de collecte), donc dans
  la même origine que les pages : un bloqueur de publicités n'y voit pas un traceur tiers, et seuls ces
  deux chemins sont exposés — ni le tableau de bord, ni l'API d'administration. `ANALYTICS_SCRIPT_URL` et
  `ANALYTICS_WEBSITE_ID` vides, le cas par défaut : aucune balise n'est posée. Le bac à sable, qui n'hérite
  pas du gabarit du site, n'est jamais mesuré, et la page « Vie privée » décrit d'elle-même ce qui est
  compté dès que la mesure est active. Le tableau de bord d'Umami n'est publié que sur la boucle locale
  du serveur et se consulte par un tunnel SSH ; `ANALYTICS_SERVER_NAME` lui donne un nom d'hôte public
  si on le souhaite, mot de passe changé d'abord. Mise en route dans
  [auto-hebergement/README.md](auto-hebergement/README.md#savoir-qui-visite-le-site).

### Corrigé

- Environnement Laravel : tout test de fonctionnalité qui envoyait un `POST`, `PUT`, `PATCH` ou `DELETE` sur
  une route du groupe `web` répondait 419 (« CSRF token mismatch ») dans le navigateur, alors qu'il passait en
  PHPUnit natif. Laravel n'exempte les tests de la vérification CSRF que si `runningInConsole()` **et**
  `runningUnitTests()` sont vrais ; or le premier se déduit de `PHP_SAPI`, qui vaut `wasm` sous php-wasm, ni
  `cli` ni `phpdbg`. Le `APP_ENV=testing` déjà forcé dans `phpunit.xml` ne couvrait donc que la moitié de la
  condition. `APP_RUNNING_IN_CONSOLE=true` y est ajouté, ce qui aligne le navigateur sur PHPUnit natif — où
  cette valeur est vraie de toute façon — sans toucher à l'aperçu, qui continue de vérifier le jeton pour de
  vrai (l'exercice « Le premier garde-fou » du parcours Laravel en dépend). À refaire suivre aux instances
  auto-hébergées : l'archive de l'environnement est reconstruite (`tools/build-env.sh laravel-13`).
  `content:check` ne pouvait pas voir ce décalage, puisqu'il exécute les tests en PHP natif.

## 1.2.2 — 2026-09-18

### Corrigé

- Playground : la correction de la 1.2.1 était incomplète — le rappel d'un exercice déjà réussi appelait
  d'autres fonctions déclarées plus bas, et l'exercice restait bloqué sur « Démarrage de PHP ». Ce rappel
  est maintenant posé une fois tout déclaré, à la fin de la mise en place.
- Playground : une erreur pendant la mise en place s'affiche à la place de l'écran de démarrage, au lieu
  de le laisser tourner indéfiniment.

## 1.2.1 — 2026-09-18

### Corrigé

- Playground : un exercice déjà réussi dont la revue de code avait été enregistrée n'ouvrait plus du tout
  — l'écran restait sur « Démarrage de PHP », faute d'une fonction appelée avant sa déclaration
  (`ReferenceError`). Le rappel de réussite affiche de nouveau la revue.

## 1.2.0 — 2026-09-18

Une mineure : le simulateur Nuxt connaît les garde-fous d'une application (middlewares, cookies, page
d'erreur, `<head>`) et sait faire écrire ses tests à l'apprenant, composants compris. Les packs existants
fonctionnent sans changement ; un pack dont les exercices utilisent l'environnement de test `nuxt`
(`tests/nuxt/`, `mountSuspended`, `registerEndpoint`, `mockNuxtImport`) exige `moteur: '^1.2'`.

### Ajouté

- **Simulateur Nuxt, les garde-fous d'une application**, vérifiés contre un vrai Nuxt 4.5.2.
  - Middlewares de route de `app/middleware/` (nommés, globaux, écrits dans `definePageMeta`), `navigateTo`,
    `abortNavigation` et `addRouteMiddleware`, côté serveur (redirection 302 ou `redirectCode`) comme dans le
    navigateur.
  - Middlewares serveur de `server/middleware/`, cookies de h3 (`getCookie`, `setCookie`, `deleteCookie`,
    `parseCookies`) et `useCookie`.
  - Page d'erreur `app/error.vue` rendue comme par `nuxi dev` (requête interne `/__nuxt_error`, statut de l'erreur),
    avec `showError`, `clearError` et `useError` ; les erreurs d'une route serveur demandée par un navigateur y passent
    aussi. Sans `app/error.vue`, la page d'erreur reste une page minimale.
  - `<head>` produit par unhead (la bibliothèque de Nuxt) : `useHead`, `useSeoMeta`, `app.head` de `nuxt.config`
    (`titleTemplate`, `htmlAttrs`…), mis à jour dans le navigateur à chaque navigation.
  - Le journal de développement transmis à la page reprend aussi les sorties de la console pendant le rendu
    (avertissements de vue-router, `console.log` d'une page).
- **Environnement de test `nuxt`** (celui de `@nuxt/test-utils`) : un exercice peut demander à l'apprenant
  d'écrire des tests de composants, et les noter par mutants comme les tests de bout en bout.
  - Chaque fichier de test tourne dans l'environnement que Vitest lui donnerait : `vitest.config.ts` est lu
    (`defineVitestConfig`, `defineVitestProject`, projets et motifs `include`) et `// @vitest-environment nuxt`
    est respecté. Les fichiers de `tests/nuxt/` (ou `*.nuxt.test.ts`) montent l'application dans un DOM
    (happy-dom), dans le navigateur comme dans `content:check`.
  - `mountSuspended` (props, événements, `setProps`, clics), `registerEndpoint` (routes simulées, méthode,
    `once`, erreurs de h3), `mockNuxtImport` et `mockComponent` (macros hissées, avec `vi.hoisted`), plus
    `vi.mocked`, `vi.stubGlobal`, `vi.waitFor` et `vi.waitUntil` dans le runner. Dans cet environnement, les
    routes de `server/` ne répondent pas : une route non enregistrée donne le 404 de h3, comme en vrai.
  - Des tests de conformité lancent les mêmes fichiers avec le vrai Vitest et le vrai `@nuxt/test-utils` 4.3.2
    et comparent chaque verdict.

### Corrigé

- Simulateur Nuxt : le template d'un composant est compilé à part de son `script setup`, comme le fait
  `nuxi dev` — sans quoi l'état du composant n'était pas visible (`wrapper.vm` dans un test, outils de
  développement de Vue).
- Tests des exercices Nuxt : le `fetch` et le `$fetch` de `@nuxt/test-utils/e2e` suivent les redirections, comme le
  `fetch` de Node (`redirect: 'manual'` pour lire la réponse 3xx). Un test qui attendait une réponse 3xx sans cette
  option la reçoit maintenant suivie : relancez `content:check` sur vos packs Nuxt.

## 1.1.0 — 2026-09-18

### Ajouté

- **Filtres de la Pratique** : une recherche (titre, résumé, notions), des notions cumulables avec leur nombre
  d'exercices, et un tri par date ou par titre, en plus du framework et des nouveautés. Tout tient dans l'URL
  (`?framework=&notions[]=&nouveautes=1&recherche=&tri=`), donc la page reste partageable et fonctionne sans
  JavaScript : avec lui, le bouton « Appliquer les filtres » disparaît et chaque case envoie le formulaire.
  Le paramètre `?notion=` d'avant devient `?notions[]=`.
- **Atelier des auteurs, page d'un parcours** (`/atelier/{parcours}`) : les chapitres en accordéon, leurs
  exercices (numéro ou ★ pour un boss, identifiant, XP), la fiche de cours de chaque chapitre et le
  formulaire de création d'exercice, replié, dans le chapitre concerné.
- **Atelier des auteurs, page de la Pratique** (`/atelier/pratique`) : les exercices hors parcours, tous packs
  confondus, et leur formulaire de création. `pratique` reste réservé : ce n'est pas un identifiant de parcours.
- **Filtres de l'atelier** : publiés ou en préparation, et une recherche sur le titre et la description
  (`?etat=&recherche=`), même formulaire GET que la Pratique.

### Modifié

- **Page de la Pratique** d'après sa maquette : accroche et compteurs en tête, colonne de filtres collante,
  exercices en cartes groupées par date (à venir, cette semaine, avant) ou par titre.
- **Sous 900 px, la colonne latérale se replie** (filtres de la Pratique, sommaire d'un parcours dans
  l'atelier) : elle passait sinon avant ce qu'on est venu lire. Sans JavaScript, elle reste dépliée et son
  résumé sert d'interrupteur.
- **Atelier des auteurs** d'après sa maquette, en deux pages au lieu d'une : `/atelier` ne liste plus que les
  parcours (une fiche chacun : état, pack, chapitres, exercices, XP, fiches de cours écrites, date de dernière
  modification), et le détail d'un parcours a sa propre page. Les pages de l'atelier portent la pastille
  « atelier » dans l'en-tête et un pied de page court.

## 1.0.2 — 2026-09-17

Un correctif : les pages du site plus légères et plus lisibles, d'après un relevé PageSpeed mobile. Aucune
migration, rien à faire sur une instance (le `Caddyfile` suit l'image) ; les packs en `^1.0` fonctionnent tels quels.

### Corrigé

- **Performances et accessibilité des pages du site** (relevé PageSpeed mobile de l'accueil) :
  - logos en WebP (`img/logo-84.webp`, `img/logo-168.webp`, ~75 % plus légers), les PNG disparaissent ;
  - un mois de cache pour `/img/*` dans le `Caddyfile` (les logos repartaient sans aucune durée de cache) ;
  - l'onde du point « Aperçu à jour » n'anime plus une ombre (repeinte à chaque image) mais `transform` et
    `opacity`, pris en charge par le compositeur ;
  - encres secondaires assombries (`--lp-ink-3`, `--lp-ink-4`) et étiquettes des onglets de parcours sans opacité :
    le petit texte atteint 4,5:1 (WCAG AA) ;
  - les liens au fil d'un paragraphe sont soulignés, plus seulement colorés.

## 1.0.1 — 2026-09-17

Un correctif : une page propre à l'instance de Forelse (éteinte ailleurs), un en-tête de sécurité et le ménage des
images au déploiement. Aucune migration, rien à faire sur une instance ; les packs en `^1.0` fonctionnent tels quels.

### Ajouté

- **Page Auto-hébergement** (`/auto-hebergement`) : le moteur libre, ce qu'il contient, l'installation, les parcours
  de Forelse sur devis et la licence ; liée en pied de page et depuis l'accueil, dans le sitemap. Elle est propre à
  l'instance de Forelse : nouvelle variable `SELF_HOSTING_PAGE` (`0` par défaut, `1` dans `deploy/compose.yaml`) ;
  éteinte, la page répond 404 et l'accueil renvoie directement au dépôt.
- L'accueil ne dit plus que le code « sera publié » : il l'est.
- En-tête `Strict-Transport-Security` (un an) sur les réponses HTTPS, sauf pour une adresse locale.
- `deploy/nettoyer-images.sh` : après chaque déploiement, le serveur ne garde que les trois images les plus récentes
  du moteur (celle en service comprise) ; chaque image pèse ~1,6 Go.

## 1.0.0 — 2026-09-17

**Première version stable, et publication du code.** Le format de pack est désormais tenu pour stable : seule une
majeure pourra casser un pack ou une instance. Aucune migration de la base, aucun changement du format de pack par
rapport à la 0.9.2 : une instance passe de la 0.9.2 à la 1.0.0 sans rien faire. **Les packs doivent accepter la
1.0** (`moteur: '^1.0'`, ou ajouter `|| ^1.0` à leur contrainte), sinon le moteur refuse de les charger.

L'historique git du dépôt repart d'un commit unique : les étiquettes `v0.x` et les commits antérieurs ne sont
plus dans le dépôt. Les images Docker `:0.x.y` restent publiées sur GHCR ; celles étiquetées par empreinte de
commit (`:<sha>`) ne correspondent plus à un commit visible.

### Ajouté

- **Guide d'auto-hébergement** ([auto-hebergement/README.md](auto-hebergement/README.md)) avec son `compose.yaml`
  et un `.env.example` générique, à télécharger tels quels : installation depuis l'image publiée, premier
  administrateur, packs, reverse proxy, essai en local, mises à jour, sauvegardes, dépannage.
- Deux variables d'instance, pour servir le conteneur **derrière un reverse proxy** ou l'essayer en local :
  `SERVER_NAME` (adresses écoutées par Caddy, `APP_URL` et `SANDBOX_URL` par défaut ; `:80` pour du HTTP simple)
  et `SYMFONY_TRUSTED_PROXIES` (`private_ranges`). Sans elles, rien ne change.

### Modifié

- L'image Docker reçoit aussi l'étiquette de la majeure (`:1`), que le guide d'auto-hébergement conseille.

### Retiré

- `deploy/README.md`, `deploy/vps/` et `deploy/.env.example` : ils décrivaient l'exploitation de l'instance de
  Forelse, pas le moteur. Pour une instance, partir de `auto-hebergement/` ; `deploy/compose.yaml` reste, c'est
  celui qu'utilise l'intégration continue de Forelse.

## 0.9.2 — 2026-09-17

Nouvelle clé facultative `duration` dans `exercise.yaml` (un moteur plus ancien l'ignore), nouvelles pages
publiques (`/contact`, `/ecoles-et-entreprises`, `/.well-known/security.txt`), nouvelle variable `CONTACT_EMAIL`
(facultative). **Deux migrations de la base sont à jouer** ; l'image Docker les joue au démarrage. Les packs
existants fonctionnent sans changement ; **l'image change de nom** (voir « Modifié »).

### Ajouté

- **Durée estimée des exercices** (`duration:` en minutes dans `exercise.yaml`, facultative) : affichée par
  exercice, par chapitre et pour le parcours sur sa carte, sur l'accueil, et dans le JSON-LD du cours
  (`timeRequired`). Une somme n'est affichée que si tous les exercices concernés ont une durée.

- **Conditions générales de vente** (`/cgv`, lien dans le pied de page) : prix TTC, commande et paiement, accès à
  vie, renonciation au droit de rétractation, garantie « satisfait ou remboursé », garanties légales, médiation.
  Comme les mentions légales, le texte vient du moteur et le vendeur de la configuration. Nouvelles variables
  d'environnement : `LEGAL_MEDIATOR_NAME` et `LEGAL_MEDIATOR_URL` (médiateur de la consommation, obligatoire pour
  vendre à des particuliers) et `LEGAL_REFUND_DAYS` (durée de la garantie, vide : pas de garantie). **Tant que le
  vendeur ou le médiateur ne sont pas renseignés, rien ne se vend** : l'achat reste « bientôt disponible ».
- La page d'achat demande d'accepter les CGV (lien direct) en plus de la renonciation ; la version acceptée et son
  heure sont enregistrées avec l'achat, visibles dans l'admin et rappelées dans l'email de confirmation.
  **Une migration de la base est à jouer** ; l'image Docker la joue au démarrage.
- **Page de contact** (`/contact`, `?objet=commande` présélectionne l'objet) et **page Écoles et entreprises**
  (`/ecoles-et-entreprises`) avec sa demande de devis. Chaque message est gardé en base (admin : Messages, à marquer
  traités) puis transmis par email avec l'expéditeur en Reply-To. Piège à robots, cinq messages par heure et par
  adresse IP, aucun accusé de réception. Nouvelle variable `CONTACT_EMAIL` (vide : `LEGAL_PUBLISHER_EMAIL`) ; sans
  adresse, pas de formulaire. Les deux pages sont dans le sitemap et le pied de page. **Une migration est à jouer.**
- **Mon compte** refait et complété : chiffres en tête (XP, exercices réussis, accès actifs), le parcours en
  cours mis en avant avec « Reprendre » vers le premier exercice pas encore réussi, les autres parcours visibles
  (en cours, accès actif, gratuit, non démarré, terminé), accès, achats et Pratique en colonne, le changement de pseudo, d'adresse et de mot de passe, et la suppression du compte
  par l'apprenant lui-même (mot de passe demandé ; les achats restent, sans lien vers lui).
- **Confirmation de l'adresse email** par un lien signé, valable 48 heures : envoyé à l'inscription, renvoyable
  depuis le compte (cinq envois par heure). Une nouvelle adresse ne remplace l'ancienne qu'une fois confirmée.
  Les comptes existants apparaissent « à confirmer ».
- **Factures** : chaque paiement Stripe émet une facture (numérotation et TVA tenues par Stripe) ; un client
  professionnel peut y porter sa raison sociale et son numéro de TVA. Elle se télécharge depuis le compte.
  **La clé Stripe doit pouvoir lire les factures** (clé restreinte : droit « Invoices » en lecture) ; Stripe
  facture ce service à l'usage. **Une migration de la base est à jouer.**
- `/.well-known/security.txt` (RFC 9116) : l'adresse de l'éditeur (`LEGAL_PUBLISHER_EMAIL`) pour signaler une
  faille, avec une expiration qui avance d'elle-même. Sans adresse, pas de fichier.
- `/favicon.ico`, pour les outils qui le demandent sans lire les balises de la page.
- La politique de confidentialité et les CGV décrivent la suppression du compte en libre-service et la facture.
- La politique de confidentialité décrit les achats (données, Stripe comme destinataire, conservation dix ans des
  pièces comptables) quand le paiement est configuré.

### Modifié

- **Le dépôt et l'image changent de nom** : `Oipnet/apprendre-forelse` et `ghcr.io/oipnet/apprendre-forelse`.
  Une instance qui tire encore `ghcr.io/oipnet/formation-symfony` ne reçoit plus de mise à jour : changez
  `APP_IMAGE` dans son `.env` (et reprenez `deploy/compose.yaml`).
- Pack de démonstration : son exercice de Pratique s'appelle désormais `exemple-map-request-header` (adresse
  `/pratique/exemple-map-request-header`). Une instance qui le proposait perd la progression de ses apprenants
  sur cet exercice.
- `deploy/.env.example` : inscription libre par défaut (`REGISTRATION_INVITE_ONLY=0`) et plus de parcours de
  démonstration dans `CONTENT_PACKS_PATHS` (à ajouter soi-même). Le défaut de `deploy/compose.yaml` ne change pas.
- **Carte d'un parcours** refaite : bandeau avec « Vous en êtes là » (ou « Pour commencer ») et le prix, barre de
  progression, exercices, chapitres, XP et durée ; sommaire des chapitres à côté ; chapitres en accordéon
  (celui en cours ouvert, « Tout ouvrir » avec JavaScript), boss mis en valeur, fiche de cours en fin de chapitre.
- **Pied de page** commun à toutes les pages, accueil compris : la marque, trois colonnes (Apprendre, Forelse,
  Informations), puis la mention des marques et la version du moteur.
- Messages après une action : vert pour un succès, rouge pour une erreur, sur toutes les pages du site.
- Accueil : la carte « Équipe ou école » mène à la nouvelle page au lieu d'annoncer « une offre dédiée arrive ».
- En-tête du site : la navigation garde Parcours, Pratique et Questions ; le compte, les espaces réservés
  (Atelier, Mes cohortes, Administration) et la déconnexion passent dans un menu ouvert par le nom de
  l'utilisateur et son XP (au clavier comme sans JavaScript).

### Corrigé

- Le message qui suit l'inscription ne parle plus de taverne : il vaut pour tous les parcours.
- Un parcours déjà acheté (ou ouvert par une cohorte) n'affiche plus son prix, ses places restantes au prix
  fondateur ni « Premier chapitre gratuit » sur l'accueil et sur sa page : seulement « Continuer ».

## 0.9.1 — 2026-09-17

Un correctif. **Une migration de la base est à jouer** (nouvelle table `track_seo`) ; l'image
Docker la joue au démarrage. Les packs ne changent pas.

### Modifié

- Title d'une page de parcours : « Formation Symfony en ligne pour les devs PHP | Forelse » au lieu de
  « Formation Symfony en ligne : Symfony pour les devs PHP ». Le public vient du titre du parcours quand il
  commence par le framework suivi de « pour … » ; sinon, « Formation Symfony en ligne : Découverte | Forelse ».
  La marque reste toujours ; un title de plus de 60 caractères (sans elle) est signalé dans les journaux.
- Description d'une page de parcours : le résumé du parcours d'abord, les chiffres ensuite
  (« … 12 chapitres, 74 exercices, premier chapitre gratuit. »). Un résumé trop long garde ses phrases entières,
  ou, s'il en reste trop peu, est coupé sur un mot. Un parcours dont le tarif est fixé à 0 € est dit « gratuit ».

### Ajouté

- **Référencement des parcours** dans l'admin (`/admin/referencement`) : un title et une description saisis
  pour un parcours remplacent ceux qui sont générés (og:title et og:description compris). La liste montre le
  title généré.

## 0.9.0 — 2026-09-17

Une mineure : **le site se laisse trouver par les moteurs de recherche**. Les packs existants
fonctionnent sans changement, mais leur contrainte `moteur:` doit accepter la 0.9 (par exemple `'^0.8 || ^0.9'`).
Aucune migration. Une instance qui ne doit pas être indexée (préproduction, instance interne) passe
`SEARCH_INDEXING=0`.

### Ajouté

- **Pages d'exercice lisibles par tous** : un exercice qui ne vous est pas encore ouvert montre sa consigne, ses
  notions, ses XP et sa place dans le parcours, avec un fil d'Ariane. L'éditeur est remplacé par un encart
  (premier chapitre gratuit, créer un compte, prix du parcours) ; ni éditeur ni moteur WebAssembly ne se chargent.
  Les indices, les tests et la solution restent réservés à ceux qui ont accès. Après inscription depuis cet
  encart, on revient sur l'exercice.
- **Pratique lisible sans compte** : chaque exercice de Pratique explique la fonctionnalité (le problème, le code
  d'avant, la nouveauté, la version et la date) ; écrire le code et le valider demande toujours un compte.
- **Catalogue à jour sur l'accueil** : les parcours en préparation sont annoncés (« Bientôt »), sans lien, et la
  question « Quels parcours ? » cite les parcours réellement installés.
- `robots.txt` et `sitemap.xml` servis par l'application. Le sitemap liste l'accueil, les parcours publiés et
  leurs exercices, la Pratique publiée et les pages légales, avec leur date de modification ; rien d'un parcours
  en préparation ni d'un exercice programmé. Le bac à sable interdit tout aux robots.
- Balises de chaque page publique : title et description propres à la page, adresse canonique, Open Graph et
  carte Twitter (image par défaut `img/og-forelse.png`), données structurées schema.org (organisation, site,
  auteur et questions fréquentes sur l'accueil, formation avec son prix sur un parcours, article sur un exercice
  de Pratique, fil d'Ariane).
- `SEARCH_INDEXING` (1 par défaut) et `GOOGLE_SITE_VERIFICATION` (balise de vérification de Google Search
  Console, vide par défaut) dans `.env` et `deploy/.env.example`.

### Modifié

- Un visiteur sans compte qui ouvre un exercice fermé ou un exercice de Pratique lit sa page au lieu d'être
  redirigé vers l'inscription.
- Les pages privées (connexion, inscription, compte, administration, cohortes, atelier, achat, erreurs) sont
  marquées `noindex` ; une liste de Pratique filtrée est en `noindex, follow`, avec la liste complète pour
  adresse canonique.
- Une page publique vue sans compte porte un ETag : un visiteur ou un robot qui revient reçoit un 304 si rien
  n'a changé.
- Logos servis à leur taille d'affichage (13 et 38 Ko au lieu de 85 Ko), images sous la ligne de flottaison
  chargées à la demande.

## 0.8.0 — 2026-09-17

Une mineure : les **parcours payants**. Les packs existants fonctionnent sans changement, mais
leur contrainte `moteur:` doit accepter la 0.8 (par exemple `'^0.7 || ^0.8'`), sinon le moteur les refuse au
chargement. **Une migration de la base est à jouer** ; l'image Docker la joue au démarrage. Une instance qui ne
fixe aucun prix reste gratuite pour tout compte. **Avant de fixer un prix**, lancer une fois
`bin/console app:acces:initialiser` : sans elle, les apprenants existants perdraient l'accès aux chapitres payants.
Un exercice ne peut plus s'appeler `acheter` (l'URL `/parcours/<parcours>/acheter` est prise).

### Ajouté

- **Premier chapitre gratuit** : tout le premier chapitre d'un parcours public se joue sans compte (auparavant,
  le seul exercice marqué `access: free`), progression gardée dans le navigateur puis reprise à l'inscription.
  La suite du parcours demande un accès ; la page d'accueil et la carte du parcours l'annoncent.
- **Accès aux parcours** : une seule règle décide de ce qu'un apprenant ouvre, qu'il ait acheté le parcours,
  qu'une cohorte le lui ouvre ou qu'on le lui ait offert. Elle protège les pages d'exercice, les API de
  l'exercice, de la progression et du mentor (403), les fiches de cours et le livret. Un accès expiré ou révoqué
  ne touche jamais à la progression : un nouvel accès la retrouve intacte. Un chapitre fermé affiche le prix et
  le bouton d'achat ; dans un exercice déjà ouvert, le playground explique qu'un accès a pris fin. Un parcours sans tarif, ou à 0 €, reste ouvert à tout compte.
- **Tarifs** (`/admin` → Tarifs, ou `bin/console app:tarif <parcours> 79 --fondateur=49 --quota=100`) : prix
  normal et **prix fondateur**, limité par une date, un nombre d'achats, ou les deux. L'accueil, la carte du
  parcours et la page d'achat affichent le prix TTC en euros, le prix normal barré et la fin du prix fondateur
  (date ou places restantes).
- **Achat à vie d'un parcours** avec Stripe Checkout et Stripe Tax (TVA du pays de l'acheteur, prix TTC) :
  page de confirmation avec renonciation obligatoire au droit de rétractation (le texte coché et son heure sont
  conservés avec l'achat), page de retour qui patiente jusqu'à la confirmation, email de confirmation avec le
  reçu. L'accès n'est ouvert que par le webhook `/paiement/stripe/webhook` (signature vérifiée, événements
  journalisés, idempotent) ; `bin/console app:stripe:rejouer` retraite un événement. Un parcours déjà ouvert ne
  se rachète pas. Nouvelles variables d'environnement, facultatives : `STRIPE_SECRET_KEY` (vide : les prix
  s'affichent, l'achat est « bientôt disponible » ; clé réelle refusée hors production), `STRIPE_WEBHOOK_SECRET`, `STRIPE_TAX_CODE`.
- **Mon compte** (`/compte`) : ses accès (parcours, origine, fin) et ses achats, avec le lien vers le reçu.
- **Administration** : liste des achats filtrable (statut, parcours, prix appliqué, période) avec
  remboursement Stripe, qui révoque l'accès ; liste des accès, « Offrir un parcours » depuis la fiche d'un
  apprenant (avec ou sans date de fin) et révocation.
- **Financement des cohortes** : par l'établissement (chaque apprenant reçoit l'accès aux parcours choisis, aux
  dates de la cohorte, dès son inscription ou son rattachement ; un parcours ajouté s'ouvre aux apprenants
  présents, un parcours retiré ferme les accès de la cohorte sans toucher à un achat) ou par les apprenants
  (chacun achète, au tarif de la cohorte s'il est fixé). Dates d'accès, effectif prévu, tarif et montant du
  devis se règlent dans l'administration, qui affiche une **estimation du devis** (effectif × parcours × prix
  unitaire, barème dégressif par paliers dans `config/services.yaml`, `app.cohort_quote.*`) et sait la copier
  dans le devis. Le chef de cohorte voit le financement et modifie l'effectif prévu ; un devis qui ne correspond
  plus à l'estimation est signalé « à revoir ».

### Modifié

- Une cohorte financée par l'établissement doit choisir ses parcours : « aucun coché = tous les parcours » ne
  vaut plus que pour une cohorte financée par ses apprenants. La migration fait passer les cohortes existantes
  en financement par l'établissement, devis à 0, accès pendant un an ; `app:acces:initialiser` fige leur liste
  de parcours (les parcours publics, si elles n'en avaient choisi aucun), ouvre les accès de leurs apprenants,
  et offre à vie les parcours publics aux apprenants sans cohorte.
- Un parcours acheté hors de la sélection de sa cohorte reste visible pour l'apprenant.

### Déprécié

- La clé `access` de `exercise.yaml` n'a plus d'effet : `content:check` la signale. Elle sera refusée dans une
  version majeure. Le pack de démonstration ne l'utilise plus.

## 0.7.0 — 2026-09-17

Une mineure : un nouvel environnement, `nuxt-4`, et son simulateur. Les packs existants fonctionnent sans
changement, mais leur contrainte `moteur:` doit accepter la 0.7 (par exemple `'^0.6 || ^0.7'`), sinon le
moteur les refuse au chargement ; un pack qui utilise `nuxt-4` exige `'^0.7'`. `content:check` a besoin de
Node.js 22.19 ou 24.11 au moins, et de `npm ci` dans `tools/nuxt-sim`, pour vérifier les exercices Nuxt.

### Ajouté

- Pages d'erreur aux couleurs du site (404, 403, 429, 503 et erreurs serveur), avec un en-tête réduit
  qui ne dépend ni du compte ni de la base, la requête en cause et un retour à l'accueil. Aperçu en
  développement sur `/_error/404`, `/_error/500`…
- Début d'un simulateur Nuxt en TypeScript (`tools/nuxt-sim`), pour un futur parcours Nuxt dans le
  navigateur : il exécute les routes de `server/api` et `server/routes` (h3, routage de Nitro,
  `runtimeConfig`, erreurs de `nuxi dev`, validation avec `getValidatedQuery`,
  `getValidatedRouterParams` et `readValidatedBody`, stockage `useStorage` en mémoire avec le montage
  `data`, import de fichiers JSON, auto-imports de `server/utils`, `shared/utils` et `shared/types`), rend côté serveur les pages de `app/pages` avec leurs
  layouts (`NuxtPage`, `NuxtLayout`, `NuxtLink`, `definePageMeta`, `useRoute`) et sert les modules qui
  les hydratent dans le navigateur. Il connaît les auto-imports de Nuxt (composants de `app/components`,
  exports de `app/composables`, `app/utils`, `shared/utils` et `shared/types`), `app.config.ts` et
  `useAppConfig`, `useState` avec ses clés automatiques, l'alias `#shared`, `<ClientOnly>`, les
  globales du navigateur qui valent `undefined` côté serveur, la récupération des données
  (`useFetch`, `useLazyFetch`, `useAsyncData`, `useNuxtData`, `refreshNuxtData`, `clearNuxtData`,
  `callOnce`, `$fetch` qui appelle les routes du projet sans réseau, erreurs transmises dans la charge
  utile), les API extérieures (`$fetch` vers une URL absolue, fichier `.env`, `runtimeConfig` privé), le
  cache de Nitro (`defineCachedEventHandler`, `defineCachedFunction`, avec les mêmes ETag et en-têtes),
  et les diagnostics de développement (layout inconnu, composant introuvable, composable
  appelé hors d'un `setup`, options incompatibles pour une même clé). Le bac à sable n'ayant pas
  Internet, les appels sortants reçoivent les réponses enregistrées dans `reseau.json`, à la racine du
  projet (une réponse par URL, ou des variantes selon la méthode, la query et les en-têtes). Des tests de
  conformité envoient les mêmes requêtes à un vrai Nuxt 4.5.2 et au simulateur, et échouent au moindre
  écart (pages comprises, à l'octet près).
- Environnement `nuxt-4` (`framework: nuxt`, sans clé `php`) : le playground joue ses exercices avec le
  simulateur Nuxt, dans un Web Worker. L'aperçu affiche les pages rendues côté serveur puis hydratées
  (navigation sans rechargement), sous le préfixe de l'aperçu. Les avertissements et erreurs de la page
  de l'aperçu (erreurs d'hydratation, `[Vue warn]`, exceptions, avertissements du rendu serveur)
  s'affichent dans l'onglet Console du playground. **Encore expérimental** (pas de commande `nuxi`).
  L'identifiant `nuxt` rejoint les frameworks de la Pratique.
- Tests des exercices Nuxt : des fichiers Vitest (`tests/….test.ts`) qui importent `vitest` et
  `@nuxt/test-utils/e2e` (`setup`, `$fetch`, `fetch`, `url`). Ils tournent contre le simulateur, avec les
  assertions de Vitest (`@vitest/expect`, `vi.fn`) et le vrai `ofetch`, dans le navigateur (« Lancer les
  tests ») comme dans `content:check`, qui les lance sous Node : même runner, même verdict. Un objectif
  désigne un test par son titre (`it('affiche le nom du refuge')`) ; les tests écrits par l'apprenant
  (`editable: [tests/….test.ts]`) sont notés par `own-tests` et les mutants, comme en PHP. **Node.js est
  nécessaire à `content:check` pour ces exercices**, ainsi que `npm ci` dans `tools/nuxt-sim` ; l'image
  Docker n'a ni l'un ni l'autre, et le signale. Un module propre au simulateur, `bac-a-sable`, laisse les
  tests mettre une API extérieure en panne ou la rendre muette (`reseau.simuler`), compter ses appels
  (`reseau.appels`) et avancer l'horloge du cache (`horloge.avancer`). Pas de snapshots, de `vi.mock`, de
  `mountSuspended` ni de navigateur piloté (`createPage`) pour l'instant. Le build du playground importe
  désormais `tools/nuxt-sim` : `npm ci` y est nécessaire avant `npm run build` (fait par le Dockerfile
  et la CI).

## 0.6.0 — 2026-09-17

Une mineure : la publication programmée des exercices de Pratique et trois composants de plus dans
`symfony-8-2-dev`. Les packs existants fonctionnent sans changement, mais leur contrainte `moteur:` doit accepter
la 0.6 (par exemple `'^0.5 || ^0.6'`), sinon le moteur les refuse au chargement. Un exercice de Pratique déjà
daté d'un jour à venir disparaît de la liste publique jusqu'à cette date.

### Ajouté

- **Publication programmée** d'un exercice de Pratique : daté d'un jour à venir (`published`), il n'est visible
  que des administrateurs, avec le badge « Programmé le … », et se publie seul ce jour-là.
- Environnement `symfony-8-2-dev` : PropertyAccess, Serializer et Validator (8.2.x-dev), pour les exercices sur le
  joker `[*]` de PropertyAccess et sur la réponse 422 d'une `ValidationFailedException`.

## 0.5.4 — 2026-09-17

Des corrections du playground : l'aide aux paramètres dans l'éditeur PHP et un message clair quand la
session a expiré. Aucun pack n'est concerné.

### Corrigé

- L'éditeur PHP n'affichait pas les paramètres de l'appel en cours. Il les montre maintenant en tapant `(` ou `,`
  (ou un argument nommé `status:`) : constructeurs (`new Response(`), attributs (`#[AsCommand(`), méthodes
  (`$request->get(`, `$this->annuaire->utilisateurs(`) et appels statiques, avec le paramètre courant en gras.
- Une session expirée (déconnexion pendant qu'un exercice est ouvert) donnait « Unauthorized » dans le mentor et
  des erreurs 401 sans explication à la sauvegarde et à l'envoi d'un avis. Le playground dit maintenant que la
  session a expiré et qu'il faut se reconnecter.

## 0.5.3 — 2026-09-17

Un correctif : un déploiement ne déconnecte plus les utilisateurs. Aucun pack n'est concerné ; une instance
auto-hébergée qui n'utilise pas l'image Docker peut régler `SESSIONS_DIR` (par défaut `var/sessions/<env>`).

### Corrigé

- Chaque déploiement déconnectait tout le monde : les sessions vivaient dans le conteneur, recréé à chaque
  mise à jour. Elles sont maintenant écrites dans `SESSIONS_DIR`, que l'image Docker place dans le volume
  `/data` (`/data/app/sessions`). Le déploiement de cette version déconnecte une dernière fois.

## 0.5.2 — 2026-09-17

Un correctif : les environnements mis à jour ne restent plus masqués par le cache du navigateur. Aucun pack n'est concerné.

### Corrigé

- Après une mise à jour d'un environnement, le navigateur gardait jusqu'à un jour l'ancienne archive et l'ancien
  index de complétion (même URL, mis en cache 24 heures) : une classe ajoutée à l'index, comme `InputOption` en
  0.5.1, restait introuvable. Leurs URL portent maintenant la date de construction du fichier (`?v=…`).

## 0.5.1 — 2026-09-17

Un correctif : la complétion de l'éditeur connaît la console Symfony. Aucun pack n'est concerné.

### Corrigé

- La complétion de l'éditeur ne connaissait aucune classe de la console Symfony : `use Symfony\Component\Console\Input\InputOption;`
  et les autres ne se complétaient pas. L'index des environnements Symfony comprend maintenant les attributs
  de la console (`#[AsCommand]`, `#[Argument]`, `#[Option]`…), `Command`, `CommandChain` (8.2), `InputInterface`,
  `InputOption`, `InputArgument`, `OutputInterface`, `SymfonyStyle`, `CommandTester`, `ApplicationTester` et
  l'`Application` de FrameworkBundle.

## 0.5.0 — 2026-09-16

Une mineure : un nouvel environnement. Les packs existants fonctionnent sans changement ; ceux qui
l'utilisent doivent exiger ce moteur (`moteur: '^0.5'`), sinon un moteur plus ancien refuse tout le pack.

### Ajouté

- Environnement `symfony-8-2-dev` : Symfony 8.2 en développement (8.2.x-dev, figé par son `composer.lock`),
  avec la console, Twig et PHPUnit. Il permet d'écrire des exercices de Pratique sur les nouveautés
  annoncées avant la sortie de Symfony 8.2 (`version: '8.2'` est accepté par `content:check`). Il
  disparaîtra quand `symfony-8` passera en 8.2 : ses exercices changeront alors d'environnement.

## 0.4.0 — 2026-09-16

Une mineure : la **Pratique**, de courts exercices à part des parcours. Les packs existants
fonctionnent sans changement, mais leur contrainte `moteur:` doit accepter la 0.4 (par exemple
`'^0.3 || ^0.4'`), sinon le moteur les refuse au chargement. **Une migration de la base est à jouer** ;
l'image Docker la joue au démarrage. Un parcours ne peut plus s'appeler `pratique`.

### Ajouté

- **Pratique** : de courts exercices à part des parcours, pour se servir d'une fonctionnalité d'un
  framework (souvent une nouveauté d'une version, parfois un point précis). Ils vivent dans
  `<pack>/practice/<id>/`, au format d'un exercice, avec les clés `environment`, `published`, `summary`,
  et au besoin `version`, `pull_request` et `visibility` ; ni `access` (un compte est toujours demandé),
  ni `base`, ni `xp`. Nouvelles URL publiques : `/pratique` (liste publique, filtres par framework,
  notion et nouveautés) et `/pratique/<id>`. À la réussite, le playground propose un « Avant / après »
  (code de départ face au code de l'apprenant) et le lien vers la pull request. `content:check` et
  `content:links` acceptent les cibles `pratique` et `pratique/<id>`, et `content:check` refuse une
  `version` plus récente que le framework de l'environnement. L'atelier crée, modifie et supprime ces
  exercices. Le pack de démo en contient un (`#[MapRequestHeader]`, Symfony 8.1). **Une migration est
  à jouer** (progression et retours sans parcours). L'identifiant de parcours `pratique` est désormais
  réservé : un pack qui l'utilisait est refusé.

### Corrigé

- L'ordre des parcours dans les listes, dont l'accueil et son onglet ouvert par défaut, dépendait des
  chemins de `CONTENT_PACKS_PATHS` et du nom des dossiers de packs : il pouvait changer d'une instance
  à l'autre sans que personne l'ait choisi. Il se fixe maintenant avec `order:` dans `track.yaml` (le
  plus petit d'abord) ; les parcours sans rang gardent l'ordre de chargement, après les autres. Un moteur
  plus ancien ignore la clé : un pack peut la déclarer sans exiger cette version.
- La revue de code du mentor et la rédaction assistée de l'atelier s'arrêtaient sur « Maximum execution
  time of 30 seconds exceeded » quand le modèle mettait plus de 30 secondes à répondre (PHP limite une
  requête web à 30 secondes par défaut). L'appel au modèle dispose maintenant du même délai que le client
  HTTP, cinq minutes.

## 0.3.3 — 2026-09-16

Des corrections seules : le simulateur Docker se comporte davantage comme Docker et les images
officielles. Un exercice qui s'appuyait sur l'un de ses anciens écarts peut échouer : relancez
`content:check` sur vos packs. Deux comportements changent visiblement : un utilisateur non root ouvre
maintenant les ports sous 1024 dans un conteneur, et un volume PostgreSQL 18 monté sur
`/var/lib/postgresql/data` reste vide, comme avec la vraie image.

### Corrigé

- Simulateur Docker : un utilisateur non root ne pouvait pas ouvrir un port sous 1024. Docker Engine
  le permet dans les conteneurs depuis la version 20.10 ; seul le réseau de l'hôte garde la limite.
- Simulateur Docker : `docker compose up --wait` jugeait la santé des conteneurs sur un état ancien ;
  il rejoue les healthchecks au moment d'attendre, et affiche `Healthy` pour les services qui en ont un.
- Simulateur Docker : `kill 1` dans un conteneur ne faisait rien. Les serveurs s'arrêtent sur TERM, INT
  ou QUIT (et une politique de redémarrage les relance), un processus sans gestionnaire les ignore, et
  KILL envoyé de l'intérieur reste sans effet sur le processus n° 1, comme sous Linux ; `kill -HUP 1`
  recharge nginx.
- Simulateur Docker : PostgreSQL 18 refuse de démarrer quand des données d'une version plus ancienne
  se trouvent dans `/var/lib/postgresql` ou `/var/lib/postgresql/data`, avec le message de l'image.
- Simulateur Docker : un fichier donné à un numéro (`chown 82:82`) n'appartenait à personne pour
  l'utilisateur du même numéro ; noms et numéros se valent désormais, pour l'écriture comme pour `stat`,
  `ls -l` et `ls -n`.
- Simulateur Docker : l'image `axllent/mailpit` contient son binaire, déclare sa vigie
  (`/mailpit readyz`) et le port 1110, et répond sur `/livez` et `/readyz`. Les images nginx Debian
  contiennent `curl`. `docker inspect` donne les durées d'une vigie (`Interval`, `StartPeriod`…).
- Simulateur Docker : `php -r` voyait les extensions du PHP du simulateur (`extension_loaded`,
  `get_loaded_extensions`, `phpversion`) ; ce sont celles du conteneur. L'avertissement d'une extension
  qui ne se charge pas apparaît une fois dans le journal (`PHP Warning:`) et une fois à l'écran
  (`Warning:`), comme en ligne de commande.
- Simulateur Docker : la pile d'appels d'une exception affichait les rouages du simulateur ; elle
  s'arrête sur `{main}`, comme celle de PHP.
- Simulateur Docker : `--ignore-platform-req=ext-intl` faisait ignorer toutes les exigences de plateforme
  à Composer ; seules celles qui sont nommées le sont (`ext-*` accepté).
- Simulateur Docker : `docker compose up` prévient qu'un volume existe déjà sans avoir été créé par
  Compose ; `docker ps --format` et `docker compose ps --format` acceptent un gabarit (`{{.Names}}`,
  `table …`).
- Simulateur Docker : `id <utilisateur>`, `getent passwd` et `getent group` répondent ; les images
  contiennent `/etc/group` et `/etc/os-release` ; `ls` connaît `-a`, `-R` et `-n` ; les tailles de
  `docker images` et `docker history` ont trois chiffres significatifs (`3.14MB`) ; nginx sur Alpine se
  dit compilé par Alpine ; un binaire d'image n'est plus lancé comme un script.

## 0.3.2 — 2026-09-16

Des corrections seules, comme la 0.3.1 : le simulateur Docker se comporte davantage comme Docker, PHP
et nginx. Un exercice qui s'appuyait sur l'un de ses anciens écarts (un `docker run` qui rendait 0
quel que soit le code de sortie, par exemple) peut échouer : relancez `content:check` sur vos packs.

### Corrigé

- Simulateur Docker : `docker run … sh -c 'exit 4'` rendait 0. Le code de sortie d'un conteneur
  ponctuel est celui de sa commande, et une commande placée après `;` s'exécute même si la
  précédente a échoué (seul `&&`, ou `set -e`, l'en empêche).
- Simulateur Docker : `php -r` n'exécutait que quelques expressions reconnues et sortait toujours en 0.
  Dans un conteneur, le code tourne maintenant pour de vrai, `exit()` compris ; `PHP_VERSION`,
  `PHP_OS` et leurs voisines sont celles du conteneur.
- Simulateur Docker : un utilisateur non root ne pouvait lancer aucun `chown`. Comme sous Linux, il
  peut se redonner un fichier qui lui appartient déjà, et se voit refuser tout le reste.
- Simulateur Docker : php-fpm lancé sans root signale que les directives `user` et `group` de son
  pool sont ignorées.
- Simulateur Docker : nginx lancé sans root démarrait comme s'il l'était. Il prévient que la
  directive `user` est ignorée, puis s'arrête s'il ne peut pas créer ses dossiers temporaires ou son
  fichier de pid. L'image `nginxinc/nginx-unprivileged` est disponible : elle écoute sur 8080 et
  garde ses fichiers temporaires dans `/tmp`.
- Simulateur Docker : le dossier des extensions PHP et le numéro d'API affiché par `phpize` étaient
  ceux de PHP 8.4 dans toutes les images ; chaque version a désormais le sien (`20230831` pour 8.3).
- Simulateur Docker : le binaire de Composer pesait 18 octets et n'était pas exécutable ; il pèse ses
  3 Mo, ce qui se voit dans `docker history` et `ls -l`. Les programmes livrés par les images sont
  exécutables.
- Simulateur Docker : `env` affichait une variable interne du simulateur ; `stat` ne traitait qu'un
  fichier à la fois ; `docker history` n'indiquait `buildkit.dockerfile.v0` que pour certaines
  couches ; l'heure de `php-fpm -t` était figée.

## 0.3.1 — 2026-09-16

Des corrections seules : le format de pack, la configuration d'une instance et les URL ne changent pas,
et les packs qui demandent `^0.3` acceptent cette version. Le simulateur Docker se comporte davantage
comme Docker, nginx et les outils des images. Un exercice qui s'appuyait sur l'un de ses anciens écarts
(une configuration nginx prise en compte sans redémarrage, par exemple) peut échouer : relancez
`content:check` sur vos packs.

### Corrigé

- Simulateur Docker : nginx relisait sa configuration à chaque requête. Il sert maintenant avec celle
  lue à son démarrage, jusqu'au redémarrage du conteneur ou à `nginx -s reload`, qui la recharge ou,
  si elle est fausse, garde l'ancienne. `nginx -t` et `nginx -v` fonctionnent aussi comme commande d'un
  conteneur (`docker compose run --rm web nginx -t`).
- Simulateur Docker : le repli de `try_files` vers une adresse sans `?` (`/index.php`) gardait les
  arguments de la requête ; comme la redirection interne de nginx, il les perd désormais, et il faut
  `/index.php$is_args$args` pour les transmettre.
- Simulateur Docker : `allow` et `deny` étaient ignorés ; `deny all;` répond maintenant 403 et le
  journalise. Un `return` posé dans le bloc `server` était ignoré aussi.
- Simulateur Docker : l'image nginx ne livrait pas `fastcgi_params`, `fastcgi.conf`, `scgi_params` ni
  `uwsgi_params`. Ils y sont, `include` les charge pour de vrai (`fastcgi.conf` pose `SCRIPT_FILENAME`,
  `fastcgi_params` non), et les `fastcgi_param` du bloc `server` sont hérités par une location qui n'en
  pose aucun.
- Simulateur Docker : `host not found in upstream` cite la ligne de `fastcgi_pass` ou `proxy_pass`, et
  non celle qui suit la `location` ; le journal d'erreurs de nginx montre la requête d'origine, et non
  l'adresse du détour de `try_files`.
- Simulateur Docker : l'image `php:*-fpm` contient `docker.conf`, `www.conf.default` et un
  `php-fpm.conf` qui inclut `php-fpm.d/*.conf` ; php-fpm ne lit que les fichiers en `.conf`, par ordre
  alphabétique.
- Simulateur Docker : le healthcheck n'était joué qu'au démarrage. Il est rejoué chaque fois qu'on
  regarde l'état d'un conteneur (`docker ps`, `inspect`, `docker compose ps`) : une application qui
  tombe en panne devient `unhealthy`, réparée elle redevient `healthy`, sans redémarrage. Le temps
  reste comprimé : un échec compte pour tous les essais.
- Simulateur Docker : `curl` et `wget` se présentaient comme un navigateur venu du réseau. Ils donnent
  leur vrai agent (`curl/8.14.1`, `Wget`) et, vers leur propre conteneur, arrivent de `127.0.0.1`.
  `curl -A` est pris en compte.
- Simulateur Docker : `grep` ignorait `-n`, `-H`, `-h`, `-l`, `-L`, `-o`, `-w`, `-x`, `-s` et `-m`, ne
  comprenait pas l'alternative `\|` des expressions basiques et prenait `|` pour une alternative ;
  plusieurs fichiers n'affichaient pas leur numéro de ligne. `egrep` et `fgrep` manquaient. `ls` d'un
  motif (`*.conf`) affichait les noms sans leur chemin.
- Simulateur Docker : dans les images Debian, `rm`, `mkdir`, `touch`, `cp`, `mv`, `ls`, `chmod`,
  `chown`, `sed`, `find` et `stat` affichaient les messages d'erreur de BusyBox ; ce sont maintenant
  ceux des coreutils de GNU. Alpine garde ceux de BusyBox.
- Simulateur Docker : `docker ps` et `docker compose ps` omettaient les ports exposés mais non publiés
  (`9000/tcp`) ; `docker ps` ne listait pas toujours les conteneurs les plus récents d'abord.
- Simulateur Docker : les adresses IP venaient d'un compteur global. Chaque réseau les distribue à
  partir de `.2`, en reprenant les adresses libérées, et un réseau supprimé rend son sous-réseau.
- Simulateur Docker : `docker compose down` arrête les services dans l'ordre inverse des dépendances.
- `content:links` signalait à tort des ancres absentes quand la page est du HTML minifié, dont les
  attributs n'ont pas de guillemets (`id=env`, comme sur docs.docker.com).

## 0.3.0 — 2026-09-16

Une migration de base de données accompagne cette version : l'image Docker l'applique au démarrage,
ailleurs lancez `bin/console doctrine:migrations:migrate`. Rien ne change pour les apprenants
existants : une cohorte dont aucun parcours n'est coché les propose tous, comme avant, et un
apprenant sans cohorte n'est pas concerné. Un pack qui déclare `moteur: '^0.1 || ^0.2'` doit ajouter
`|| ^0.3` pour être chargé.

### Ajouté

- Rôle **chef de cohorte** : un enseignant suit une ou plusieurs cohortes sans être administrateur.
  Il le reçoit sur sa fiche (« Apprenants », case « Chef de cohorte »), puis un administrateur
  l'ajoute aux chefs d'une cohorte dans son formulaire. L'administrateur a tous les droits d'un chef,
  sur toutes les cohortes.
- Espace **Mes cohortes** (`/cohorte`), accessible depuis l'en-tête du site et le menu de l'admin :
  pour chaque cohorte du chef, son code, son lien d'invitation à copier, son effectif et ses parcours ;
  puis, sur la page d'une cohorte, l'avancement par parcours et par chapitre et le choix des parcours
  proposés. Un parcours commencé par des apprenants de la cohorte est signalé (« 2 apprenants en
  cours ») avant qu'on le retire. Un chef ne voit que ses cohortes (les autres répondent 403) et n'a
  accès à rien d'autre de l'admin : ni création, renommage ou suppression de cohorte, ni apprenants,
  retours ou liste d'attente.
- **Parcours disponibles par cohorte** : dans le formulaire de cohorte (admin) ou l'espace du chef,
  on coche les parcours que la cohorte propose. Un apprenant de cette cohorte ne voit plus que ceux-là,
  à l'accueil comme par lien direct (404). Un parcours retiré ne perd aucune progression : ceux qui
  l'ont commencé le voient toujours et peuvent le terminer. Un parcours en préparation
  (`visibility: admin`) peut être ouvert à une seule cohorte en le cochant ; seul un administrateur
  peut le faire.

### Modifié

- « Avancement par cohorte » (admin) n'affiche, pour chaque cohorte, que les parcours qu'elle propose.

## 0.2.0 — 2026-09-16

### Ajouté

- Simulateur Docker : les variables posées devant une commande (`APP_ENV=prod docker compose up`)
  sont lues par Compose et l'emportent sur le fichier `.env` du projet, dans la console comme dans
  un script rejoué par `sh`.
- Simulateur Docker : `docker compose ps --format json` rend du JSON, une ligne par conteneur.

### Modifié

Ces trois comportements se rapprochent de Docker, et peuvent faire échouer un exercice écrit
contre l'ancien : relancez `content:check` sur vos packs.

- Un montage en lecture seule (`:ro`) refuse l'écriture, à tout le monde, root compris, et répond
  « Read-only file system » là où le simulateur laissait passer la modification.
- Un volume garde **ses** modes et propriétaires d'un conteneur à l'autre. Un volume neuf hérite
  toujours des droits que l'image portait sur le point de montage, mais un volume déjà peuplé
  n'est plus réaligné sur ceux de l'image à chaque démarrage.
- `docker volume rm --force` ne se plaint plus d'un volume déjà absent (code 0, sans sortie) et
  refuse, comme Docker, de supprimer un volume monté par un conteneur.

### Corrigé

- Simulateur Docker : l'avertissement « the attribute `version` is obsolete » s'affiche même quand
  le fichier compose est refusé ensuite pour une autre raison, comme Compose le fait.
- Simulateur Docker : `docker compose --env-file` refuse un fichier absent (`env file … not found`)
  au lieu de continuer avec des variables vides et d'échouer plus loin, sur une autre cause.
- Simulateur Docker : `docker compose config` trie les variables d'environnement par ordre
  alphabétique et rend les tables d'une liste sur la ligne du tiret, comme Compose.

## 0.1.0 — 2026-09-16

Première version numérotée. Le moteur existait déjà ; ce qui est nouveau, c'est qu'on peut
désormais désigner une version précise, la faire tourner et savoir ce qui change d'une à l'autre.

### Ajouté

- Versionnage sémantique du moteur : fichier `VERSION` à la racine, version affichée en pied de page
  et rappelée par `bin/console content:check`.
- Clé `moteur:` dans `pack.yaml` : un pack déclare la ou les versions du moteur avec lesquelles il
  fonctionne (syntaxe de Composer, `^0.1`, `>=1.2 <2.0`). Le moteur refuse de charger un pack
  incompatible plutôt que de le casser en silence.
- Images Docker étiquetées par version : `:0.1.0`, `:0.1`, `:latest` (dernière version publiée) et
  `:edge` (dernier commit de `main`, sans garantie). `tools/release.sh` prépare une version.

### Corrigé

- `platform/composer.json` déclarait une licence « proprietary » (valeur par défaut du squelette
  Symfony) alors que le moteur est sous AGPL-3.0-or-later.

### Le moteur, à cette version

- Parcours d'exercices joués dans le navigateur : PHP 8.4 en WebAssembly, aperçu isolé sur une
  origine distincte, tests PHPUnit par objectif, console du projet (`bin/console`, `php artisan`,
  `docker`).
- Environnements `symfony-8`, `symfony-8-doctrine`, `symfony-8-app`, `laravel-13` et `docker`
  (simulateur Docker en PHP, `tools/docker-sim`).
- Packs de contenu chargés depuis le disque (`CONTENT_PACKS_PATHS`), vérifiés par `content:check`
  et `content:links`, fiches de cours de chapitre et livret PDF.
- Atelier des auteurs (`/atelier`), génération assistée et mentor de l'apprenant avec une clé d'API.
- Comptes, progression, XP, administration, mentions légales renseignées par variables `LEGAL_*`.
- Auto-hébergement par image Docker (FrankenPHP), packs montés dans `/packs`.
