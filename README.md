# Forelse · apprendre — le moteur

Une plateforme pour **apprendre à développer en codant**, sans vidéo ni installation. L'apprenant écrit son code dans
le navigateur, où tourne un vrai projet : PHP 8.4 compilé en WebAssembly pour Symfony et Laravel, et des simulateurs
pour Docker et Nuxt. L'aperçu se met à jour en direct, et des tests automatiques valident chaque objectif. Rien n'est
exécuté sur le serveur.

C'est le moteur de [apprendre.forelse.fr](https://apprendre.forelse.fr), **open source (AGPL-3.0) et
auto-hébergeable**. Les exercices n'en font pas partie : ils vivent dans des **packs de contenu**, que le moteur
charge. Un pack de démonstration est fourni dans `examples/packs/demo`, et chacun peut écrire les siens. Les parcours
de Forelse ne sont pas dans ce dépôt ; une école ou une entreprise peut les utiliser sur sa propre instance, sur devis
([en savoir plus](https://apprendre.forelse.fr/auto-hebergement)).

- **Installer une instance** avec Docker : [auto-hebergement/README.md](auto-hebergement/README.md)
- **Écrire des exercices** : [Packs de contenu](#packs-de-contenu) et [L'atelier des auteurs](#latelier-des-auteurs)
- **Développer le moteur** : [Démarrer en local](#démarrer-en-local)
- **Ce qui change d'une version à l'autre** : [CHANGELOG.md](CHANGELOG.md)

## Architecture

```
platform/         Application Symfony 8.1 : pages, comptes, progression, API, content:check
    src/Content/Framework/   Ce que le moteur sait de chaque framework (un profil par runtime)
playground/       Îlot TypeScript : runtime PHP WebAssembly, éditeur Monaco, aperçu isolé
environments/     Projets de base dans lesquels s'exécutent les exercices, et de quoi les empaqueter
                  (bin/build-env.sh) — destiné à devenir son propre dépôt, voir environments/README.md
tools/            Simulateurs Docker (docker-sim) et Nuxt (nuxt-sim), publication d'une version
auto-hebergement/ Guide, compose.yaml et .env.example d'une instance auto-hébergée
deploy/           compose.yaml de l'instance de Forelse, copié sur son serveur par l'intégration continue
examples/packs/   Pack de démonstration (format de contenu, tests du moteur)
examples/marque/  Exemple d'identité d'instance (marque blanche) : marque.yaml commenté et ses images
```

Dans le navigateur :

```
Page exercice (plateforme) ── worker PHP (php-wasm) : instance « aperçu » + instance « tests »
      │  postMessage (origine vérifiée)
      ▼
Relais /sandbox (origine bac à sable) ── Service Worker ── iframe d'aperçu (code de l'apprenant)
```

Le code de l'apprenant n'est **jamais exécuté sur le serveur** : PHP tourne dans le bac à sable WebAssembly du navigateur, sans accès au disque ni au réseau. Le playground offre aussi une **console** du projet (`bin/console` pour Symfony : Doctrine, `debug:router`… ; `php artisan` pour Laravel : `migrate`, `route:list`…), exécutée dans ce même bac à sable.

## Démarrer en local

Prérequis : PHP 8.4 (avec `pdo_pgsql`), Composer, Node.js 22+, PostgreSQL 15+.

Créez une fois le rôle attendu par `platform/.env` (ou adaptez `DATABASE_URL` dans `platform/.env.local`) :

```bash
psql -d postgres -c "CREATE ROLE app LOGIN PASSWORD 'app' CREATEDB"
```

```bash
make install   # dépendances, environnements d'exécution, bases PostgreSQL (formation, formation_test)
make dev       # plateforme http://localhost:8000, bac à sable http://127.0.0.1:8001, Vite, Mailpit http://localhost:8025
```

Ouvrez http://localhost:8000. Pour charger d'autres packs, dans `platform/.env.local` :

```dotenv
CONTENT_PACKS_PATHS="%kernel.project_dir%/../examples/packs,/chemin/vers/mes-packs"
```

Tests : `make test`. Vérification des packs : `make check`.

> `make build` écrase `platform/public/build/.vite/entrypoints.json` : la plateforme sert alors les fichiers compilés, même si Vite tourne encore. Après un build, relancez Vite (`make dev-vite`) pour retrouver le rechargement à chaud — sinon vos modifications front ne s'affichent pas.

> Les serveurs de développement tournent avec `-d xdebug.mode=off` : Xdebug fait planter le serveur interne de PHP (segfault) sur certaines pages, et ralentit tout. `content:check` fait de même.

## L'atelier des auteurs

`/atelier` écrit les exercices d'un pack depuis le navigateur : les fichiers tels qu'ils sont sur le disque (`exercise.yaml`, `instructions.md`, `starter/`, `solution/`, `tests/`), un bouton **Vérifier** qui lance `content:check` et affiche son verdict, et un lien pour jouer l'exercice comme un apprenant.

L'atelier écrit sur le disque et exécute le PHP du pack : il est réservé au rôle `ROLE_AUTEUR`.

```bash
bin/console app:auteur vous@example.com            # donner le droit
bin/console app:auteur vous@example.com --retirer  # le retirer
```

Un exercice se supprime depuis son éditeur (dossier et ligne de `track.yaml`), sauf s'il sert de `base` à un autre : le fil rouge se casserait.

Un parcours dont le dossier est en lecture seule reste consultable, mais n'est pas modifiable.

Chaque chapitre a sa **fiche de cours** (voir « Packs de contenu »), éditable depuis l'atelier : markdown à gauche, à droite l'aperçu rendu par le serveur, donc exactement ce que verra l'apprenant. **Squelette** assemble un point de départ à partir des exercices du chapitre, **Rédiger avec l'IA** demande un brouillon complet au modèle ; les deux sont proposés, jamais enregistrés sans relecture. Enregistrer une fiche vide la retire du chapitre.

### Génération assistée (facultative)

Avec une clé d'API, l'atelier peut **proposer un brouillon d'exercice** à partir d'un sujet (« tester un voter avec des stubs »), dans l'univers et le style du parcours : consignes, fichiers de départ, solution, tests, indices, liens de documentation. Après un échec de `content:check`, un bouton **Corriger avec l'IA** lui renvoie le rapport et propose une correction.

```dotenv
# platform/.env.local
ANTHROPIC_API_KEY=sk-ant-…
AI_MODEL=claude-sonnet-5   # facultatif
```

La même clé sert à `content:lesson-draft --ia`, qui rédige un brouillon de fiche de cours (voir « Packs de contenu »). Sans clé, l'atelier fonctionne à l'identique et ces boutons n'apparaissent pas. Un brouillon reste un brouillon : rien n'est enregistré sans relecture, et c'est `content:check` qui tranche — tests rouges au départ, verts avec la solution.

## Le mentor de l'apprenant

Avec la même clé d'API, l'apprenant connecté dispose d'un **mentor** dans le playground, qui intervient là où un bon mentor interviendrait, jamais à sa place :

- **Une revue de code**, sur demande, quand les tests passent : un regard sur ce qui pourrait être plus idiomatique, plus lisible ou plus sûr, en quatre points au plus, avec la solution de référence comme point de comparaison (jamais citée). Elle est conservée avec la progression.
- **Des erreurs expliquées** : sur une page d'exception de l'aperçu, des tests qui plantent ou une commande `bin/console` en échec, un bouton « Expliquer l'erreur » traduit l'exception en cause probable et en piste (où regarder, quoi vérifier), sans donner la correction.

Les indices, eux, sont écrits par l'auteur dans `exercise.yaml` et ne coûtent rien. Le mentor est réservé aux comptes et limité à 40 appels par heure et par apprenant (`config/packages/rate_limiter.yaml`) : chaque appel se paie. Sans clé, les boutons n'apparaissent pas.

**La solution** de référence est consultable par un apprenant connecté (bouton « Solution »), à côté de son code, en lecture seule. Il est prévenu avant : l'exercice ne rapportera alors pas d'XP, même réussi ensuite avec son propre code. Un invité n'y a pas accès : les solutions font partie du contenu.

## Parcours payants

Une seule question décide de ce qu'un apprenant ouvre : **a-t-il accès à ce chapitre ?** (`TrackAccessChecker`,
utilisé par les pages, les API de l'exercice, de la progression et du mentor, les fiches et le livret).

1. Le **premier chapitre** d'un parcours public est ouvert à tous, sans compte.
2. Un administrateur ou un auteur ouvre tout.
3. Un parcours **sans tarif, ou à 0 €**, est ouvert à tout compte : une instance qui ne fixe aucun prix reste gratuite.
4. Un chef de cohorte ouvre les parcours de ses cohortes.
5. Sinon, il faut un **accès actif** : acheté, ouvert par une cohorte ou offert (`/admin` → Accès). Un accès
   expiré ou révoqué garde la progression, qu'un nouvel accès retrouve intacte.

Les prix (TTC, avec prix fondateur limité par une date ou un quota) se fixent dans `/admin` → Tarifs ou avec
`bin/console app:tarif`. L'achat passe par **Stripe Checkout** et **Stripe Tax** (`STRIPE_SECRET_KEY`,
`STRIPE_WEBHOOK_SECRET`, `STRIPE_TAX_CODE`) ; seul le webhook `/paiement/stripe/webhook` ouvre l'accès, à vie.
Les événements reçus sont journalisés et se rejouent avec `bin/console app:stripe:rejouer`. Un administrateur
rembourse depuis `/admin` → Achats (l'accès est révoqué).

Une **cohorte** est financée par l'établissement (ses apprenants reçoivent l'accès aux parcours choisis, aux dates
de la cohorte ; l'estimation du devis se règle dans `config/services.yaml`, `app.cohort_quote.*`) ou par ses
apprenants (chacun achète, au tarif de la cohorte s'il est fixé).

**Mise à jour d'une instance vers la 0.8** : après la migration, lancer une fois `bin/console app:acces:initialiser`
(les cohortes et les apprenants existants gardent ce qu'ils avaient), puis seulement fixer des prix.

## Packs de contenu

```
<pack>/pack.yaml                       id, titre, version, licence, moteur attendu, parcours
<pack>/tracks/<parcours>/track.yaml    environnement, chapitres → exercices
<pack>/tracks/<parcours>/chapters/<chapitre>/
    lesson.md         fiche de cours de fin de chapitre (Markdown, facultative)
<pack>/tracks/<parcours>/exercises/<exercice>/
    exercise.yaml     titre, XP, accès, fichiers éditables / en lecture seule, objectifs, indices
    instructions.md   consignes (Markdown)
    starter/          fichiers de départ, superposés à l'environnement
    tests/            tests PHPUnit cachés ; chaque objectif = une méthode de test
    solution/         solution de référence (jamais envoyée au navigateur, sauf en dev)
<pack>/practice/<exercice>/            exercice de Pratique, hors parcours (même contenu qu'un exercice)
```

- `duration:` (dans `exercise.yaml`, facultative) : la durée estimée de l'exercice, en minutes, pour le public du
  parcours (`duration: 25`). La carte du parcours l'affiche par exercice, par chapitre (« ≈ 3 h 15 ») et pour le
  parcours (« environ 46 h de pratique »), ainsi que l'accueil et le JSON-LD du cours (`timeRequired`). Un chapitre
  ou un parcours dont un exercice n'a pas de durée n'en affiche pas : une somme partielle promettrait trop peu.

- **Pratique** (`<pack>/practice/<exercice>/`) : de courts exercices à part des parcours, pour se servir
  d'une fonctionnalité d'un framework, souvent une nouveauté d'une version, parfois un point précis. Pas de
  chapitre, pas de fiche, pas d'XP, pas d'histoire : le code de départ est écrit à l'ancienne, les tests
  vérifient le comportement **et** l'usage de la fonctionnalité (par réflexion, par exemple). Le moteur
  découvre les dossiers de `practice/` tout seul, sans liste dans `pack.yaml`. `exercise.yaml` garde son
  format, avec en plus :
  - `environment:` (obligatoire : il n'y a pas de parcours dont l'hériter) ;
  - `published: AAAA-MM-JJ` (obligatoire) : la liste `/pratique` va du plus récent au plus ancien. Une date à
    venir **programme** l'exercice : visible des seuls administrateurs (badge « Programmé »), il est publié
    automatiquement ce jour-là ;
  - `summary:` (obligatoire), une phrase affichée dans la liste ;
  - `version: '8.1'` (facultative, **entre guillemets**) : la version du framework qui apporte la
    fonctionnalité ; elle affiche le badge « Nouveauté » et `content:check` échoue si l'environnement a une
    version plus ancienne (lue dans son `composer.lock`) ;
  - `pull_request:` (URL https, facultative), proposée à la réussite ;
  - `visibility: admin` (facultative), comme pour un parcours : un exercice en préparation.

  `access`, `base` et `xp` y sont refusés : la Pratique demande toujours un compte et ne rapporte pas
  d'XP. Les identifiants sont uniques parmi les exercices de Pratique de tous les packs, et un parcours ne
  peut pas s'appeler `pratique`. La liste `/pratique` est publique, filtrable par framework, par notion
  (`concepts:`) et par nouveauté ; l'exercice se joue sur `/pratique/<exercice>`. À la réussite, le
  playground propose un « Avant / après » (le code de départ face à celui de l'apprenant). L'atelier
  crée un exercice de Pratique en préparation (`visibility: admin`) dans un pack modifiable.

- `moteur:` (dans `pack.yaml`) déclare la ou les versions du moteur avec lesquelles le pack fonctionne,
  avec la syntaxe de Composer (`^0.1`, `>=1.2 <2.0`). Un moteur qui ne la satisfait pas refuse de charger le
  pack et le dit, plutôt que de le casser en silence. La clé est facultative — un pack livré avec le moteur
  n'en a pas besoin — mais un pack distribué à part a tout intérêt à la déclarer (voir « Versionnage »).
- `access` est **dépréciée** (sans effet depuis la 0.8.0, signalée par `content:check`) : le premier chapitre de chaque parcours public se joue sans compte, la suite dépend de l'accès au parcours (voir « Parcours payants »).
- `editable:` accepte des **motifs** (`migrations/*.php` ; `*` ne franchit pas un « / ») pour les fichiers qu'une commande va créer, dont le nom est imprévisible. Tout ce qu'une commande de la console crée, modifie ou supprime sous `src/`, `migrations/`, `templates/`, `config/`, `tests/` ou `translations/` (par exemple `doctrine:migrations:diff`) est reporté dans l'éditeur — un fichier modifiable s'ouvre aussitôt —, dans l'explorateur, dans l'instance de tests et dans le brouillon. « Réinitialiser » retire ces fichiers générés. Quand l'exercice a un motif, le bouton **＋** de l'explorateur crée un fichier qu'il couvre, avec le bon namespace. Les fichiers déjà présents qu'un motif couvre (`src/Entity/*.php`) ne s'ouvrent pas tous au démarrage : l'apprenant les ouvre depuis l'explorateur. `open:` doit toujours désigner un fichier, jamais un motif ; ce fichier peut être couvert par un motif s'il existe au départ (`content:check` le vérifie). Les commandes externes lancées par PHP (`proc_open`, comme le php-cs-fixer de MakerBundle) se terminent sans rien faire : `make:entity`, `make:controller`… fonctionnent dans la console du navigateur.
- Les tests d'exercices disposent d'outils fournis par l'environnement, dans `tests/Formation/` : `BaseDeDonnees` (recréer la base depuis les entités, enregistrer des objets), `FileDeMessages` (file Messenger, worker) et `MigrationsDeLaBase` (vider la base, jouer les migrations jusqu'à une version, comparer le schéma aux entités).
- `setup:` liste des commandes `bin/console` lancées au chargement de l'aperçu, par exemple `doctrine:schema:update --force` : la base de l'aperçu (SQLite) vit en mémoire et repart de zéro à chaque chargement.
- `requests:` propose des requêtes toutes faites dans l'onglet « Requêtes » du playground, un petit client HTTP pour tester une API : `{title, method, path, headers, body}`. Un `body` écrit en objet YAML est envoyé en JSON, et `{{ date:+7 }}` y est remplacé par la date du jour + 7 jours, pour que les exemples ne se périment pas.
- **Exercices où l'apprenant écrit ses tests** : ses fichiers éditables `tests/…Test.php` sont ses tests, notés par deux sortes d'objectifs.
  - `own-tests: pass` : ses tests passent tous sur l'application correcte, avec au moins un test.
  - `mutant: <id>` : ses tests échouent sur un **mutant**, une version volontairement cassée de l'application, déclarée dans `mutants: [{id, label, changes: [{file, search, replace}]}]`.

  Chaque mutant est appliqué, testé puis retiré, de la même façon dans le navigateur et dans `content:check`. `content:check` vérifie que chaque texte à remplacer existe, et que la solution détecte chaque mutant.
- `docs:` liste la documentation qui aide à résoudre l'exercice, `{title, url}` (URL http(s) uniquement), affichée sous les objectifs. `bin/console content:links [pack|parcours|parcours/exercice|pratique|pratique/exercice]` vérifie que chaque page répond et que chaque ancre (`#…`) existe encore (accès réseau requis). Les liens des fiches de cours et les `pull_request` de la Pratique sont vérifiés en même temps.
- **Fiche de cours** : `chapters/<chapitre>/lesson.md` résume ce qu'un chapitre a enseigné ; l'apprenant y accède une fois le chapitre réussi. Elle commence à `##` (le moteur affiche le titre du chapitre en `#`), utilise des blocs de code avec leur langue (`php`, `twig`, `yaml`…) et des liens `https://` ; le HTML brut n'est pas rendu. Structure conseillée : « Ce que vous avez appris », une section par concept avec l'extrait de code clé, « Les pièges », « Pour aller plus loin ». `content:check` signale (sans échouer) les chapitres qui n'en ont pas. Pour ne pas partir d'une page blanche, `bin/console content:lesson-draft <parcours>/<chapitre>` écrit un squelette assemblé à partir des exercices du chapitre (concepts, sections « Rappel » des consignes, liens de documentation) ; avec `--ia` et une clé d'API (voir l'atelier), le modèle rédige un brouillon complet, à relire. `--force` remplace une fiche existante. La fiche se lit en HTML (`/parcours/<parcours>/chapitre/<chapitre>`) et se télécharge en PDF, établi au nom de l'apprenant (dompdf, en PHP pur : aucune dépendance système). Une fois le parcours terminé, `/parcours/<parcours>/livret.pdf` regroupe toutes ses fiches derrière une page de garde et un sommaire.
- **Environnements** : `environments/<id>/environment.yaml` décrit un projet de base (`id`, `title`, `php`), empaqueté par `environments/bin/build-env.sh [<id>]` (sans argument : tous) en archive zip servie au navigateur, avec un index de complétion généré par Reflection. **Tout ce qui concerne les environnements vit dans ce dossier** — les projets et leur empaquetage —, prêt à partir dans son propre dépôt : il ne dépend d'aucun code du moteur, et le moteur n'y entre que par `ENVIRONMENTS_DIR` et ce script (voir [environments/README.md](environments/README.md)). `framework:` choisit le **runtime** (`symfony` par défaut, `laravel`, `docker`, `nuxt`) : sa console, l'organisation de son projet, ses caches, son lanceur de tests. `framework: docker` bascule sur le **simulateur Docker** (voir plus bas) ; `framework: laravel` sur les conventions de Laravel : console `artisan`, dossiers (`app/`, `routes/`, `resources/`…), vues Blade compilées dans `storage/framework/views` vidées entre deux runs de tests (`cache:` pour un autre dossier). Un fichier `.archiveignore` (motifs `zip -x`, un par ligne) allège l'archive, par exemple des traductions de `vendor/` inutiles à l'apprenant. Les environnements Laravel forcent `APP_ENV=testing` **et** `APP_RUNNING_IN_CONSOLE=true` dans `phpunit.xml` : Laravel n'exempte les tests de la vérification CSRF que si les deux moitiés de `runningInConsole() && runningUnitTests()` sont vraies, et la première se déduit de `PHP_SAPI`, qui vaut `wasm` dans le navigateur (ni `cli` ni `phpdbg`) — sans cela, tout `POST` d'un test de fonctionnalité répond 419. L'aperçu, lui, vérifie le jeton pour de vrai. Leur `.env` est livré tel quel : rien n'y est secret, le projet ne quitte jamais le bac à sable.
- **Runtimes** : tout ce que le moteur sait d'un framework est déclaré **une fois**, dans un profil
  (`platform/src/Content/Framework/Profiles/`) : nom, console et commande d'exemple, dossiers du projet
  et du code, caches à vider, dossiers masqués, racines de namespace, lanceur de tests (PHPUnit ou
  Vitest), paquet dont la version fait foi pour `version:`, langages des fiches de cours et conventions
  données au modèle qui rédige. Le navigateur le **reçoit** dans la charge utile de l'exercice au lieu
  de le réécrire : l'éditeur ne contient plus de table de frameworks, seulement le code propre à chaque
  runtime (le worker, les snippets, le script de la console). Ajouter un framework, c'est fournir un
  `FrameworkProfileProvider` — une classe, découverte par son étiquette de service — et, s'il s'agit
  d'une nouvelle famille, son worker côté navigateur. C'est la moitié serveur du contrat décrit dans
  [ANALYSE-MARQUE-BLANCHE.md](ANALYSE-MARQUE-BLANCHE.md) ; il reste jeune, et bougera tant qu'un paquet
  tiers ne l'aura pas essayé.
- **Simulateur Docker** (`framework: docker`, environnement `environments/docker`) : Docker ne tourne évidemment pas dans le navigateur. `tools/docker-sim` le simule en PHP pur — images (catalogue fermé : php, composer, nginx, postgres, mysql, mariadb, redis, node, alpine, debian, caddy, mailpit, adminer…), construction d'images à la façon de BuildKit (étapes numérotées, cache par couche, « build checks »), conteneurs, réseaux, volumes, `docker compose` — et **exécute pour de vrai le PHP servi par les conteneurs** : Apache et son `.htaccess`, nginx devant php-fpm, `php -S`. Les erreurs sont celles de Docker (`port is already allocated`, `Unable to locate package`, `host not found in upstream`, `File not found.`), et une suppression de fichiers dans une couche ultérieure ne rend pas la place, comme dans une vraie image. La console du playground devient `docker` (`sh lancer.sh` rejoue un script de commandes), et l'aperçu visite les ports publiés : `preview: /localhost:8080/`. Les tests des exercices étendent `Forelse\DockerSim\Testing\DockerTestCase` (`build()`, `docker()`, `runScript()`, `http()`, `container()`, `service()`, `image()`, `exec()`, et les assertions `assertBuildSucceeded`, `assertImageLacksFile`, `assertImageSizeBelow`, `assertPageContains`…). Le simulateur est un **runtime du moteur**, pas un environnement : il reste dans `tools/`, et `environments/docker` en dépend comme d'une bibliothèque (dépôt Composer `path`). Il a sa propre suite de tests (`cd tools/docker-sim && vendor/bin/phpunit`), lancée par `make test` et la CI. Limites assumées : pas de registre (`docker push`), pas de terminal interactif (`-it`), `RUN` interprété et non exécuté, montages imbriqués non pris en charge, temps comprimé (un healthcheck est rejoué à chaque fois qu'on regarde l'état d'un conteneur, sans phase `starting`, et un échec y vaut tous les essais) ; le PHP des conteneurs s'exécute dans le processus des tests (pas d'`exit()`, pas de fonction globale redéclarée), et n'est donc pas soumis aux droits Unix — ceux-ci s'appliquent aux commandes du shell (`docker exec -u www-data … touch`, un montage `:ro`), qui sont le bon moyen de les vérifier.
- `visibility: admin` (dans `track.yaml`) réserve un parcours en préparation aux administrateurs : absent de l'accueil, introuvable (404) pour les autres, jamais conseillé comme suite. Il reste vérifié par `content:check` et modifiable dans l'atelier. Retirez la clé (ou `visibility: public`) pour l'ouvrir. Un administrateur peut aussi l'ouvrir à une seule cohorte en le cochant dans ses parcours disponibles.
- `order: <entier>` (dans `track.yaml`) fixe le rang du parcours dans les listes, à commencer par l'accueil, dont l'onglet ouvert par défaut est le premier : le plus petit d'abord. Les parcours sans rang viennent après, dans l'ordre de chargement (chemins de `CONTENT_PACKS_PATHS`, puis dossiers de packs par ordre alphabétique, puis liste `tracks:` du pack). Laissez de l'écart entre les rangs (10, 20, 30) pour intercaler un parcours d'un autre pack sans renuméroter.
- `next: <parcours>` (dans `track.yaml`) conseille un parcours à suivre ensuite : proposé à la fin du dernier exercice et sur la carte du parcours. Il peut vivre dans un autre pack ; s'il n'est pas installé, il est ignoré.
- `environment:` choisit l'environnement d'exécution, au niveau du parcours, d'un chapitre ou d'un exercice (`symfony-8`, `symfony-8-doctrine`, `symfony-8-app`…). `symfony-8-2-dev` suit Symfony 8.2 en développement (8.2.x-dev, figé par son `composer.lock`), avec la console, PropertyAccess, Serializer et Validator : il sert aux exercices de Pratique sur les nouveautés annoncées avant la sortie, et disparaîtra quand `symfony-8` passera en 8.2.
- `base: <exercice>` fait partir un exercice de l'état final d'un exercice précédent : un fil rouge s'enchaîne sans dupliquer de fichiers.
- `bin/console content:check [pack|parcours|parcours/exercice|pratique|pratique/exercice]` vérifie chaque exercice : tests **rouges** sur l'état de départ, **verts** avec la solution. `--keep` conserve le projet reconstitué pour l'inspecter. PHPUnit y tourne avec `memory_limit=512M` et sans Xdebug, et `--chapitre <id>` limite la vérification à un chapitre (pratique pour paralléliser).

⚠️ `content:check` exécute le PHP des packs sur votre machine : ne l'utilisez qu'avec des packs de confiance.

**Écarts entre PHP natif et navigateur.** `content:check` s'exécute avec le PHP de la machine, alors que l'apprenant utilise php-wasm, qui n'a **pas l'extension `intl`** : la locale y reste `en`. Évitez donc ce qui dépend d'`intl` (`NumberType` sans `'html5' => true`, `MoneyType`, dates localisées…), sous peine de voir des exercices validés par `content:check` se comporter autrement dans le navigateur. La traduction (messages de validation en français) n'est pas concernée.

## Habiller son instance

Le moteur ne s'appelle « Forelse » que **tant qu'on ne lui dit rien**. Une instance pose sa marque en
montant un dossier (`BRANDING_DIR`, `/marque` dans l'image) à côté des packs — aucun fork, aucune image
à reconstruire, rien à republier au titre de l'AGPL : c'est de la configuration, pas du code.

```
marque/
  marque.yaml     nom, puce, accroche, site, couleurs, polices, images, textes de l'accueil
  logo.svg  favicon.svg  partage.png      les images, nommées dans marque.yaml
  templates/      (facultatif) des gabarits Twig qui remplacent ceux du moteur
```

Un exemple complet et commenté, à copier : [examples/marque/marque.yaml](examples/marque/marque.yaml).

**La règle à retenir** : dès que `marque.yaml` existe, **plus rien de la marque du moteur n'est servi**.
Ni le nom, ni le logo, ni la favicon, ni l'image de partage, ni les textes d'accueil qui parlent de la
Taverne du Dragon Ivre et de l'auteur de Forelse. Une instance ne peut donc pas se retrouver à vendre
une marque qui n'est pas la sienne parce qu'elle a oublié une clé.

- `name` (obligatoire) s'affiche dans l'en-tête, le pied de page, les `<title>` (« Mon compte · … »),
  les emails (confirmation d'adresse, mot de passe oublié, achat), les balises Open Graph et les données
  structurées. `chip` est la petite puce à côté, `tagline` la phrase du pied de page, `title` le `<title>`
  de l'accueil, `url` le site de la marque.
- `colors` et `fonts` écrivent les variables CSS du **thème clair** (les pages du site : `--lp-rust`,
  `--lp-bg`…), `editor` celles du **thème sombre** (l'éditeur d'exercice et l'atelier : `--accent`,
  `--bg`…). Toutes sont posées après la feuille de styles. Hexadécimal seulement ; une valeur mal écrite
  **arrête la page** avec un message qui dit laquelle — une instance à moitié habillée est pire qu'une
  erreur. Les deux palettes se déclarent séparément et l'une n'est jamais déduite de l'autre : une
  couleur claire assombrie automatiquement, c'est un contraste perdu au hasard.
- `logo`, `icon` et `share` nomment des fichiers **de ce dossier** (jamais un chemin), servis sur
  `/marque/<rôle>` avec la date du fichier dans l'URL : une image remplacée change d'URL. Sans `icon`,
  l'onglet n'affiche aucune icône plutôt que celle du moteur : mieux vaut rien que la marque d'un autre.
- `home.showcase`, `home.author` et `home.demo` remplissent les trois sections de l'accueil qui parlent
  de la marque (le fil rouge, « qui est derrière », l'illustration du bandeau). Une section non déclarée
  n'apparaît pas, et la page reste cohérente sans elle. Leur forme est vérifiée à la lecture, avec le
  piège du YAML en tête : **une phrase qui contient « : » doit être entre guillemets**, sinon elle
  devient un tableau — le message le dit plutôt que de laisser la page échouer à l'affichage.
- **L'échappatoire** : un fichier déposé dans `marque/templates/` remplace le gabarit de même nom du
  moteur (`home.html.twig`, `_footer.html.twig`, `legal/notice.html.twig`…). Il n'y a rien à copier
  d'autre que le fichier à changer. En production les gabarits sont compilés une fois : après en avoir
  déposé un, redémarrez le conteneur.
- Les tarifs de cohorte (`COHORT_UNIT_PRICE`, `COHORT_TIERS`) sont des variables d'environnement, comme
  les mentions légales (`LEGAL_*`) : aucune instance n'a à reconstruire l'image pour ses prix.

Ce qui reste du moteur dans tous les cas : le pied de page dit la version et la licence AGPL du moteur,
comme l'exige la licence.

## Sécurité

- **Aperçu isolé** : le HTML/JS produit par l'apprenant s'affiche sur une origine distincte (`SANDBOX_ORIGIN`), sans cookie ni accès à la plateforme. Chaque origine ne sert que ses propres pages (`OriginIsolationListener`).
- **Cookies de session** limités à l'hôte de la plateforme (`HttpOnly`, `SameSite=Lax`, pas de `cookie_domain`).
- **Écritures** refusées si l'en-tête `Origin` diffère de celle de la plateforme ; consignes Markdown nettoyées (DOMPurify).
- La réussite d'un exercice est constatée dans le navigateur : l'XP est donc falsifiable. C'est acceptable pour apprendre, mais il ne faut pas s'en servir pour certifier.

## Auto-héberger avec Docker

L'image publiée contient le moteur prêt à servir (FrankenPHP/Caddy, utilisateur non-root) ; PostgreSQL tourne
à côté, et les packs de contenu restent **hors de l'image**, montés dans `/packs`. En bref :

```bash
mkdir apprendre && cd apprendre
curl -fsSLO https://raw.githubusercontent.com/oipnet/apprendre-forelse/main/auto-hebergement/compose.yaml
curl -fsSL -o .env https://raw.githubusercontent.com/oipnet/apprendre-forelse/main/auto-hebergement/.env.example
# renseigner .env : APP_URL, SANDBOX_URL (deux domaines distincts), POSTGRES_PASSWORD, emails, mentions légales
docker compose up -d --wait
```

**Le guide complet est dans [auto-hebergement/README.md](auto-hebergement/README.md)** : prérequis, premier
administrateur, packs, reverse proxy, essai en local, mises à jour et choix de l'étiquette d'image, sauvegardes,
dépannage.

Pour essayer l'image construite depuis ce dépôt, avec le pack de démonstration : `docker compose up --build` à la
racine (plateforme http://localhost:8080, bac à sable http://127.0.0.1:8080).

Sans Docker : `make build`, puis servez `platform/public` sur les deux origines (`DEFAULT_URI` et `SANDBOX_ORIGIN`) avec `APP_ENV=prod` et `date.timezone` réglé dans `php.ini`. Les archives de `environments/bin/build-env.sh` doivent être dans `platform/public/envs`.

## Versionnage

Le moteur suit le **versionnage sémantique** (`MAJEUR.MINEUR.CORRECTIF`), et les changements sont
consignés dans [CHANGELOG.md](CHANGELOG.md). La version vit dans le fichier [VERSION](VERSION) ; elle
s'affiche en pied de page et au début de `content:check`.

Ce qu'un numéro promet porte sur ce que le moteur **expose au dehors** :

1. **Le format de pack** — `pack.yaml`, `track.yaml`, `exercise.yaml`, les outils de test fournis aux
   exercices (`tests/Formation/`, `DockerTestCase`) et les identifiants d'environnements.
2. **La configuration d'une instance** — variables d'environnement, `compose.yaml`, volumes, base de données.
3. **Les URL publiques** de la plateforme (`/parcours/…`, `/pratique/…`…).

Donc :

- **MAJEUR** : un pack qui fonctionnait ne fonctionne plus, ou une instance qui tourne casse sans
  intervention (variable renommée, migration manuelle à faire).
- **MINEUR** : nouvelle clé du format, nouvel environnement, nouvelle fonctionnalité — les packs
  existants continuent de fonctionner.
- **CORRECTIF** : corrections seules.

Depuis la `1.0.0`, le format de pack est tenu pour stable : seule une **majeure** peut casser un pack ou une
instance. (En `0.x`, une mineure le pouvait.)

Le code PHP et TypeScript **n'est pas une API publique** : les classes, services et modules sont
réorganisés librement d'une version à l'autre. Ce qui est tenu, c'est le format de pack et la
configuration, pas les signatures internes.

**Dépréciation** : une clé du format n'est jamais retirée du jour au lendemain. Elle est d'abord
signalée par `content:check` dans au moins une version mineure, puis retirée dans une majeure.

**Publier une version** (mainteneurs) : décrire les changements sous « Non publié » dans le journal, puis

```bash
tools/release.sh 1.1.0
git push --follow-tags
```

Le script vérifie le dépôt, met à jour `VERSION`, `playground/package.json` et le journal, commite et
étiquette. L'étiquette poussée déclenche la publication des images Docker correspondantes.

## Licence

[AGPL-3.0-or-later](LICENSE). Le runtime s'appuie sur [php-wasm](https://github.com/WordPress/wordpress-playground/tree/trunk/packages/php-wasm) (GPL-2.0-or-later).
