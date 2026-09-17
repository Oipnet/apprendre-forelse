# Auto-héberger le moteur

Ce guide installe votre propre instance de la plateforme avec Docker : l'application (FrankenPHP/Caddy +
Symfony) et sa base PostgreSQL, à partir de l'image publiée. Rien à compiler.

Le code des apprenants s'exécute **dans leur navigateur** (PHP compilé en WebAssembly), jamais sur le serveur :
un petit serveur suffit, même pour beaucoup d'apprenants.

- [Ce qu'il faut](#ce-quil-faut)
- [Installation](#installation)
- [Premier administrateur](#premier-administrateur)
- [Contenu : les packs](#contenu--les-packs)
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
sur un poste de développement (voir le [README](../README.md) du dépôt).

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
| Emails | `MAILER_DSN`, `MAILER_FROM`, `CONTACT_EMAIL`, `REGISTRATION_ALERT_EMAIL` | Sans `MAILER_DSN`, aucun email ne part : ni confirmation d'adresse, ni mot de passe oublié. Test : `docker compose exec app bin/console mailer:test vous@example.org`. |
| Mentions légales | `LEGAL_*` | Remplissent `/mentions-legales` et `/confidentialite` ; tant qu'un champ obligatoire manque, les pages le signalent. Les textes sont écrits pour le droit français. |
| Inscription | `REGISTRATION_INVITE_ONLY` | `1` : sur code de cohorte uniquement (cohortes créées dans `/admin`). |
| Référencement | `SEARCH_INDEXING`, `GOOGLE_SITE_VERIFICATION` | `0` pour une instance interne ou de préproduction : robots.txt interdit l'indexation. |
| Mentor et IA | `ANTHROPIC_API_KEY`, `AI_MODEL` | Revue de code et erreurs expliquées pour les apprenants connectés, brouillons dans l'atelier. Chaque appel est facturé sur votre clé ; sans clé, les boutons n'apparaissent pas. |
| Vente | `STRIPE_*`, `LEGAL_MEDIATOR_*`, `LEGAL_REFUND_DAYS` | Facultatif : **une instance sans tarif reste entièrement gratuite** pour les comptes. Détails dans le [README](../README.md#parcours-payants). |
| Image | `APP_IMAGE` | Voir [Mettre à jour](#mettre-à-jour). |

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
sauvegarder comme tels.

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
| Aucun email reçu | `docker compose exec app bin/console mailer:test vous@example.org`, puis les journaux de votre fournisseur. |
| Encart « Mentions incomplètes » | Renseignez les variables `LEGAL_*` obligatoires. |
| Quelle version tourne ? | `docker compose exec app cat /app/VERSION`, aussi affichée en pied de page. |

Une commande de la console se lance toujours dans le conteneur : `docker compose exec app bin/console list`.
