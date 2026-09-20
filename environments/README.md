# Les environnements d'exécution

Un **environnement** est le projet de base dans lequel un exercice s'exécute : un Symfony nu, un Symfony
avec Doctrine, un Laravel, un projet Nuxt, un petit projet Docker. L'apprenant le reçoit dans son
navigateur sous forme d'archive, y écrit son code, et ses tests s'y lancent.

Ce dossier les rassemble **tous**, avec ce qui les empaquette. Il est fait pour partir un jour dans son
propre dépôt : rien ici n'a besoin du code du moteur, et le moteur n'entre ici que par deux portes,
décrites plus bas.

```
environments/
  bin/build-env.sh          empaquette un environnement (archive + index de complétion)
  bin/build-completion.php  l'index de complétion, par Reflection
  <id>/environment.yaml     un environnement, un dossier
  <id>/…                    le projet lui-même (composer.json, src/, config/…)
```

Un dossier est un environnement **s'il contient un `environment.yaml`** — comme un dossier est un pack
de contenu s'il contient un `pack.yaml`. `bin/` n'en est donc pas un.

## Empaqueter

```bash
environments/bin/build-env.sh symfony-8        # un seul
environments/bin/build-env.sh                  # tous
environments/bin/build-env.sh symfony-8 /tmp/envs   # ailleurs que dans le moteur
```

La sortie est, dans l'ordre : le second argument, la variable `ENVIRONMENTS_OUT`, ou
`platform/public/envs` — le dossier public du moteur, tant que les deux vivent dans le même dépôt.
Le script produit deux fichiers par environnement :

- `<id>.zip` — le projet, `vendor/` compris, servi au navigateur ;
- `<id>.completion.json` — les classes et signatures que l'éditeur propose, extraites par Reflection.

Un `.archiveignore` dans un environnement (motifs `zip -x`, un par ligne) allège son archive.

## `environment.yaml`

```yaml
id: symfony-8
title: Symfony 8.1 — Twig, PHPUnit
php: '8.4'          # obligatoire, sauf pour un environnement sans PHP (Nuxt)
framework: symfony  # symfony (défaut), laravel, docker, nuxt
cache: [var/cache]  # facultatif : les caches à vider entre deux runs, si ceux du framework ne conviennent pas
extends: <id>       # facultatif : prolonge un autre environnement (voir « Fusionner »)
```

`framework:` choisit le **runtime**, c'est-à-dire ce que le moteur sait faire tourner : la console du
projet, l'organisation de ses dossiers, ses caches, son lanceur de tests. Chaque runtime est déclaré
côté moteur (`platform/src/Content/Framework/Profiles/`) ; un environnement ne fait que le désigner.

## Fusionner : `extends:`

Un environnement peut en **prolonger** un autre. Il ne contient alors que ce qu'il ajoute ou remplace, et
le projet joué est la superposition de la chaîne, **du plus général au plus particulier** :

```yaml
# symfony-8-app/environment.yaml
id: symfony-8-app
extends: symfony-8
title: Symfony 8.1 — Twig, Doctrine (SQLite), Form, Validator, Security…
```

`symfony-8-app` pesait 45 fichiers, dont 19 copies à l'octet près de `symfony-8` ; il en porte 26, et
plus une seule copie. `symfony-8-doctrine` est passé de 33 à 14.

Les règles, toutes vérifiables dans `EnvironmentAssemblerTest` :

- **les fichiers se superposent** — à chemin égal, celui du plus particulier l'emporte, quelles que
  soient les dates (un dépôt fraîchement cloné a des dates toutes voisines : s'y fier serait un tirage
  au sort) ;
- **les clés d'`environment.yaml` s'héritent** (`php`, `framework`, `cache`), sauf `title`, qu'un
  environnement ne partage avec personne ;
- **`vendor/` ne se superpose pas** : il vient du seul dossier de la chaîne qui déclare un
  `composer.json`, le plus particulier. Un environnement qui n'ajoute aucune dépendance hérite donc du
  `vendor/` de sa base et n'a ni `composer.json` ni `composer.lock` à porter ;
- **une chaîne qui tourne en rond** ou une base introuvable arrêtent le chargement avec un message qui
  nomme le coupable.

Ce qu'un `composer.json` ne fait **pas**, c'est fusionner : un verrou est le résultat d'une résolution,
pas une addition. Un environnement qui ajoute des paquets déclare son `composer.json` et son
`composer.lock` complets, comme avant.

C'est le mécanisme qui rendra un décor de pack abordable : « le Symfony complet, plus mes trois
entités » se dira en quelques fichiers au lieu d'une copie de tout un squelette.

## Les deux portes du moteur

C'est tout ce qui lie ce dossier au reste, et c'est ce qui rendra l'extraction simple :

1. **`ENVIRONMENTS_DIR`** — le moteur lit les `environment.yaml` de ce dossier
   (`App\Content\EnvironmentRegistry`). C'est une **liste** de dossiers séparés par des virgules, lue
   dans l'ordre : ceux du moteur, puis ceux de l'instance. Une instance peut la faire pointer ailleurs,
   ou n'en garder aucun.
2. **`bin/build-env.sh`** — appelé par le `Makefile`, par le `Dockerfile`, par l'intégration continue du
   moteur, par celle du dépôt de contenu (qui vérifie ses packs contre de vrais environnements), et par
   l'administration du moteur quand elle installe un environnement depuis un dépôt Git. Le script cherche
   ses environnements dans `ENVIRONMENTS_PATH` (même format, défaut : ce dossier) et écrit où on lui dit.

Rien d'autre : pas d'`include` du code du moteur, pas de classe partagée.

### Un environnement peut déjà vivre dans son propre dépôt

C'est la porte que ce dossier empruntera. Un administrateur colle une adresse `https://` dans
`/admin` → **Environnements** (ou lance `bin/console app:environnement:installer <adresse>`) : le moteur
clone, lit l'`id:` de l'`environment.yaml` pour nommer l'environnement, dépose le dossier dans
`INSTALLED_ENVIRONMENTS_DIR` — qui fait partie d'`ENVIRONMENTS_DIR` — puis appelle `bin/build-env.sh`.
Rien de particulier n'est demandé au dépôt : un `environment.yaml` à sa racine, et le format décrit
plus haut. `extends:` traverse les dossiers, donc un environnement installé peut prolonger `symfony-8`
sans emporter de `vendor/`.

Ses archives ne vont pas dans `public/` : elles sont servies depuis le dossier des installations par
`/envs/<id>.zip`, la même URL que pour un environnement du moteur.

Ce que cela coûte : empaqueter exécute `composer install`, donc le code du dépôt, sur le serveur. La page
est réservée aux administrateurs, seules les adresses `https://` sont acceptées, et
`ENVIRONMENT_SOURCES_ALLOWLIST` limite les hôtes.

## Ce qui n'est pas ici, et pourquoi

- **Les simulateurs** (`tools/docker-sim`, `tools/nuxt-sim`) sont des **runtimes**, pas des
  environnements : c'est ce que le moteur fournit pour exécuter un projet, pas le projet. Ils restent au
  moteur, qui les livre — le playground importe le simulateur Nuxt à la construction de son bundle, et
  `content:check` lance ses tests Vitest. `environments/docker` dépend du simulateur Docker comme d'une
  bibliothèque (dépôt Composer `path` vers `../../tools/docker-sim`) ; le jour de l'extraction, ce
  chemin devient une contrainte de version, rien de plus.
- **`securite-boutique`** est ici pour l'instant, mais c'est du **contenu** : la boutique de la Brasserie
  Lacombe n'existe que pour le parcours `houblon-noir`. Sa place est dans son pack (voir
  [ANALYSE-DECOUPLAGE.md](../ANALYSE-DECOUPLAGE.md), étape 5). La règle qui tranche : un environnement
  qui raconte une histoire appartient au pack qui la raconte ; un environnement générique reste ici.

## Le jour de l'extraction

Ce dossier devient la racine d'un dépôt. Il faudra alors :

- ~~publier les archives construites quelque part que le moteur sache lire~~ — **fait** : un dépôt
  d'environnements s'installe déjà dans `INSTALLED_ENVIRONMENTS_DIR`, archives comprises (voir plus
  haut). Restera à décider si le moteur continue d'en livrer par défaut, ou si une instance neuve part
  sans aucun environnement et les installe tous ;
- remplacer le dépôt Composer `path` de `environments/docker` par une contrainte de version sur
  `forelse/simulateur-docker` ;
- reprendre, dans le nouveau dépôt, le job d'intégration continue qui construit et met en cache les
  environnements (aujourd'hui dans `.github/workflows/ci.yml` du moteur).
