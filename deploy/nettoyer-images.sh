#!/bin/sh
# Garde sur le serveur les N images les plus récentes du moteur (de quoi revenir en arrière sans registre)
# et supprime les autres : chaque déploiement ajoute ~1,6 Go, et le disque se remplissait (vu le 2026-09-17 : 30 images).
# Une image utilisée par un conteneur en marche n'est jamais supprimée : Docker refuse, même avec -f.
# Usage : nettoyer-images.sh ghcr.io/oipnet/apprendre-forelse [3]
set -eu

REPO="${1:?usage: $0 depot-des-images [nombre-a-garder]}"
KEEP="${2:-3}"

docker image ls --no-trunc --format '{{.CreatedAt}}|{{.ID}}' "$REPO" \
    | sort -r \
    | cut -d'|' -f2 \
    | awk '!seen[$0]++' \
    | tail -n +"$((KEEP + 1))" \
    | while read -r id; do
        docker image rm -f "$id" > /dev/null 2>&1 || true
    done
docker image prune -f > /dev/null
