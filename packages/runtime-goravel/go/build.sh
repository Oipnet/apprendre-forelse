#!/usr/bin/env bash
# Compile Goravel en WebAssembly (GOOS=js) : le binaire que le runtime goravel-wasm charge dans le navigateur.
#
# Usage : go/build.sh [dossier de sortie]   (par défaut : ../dist)
set -euo pipefail
cd "$(dirname "$0")"
OUT="$(mkdir -p "${1:-../dist}" && cd "${1:-../dist}" && pwd)"

./prepare-vendor.sh
GOOS=js GOARCH=wasm go build -mod=vendor -tags sqlite3_dotlk -trimpath -ldflags="-s -w" -o "$OUT/goravel.wasm" .
# Le chargeur JS de Go doit être celui du compilateur qui a produit le binaire : on le recopie à côté, et dans
# src/ (importé par instance.ts) quand on construit pour ce dépôt.
cp "$(go env GOROOT)/lib/wasm/wasm_exec.js" "$OUT/wasm_exec.js"
[ -z "${1:-}" ] && cp "$OUT/wasm_exec.js" ../src/wasm_exec.js
echo "$OUT/goravel.wasm : $(du -h "$OUT/goravel.wasm" | cut -f1)"
