#!/bin/sh
# Copie la base de l'instance dans sauvegardes/ (DEPLOY_DUMPS_DIR), au format personnalisé de pg_dump, et ne garde que
# les plus récentes de ce préfixe. Lancé depuis le dossier de l'instance (celui de son compose.yaml et de son .env).
# Usage : sauvegarder.sh <préfixe> [nombre à garder, 10 par défaut]
#   - deployer.sh : « avant-deploiement », avant chaque migration ;
#   - la crontab du compte deploy : « quotidienne » (voir README.md).
# La copie s'écrit dans un fichier .partiel, renommé une fois complète : en échec, le script sort en erreur et ne laisse
# rien. Pour la restaurer, ou vérifier qu'elle se restaure : verifier-sauvegarde.sh.
set -u

PREFIX="${1:?usage: $0 préfixe [nombre à garder]}"
KEEP="${2:-10}"
DUMPS="${DEPLOY_DUMPS_DIR:-sauvegardes}"

mkdir -p "$DUMPS" || exit 1
DUMP="$DUMPS/$PREFIX-$(date -u +%Y%m%dT%H%M%SZ).dump"
echo "Copie de la base : $DUMP"
if ! docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > "$DUMP.partiel"; then
    rm -f "$DUMP.partiel"
    echo "Copie de la base en échec : $DUMP n'a pas été écrite." >&2
    exit 1
fi
mv "$DUMP.partiel" "$DUMP" || exit 1

# shellcheck disable=SC2012 # noms horodatés sans espace : l'ordre de ls -t suffit.
ls -1t "$DUMPS/$PREFIX"-*.dump | tail -n "+$((KEEP + 1))" | xargs -r rm -f
