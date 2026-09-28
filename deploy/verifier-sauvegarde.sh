#!/bin/sh
# Vérifie qu'une copie de la base se restaure : elle est chargée dans une base temporaire du même PostgreSQL, on y
# compte les comptes et la progression, puis cette base est supprimée. La base en service n'est pas touchée.
# Lancé depuis le dossier de l'instance.
# Usage : verifier-sauvegarde.sh [copie.dump]   (sans argument : la plus récente de sauvegardes/)
set -u

COPIE="${1:-}"
if [ -z "$COPIE" ]; then
    # La plus récente, sauvegarde quotidienne ou copie d'avant déploiement.
    COPIE="$(find sauvegardes -name '*.dump' -type f -printf '%T@ %p\n' 2> /dev/null | sort -rn | head -n 1 | cut -d' ' -f2-)"
    [ -n "$COPIE" ] || { echo "Aucune copie dans sauvegardes/." >&2; exit 1; }
fi
[ -r "$COPIE" ] || { echo "$COPIE illisible." >&2; exit 1; }

ESSAI="verification_$(date -u +%Y%m%d%H%M%S)"
base() { docker compose exec -T db sh -c "$1"; }
# shellcheck disable=SC2016 # $POSTGRES_USER est celui du conteneur db, développé là-bas.
trap 'base "dropdb -U \"\$POSTGRES_USER\" --if-exists $ESSAI" > /dev/null 2>&1' EXIT

echo "Vérification de $COPIE ($(du -h "$COPIE" | cut -f1)), dans la base temporaire $ESSAI."
# shellcheck disable=SC2016
base "createdb -U \"\$POSTGRES_USER\" $ESSAI" || exit 1
# shellcheck disable=SC2016
if ! base "pg_restore -U \"\$POSTGRES_USER\" -d $ESSAI --no-owner --exit-on-error" < "$COPIE"; then
    echo "ÉCHEC : la copie ne se restaure pas." >&2
    exit 1
fi
# shellcheck disable=SC2016
COMPTES="$(base "psql -U \"\$POSTGRES_USER\" -d $ESSAI -tAc 'SELECT count(*) FROM \"user\"'")" || exit 1
# shellcheck disable=SC2016
PROGRESSION="$(base "psql -U \"\$POSTGRES_USER\" -d $ESSAI -tAc 'SELECT count(*) FROM exercise_progress'")" || exit 1
echo "Restaurée : $COMPTES comptes, $PROGRESSION exercices en cours ou réussis."
