#syntax=docker/dockerfile:1

# Image de production du moteur : plateforme Symfony servie par FrankenPHP (Caddy).
# Les packs de contenu ne sont PAS dans l'image : ils sont montés dans /packs.
# La base PostgreSQL est un service à part (voir compose.yaml), indiquée par DATABASE_URL.

# --- 1. Playground : build Vite (îlot JS, runtime WebAssembly) -------------------------
# Même version majeure que .nvmrc, avec laquelle la CI teste le playground (elle vérifie qu'elles concordent).
FROM node:24-slim AS playground

# Le contrat des runtimes : le playground et chaque paquet de runtime en dépendent (lien npm « file: »),
# il doit donc être présent avant le moindre « npm ci ».
COPY packages/runtime-contract /src/packages/runtime-contract

# Le simulateur Nuxt est un paquet de runtime : le playground le découvre parmi ses dépendances.
WORKDIR /src/packages/simulateur-nuxt
COPY packages/simulateur-nuxt/package.json packages/simulateur-nuxt/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY packages/simulateur-nuxt/src ./src

WORKDIR /src/playground
COPY playground/package.json playground/package-lock.json ./
COPY playground/stubs ./stubs
RUN npm ci --no-audit --no-fund
COPY playground/ ./
# Sortie : /src/platform/public/build (voir playground/vite.config.ts)
RUN npm run build


# --- 2. Base PHP commune ----------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS php_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]
WORKDIR /app

RUN install-php-extensions @composer intl opcache pdo_pgsql pcntl \
    # pcntl pour les seules commandes longues (worker, empaqueteur), qui la chargent avec « php -d extension=pcntl » :
    # elle leur fait entendre le SIGTERM d'un arrêt. Pas pour FrankenPHP (serveur multithread), d'où l'ini retiré.
    && rm "$PHP_INI_DIR/conf.d/docker-php-ext-pcntl.ini"
ENV COMPOSER_ALLOW_SUPERUSER=1


# --- 3. Dépendances de la plateforme ----------------------------------------------------
FROM php_base AS platform

WORKDIR /app/platform
COPY platform/composer.json platform/composer.lock platform/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction
COPY platform/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative
# `composer install --no-scripts` n'a pas copié les assets des bundles (EasyAdmin) dans public/bundles,
# et ce dossier est ignoré par git : on le fait ici, avec de vrais fichiers (pas de liens symboliques).
RUN APP_ENV=prod php bin/console assets:install public --no-interaction


# --- 4. Environnements d'exécution des exercices (archive + index de complétion) -------
FROM php_base AS environments

RUN apt-get update && apt-get install -y --no-install-recommends zip && rm -rf /var/lib/apt/lists/*
COPY environments/ /app/environments/
# Le simulateur Docker : dépôt Composer « path » de l'environnement docker (voir environments/README.md).
COPY packages/simulateur-docker/ /app/packages/simulateur-docker/
# Écrit /app/platform/public/envs/<id>.zip et <id>.completion.json, pour tous les environnements.
RUN mkdir -p /app/platform/public && /app/environments/bin/build-env.sh


# --- 5. Image finale --------------------------------------------------------------------
FROM php_base AS app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

COPY --from=platform /app/platform /app/platform
COPY --from=playground /src/platform/public/build /app/platform/public/build
COPY --from=environments /app/platform/public/envs /app/platform/public/envs
# vendor/ des environnements conservé : permet `bin/console content:check` dans le conteneur.
COPY --from=environments /app/environments /app/environments
COPY examples/ /app/examples/
# Version du moteur : lue par App\Version (%kernel.project_dir%/../VERSION), affichée en pied de page.
COPY VERSION /app/VERSION

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_TIMEZONE=UTC \
    CONTENT_PACKS_PATHS=/packs \
    ENVIRONMENTS_DIR=/app/environments,/environnements \
    INSTALLED_ENVIRONMENTS_DIR=/environnements \
    ENVIRONMENTS_AUTO_INSTALL=0 \
    BRANDING_DIR=/marque

# Utilisateur non privilégié ; le binaire peut tout de même écouter sur 80/443.
# git et zip : l'administration installe un environnement depuis un dépôt et l'empaquette ici même
# (voir README, « Ajouter un environnement depuis un dépôt Git »). util-linux fournit setsid, qui détache
# l'installation de la requête HTTP qui la lance.
RUN apt-get update && apt-get install -y --no-install-recommends libcap2-bin git zip util-linux && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 1000 --home /app formation \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /data/app /config /packs /marque /environnements /app/platform/var \
    && chown -R formation:formation /data /config /environnements /app/platform/var
USER formation

VOLUME ["/data"]
EXPOSE 8080 80 443

# /sante passe par PHP et fait un SELECT 1 : base arrêtée ou PHP en panne, le conteneur devient « unhealthy ».
# Adresse interne au conteneur, déclarée dans le Caddyfile. file_get_contents échoue aussi sur un 503.
HEALTHCHECK --start-period=30s CMD php -r 'exit(false === @file_get_contents("http://127.0.0.1:2020/sante", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'

WORKDIR /app/platform
ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
