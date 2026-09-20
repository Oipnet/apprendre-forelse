# Marque blanche : extraire les plateformes

> Suite de [ANALYSE-DECOUPLAGE.md](ANALYSE-DECOUPLAGE.md). Celui-ci répond à l'objectif — « quelqu'un
> installe le moteur, crée ses parcours, sous sa marque » — et à la piste proposée : extraire les
> plateformes dans des dépôts open source intégrables au moteur.

## 1. Trois promesses, souvent confondues

« Marque blanche » recouvre trois choses qui n'ont ni la même difficulté ni la même solution :

| | La promesse | Où on en est |
| --- | --- | --- |
| **A** | *J'installe le moteur et j'écris mes parcours* | Presque acquis — il manque les environnements (doc 1) |
| **B** | *C'est **ma** plateforme : mon nom, mon logo, mon ton, mes mentions, mes tarifs* | **Pas acquis** — 69 occurrences de « Forelse » dans les gabarits, une taverne en page d'accueil |
| **C** | *J'ajoute un framework que vous ne supportez pas* | Possible, mais au prix d'un fork : aucun point d'extension |

La question posée (« extraire les plateformes ») porte sur **C**. C'est la bonne question, mais c'est la
troisième par ordre d'urgence : un client qui bute sur **B** n'arrivera jamais jusqu'à **C**.

## 2. Ce qui marche déjà — à ne pas refaire

L'auto-hébergement est solide, et ça change la nature du chantier : le manque n'est pas l'installation,
c'est l'**appropriation**.

- Licence **AGPL-3.0**, image publiée sur ghcr.io, `docker compose up -d --wait`, certificats
  automatiques, guide `auto-hebergement/README.md` complet (deux domaines, reverse proxy, sauvegarde).
- Les **packs sont déjà décollés** : `/packs` monté en lecture seule, pack de démonstration en repli
  quand le dossier est vide.
- L'**atelier** (`/atelier`) permet d'écrire exercices, parcours et fiches depuis le navigateur, avec
  brouillon assisté par modèle si une clé d'API est fournie.
- Le **légal est déjà en variables** (`LEGAL_PUBLISHER_*`, `LEGAL_HOST_*`, `LEGAL_MEDIATOR_*`,
  `LEGAL_REFUND_DAYS`) : c'est le bon réflexe, il n'a simplement pas été étendu à la marque.
- Le **paiement se désactive tout seul** : `TrackOfferFactory` compose `gateway->isConfigured()` et
  `legal->canSell()`, donc une instance sans Stripe n'affiche pas d'offre. Modèle à réutiliser
  ailleurs.
- Les **simulateurs sont déjà des paquets** — voir §4, c'est le point le plus important pour la suite.

## 3. Le modèle que je propose : trois niveaux d'extension

L'erreur à éviter est de chercher **un** mécanisme d'extension. Il en faut trois, parce que les objets
étendus n'ont pas la même nature — et la ligne de partage qui compte est : **est-ce que ça demande de
reconstruire l'image ?**

| Niveau | Ce qu'on ajoute | Mécanisme | Rebuild ? | Qui s'en sert |
| --- | --- | --- | --- | --- |
| **1. Contenu** | Parcours, exercices, **et leurs environnements** | Dossiers montés (`/packs`, `/envs`) | **non** | tout le monde, tout le temps |
| **2. Habillage** | Nom, logo, couleurs, accueil, emails, mentions, tarifs | Variables + dossier monté (`/branding`) | **non** | tout client marque blanche |
| **3. Plateformes** | Un runtime : profil + worker + simulateur | Paquets Composer / npm déclarés | **oui** | rare — une nouvelle famille de framework |

Le but n'est pas de supprimer le niveau 3, c'est de le rendre **rare** : si le moteur livre d'origine
Symfony, Laravel, Docker et Nuxt, l'immense majorité des marques blanches ne l'atteindra jamais. Un
niveau 3 accessible mais peu fréquenté est un bon niveau 3.

## 4. « Extraire les plateformes » : oui — mais savoir ce qu'on extrait

Sous le mot « plateforme » il y a trois objets, et les confondre est ce qui a produit le couplage
actuel :

| Objet | Nature | Où il doit vivre | Rebuild ? |
| --- | --- | --- | --- |
| Le **projet de base** (`symfony-8`, `securite-boutique`) | des fichiers | dans le pack, ou en bibliothèque du moteur | non |
| Le **profil** (console, dossiers, lanceur de tests, namespaces) | des métadonnées | déclaratif (YAML), servi au navigateur | non |
| Le **runtime** (worker php-wasm, docker-sim, nuxt-sim) | du code PHP **et** TypeScript | un paquet, un dépôt | **oui** |

### C'est déjà à moitié fait

Les deux simulateurs sont **déjà des paquets en tout sauf le dépôt** :

```jsonc
// tools/docker-sim/composer.json
{ "name": "forelse/simulateur-docker", "type": "library", "license": "AGPL-3.0-or-later",
  "autoload": { "psr-4": { "Forelse\\DockerSim\\": "src/" } }, "bin": ["bin/docker"] }

// tools/nuxt-sim/package.json
{ "name": "@forelse/simulateur-nuxt", "license": "AGPL-3.0-or-later",
  "exports": { ".": "./src/index.ts", "./node": "./src/node/index.ts" } }
```

Nom, licence, autoload, exports, suite de tests propre (`vendor/bin/phpunit` pour l'un, `vitest` +
une **suite de conformité contre un vrai Nuxt 4.5.2** pour l'autre). `tools/build-env.sh` sait déjà
réinstaller les dépôts Composer de type `path`. L'extraction est un déménagement, pas une refonte.

### Ce qui bloque l'extraction franche, à regarder en face

`playground/vite.config.ts` importe le simulateur Nuxt **en profondeur** :

```ts
import { buildClientBundle }      from '../tools/nuxt-sim/src/node/client-bundle.ts';
import { buildTestRuntimeBundle } from '../tools/nuxt-sim/src/node/test-runtime-bundle.ts';
```

plus deux modules virtuels (`virtual:nuxt-sim-client`, `virtual:nuxt-sim-test-runtime`), une liste de
doublures Node pour happy-dom, les drapeaux de compilation de Vue, et un `fs.allow` qui ouvre le dossier
parent. **Le bundle du playground sait qu'il existe un simulateur Nuxt.** Tant que c'est vrai,
« intégrable » veut dire « recompilable », pas « installable ».

C'est la vraie dette du niveau 3 — pas l'emplacement des dépôts.

## 5. Le contrat d'un plugin « plateforme »

Pour que l'extraction produise un point d'extension et pas seulement trois dépôts, il faut écrire le
contrat. Un paquet de plateforme fournit :

1. **Un manifeste** — c'est le `FrameworkProfile` du document 1 (identifiant, libellé, console,
   dossiers du projet, dossiers de cache, lanceur de tests, racines de namespace, défauts de complétion,
   conventions de rédaction). Déclaratif, lisible par le PHP **et** servi au navigateur dans la charge
   utile de l'exercice, pour que le playground cesse de le réécrire.
2. **Un adaptateur de vérification** (PHP) — comment `content:check` lance les tests de cet
   environnement avec le PHP natif (aujourd'hui : `ExerciseChecker` fait `if ('nuxt' === …)` à cinq
   endroits).
3. **Un module de runtime** (TypeScript) exportant une interface fixe — `boot()`, `request()`,
   `runTests()`, `console()` —, plus, en option, ses snippets d'éditeur et **son** plugin Vite (c'est
   la pièce qui manque : chaque runtime apporte sa contribution au build, au lieu que le build
   connaisse chaque runtime).
4. **Ses environnements de référence**, en option (`symfony-8`, `laravel-13`…).

Côté moteur, une liste déclarée — un `runtimes.yaml` côté PHP, les dépendances côté `package.json` — lue
à la construction du bundle et au démarrage du conteneur. Le `Dockerfile` cesse de coder `docker` et
`nuxt` en dur : il compose la liste.

### Le critère de réussite, vérifiable

> **Retirer `@forelse/simulateur-nuxt` de la liste doit produire un moteur qui fonctionne, sans Nuxt,
> sans modifier une ligne de moteur** — et les exercices Nuxt doivent alors échouer avec un message
> clair (« runtime `nuxt` non installé »), pas avec une erreur de build.

Tant que ce test échoue, le plugin n'en est pas un. Tant qu'il passe, un tiers peut écrire son runtime
Slim ou Express.

### Découpage de dépôts proposé

```
forelse/moteur                 AGPL   plateforme Symfony + coquille du playground + format + content:check
forelse/runtime-php            ?      worker php-wasm + profils symfony/laravel (une seule base wasm)
forelse/simulateur-docker      ?      déjà un paquet : tools/docker-sim
forelse/simulateur-nuxt        ?      déjà un paquet : tools/nuxt-sim
forelse/environnements         ?      projets de base génériques (symfony-8, laravel-13, nuxt-4, docker)
```

Les quatre premiers restent **requis par défaut** dans l'image officielle : personne ne doit assembler
son moteur pour obtenir ce qu'il a aujourd'hui. Le « ? » de licence n'est pas un oubli, voir §7.

## 6. Le niveau 2 est l'angle mort

C'est le chantier qui transforme « auto-hébergement » en « marque blanche », et c'est le seul qui
n'apparaissait nulle part dans la discussion.

État des lieux :

- **69 occurrences de « Forelse »** dans une trentaine de gabarits : `<title>`, `og:site_name`,
  `og:image` (`img/og-forelse.png`), en-tête, pied de page, **et tous les emails** (confirmation,
  mot de passe oublié, confirmation d'achat, alerte d'inscription).
- La **page d'accueil raconte la Taverne du Dragon Ivre** (`home.html.twig:35-103`), avec son enseigne
  dessinée en CSS (`playground/src/site.css:1620`) — voir doc 1, §4.7.
- Les **tarifs de cohorte** sont un paramètre du conteneur (`services.yaml:12-16` :
  `app.cohort_quote.unit_price: 3000` et ses paliers), donc pas réglables sans rebuild.
- Le **logo** est un fichier de l'image (`platform/public/img/logo-168.webp`, `favicon.svg`…).

Proposition, dans le même esprit que `/packs` — un dossier monté, aucun rebuild :

```
branding/
  marque.yaml     nom, baseline, couleurs (→ variables CSS), liens, signature des emails
  logo.svg        og.png        favicon.svg
```

avec repli intégral sur la marque du moteur quand le dossier est absent, le `showcase:` du pack pour le
fil rouge de l'accueil (doc 1, chantier G), et les tarifs de cohorte passés en variables comme le reste.

Sans ce niveau, les deux autres ne servent à rien : le client installe, ouvre son domaine, et lit
« Forelse » au-dessus d'une histoire de taverne.

## 7. La licence décide du modèle — à trancher avant d'extraire

Le moteur est en **AGPL-3.0**. Pour de la marque blanche, ce n'est pas un détail juridique de fin de
document, c'est ce qui dicte l'architecture :

- Un exploitant qui **modifie le moteur** doit offrir ses sources à ses utilisateurs, service réseau
  compris. Le modèle « je forke et je customise » est donc **hostile à vos clients**.
- Un exploitant qui **configure et monte des données** (packs, environnements, branding) ne modifie
  rien : il n'a rien à publier, et ses parcours restent à lui.

> Autrement dit : le modèle par plugins et par données n'est pas seulement plus propre, c'est **ce qui
> rend la marque blanche praticable sous AGPL**. Chaque chose qu'on fait passer de « code » à
> « donnée » est une chose que le client n'a pas à publier.

Deux décisions à prendre **avant** de créer les dépôts, parce qu'elles ne se rattrapent pas :

1. **Sous quelle licence les paquets de plateforme ?** En AGPL, un tiers qui écrit un runtime Slim doit
   le publier — c'est peut-être exactement ce que vous voulez (ça nourrit l'écosystème). En LGPL ou MIT,
   un client peut garder un runtime maison fermé — c'est un argument de vente. Les deux se défendent ;
   ce qui ne se défend pas, c'est de découvrir la question après le premier contributeur.
2. **Double licence ?** Si des clients marque blanche refusent l'AGPL par principe (beaucoup de DSI le
   font), une licence commerciale en parallèle est le schéma habituel. Elle suppose de garder la
   propriété des contributions (CLA) — donc de le décider avant d'ouvrir les dépôts.

## 8. L'ordre que je propose

1. **Niveau 2, l'habillage** — le plus petit, le plus visible, zéro risque architectural. Sans lui, rien
   ne mérite le nom de marque blanche.
2. **Les environnements dans les packs** (doc 1, étapes 1 à 5) — c'est littéralement « créer ses
   parcours sans toucher au moteur ».
3. **`FrameworkProfile`** (doc 1, chantier E) — condition technique du niveau 3, utile même sans lui.
4. **Décision de licence** (§7) — avant l'étape 5, pas après.
5. **Extraction des simulateurs en dépôts**, avec le contrat de plugin (§5) et son critère de réussite.

Les étapes 1 à 3 tiennent dans des versions mineures et n'engagent rien. L'étape 5 est la seule
irréversible ; elle arrive en dernier, une fois qu'on sait quel contrat on publie.

## 9. Deux pièges

- **Le multi-tenant.** Une instance par marque, pas un moteur multi-marques. Le modèle actuel (une image,
  un compose, une base) est le bon : le multi-tenant ajouterait de l'isolation à écrire, à tester et à
  auditer, pour un besoin que l'image résout déjà. Et il ramènerait dans le moteur exactement ce qu'on
  cherche à en sortir : la connaissance des clients.
- **Le plugin trop tôt.** N'ouvrez pas le point d'extension du niveau 3 avant d'avoir un second
  consommateur réel. Aujourd'hui les deux « plugins » sont vos propres simulateurs : c'est le bon nombre
  pour **découvrir** le contrat, pas pour le figer. Extrayez, mais annoncez le contrat en `0.x` le temps
  qu'un tiers l'essaie pour de vrai.
