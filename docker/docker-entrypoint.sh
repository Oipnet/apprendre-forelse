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
	# Migrations de la base : au démarrage, par défaut, pour qu'une instance auto-hébergée n'ait rien à lancer à la
	# main. Une plateforme qui les joue dans une étape à part de son déploiement, avant de basculer, met
	# MIGRATIONS_AT_STARTUP=0 (voir deploy/deployer.sh) : une migration en échec n'arrête plus la version en service.
	if [ "${MIGRATIONS_AT_STARTUP:-1}" = "1" ]; then
		php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
	fi
	# Compteurs des limites de débit expirés (table cache_items) : l'adaptateur ne supprime une ligne que si sa clé
	# est relue ; celles des adresses IP de passage resteraient. Sans gravité en cas d'échec.
	php bin/console cache:pool:prune --no-interaction || echo "Purge des compteurs expirés impossible, on continue."

	# Environnements déclarés par les packs (clé « environments » de pack.yaml) : installés au démarrage
	# si l'instance l'a demandé. En tâche de fond, volontairement : cloner puis lancer composer dure des
	# minutes, et un dépôt injoignable n'a pas à empêcher la plateforme de servir ses pages. Les exercices
	# concernés attendent leur environnement ; les autres tournent tout de suite. Suivi dans
	# /admin → Environnements, et dans les journaux du conteneur.
	# Avec le service empaqueteur (ENVIRONMENTS_BUILDER=empaqueteur), c'est lui qui s'en charge, loin des secrets.
	if [ "${ENVIRONMENTS_AUTO_INSTALL:-0}" = "1" ] && [ "${ENVIRONMENTS_BUILDER:-}" != "empaqueteur" ]; then
		echo "Environnements des packs : installation des manquants en tâche de fond (ENVIRONMENTS_AUTO_INSTALL=1)."
		php bin/console app:environnement:synchroniser --no-interaction &
	fi

	echo "Plateforme : $APP_URL — bac à sable : $SANDBOX_URL"
fi

exec "$@"
