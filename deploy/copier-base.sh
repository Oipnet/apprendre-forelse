#!/bin/sh
# Copie la base de l'instance (service db) dans un fichier, au format personnalisé de pg_dump (restauration :
# pg_restore --clean, voir README). Partagé par deployer.sh (avant chaque migration) et sauvegarde/sauvegarder.sh
# (chaque nuit), et lancé depuis le dossier de l'instance.
#
# La copie s'écrit dans un .partiel, est relue par pg_restore -l, puis seulement renommée : un fichier au nom définitif
# est toujours une copie complète et lisible. En échec, le script sort en erreur et ne laisse aucun fichier.
# Usage : copier-base.sh chemin/vers/copie.dump
set -u

CIBLE="${1:?usage: $0 chemin/vers/copie.dump}"
PARTIEL="$CIBLE.partiel"

mkdir -p "$(dirname "$CIBLE")" || exit 1
if docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > "$PARTIEL" \
    && docker compose exec -T db pg_restore -l < "$PARTIEL" > /dev/null; then
    mv "$PARTIEL" "$CIBLE"
    echo "Copie de la base : $CIBLE ($(du -h "$CIBLE" | cut -f1))."
else
    rm -f "$PARTIEL"
    echo "Copie de la base en échec : aucun fichier gardé." >&2
    exit 1
fi
