#!/bin/sh
# Déploie une image du moteur sur le serveur, et revient à la précédente si la nouvelle ne tient pas : conteneurs pas
# « healthy » dans le délai (le HEALTHCHECK interroge /sante, donc PHP et la base), ou /sante injoignable de l'extérieur.
# Lancé par le job « Déployer » de .github/workflows/ci.yml, après un `docker login ghcr.io`.
# Usage : deployer.sh ghcr.io/oipnet/apprendre-forelse@sha256:… https://apprendre.forelse.fr
#
# Les migrations de la nouvelle image sont jouées avant de basculer, dans un conteneur à part (compose.yaml met
# MIGRATIONS_AT_STARTUP=0) : si elles échouent (--all-or-nothing, tout est annulé), la version en service n'a pas bougé.
#
# Le retour arrière remet l'image, pas la base : les migrations de la nouvelle version restent appliquées. L'ancienne
# image redémarre quand même (Doctrine signale des migrations qu'il ne connaît pas, sans échouer), mais son code doit
# supporter le nouveau schéma : une migration qui retire ou renomme une colonne se fait en deux déploiements.
set -u

NEW="${1:?usage: $0 image@digest url-de-la-plateforme}"
URL="${2:?usage: $0 image@digest url-de-la-plateforme}"
REPO="${NEW%@*}"
WAIT="${DEPLOY_WAIT_TIMEOUT:-180}"

# Le jeton du registre ne doit pas rester sur le serveur, que le déploiement réussisse ou non.
trap 'docker logout ghcr.io > /dev/null 2>&1' EXIT

cd "${DEPLOY_DIR:-/srv/forelse}" || exit 1

# L'étiquette locale de cette instance, lue dans son .env comme le fait compose.yaml : « edge » pour la production,
# autre chose pour la préproduction, qui tourne sur le même serveur et ne doit pas déplacer les étiquettes de l'autre.
TAG=$(sed -n 's/^IMAGE_TAG=//p' .env 2>/dev/null | tail -n 1)
TAG="${TAG:-edge}"

demarrer() {
    # L'image déployée reçoit aussi l'étiquette locale de l'instance (:edge en production), celle qu'utilise
    # compose.yaml par défaut : un `docker compose up -d` lancé à la main sur le serveur (après avoir modifié .env)
    # relance la même image, au lieu d'une ancienne restée en cache (vu le 2026-09-17 : retour à un moteur 0.1.0).
    docker tag "$1" "$REPO:$TAG" \
        && APP_IMAGE="$1" docker compose up -d --remove-orphans --wait --wait-timeout "$WAIT" \
        && curl -fsS -o /dev/null --retry 6 --retry-delay 5 --retry-all-errors "$URL/sante"
}

# L'image en service, gardée sous une étiquette à elle : :$TAG va changer, et le nettoyage ne passe qu'après un succès.
PREVIOUS=""
APP=$(docker compose ps -q app)
if [ -n "$APP" ]; then
    PREVIOUS="$REPO:$TAG-precedente"
    docker tag "$(docker inspect -f '{{.Image}}' "$APP")" "$PREVIOUS"
fi

# Sans épinglage, une étiquette qui n'existe plus (ou un APP_IMAGE oublié dans le .env du serveur) fait tirer une
# image inchangée : compose ne recrée rien et la CI reste verte. Un échec ici n'a encore rien touché.
# L'image du moteur seulement (worker et empaqueteur la partagent) : PostgreSQL et Umami ne changent pas de version
# avec un déploiement du moteur. Une version épinglée pas encore présente est tirée par `up`, une fois.
APP_IMAGE="$NEW" docker compose pull --quiet app || exit 1

# Base à jour pour la nouvelle image, avant qu'elle ne serve la moindre requête. La base démarre si besoin (depends_on).
echo "Migrations de la base."
if ! APP_IMAGE="$NEW" docker compose run --rm app php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing; then
    echo "::error::Migrations en échec : rien n'a été basculé, la version en service continue."
    exit 1
fi

if demarrer "$NEW"; then
    echo "Déployé : $NEW"
    sh nettoyer-images.sh "$REPO" 3
    exit 0
fi

echo "::error::La nouvelle image ne tient pas : $NEW"
docker compose ps
docker compose logs --tail 50 app
if [ -z "$PREVIOUS" ]; then
    echo "::error::Aucune version précédente en service : rien à remettre."
    exit 1
fi
echo "Retour à l'image précédente."
if demarrer "$PREVIOUS"; then
    echo "::warning::Image précédente remise en service ; le déploiement reste en échec."
else
    echo "::error::L'image précédente ne tient pas non plus : intervention nécessaire."
fi
exit 1
