#!/usr/bin/env bash
# Empaquette un environnement (ex. symfony-8) en archive zip servie au navigateur.
# Usage : tools/build-env.sh symfony-8
set -euo pipefail

ENV_NAME="${1:?usage: $0 <environnement>}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_DIR="$ROOT/environments/$ENV_NAME"
OUT_DIR="$ROOT/platform/public/envs"
OUT="$OUT_DIR/$ENV_NAME.zip"

[ -d "$ENV_DIR" ] || { echo "Environnement introuvable : $ENV_DIR" >&2; exit 1; }
mkdir -p "$OUT_DIR"

# Environnement sans PHP (Nuxt, servi par le simulateur du navigateur) : ni Composer ni index de complétion PHP.
if [ ! -f "$ENV_DIR/composer.json" ]; then
    rm -f "$OUT"
    (cd "$ENV_DIR" && zip -qr9X "$OUT" . -x 'node_modules/*' '.nuxt/*' '.output/*' '.git/*' 'environment.yaml' '*.DS_Store' '*/.gitkeep')
    echo '{"classes":{}}' > "$OUT_DIR/$ENV_NAME.completion.json"
    echo "$(du -h "$OUT" | cut -f1)  $OUT"
    exit 0
fi

(cd "$ENV_DIR" && composer install --no-interaction --no-scripts --quiet && composer dump-autoload --optimize --quiet)
# Paquets locaux (dépôts « path » copiés, comme le simulateur Docker) : recopiés à chaque empaquetage,
# sinon une modification de tools/ n'atteindrait pas l'environnement.
PATH_PACKAGES=$(cd "$ENV_DIR" && php -r '$lock = json_decode((string) @file_get_contents("composer.lock"), true) ?: []; foreach ([...($lock["packages"] ?? []), ...($lock["packages-dev"] ?? [])] as $p) { if (($p["dist"]["type"] ?? "") === "path") echo $p["name"], " "; }')
if [ -n "$PATH_PACKAGES" ]; then
    # shellcheck disable=SC2086
    (cd "$ENV_DIR" && composer reinstall --no-interaction --quiet $PATH_PACKAGES && composer dump-autoload --optimize --quiet)
fi

rm -f "$OUT"
EXCLUDES=(-x 'var/*' '.phpunit.cache/*' '.git/*' 'CLAUDE.md' 'AGENTS.md' 'environment.yaml' '.archiveignore' '.editorconfig' '*.DS_Store')
# .archiveignore : motifs zip supplémentaires, un par ligne (ex. traductions de vendor/ inutiles à l'apprenant).
[ -f "$ENV_DIR/.archiveignore" ] && EXCLUDES+=("-x@$ENV_DIR/.archiveignore")
(cd "$ENV_DIR" && zip -qr9X "$OUT" . "${EXCLUDES[@]}")

php "$ROOT/tools/build-completion.php" "$ENV_DIR" "$OUT_DIR/$ENV_NAME.completion.json"

echo "$(du -h "$OUT" | cut -f1)  $OUT"
echo "$(du -h "$OUT_DIR/$ENV_NAME.completion.json" | cut -f1)  $OUT_DIR/$ENV_NAME.completion.json"
