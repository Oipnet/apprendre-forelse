# Découpler le moteur du contenu

> Analyse d'architecture — état des lieux au moteur **1.4.0**, dépôt de contenu au commit `c81ca9a`.
> Ce document ne change aucun code : il pose le diagnostic, la frontière visée et un chemin de migration.
> Suite — objectif marque blanche et extraction des plateformes : [ANALYSE-MARQUE-BLANCHE.md](ANALYSE-MARQUE-BLANCHE.md).

## 1. Le problème, en une phrase

**Créer un parcours peut obliger à modifier le moteur**, parce que le projet de base dans lequel
l'exercice s'exécute (l'« environnement ») vit dans le dépôt du moteur, pas dans le pack qui s'en sert.

Le moteur devrait savoir **faire tourner** un Symfony 8, un Laravel, un Symfony bêta ; il ne devrait pas
savoir qu'il existe une brasserie Lacombe.

## 2. La preuve, chiffrée

Le parcours « Sécuriser une application Symfony » (pack `houblon-noir`) a été écrit dans le dépôt de
contenu. Il a quand même demandé, dans le dépôt du moteur, le commit `9d7b4cd` :

| Ce qui a été touché dans le moteur | Volume | Nature |
| --- | --- | --- |
| `environments/securite-boutique/` | 119 fichiers, 15 904 lignes | La boutique Lacombe : entités, contrôleurs, fixtures, simulations SSRF — **du contenu pur** |
| `tools/build-completion.php` | 27 lignes | Espaces de noms élargis (RateLimiter, `Security\Http\Event`, `Validator\Constraint`…) parce que ce parcours-là les enseigne |
| `VERSION` | 1.3.0 → **1.4.0** | Une mineure du moteur dont le seul contenu est un décor de formation |

Puis, en retour, dans le dépôt de contenu : `houblon-noir/pack.yaml` déclare `moteur: '^1.4'` — « ce
parcours exige l'environnement `securite-boutique`, que le moteur n'embarque pas avant ».

C'est le cycle complet du couplage : **écrire un parcours → modifier le moteur → publier une version du
moteur → épingler cette version dans le pack**. Comparons avec la `1.2.0`, une mineure légitime : elle
ajoutait le *simulateur* Nuxt (une capacité). La `1.4.0` n'ajoute pas de capacité, elle ajoute un décor.
(Elle n'a d'ailleurs pas d'entrée dans `CHANGELOG.md` — le symptôme qu'il n'y avait rien à y écrire.)

Second symptôme, déjà installé : **la même application existe en double**, et les deux copies ont déjà
divergé.

```
moteur/environments/securite-boutique/      119 fichiers   ← jouée dans le navigateur
contenu/houblon-noir/boutique-lacombe/      101 fichiers   ← archive téléchargeable
```

`diff -rq` sur `src/` : **tous** les fichiers communs diffèrent, et la copie du moteur a en plus
`src/Faux/`, `ConnexionRapideController`, `InscriptionController`, `MotDePasseOublieController`,
`SecurityController`. Une seule intention pédagogique, deux sources de vérité, dans deux dépôts, avec
deux cycles de publication.

## 3. Où passe la frontière aujourd'hui

```
MOTEUR (apprendre-forelse)                          CONTENU (apprendre-forelse-contenu)
├── platform/        plateforme Symfony             ├── <pack>/pack.yaml
├── playground/      runtime php-wasm, éditeur      ├── <pack>/tracks/<parcours>/track.yaml
├── tools/           docker-sim, nuxt-sim,          │     └── environment: securite-boutique  ──┐
│                    build-env.sh, build-completion ├── <pack>/tracks/…/exercises/…             │
├── environments/    ◀── LA FUITE                   └── houblon-noir/boutique-lacombe/  (copie) │
│     symfony-8, laravel-13, nuxt-4, docker  …  ces quatre-là sont génériques                   │
│     securite-boutique  ◀────────────────────────── celui-ci est du contenu ───────────────────┘
└── examples/packs/demo
```

Le contrat public du moteur, tel que le README le décrit (« Versionnage », point 1), inclut
explicitement « **les identifiants d'environnements** ». Autrement dit : le catalogue des décors fait
partie de l'API du moteur. C'est la racine formelle du problème — tant que cette phrase est vraie, tout
nouveau décor est une mineure du moteur.

## 4. Les points de couplage, un par un

### 4.1 Les environnements vivent dans le moteur

`platform/src/Content/EnvironmentRegistry.php:14` lit **un seul** dossier :

```php
#[Autowire(env: 'resolve:ENVIRONMENTS_DIR')]
private readonly string $directory,
```

À comparer avec les packs, qui eux sont déjà décollés — `ContentRepository.php:47` accepte une **liste** :

```php
#[Autowire(env: 'csv:resolve:CONTENT_PACKS_PATHS')]
private readonly array $packPaths,
```

Les packs savent vivre hors de l'image ; les environnements, non. L'asymétrie est le cœur du sujet.

### 4.2 La chaîne de construction fige les environnements dans l'image

`Dockerfile:49-55` construit `environments/*` au moment du build de l'image, `Dockerfile:68-70` copie les
archives et les `vendor/` dans l'image finale, `Dockerfile:78-79` monte les packs depuis `/packs` mais
pointe `ENVIRONMENTS_DIR` sur `/app/environments`.

Conséquence opérationnelle : **un pack peut être déployé sans redéployer le moteur, son environnement
non.** Le déploiement du contenu (rsync des packs sur le VPS, workflow `contenu.yml`) ne peut rien faire
pour un décor : il faut reconstruire et republier l'image.

**Réglé** (voir le journal) : un pack déclare ses environnements et le dépôt où les prendre
(`environments:` dans `pack.yaml`), et le moteur installe ceux qui manquent. Un décor se déploie
désormais comme un pack — sans image à reconstruire. Ce qui reste de 4.2 : les environnements que le
moteur **livre** sont toujours figés dans l'image, ce qui est normal tant qu'ils sont à lui.

### 4.3 L'archive et l'index de complétion sont des chemins statiques du moteur

`Environment.php:26-33` :

```php
public function archivePath(): string        { return 'envs/'.$this->id.'.zip'; }
public function completionIndexPath(): string { return 'envs/'.$this->id.'.completion.json'; }
```

servis par Caddy depuis `platform/public/` (`docker/Caddyfile:36`), horodatés par `filemtime()` dans
`ExercisePayloadFactory.php:41`. Rien là-dedans ne sait qu'un environnement pourrait venir d'ailleurs.

### 4.4 L'outillage du moteur connaît le contenu

`tools/build-completion.php:20` porte une liste d'espaces de noms **en dur**, que chaque parcours doit
venir élargir (c'est exactement ce qu'a fait `9d7b4cd`). Un parcours sur les API Platform, sur Messenger
avancé ou sur le composant Workflow rouvrira ce fichier. Un allowlist codé dans le moteur que le contenu
doit éditer : c'est une dépendance inversée.

### 4.5 L'intégration continue tourne en rond

- `ci.yml` (moteur) déclenche le dépôt de contenu via `repository_dispatch` dès que
  `^(environments/|tools/|platform/src/Content/|platform/src/Version\.php|VERSION|Dockerfile)` bouge.
  Tant que les décors sont dans `environments/`, **ajouter un parcours fait revérifier tous les packs**.
- `contenu.yml` (contenu) fait l'inverse : il clone le moteur et exécute
  `for env in environments/*/; do tools/build-env.sh …; done` — c'est-à-dire qu'il construit les décors
  de *tous* les autres parcours pour en vérifier un seul. La clé de cache
  (`environments-8.4-${{ hashFiles('moteur/environments/*/composer.lock', …) }}`) se périme dès qu'un
  parcours quelconque ajoute le sien.
- Au passage, `.github/actions/moteur/action.yml` appelle `tools/build-env.sh`, un **chemin interne** du
  moteur, alors que le README dit noir sur blanc que le code n'est pas une API publique. Le contenu
  dépend aujourd'hui d'un détail d'implémentation.

### 4.6 Les frameworks sont une énumération fermée, éparpillée

`Environment.php:8` : `public const array FRAMEWORKS = ['symfony', 'laravel', 'docker', 'nuxt'];`

et la même connaissance, réécrite à la main, dans au moins dix endroits :

| Fichier | Ce qu'il sait du framework |
| --- | --- |
| `EnvironmentRegistry.php:41-43` | dossiers de cache par défaut |
| `ExerciseChecker.php:39,70,134,187,402` | lanceur de tests, paquet à chercher, forme d'un test |
| `ExerciseDrafter.php:223-240`, `LessonDrafter.php:193-196` | dossiers du projet, conventions, langages de blocs |
| `PracticeController.php:24`, `SeoWriter.php:237` | libellé affiché |
| `playground/src/runtime/php-worker.ts:59` | profil d'exécution (dossiers, amorçage) |
| `playground/src/app/Playground.ts:604-605` | nom de la console, nom du framework |
| `playground/src/app/editable.ts:39` | racines de namespace |
| `playground/src/editor/completion.ts:102,286,352` | jeux de snippets |

**C'est ici que se joue la vraie demande** : « le moteur devrait contenir de quoi faire tourner un
Symfony 8, un Laravel, un Symfony bêta ». Un Symfony bêta coûte déjà peu — `symfony-8-2-dev` n'est
qu'un `composer.lock` différent, sans une ligne de code de moteur (mais toujours un commit dans son
dépôt : voir 4.1). En revanche une **nouvelle famille** (un Slim, un API Platform autonome, un
Node/Express) demande aujourd'hui dix retouches dispersées. La capacité est là, elle n'est simplement pas *nommée* : il n'existe pas d'objet « profil de
runtime », juste des `match` recopiés.

### 4.7 Le vocabulaire d'un pack a fui jusque dans l'interface du moteur

Le couplage ne se limite pas aux environnements : la fiction d'un pack (`dragon-ivre`) est écrite en dur
dans le moteur lui-même.

| Fichier | Ce qu'il contient |
| --- | --- |
| `platform/templates/home.html.twig:35-103` | La page d'accueil **vend la Taverne du Dragon Ivre** : bloc de code d'illustration, enseigne dessinée, section « Chaque parcours a son fil rouge. Le premier : une taverne. », le pitch de Bruna |
| `playground/src/site.css:1620` | Les styles de l'enseigne de la taverne |
| `platform/src/Controller/ResetPasswordController.php:121` | « Mot de passe modifié. **Bon retour à la taverne !** » — sur la réinitialisation de mot de passe de la plateforme |
| `platform/src/Content/Author/ExerciseStudio.php:208,222-223` | L'atelier échafaude tout nouvel exercice, de **tout** pack, dans `tests/Taverne/` / `App\Tests\Taverne` |

Une instance auto-hébergée qui n'installerait que des packs Laravel afficherait quand même une taverne
en page d'accueil, et son atelier créerait des tests dans un namespace qui ne veut rien dire pour elle.

Le correctif est du même ordre que le reste, en plus petit :

- le fil rouge de la page d'accueil devient une **donnée du pack** (un bloc `showcase:` dans `pack.yaml` :
  titre, accroche, illustration), avec un repli neutre quand aucun pack n'en propose ;
- le namespace de test par défaut de l'atelier vient du **profil de runtime** (`App\Tests`, `Tests`), pas
  d'une fiction ;
- les messages de la plateforme redeviennent neutres.

### 4.8 Ce qui marche déjà bien — à prendre comme modèle

L'archive téléchargeable de la boutique est, elle, parfaitement décollée : le dépôt de contenu la
construit (`git archive` dans `contenu.yml`), la dépose sur le serveur, et le moteur la sert sans rien
savoir d'elle (`DownloadController.php`, un dossier `DOWNLOADS_DIR`, une route générique).

**Le contenu produit un artefact, le moteur le sert.** Toute la suite consiste à appliquer ce même
schéma aux environnements.

## 5. La frontière visée

> **Le moteur fournit des runtimes. Le contenu fournit des décors.**

Règle de décision, applicable sans discussion :

- un environnement **générique** — rien d'une histoire, d'un domaine, d'une marque : `symfony-8`,
  `symfony-8-doctrine`, `laravel-13`, `nuxt-4`, `docker` — reste au moteur, comme bibliothèque de départ ;
- un environnement qui **raconte quelque chose** — `securite-boutique`, c'est-à-dire la Brasserie
  Lacombe du pack `houblon-noir` — appartient au pack.

Appliquée aujourd'hui, la règle déplace exactement un environnement. Appliquée demain, elle évite au
moteur de devenir un catalogue de décors.

```
MOTEUR = capacités                          CONTENU = décors + pédagogie
  php-wasm + profils de runtime               <pack>/pack.yaml
  (symfony, laravel, docker, nuxt)            <pack>/tracks/…
  simulateurs docker-sim / nuxt-sim           <pack>/environments/<id>/       ◀── nouveau
  empaquetage (archive, index complétion)          environment.yaml, composer.json, src/…
  content:check, format de pack               <pack>/practice/…
  bibliothèque d'environnements génériques
```

## 6. Les sept chantiers

### A. Résolution : `EnvironmentRegistry` devient une chaîne de fournisseurs

`has()`/`get()` interrogent dans l'ordre : l'environnement **local au pack** (`<pack>/environments/<id>/`),
puis la bibliothèque générique du moteur. `Environment` gagne un champ `source` (identifiant du pack, ou
`null` pour le moteur).

Collisions entre packs : les identifiants restent locaux au pack qui les déclare ; une référence
inter-pack s'écrit qualifiée (`houblon-noir/securite-boutique`). Un pack ne peut pas masquer un
environnement du moteur sans le dire — `content:check` le signale.

Accessoirement, `ENVIRONMENTS_DIR` devient une liste CSV comme `CONTENT_PACKS_PATHS` : une instance
auto-hébergée peut alors ajouter sa propre bibliothèque sans reconstruire l'image.

### B. Construction : une commande, un contrat

`tools/build-env.sh` (appelé aujourd'hui par le contenu, donc de fait public) est promu en commande
supportée :

```
bin/console content:env-build <pack>/<id>     # ou <id> pour la bibliothèque du moteur
```

Elle fait ce que fait le script (composer install, zip, index de complétion) et écrit dans un dossier de
sortie choisi. Le dépôt de contenu ne dépend plus d'un chemin interne, et la CI du contenu ne construit
plus que **les environnements du pack qu'elle vérifie**.

### C. Service : un volume d'artefacts, comme les packs

Le contenu construit ses archives en CI et les dépose sur le serveur, exactement comme il dépose déjà
`boutique-lacombe.zip`. Le moteur les sert depuis un dossier monté (`ENV_ARTIFACTS_DIR`, symétrique de
`CONTENT_PACKS_PATHS` et de `DOWNLOADS_DIR`), en repli sur `public/envs` pour sa bibliothèque.

`Environment::archivePath()` devient une URL résolue par le registre (`/envs/<pack>/<id>.zip`), horodatée
comme aujourd'hui. Les en-têtes de cache de Caddy (`docker/Caddyfile:36`) couvrent déjà ce préfixe.

**Alternative écartée** : construire les environnements des packs **au démarrage du conteneur**. Cela
demanderait Composer et le réseau en production, rallongerait le boot, et ferait exécuter du code de pack
sur le serveur — ce que le README interdit déjà en substance (« `content:check` exécute le PHP des packs
sur votre machine : ne l'utilisez qu'avec des packs de confiance »).

**Ce qui a été fait à la place, et en quoi c'est différent** (voir le journal, « Installer un
environnement depuis un dépôt Git ») : le moteur sait construire un environnement sur le serveur, mais
seulement quand un **administrateur le demande**, dépôt par dépôt, en connaissance de cause. Les trois
objections tombent : rien au boot, rien pour un environnement qu'on n'a pas installé, et l'exécution de
code tiers est un acte administratif explicite — le même que `content:check`, dont le README dit déjà
qu'il demande un pack de confiance. Ce qui reste écarté est bien l'automatisme : aucun pack ne fait
construire quoi que ce soit du seul fait d'être monté.

### D. Complétion : l'environnement déclare ses espaces de noms

La liste en dur de `tools/build-completion.php:20` se scinde en deux :

- un socle par framework, dans le moteur (ce qu'un Symfony a toujours) ;
- un complément dans `environment.yaml` :

```yaml
completion:
  namespaces:
    - 'Symfony\Component\RateLimiter\'
    - 'Symfony\Component\Security\Http\Event\'
```

Un parcours qui enseigne un composant peu courant l'ajoute **chez lui**. Le moteur cesse d'être édité
pour des raisons pédagogiques.

### E. Frameworks : un profil de runtime, déclaré une fois

Remplacer l'énumération fermée et les dix `match` par un objet unique :

```php
final readonly class FrameworkProfile
{
    public string $id;            // symfony, laravel, docker, nuxt
    public string $label;         // « Symfony »
    public string $console;       // bin/console, php artisan, docker, npx nuxi
    public array  $projectDirs;   // src/, config/, templates/…
    public array  $cacheDirs;     // var/cache, storage/framework/views…
    public string $testRunner;    // phpunit | vitest
    public array  $namespaceRoots;// src → App, tests → App\Tests | Tests
    public array  $completionDefaults;
    public array  $draftingConventions;
}
```

Un registre côté PHP ; la même table **servie dans la charge utile de l'exercice**
(`ExercisePayloadFactory`) pour que le playground cesse de la réécrire en TypeScript
(`Playground.ts:604-605`, `editable.ts:39`, `php-worker.ts:59`). Ajouter une famille de frameworks
devient : un profil, un worker, deux fichiers — au lieu de dix retouches à retrouver à la main.

C'est le chantier qui répond littéralement à « le moteur devrait contenir de quoi faire tourner un
Symfony 8, un Laravel, un Symfony bêta » : la capacité existe, il lui manque un nom et un point unique
de déclaration.

### F. Versionnage : rendre au numéro son sens

Retirer « les identifiants d'environnements » du contrat public (README, « Versionnage », point 1) et le
remplacer par « **les runtimes de framework disponibles** ». Le `moteur:` d'un `pack.yaml` cesse alors de
signifier « la version qui embarque mon décor » pour signifier « la version qui sait faire tourner un
Symfony ».

Sur la `houblon-noir` d'aujourd'hui : `moteur: '^1.4'` redevient `moteur: '^1.0'`, comme tous les autres
packs. C'est un changement de contrat, donc une **majeure** (`2.0.0`) — c'est justement le bon moment,
avant qu'un troisième parcours n'ajoute un décor au moteur.

### G. Interface : sortir la fiction du moteur

Trois retouches indépendantes des précédentes, et faisables tout de suite (voir 4.7) :

1. `pack.yaml` accepte un bloc `showcase:` (titre, accroche, illustration au choix parmi quelques
   formes fournies par le moteur) ; la page d'accueil affiche celui du parcours le mieux classé
   (`order:`) et retombe sur un texte neutre si aucun pack n'en déclare.
2. L'atelier (`ExerciseStudio`) prend son namespace de test dans le profil de runtime.
3. Les messages de la plateforme (`ResetPasswordController`) ne citent plus de parcours.

Aucune de ces trois-là ne touche au format d'exercice : elles peuvent partir avant le reste.

## 7. Migration, par étapes vertes

| # | Étape | Version | Casse ? |
| --- | --- | --- | --- |
| 1 | Chaîne de fournisseurs (A) + `ENVIRONMENTS_DIR` en CSV. Le moteur continue de livrer ses environnements. **CSV : fait.** La chaîne locale au pack reste à faire. | 1.5.0 | non |
| 2 | `content:env-build` (B) ; le dépôt de contenu l'appelle au lieu de `tools/build-env.sh`. | 1.5.0 | non |
| 3 | `ENV_ARTIFACTS_DIR` + service des archives de pack (C) ; repli sur `public/envs`. **Fait, sous une autre forme** : `/envs/<fichier>` sert aussi les archives des environnements installés, repli sur `public/envs` compris — reste à l'ouvrir aux archives livrées par un pack. | 1.6.0 | non |
| 4 | `completion.namespaces` dans `environment.yaml` (D), la liste en dur devient le socle Symfony. | 1.6.0 | non |
| 5 | **Déménagement** : `securite-boutique` → `houblon-noir/environments/securite-boutique`, fusionné avec `boutique-lacombe` (une seule source, l'archive téléchargeable en dérive). | 1.7.0 côté moteur, pack côté contenu | non (l'ancien id reste résolu, déprécié) |
| 6 | ~~`FrameworkProfile` (E), table servie au playground.~~ **Fait** (voir le journal). | 1.8.0 | non |
| 6 bis | ~~`showcase:`, atelier et messages neutralisés (G).~~ **Fait** : `home.showcase` vient de `marque.yaml`, pas de `pack.yaml` — le fil rouge de l'accueil parle de l'instance, pas d'un pack. | 1.5.0 | non |
| 7 | Retrait des identifiants d'environnement du contrat (F) ; le moteur ne garde que la bibliothèque générique. | **2.0.0** | contrat |

L'étape 5 est la seule qui demande un vrai arbitrage éditorial : réconcilier les deux copies divergentes
de la boutique. Le plus simple est de prendre la copie du moteur comme base (elle est jouable, vérifiée
par `content:check` à 97/97) et de réintégrer par-dessus ce que la copie du contenu apporte en propre
(`Makefile`, `docker/`, `README.md` — de quoi la lancer en local), l'archive téléchargeable devenant une
projection de l'environnement plutôt qu'un jumeau.

Étape 0, gratuite et utile tout de suite : donner au pack de démonstration
(`examples/packs/demo`) son propre environnement local, pour que le moteur teste le chemin « environnement
fourni par un pack » à chaque passage de CI.

**Mise à jour** : le chemin « environnement fourni par un pack » existe désormais sous une autre forme —
le pack ne *porte* pas son environnement, il le *déclare* et le moteur va le chercher (`environments:`
dans `pack.yaml`). C'est testé de bout en bout (`PackEnvironmentsTest`), avec un vrai dépôt Git. Reste
ouverte la question de l'environnement **embarqué** dans le dossier du pack, qui éviterait un dépôt
séparé pour un décor minuscule ; les deux ne s'excluent pas.

## 8. Comment on saura que c'est fait

Un seul critère, vérifiable :

> Écrire un parcours complet, décor compris, et le déployer — **sans un seul commit dans le dépôt du
> moteur, sans publier de version du moteur, sans redéployer l'image**.

Corollaires mesurables :

- aucun `pack.yaml` n'a besoin d'un `moteur:` plus récent que la version qui a figé le format ;
- la CI du contenu, pour vérifier un pack, ne construit que les environnements de ce pack ;
- la CI du moteur ne déclenche une revérification du contenu que sur un changement de *capacité*
  (`platform/src/Content/`, `tools/`, `playground/`), jamais sur l'ajout d'un décor ;
- `grep -ril "lacombe\|taverne\|bigorneau" moteur/` ne renvoie plus que les fixtures des tests du
  moteur — ni environnement, ni page d'accueil, ni message de la plateforme, ni gabarit de l'atelier.

## 9. Ce que ça coûte, et ce que ça ne règle pas

- **Le temps de CI du contenu augmente** : chaque pack construit son ou ses environnements (un
  `composer install` de plus), là où il réutilisait une image déjà chaude. Le cache par `composer.lock`
  du pack compense, et la facture globale baisse puisqu'un pack ne construit plus les décors des autres.
- **La confiance** : servir une archive construite par le dépôt de contenu suppose de faire confiance à
  ce dépôt — c'est déjà le cas pour les packs et pour `boutique-lacombe.zip`. Rien de nouveau, mais
  l'autohébergeur doit le lire quelque part : à écrire dans `auto-hebergement/`.
- **Ce n'est pas réglé par ce document** : la duplication boutique/environnement est un arbitrage
  éditorial, pas une décision d'architecture. Et le playground gardera nécessairement un worker par
  famille de runtime — le profil unifie les *métadonnées*, pas l'implémentation WebAssembly.
