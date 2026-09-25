# Déploiement de l'instance Forelse

Deux instances sur le même serveur, derrière un proxy commun :

| Dossier sur le serveur | Rôle | Mise à jour |
|---|---|---|
| `/srv/front` | proxy (Caddy) : seul à tenir 80/443, tous les certificats | à la main, rarement |
| `/srv/forelse-staging` | **préproduction** | chaque poussée sur `main` |
| `/srv/forelse` | **production** | chaque version publiée (`tools/release.sh`), après validation dans GitHub |

Chaque instance a son `.env`, donc son nom de projet Docker, sa base et ses volumes. Les fichiers de ce dossier y sont
copiés par la CI (`.github/workflows/ci.yml`) ; le proxy, lui, s'installe une fois.

- `compose.yaml` : les services d'une instance. Seul, l'application tient elle-même 80/443 (l'ancien fonctionnement).
- `derriere-front.yaml` : l'instance passe derrière le proxy (plus de port publié, réseau `front`).
- `preproduction.yaml` : ce qui distingue la préproduction (pas d'indexation, pas d'Umami).
- `deployer.sh` : migrations, bascule, contrôle de `/sante`, retour à l'image précédente en cas d'échec.
- `front/` : le proxy commun.

Le `.env` de chaque instance choisit ses fichiers avec `COMPOSE_FILE`. Il faut **Docker Compose 2.24 ou plus**
(`docker compose version`).

## 1. Passer la production derrière le proxy

Une coupure de quelques secondes, le temps que le proxy prenne les ports. À faire en premier, seul, et à vérifier
avant d'installer la préproduction.

Le compte `deploy` n'a pas `sudo` : les deux dossiers se créent une fois avec un compte administrateur, puis lui
sont confiés.

```sh
# Avec un compte administrateur
sudo mkdir -p /srv/front /srv/forelse-staging
sudo chown deploy:deploy /srv/front /srv/forelse-staging
```

Puis, avec `deploy` :

```sh
# Le proxy
cd /srv/front
# compose.yaml, Caddyfile et .env.example depuis deploy/front/ du dépôt
cp .env.example .env    # ACME_EMAIL, PRODUCTION_HOSTS (plateforme, bac à sable, et suivi si exposé)

# Les certificats de la production, repris tels quels : rien à redemander à Let's Encrypt pendant la coupure.
docker volume create front_data
docker run --rm -v forelse_data:/depuis:ro -v front_data:/vers alpine cp -a /depuis/caddy /vers/

# La production : derriere-front.yaml depuis le dépôt (la CI ne le copie qu'au prochain déploiement), puis dans son .env :
#   COMPOSE_FILE=compose.yaml:derriere-front.yaml
#   FRONT_ALIAS=forelse-prod
# Si ANALYTICS_SERVER_NAME est en https://, le passer en http:// : c'est le proxy qui tient le HTTPS.

# La bascule : l'application rend les ports, le proxy les prend.
cd /srv/forelse && docker compose up -d app
cd /srv/front && docker compose up -d
```

À vérifier aussitôt :

- `curl -I https://<plateforme>/sante` répond 200 (en HTTP/2) ;
- un formulaire s'envoie (la connexion avec un mauvais mot de passe dit « Identifiants invalides », pas une erreur 403) :
  une 403 veut dire que l'application ne voit pas le HTTPS, voir `SYMFONY_TRUSTED_PROXIES` dans `derriere-front.yaml` ;
- l'aperçu d'un exercice s'affiche (bac à sable) ;
- le tableau de bord d'Umami, s'il est exposé.

Revenir en arrière : `docker compose down` dans `/srv/front`, retirer `COMPOSE_FILE` du `.env` de la production,
puis `docker compose up -d app` dans `/srv/forelse`.

## 2. Installer la préproduction

Deux adresses, en sous-domaines, **chacune sous le domaine correspondant de la production** : la plateforme sous
celui de la plateforme, le bac à sable sous celui du bac à sable. Jamais le bac à sable sous le domaine de la
plateforme (même « site » pour le navigateur, voir `auto-hebergement/README.md`).

1. Enregistrements DNS des deux adresses vers le serveur.
2. `/srv/forelse-staging` : `.env` à partir de `.env.preproduction.example`.
3. `/srv/front/.env` : `PREPRODUCTION_APP_HOST`, `PREPRODUCTION_SANDBOX_HOST`, l'utilisateur et l'empreinte du mot de
   passe, puis `docker compose up -d` dans `/srv/front` (les nouveaux noms obtiennent leurs certificats ; la
   production est coupée une seconde, le temps de recréer le proxy). L'empreinte :
   `docker run --rm -it caddy:2.10-alpine caddy hash-password` (le mot de passe ne passe ni à l'écran ni dans
   l'historique), puis dans le `.env`, entre guillemets simples. Pour l'y écrire par un bloc `cat >> .env <<'EOF'`,
   gardez les guillemets autour de `EOF` : sans eux, le shell remplacerait les `$` de l'empreinte.
4. GitHub, **Settings → Environments** : créer `staging`. Si les secrets `DEPLOY_*` sont rangés dans l'environnement
   `production`, les y recopier (ou les passer en secrets du dépôt).
5. GitHub, **Settings → Secrets and variables → Actions → Variables** : variable **de dépôt** `STAGING_URL`
   (l'adresse de la plateforme de préproduction, en `https://`). Sans elle, le job de préproduction est ignoré.
6. Premier déploiement : une poussée sur `main`, ou **Actions → CI → Run workflow** sur `main`.
7. Un compte administrateur sur sa base, qui démarre vide : s'inscrire sur la préproduction (les inscriptions y
   sont ouvertes, elle est derrière son mot de passe), puis dans `/srv/forelse-staging` :
   `docker compose exec app php bin/console app:admin votre@adresse`.
8. `docker stats` : la production garde de la marge avec les deux instances en marche.

## 3. Suivi des erreurs

Un seul projet Sentry (type Symfony, région UE) pour les deux instances : la préproduction y range ses erreurs
sous `preproduction` (`preproduction.yaml`), la production sous `production`.

1. Le DSN du projet (Settings → Client Keys) dans le `.env` de chaque instance : `SENTRY_DSN=https://…`.
2. `docker compose up -d app worker` dans le dossier de l'instance : un conteneur ne relit son `.env` qu'à sa
   création.
3. `docker compose exec app php bin/console app:sentry:essai` : l'erreur volontaire doit apparaître dans Sentry,
   avec sa pile et la version du moteur.

## 4. La production ne suit plus que les versions

1. GitHub, **Settings → Environments → production → Required reviewers** : vous. Chaque mise en production attend
   votre validation dans l'onglet Actions.
2. Publier une version : `tools/release.sh X.Y.Z`, relire `git show`, puis `git push --follow-tags`. La CI reprend
   l'image déjà testée sur `main` pour ce commit, l'étiquette, puis attend la validation avant de déployer.

Une poussée sur `main` ne touche plus la production. Un passage relancé sur un commit ancien ne remet pas non plus une
version dépassée en préproduction : seul le commit en tête de `main` s'y déploie.
