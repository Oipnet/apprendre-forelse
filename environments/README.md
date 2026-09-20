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
```

`framework:` choisit le **runtime**, c'est-à-dire ce que le moteur sait faire tourner : la console du
projet, l'organisation de ses dossiers, ses caches, son lanceur de tests. Chaque runtime est déclaré
côté moteur (`platform/src/Content/Framework/Profiles/`) ; un environnement ne fait que le désigner.

## Les deux portes du moteur

C'est tout ce qui lie ce dossier au reste, et c'est ce qui rendra l'extraction simple :

1. **`ENVIRONMENTS_DIR`** — le moteur lit les `environment.yaml` de ce dossier
   (`App\Content\EnvironmentRegistry`). Une instance peut le faire pointer ailleurs.
2. **`bin/build-env.sh`** — appelé par le `Makefile`, par le `Dockerfile`, par l'intégration continue du
   moteur, et par celle du dépôt de contenu (qui vérifie ses packs contre de vrais environnements).

Rien d'autre : pas d'`include` du code du moteur, pas de classe partagée.

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

- publier les archives construites quelque part que le moteur sache lire (un volume monté à côté des
  packs, comme `/packs` et `/marque` — voir `ANALYSE-DECOUPLAGE.md`, chantier C) ;
- remplacer le dépôt Composer `path` de `environments/docker` par une contrainte de version sur
  `forelse/simulateur-docker` ;
- reprendre, dans le nouveau dépôt, le job d'intégration continue qui construit et met en cache les
  environnements (aujourd'hui dans `.github/workflows/ci.yml` du moteur).
