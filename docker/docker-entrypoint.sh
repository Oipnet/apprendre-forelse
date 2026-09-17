#!/bin/sh
set -e

# URL publiques : origines vues par le navigateur (schéma, hôte, port).
: "${APP_URL:?Définissez APP_URL (ex. https://formation.example.com)}"
: "${SANDBOX_URL:?Définissez SANDBOX_URL (ex. https://sandbox.example-sandbox.net)}"
export DEFAULT_URI="$APP_URL"
export SANDBOX_ORIGIN="$SANDBOX_URL"
# Adresses écoutées par Caddy : les deux URL publiques, sauf si SERVER_NAME est fourni (reverse proxy, essai local).
export SERVER_NAME="${SERVER_NAME:-$APP_URL, $SANDBOX_URL}"
: "${DATABASE_URL:?Définissez DATABASE_URL (PostgreSQL, ex. postgresql://user:mdp@db:5432/formation?serverVersion=17)}"

mkdir -p /data/app

# Secret Symfony : fourni, sinon généré au premier démarrage et conservé dans le volume.
if [ -z "$APP_SECRET" ]; then
	[ -s /data/app/secret ] || php -r 'echo bin2hex(random_bytes(32));' > /data/app/secret
	APP_SECRET="$(cat /data/app/secret)"
	export APP_SECRET
fi

# Sessions dans le volume : recréer le conteneur (un déploiement) ne déconnecte personne.
export SESSIONS_DIR="${SESSIONS_DIR:-/data/app/sessions}"
mkdir -p "$SESSIONS_DIR"

if [ -z "$(ls -A /packs 2>/dev/null)" ]; then
	echo "Aucun pack de contenu dans /packs : le pack de démonstration est utilisé."
	export CONTENT_PACKS_PATHS=/app/examples/packs
fi

if [ "$1" = 'frankenphp' ]; then
	# Cache compilé avec les variables d'environnement réelles du conteneur.
	php bin/console cache:clear --no-interaction
	php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
	echo "Plateforme : $APP_URL — bac à sable : $SANDBOX_URL"
fi

exec "$@"
