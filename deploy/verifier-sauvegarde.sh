#!/bin/sh
# Vérifie qu'une copie de la base se restaure : elle est chargée dans une base temporaire du même PostgreSQL, on y
# compte les comptes, la progression et les achats, puis cette base est supprimée. La base en service n'est pas touchée.
# Lancé depuis le dossier de l'instance.
# Usage : verifier-sauvegarde.sh [copie.dump]   (par défaut, la plus récente de sauvegardes/)
set -u

DUMPS="${DEPLOY_DUMPS_DIR:-sauvegardes}"
# shellcheck disable=SC2012 # noms horodatés sans espace : l'ordre de ls -t suffit.
DUMP="${1:-$(ls -1t "$DUMPS"/*.dump 2>/dev/null | head -n 1)}"
if [ -z "$DUMP" ] || [ ! -f "$DUMP" ]; then
    echo "Aucune copie à vérifier${1:+ : $1 n'existe pas}." >&2
    exit 1
fi

TEMP="verification_$(date -u +%Y%m%d%H%M%S)"
base() {
    docker compose exec -T db sh -c "$1"
}
trap 'base "dropdb -U \"\$POSTGRES_USER\" --if-exists $TEMP" > /dev/null 2>&1' EXIT

echo "Restauration de $DUMP dans la base temporaire $TEMP."
base "createdb -U \"\$POSTGRES_USER\" $TEMP" || exit 1
if ! base "pg_restore -U \"\$POSTGRES_USER\" --no-owner --exit-on-error -d $TEMP" < "$DUMP"; then
    echo "La copie ne se restaure pas : $DUMP" >&2
    exit 1
fi

COUNTS=$(base "psql -U \"\$POSTGRES_USER\" -d $TEMP -At -F ' ' -c 'SELECT (SELECT count(*) FROM \"user\"), (SELECT count(*) FROM exercise_progress), (SELECT count(*) FROM purchase)'") || {
    echo "Copie restaurée, mais les tables attendues n'y sont pas : $DUMP" >&2
    exit 1
}
# shellcheck disable=SC2086 # trois nombres séparés par des espaces : découpés exprès.
set -- $COUNTS
echo "Copie restaurée : $1 comptes, $2 progressions, $3 achats."
