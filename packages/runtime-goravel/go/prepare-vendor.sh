#!/usr/bin/env bash
# Prépare vendor/ : les dépendances, complétées par overlay/.
#
# Quelques dépendances ne compilent pas pour js/wasm (la console interactive, les processus). Go refuse de
# remplacer un fichier du cache de modules (-overlay), et copier ces modules dans le dépôt serait lourd :
# on les vendorise, puis on recopie par-dessus les fichiers de overlay/<module>/<chemin>. Les fichiers
# remplacés (process_unix.go, env.go) sont ceux de la version de go.mod : à revoir à chaque montée de Goravel.
set -euo pipefail
cd "$(dirname "$0")"
go mod vendor
cp -R overlay/. vendor/
