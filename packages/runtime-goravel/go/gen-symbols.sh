#!/usr/bin/env bash
# Régénère yaegisym/ : les paquets compilés que le code de l'apprenant (interprété par yaegi) peut importer.
# À relancer après une montée de version de Goravel, ou pour ouvrir un paquet de plus aux leçons.
#
# Usage : go/gen-symbols.sh   (il faut yaegi : go install github.com/traefik/yaegi/cmd/yaegi@v0.16.1)
set -euo pipefail
cd "$(dirname "$0")"
./prepare-vendor.sh
export GOOS=js GOARCH=wasm GOFLAGS="-mod=vendor -tags=sqlite3_dotlk"
cd yaegisym

PAQUETS=(
	github.com/goravel/framework/contracts/http
	github.com/goravel/framework/contracts/route
	github.com/goravel/framework/contracts/database/db
	github.com/goravel/framework/contracts/database/orm
	github.com/goravel/framework/contracts/testing/http
	github.com/goravel/framework/database/orm
	github.com/stretchr/testify/assert
	goravel/app/facades
	goravel/tests
)

rm -f ./*-*.go
for paquet in "${PAQUETS[@]}"; do
	yaegi extract -name yaegisym "$paquet"
done

python3 ../fix-symbols.py .
gofmt -l . || true
