# Auto-héberger le moteur

Ce guide installe votre propre instance de la plateforme avec Docker : l'application (FrankenPHP/Caddy +
Symfony) et sa base PostgreSQL, à partir de l'image publiée. Rien à compiler.

Le code des apprenants s'exécute **dans leur navigateur** (PHP compilé en WebAssembly), jamais sur le serveur :
un petit serveur suffit, même pour beaucoup d'apprenants.

- [Ce qu'il faut](#ce-quil-faut)
- [Installation](#installation)
- [Premier administrateur](#premier-administrateur)
- [Contenu : les packs](#contenu--les-packs)
- [Votre marque](#votre-marque)
- [Réglages](#réglages)
- [Derrière un reverse proxy](#derrière-un-reverse-proxy)
- [Essai sur votre poste](#essai-sur-votre-poste)
- [Mettre à jour](#mettre-à-jour)
- [Sauvegarder et restaurer](#sauvegarder-et-restaurer)
- [Dépannage](#dépannage)

## Ce qu'il faut

- Un serveur Linux avec **Docker** et **Docker Compose v2** (`docker compose version`). Comptez 1 à 2 Go de
  mémoire et 3 Go de disque pour les images.
- **Deux noms de domaine** qui pointent vers ce serveur (enregistrements A/AAAA) :
  - celui de la plateforme, par exemple `apprendre.example.org` ;
  - celui du **bac à sable**, qui affiche le HTML et le JavaScript écrits par les apprenants, par exemple
    `apprendre-bac-a-sable.net`.

  Prenez pour le bac à sable un **autre domaine**, pas un sous-domaine du premier : pour un navigateur, deux
  sous-domaines d'un même domaine appartiennent au même « site », et le code d'un apprenant se retrouverait
  trop près des cookies de la plateforme.
- Les ports **80 et 443** libres et ouverts : Caddy, dans le conteneur, obtient et renouvelle seul les
  certificats HTTPS (Let's Encrypt). Un reverse proxy occupe déjà ces ports ?
  Voir [Derrière un reverse proxy](#derrière-un-reverse-proxy).
- Un moyen d'envoyer des emails (SMTP ou Brevo), pour la confirmation d'adresse et le mot de passe oublié.

## Installation

```bash
mkdir apprendre && cd apprendre
curl -fsSLO https://raw.githubusercontent.com/oipnet/apprendre-forelse/main/auto-hebergement/compose.yaml
curl -fsSL -o .env https://raw.githubusercontent.com/oipnet/apprendre-forelse/main/auto-hebergement/.env.example
mkdir packs
```

Ouvrez `.env` et renseignez au moins :

```dotenv
APP_URL=https://apprendre.example.org
SANDBOX_URL=https://apprendre-bac-a-sable.net
POSTGRES_PASSWORD=…        # openssl rand -hex 24
APP_TIMEZONE=Europe/Paris
MAILER_DSN=smtp://utilisateur:mot-de-passe@smtp.example.org:587
MAILER_FROM="Apprendre <ne-pas-repondre@example.org>"
```

Puis :

```bash
docker compose up -d --wait
```

Au premier démarrage, le conteneur crée le schéma de la base, génère le secret de l'application et
demande les certificats. Ouvrez `APP_URL` : l'accueil présente le **parcours de démonstration**, chargé
tant que `packs/` est vide.

Pour suivre le démarrage : `docker compose logs -f app`.

## Premier administrateur

Créez votre compte sur `/inscription`, puis donnez-lui les droits :

```bash
docker compose exec app bin/console app:admin vous@example.org
```

Le tableau de bord est sur `/admin` : comptes, cohortes, accès, tarifs, messages de contact.

Pour écrire des exercices dans l'atelier (`/atelier`), le droit est distinct :
`bin/console app:auteur vous@example.org`. L'atelier écrit dans les packs : avec le montage en lecture seule
de `compose.yaml`, il reste consultable mais ne modifie rien. Écrire du contenu se fait plus confortablement
sur un poste de développement (voir le [README](../README.md) du dépôt). **Gardez ce `:ro`** : sur des packs
modifiables, un auteur fait exécuter à **Vérifier** les tests qu'il écrit, dans le conteneur `app`, avec ses
secrets à portée — le rôle d'auteur deviendrait un rôle d'administrateur.

## Contenu : les packs

Les exercices ne sont pas dans l'image : ils viennent de **packs de contenu**, des dossiers qui contiennent un
`pack.yaml` (format décrit dans le [README](../README.md#packs-de-contenu)). Déposez chaque pack dans son
propre dossier :

```
packs/
  mon-pack/
    pack.yaml
    tracks/…
```

- **Premier pack** : le choix entre le parcours de démonstration et vos packs se fait au démarrage du
  conteneur. Après avoir déposé le premier pack dans un `packs/` vide (ou retiré le dernier) :
  `docker compose restart app`.
- Ensuite, les packs sont lus **à chaque requête** : un pack ajouté ou modifié apparaît sans redémarrer.
- Le conteneur tourne sous l'uid 1000 : les fichiers doivent être lisibles par tous
  (`chmod -R a+rX packs`), sinon l'accueil n'affiche aucun parcours.
- Pour garder le parcours de démonstration à côté de vos packs : `CONTENT_PACKS_PATHS=/packs,/app/examples/packs`,
  puis `docker compose up -d`.
- Un pack déclare les versions du moteur qu'il accepte (`moteur: '^1.0'` dans `pack.yaml`). **Si un pack
  n'accepte pas la version du moteur installée, le contenu ne se charge plus et la plateforme répond en
  erreur 500** : le message, dans `docker compose logs app`, nomme le pack. Retirez-le, ou alignez la version du
  pack et celle du moteur.
- Pour vérifier les packs installés (tests rouges au départ, verts avec la solution) :

  ```bash
  docker compose exec app bin/console content:check
  ```

  ⚠️ `content:check` **exécute le PHP des packs** sur votre serveur : ne le lancez que sur des packs de
  confiance. Les apprenants, eux, n'exécutent rien sur le serveur.

## Votre marque

Sans rien, l'instance s'appelle « Forelse » et son accueil raconte la Taverne du Dragon Ivre. Pour poser
votre marque, créez un dossier `marque/` à côté de `compose.yaml` — il est déjà monté :

```bash
mkdir -p marque
curl -fsSL -o marque/marque.yaml \
  https://raw.githubusercontent.com/oipnet/apprendre-forelse/main/examples/marque/marque.yaml
# éditez marque/marque.yaml, déposez vos images à côté, puis :
docker compose up -d
```

**Dès que `marque/marque.yaml` existe, plus rien de la marque du moteur n'est servi** : ni le nom, ni le
logo, ni la favicon, ni l'image de partage, ni les textes d'accueil de Forelse. Vous ne risquez pas de
laisser traîner « Forelse » dans un coin de page ou dans un email.

Le fichier d'exemple est commenté ligne à ligne. En résumé :

| Clé | Ce qu'elle habille |
| --- | --- |
| `name` (obligatoire) | En-tête, pied de page, titres des pages, **emails**, balises de partage |
| `chip`, `tagline`, `title`, `url` | La puce à côté du nom, la phrase du pied de page, le titre de l'accueil, votre site |
| `logo`, `icon`, `share` | Vos images, déposées dans ce dossier (jamais un chemin) |
| `colors`, `fonts` | Les couleurs et polices du thème clair (les pages du site) |
| `editor` | Les couleurs du thème sombre (l'éditeur d'exercice et l'atelier) |
| `home.showcase`, `home.author`, `home.demo` | Les trois sections de l'accueil qui parlent de vous ; non déclarées, elles n'apparaissent pas |

Une couleur mal écrite ou une clé inconnue **arrête la page** avec un message qui dit quoi corriger :
mieux vaut une erreur franche qu'une instance à moitié habillée. Vérifiez après coup :

```bash
docker compose logs --tail 50 app
```

**Aller plus loin : vos propres pages.** Un gabarit Twig déposé dans `marque/templates/` remplace celui
du moteur qui porte le même nom — `home.html.twig` pour refaire l'accueil, `_footer.html.twig` pour le
pied de page, `legal/notice.html.twig` pour vos mentions. Vous ne copiez que le fichier à changer. Les
gabarits sont compilés une fois : après en avoir déposé un, `docker compose restart app`.

Ce qui reste du moteur dans tous les cas : le pied de page indique sa version et sa licence AGPL, comme
la licence l'exige.

## Ajouter un environnement d'exécution

Un **environnement** est le projet de base qu'un exercice ouvre dans le navigateur : un Symfony 8 nu, un
Symfony avec Doctrine, un Laravel. Le moteur en livre plusieurs ; un pack de contenu peut avoir besoin du
sien — « le Symfony complet, plus mes trois entités ». `/admin` → **Environnements** l'installe depuis un
dépôt Git, sans reconstruire l'image.

> ⚠️ **Empaqueter un environnement exécute le code du dépôt sur ce serveur** : le moteur y lance
> `composer install`, qui exécute les greffons et les scripts déclarés par ce dépôt. Ce code tourne dans le
> service **`empaqueteur`**, pas dans `app` : il n'y trouve ni vos clés (Stripe, Anthropic, `APP_SECRET`),
> ni la base, ni le réseau des autres services — seulement Internet et le volume des environnements, avec
> 1 Go de mémoire et un processeur au plus. Il reste que cet environnement sera ensuite servi à vos
> apprenants : n'installez que des dépôts que vous contrôlez ou dont vous connaissez l'auteur.
> `ENVIRONMENT_SOURCES_ALLOWLIST=github.com` limite les adresses acceptées à un hôte ; videz
> `INSTALLED_ENVIRONMENTS_DIR` pour retirer la fonctionnalité — la page reste, sans formulaire. Un
> `compose.yaml` antérieur à la 2.1 n'a pas ce service : la plateforme empaquette alors elle-même, à côté
> des secrets (`ENVIRONMENTS_BUILDER` vide) ; retéléchargez-le.

Ce qu'il faut dans le dépôt : un `environment.yaml` à sa racine. C'est lui qui **nomme** l'environnement
(`id:`), pas l'adresse ni l'administrateur, et cet identifiant est celui que les exercices désignent par
`environment:`. Un environnement qui en prolonge un autre (`extends: symfony-8`) ne porte que sa
différence, et n'a le plus souvent ni `composer.json` ni `vendor/` à installer. Le format est décrit dans
[environments/README.md](../environments/README.md).

Collez l'adresse `https://…` du dépôt, éventuellement une branche ou une étiquette, puis **Installer**.
Le moteur clone, vérifie, puis empaquette (archive du projet et index de complétion) : comptez quelques
minutes. La page ne vous fait pas attendre — rafraîchissez-la pour voir l'avancement, y compris après un
redémarrage du conteneur. Un échec reste affiché avec la sortie de `git` ou de `composer`, jusqu'à ce que
vous l'effaciez.

Ensuite :

- **Mettre à jour** rejoue l'installation depuis l'adresse enregistrée : c'est ainsi qu'on suit une
  nouvelle version du dépôt.
- **Retirer** supprime l'environnement et ses archives. Les exercices qui le désignaient ne se chargent
  plus : retirez-les, ou réinstallez.
- Un environnement installé ne peut **jamais** masquer un environnement du moteur : un identifiant déjà
  pris est refusé, avec le nom à changer dans `environment.yaml`.
- Le tout vit dans le volume `environnements`, à sauvegarder comme `data` (voir
  [Sauvegarder et restaurer](#sauvegarder-et-restaurer)).

En ligne de commande, sans passer par l'administration :

```bash
docker compose exec empaqueteur bin/console app:environnement:installer https://github.com/…/mon-environnement.git
docker compose exec empaqueteur bin/console app:environnement:installer mon-environnement   # mise à jour
```

### Le plus souvent, le pack apporte son décor

Un pack peut porter ses environnements lui-même, dans `environments/<id>/` à côté de ses parcours. Vous
n'avez rien à déclarer ni à installer : déposez le pack, le moteur trouve le décor. Il reste seulement à
l'empaqueter :

```bash
docker compose exec app bin/console app:environnement:synchroniser
```

(ou le bouton dans `/admin` → Environnements, ou `ENVIRONMENTS_AUTO_INSTALL=1` pour que ce soit fait au
démarrage). L'archive va dans le volume `environnements`, pas dans l'image.

## Réglages

Tout se règle dans `.env`, commenté ligne à ligne. Après chaque modification :

```bash
docker compose up -d
```

| Sujet | Variables | À savoir |
| --- | --- | --- |
| Adresses | `APP_URL`, `SANDBOX_URL` | Deux domaines distincts, avec leur schéma (`https://`). |
| Base | `POSTGRES_PASSWORD` | Lettres et chiffres. PostgreSQL ne le lit qu'à la création de la base : le changer ensuite ne change pas le mot de passe réel. |
| Fuseau | `APP_TIMEZONE` | Les dates sont stockées sans fuseau : choisissez-le avant les premiers comptes. |
| Emails | `MAILER_DSN`, `MAILER_FROM`, `CONTACT_EMAIL`, `REGISTRATION_ALERT_EMAIL` | Sans `MAILER_DSN`, aucun email ne part : ni confirmation d'adresse, ni mot de passe oublié. Test : `docker compose exec app bin/console mailer:test vous@example.org`. Les emails passent par une file d'attente dans la base, que vide le service `worker` : un serveur SMTP en panne ne bloque pas l'inscription, l'email part à son retour. `MESSENGER_TRANSPORT_DSN=sync://` les envoie pendant la requête, sans worker. |
| Mentions légales | `LEGAL_*` | Remplissent `/mentions-legales` et `/confidentialite` ; tant qu'un champ obligatoire manque, les pages le signalent. Les textes sont écrits pour le droit français. |
| Inscription | `REGISTRATION_INVITE_ONLY` | `1` : sur code de cohorte uniquement (cohortes créées dans `/admin`), et la Pratique demande un compte. `0` : inscription libre, et la Pratique s'écrit sans compte. |
| Ressources | `APP_MEM_LIMIT`, `EMPAQUETEUR_MEM_LIMIT`, `UMAMI_MEM_LIMIT` | Mémoire au plus de chaque service (1536m, 1g, 512m), avec un plafond de processus : un service qui s'emballe ne fait pas tomber les autres. À relever sur un gros serveur. |
| Sécurité du contenu | `CSP_REPORT_ONLY` | `1` (par défaut) : le navigateur signale seulement les scripts qu'il bloquerait, en lignes « CSP : … » dans `docker compose logs app`. `0` : il les bloque — le filet contre une faille XSS. Passez à `0` une fois les journaux muets, surtout si vous servez un traceur ou des gabarits à vous. |
| Référencement | `SEARCH_INDEXING`, `GOOGLE_SITE_VERIFICATION` | `0` pour une instance interne ou de préproduction : robots.txt interdit l'indexation. |
| Audience | `ANALYTICS_*` | Facultative, éteinte par défaut : voir [Savoir qui visite le site](#savoir-qui-visite-le-site). |
| Mentor et IA | `ANTHROPIC_API_KEY`, `AI_MODEL`, `AI_MODEL_POST`, `MENTOR_DAILY_LIMIT` | Revue de code et erreurs expliquées pour les apprenants connectés, brouillons dans l'atelier. Chaque appel est facturé sur votre clé ; sans clé, les boutons n'apparaissent pas. `AI_MODEL_POST` ne concerne que les brouillons de post LinkedIn ; vide, ils suivent `AI_MODEL`. Le mentor demande une adresse confirmée, et se limite à 40 appels par heure et par compte, 100 par adresse IP, et `MENTOR_DAILY_LIMIT` (500) par jour pour toute l'instance. |
| Vente | `STRIPE_*`, `LEGAL_MEDIATOR_*`, `LEGAL_REFUND_DAYS` | Facultatif : **une instance sans tarif reste entièrement gratuite** pour les comptes. Détails dans le [README](../README.md#parcours-payants). |
| Environnements | `INSTALLED_ENVIRONMENTS_DIR`, `ENVIRONMENT_SOURCES_ALLOWLIST`, `ENVIRONMENTS_AUTO_INSTALL` | Installer un environnement d'exécution depuis un dépôt Git. **Exécute le code du dépôt sur ce serveur** : voir [Ajouter un environnement d'exécution](#ajouter-un-environnement-dexécution). |
| Marque | `BRANDING_HOST_DIR`, `COHORT_*` | Votre nom, vos couleurs, vos images, vos tarifs de cohorte : voir [Votre marque](#votre-marque). |
| Image | `APP_IMAGE` | Voir [Mettre à jour](#mettre-à-jour). |

## Savoir qui visite le site

Facultatif, et éteint par défaut : rien n'est mesuré tant que vous n'avez pas fait ce qui suit.

[Umami](https://umami.is) tourne à côté de l'instance, dans ses propres tables du PostgreSQL déjà là.
Il ne pose aucun cookie et ne conserve aucune adresse IP : rien à faire accepter par un bandeau de
consentement, et aucune donnée ne part chez un tiers. Le traceur est servi par le site lui-même, sous
`/mesure/traceur.js` : un bloqueur de publicités n'y voit pas un traceur tiers.

1. Démarrez-le :

   ```bash
   docker compose --profile mesure up -d
   ```

2. Ouvrez le tableau de bord. Sans nom de domaine pour lui, passez par un tunnel SSH depuis votre poste :

   ```bash
   ssh -L 8081:localhost:3000 vous@votre-serveur
   ```

   puis http://localhost:8081. Le port d'Umami n'est publié que sur la boucle locale du serveur
   (`127.0.0.1`) : il n'est pas exposé à l'internet, et votre pare-feu n'a rien à filtrer. Sans ce
   tunnel, le tableau de bord n'est joignable que depuis le serveur lui-même.

   ⚠️ Le premier compte est `admin` / `umami`. **Changez ce mot de passe** avant tout, dans *Settings →
   Profile*.

3. *Settings → Websites → Add website* : donnez un nom et le domaine de votre plateforme. Umami affiche
   alors un bout de code ; n'en retenez que l'identifiant (`data-website-id`).

4. Reportez-le dans `.env`, avec le chemin du traceur :

   ```dotenv
   ANALYTICS_SCRIPT_URL=/mesure/traceur.js
   ANALYTICS_WEBSITE_ID=celui-affiché-par-umami
   ```

   ```bash
   docker compose --profile mesure up -d
   ```

   Les pages publiques portent désormais la balise ; la page « Vie privée » s'adapte d'elle-même et
   décrit ce qui est mesuré. Le bac à sable, lui, n'est jamais mesuré.

Pour consulter les chiffres sans tunnel, donnez un nom d'hôte au tableau de bord : créez
l'enregistrement DNS (ex. `suivi.example.org` vers votre serveur), puis dans `.env` :

```dotenv
ANALYTICS_SERVER_NAME=https://suivi.example.org
```

Caddy obtiendra son certificat tout seul. **À ne faire qu'une fois le mot de passe changé** : cette
adresse expose la page de connexion d'Umami sur l'internet.

Au-delà des pages vues, la plateforme envoie cinq événements, tous anonymes : `exercice-ouvert`,
`tests-lances`, `exercice-reussi`, `indice-demande` et `solution-consultee`, avec l'identifiant de
l'exercice et du parcours. Ils se lisent dans *Events*, et ils répondent à la question que les pages
vues laissent entière : un visiteur a-t-il écrit du code, ou seulement lu ? Le code écrit, lui, n'est
jamais transmis.

### Les robots des moteurs de recherche

Umami ne les voit pas : ils n'exécutent pas le JavaScript. Ils sont dans les journaux d'accès, que
Caddy écrit sur la sortie standard du conteneur (les fichiers statiques en sont écartés) :

```bash
# Les pages les plus demandées, toutes origines confondues
docker compose logs --since 24h app | grep -o '"uri":"[^"]*"' | sort | uniq -c | sort -rn | head

# Le passage des robots
docker compose logs --since 24h app | grep -oiE 'googlebot|bingbot|gptbot|claudebot' | sort | uniq -c
```

## Derrière un reverse proxy

Si nginx, Traefik ou un autre Caddy occupe déjà les ports 80 et 443 et gère le HTTPS, le conteneur sert du
HTTP simple sur une adresse locale. Dans `.env` :

```dotenv
APP_URL=https://apprendre.example.org
SANDBOX_URL=https://apprendre-bac-a-sable.net
SERVER_NAME=:80
SYMFONY_TRUSTED_PROXIES=private_ranges
HTTP_PORT=127.0.0.1:8080
HTTPS_PORT=127.0.0.1:8443
```

`APP_URL` et `SANDBOX_URL` restent les adresses **publiques**, en `https://`. `HTTPS_PORT` n'est pas utilisé
dans ce mode, mais doit rester sur un port libre.

Le proxy doit servir **les deux domaines** vers `127.0.0.1:8080`, en transmettant l'en-tête `Host` d'origine et
`X-Forwarded-Proto`. Par exemple, avec nginx (et un bloc identique pour le bac à sable) :

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name apprendre.example.org;
    # ssl_certificate … ;

    client_max_body_size 20m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Avec Caddy sur la machine hôte, ces en-têtes sont transmis d'office :

```caddyfile
apprendre.example.org, apprendre-bac-a-sable.net {
	reverse_proxy 127.0.0.1:8080
}
```

`SYMFONY_TRUSTED_PROXIES=private_ranges` fait confiance aux en-têtes `X-Forwarded-*` venus des réseaux privés,
d'où arrivent les requêtes du proxy vu depuis le conteneur. Sans lui, l'application se croit servie en HTTP :
le domaine du bac à sable affiche la plateforme au lieu de l'aperçu, et les formulaires sont refusés
(« Origine non autorisée »). Si votre proxy est lui-même un conteneur (Traefik…), reliez-le au réseau de
l'instance et visez `app:80`.

## Essai sur votre poste

Sans nom de domaine, pour voir la plateforme tourner :

```dotenv
APP_URL=http://localhost:8080
SANDBOX_URL=http://127.0.0.1:8080
SERVER_NAME=:80
HTTP_PORT=8080
HTTPS_PORT=8443
POSTGRES_PASSWORD=essai
```

`docker compose up -d --wait`, puis http://localhost:8080. `localhost` et `127.0.0.1` sont bien deux
origines distinctes pour le navigateur : c'est ce qui sépare ici la plateforme du bac à sable.

## Mettre à jour

```bash
docker compose pull
docker compose up -d --wait
docker compose exec app cat /app/VERSION
```

Les migrations de la base sont jouées au démarrage du conteneur.

Le moteur suit le versionnage sémantique ; chaque version est décrite dans le
[journal des modifications](../CHANGELOG.md). Choisissez l'étiquette de `APP_IMAGE` selon ce que vous acceptez
de voir changer à un `docker compose pull` :

| Étiquette | Contenu | Pour qui |
| --- | --- | --- |
| `:1.0.0` | une version précise, figée | production prudente |
| `:1.0` | les correctifs de la 1.0 | production, mises à jour minimales |
| `:1` | toute la 1.x : correctifs et nouveautés, sans changement cassant | la plupart des instances |
| `:latest` | la dernière version publiée, même majeure | suivre le projet de près |
| `:edge` | le dernier commit de `main` | essais, sans garantie |

Une version **majeure** (1.x → 2.0) peut demander d'agir : lisez le journal avant de changer d'étiquette, faites
une sauvegarde, et vérifiez que vos packs acceptent la nouvelle version (clé `moteur:` de leur `pack.yaml`, voir
[Contenu : les packs](#contenu--les-packs)).

Pour revenir en arrière, remettez l'étiquette précédente dans `APP_IMAGE` et restaurez la sauvegarde faite avant
la mise à jour : une migration déjà jouée ne se défait pas toute seule.

Le fichier `compose.yaml` évolue parfois avec le moteur (une nouvelle variable, par exemple) : le journal le
signale ; retéléchargez-le alors avec la commande `curl` de l'installation.

## Sauvegarder et restaurer

Ce qui compte : **la base** (comptes, progression, achats) et, dans une moindre mesure, le volume `data`
(secret de l'application, sessions, certificats). Vos packs et votre `.env` sont des fichiers ordinaires, à
sauvegarder comme tels. Le volume `environnements` se reconstitue en réinstallant depuis les dépôts —
l'administration en garde l'adresse —, mais le sauvegarder évite un `composer install` par environnement
le jour où vous restaurez.

```bash
# Sauvegarde de la base
docker compose exec -T db pg_dump -U formation -Fc formation > sauvegarde-$(date +%F).dump

# Restauration
docker compose exec -T db pg_restore -U formation -d formation --clean --if-exists < sauvegarde-2026-09-17.dump
```

Le secret de l'application signe les sessions et les liens envoyés par email (confirmation, mot de passe
oublié). Perdu, il est régénéré : les apprenants se reconnectent et les liens en cours expirent, rien de
plus. Pour le fixer vous-même, définissez `APP_SECRET` dans `.env`.

Planifiez la sauvegarde (cron) et copiez les fichiers hors du serveur.

## Dépannage

| Symptôme | Piste |
| --- | --- |
| `docker compose` refuse de démarrer : « … manquant dans .env » | La variable nommée est vide ou absente de `.env`, à côté de `compose.yaml`. |
| Le conteneur `app` redémarre en boucle | `docker compose logs app` : le plus souvent, la base refuse le mot de passe (voir `POSTGRES_PASSWORD` dans [Réglages](#réglages)). |
| Pas de certificat HTTPS | Les deux domaines résolvent-ils vers ce serveur ? Les ports 80 et 443 sont-ils ouverts ? `docker compose logs app \| grep -i acme`. |
| `port is already allocated` | Un autre service écoute sur 80 ou 443 : voir [Derrière un reverse proxy](#derrière-un-reverse-proxy). |
| L'accueil affiche encore la démonstration, ou plus aucun parcours | Après le premier pack déposé ou le dernier retiré : `docker compose restart app`. Sinon : droits de lecture (`chmod -R a+rX packs`), `CONTENT_PACKS_PATHS`, `docker compose exec app bin/console content:check`. |
| Erreur sur toutes les pages après l'ajout d'un pack ou une mise à jour | Un pack refuse la version du moteur, ou son format est invalide : le message est dans `docker compose logs app`. |
| L'aperçu du code reste vide | `SANDBOX_URL` exacte et joignable ? Derrière un proxy : `SYMFONY_TRUSTED_PROXIES`, et le proxy sert bien les deux domaines. |
| « Origine non autorisée » à l'envoi d'un formulaire | `APP_URL` ne correspond pas à l'adresse affichée par le navigateur (schéma, domaine, port), ou `SYMFONY_TRUSTED_PROXIES` manque derrière un proxy. |
| Aucun email reçu | `docker compose exec app bin/console mailer:test vous@example.org`, puis `docker compose logs worker` (le service `worker` tourne-t-il ? il manque à un `compose.yaml` antérieur à la 2.1) et les journaux de votre fournisseur. Un email qui a échoué plusieurs heures durant attend dans `docker compose exec worker bin/console messenger:failed:show` ; `messenger:failed:retry` le renvoie. |
| Encart « Mentions incomplètes » | Renseignez les variables `LEGAL_*` obligatoires. |
| Quelle version tourne ? | `docker compose exec app cat /app/VERSION`, aussi affichée en pied de page. |

Une commande de la console se lance toujours dans le conteneur : `docker compose exec app bin/console list`.
