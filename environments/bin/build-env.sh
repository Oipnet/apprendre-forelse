#!/usr/bin/env bash
# Empaquette un environnement (ex. symfony-8) en archive zip servie au navigateur, avec son index de
# complétion. Sans argument, empaquette tous les environnements de ce dossier.
#
# Usage : environments/bin/build-env.sh [environnement] [dossier de sortie]
#
# La sortie se choisit par argument, par ENVIRONMENTS_OUT, ou retombe sur le public/ du moteur tant
# que les deux vivent dans le même dépôt (voir environments/README.md).
#
# ENVIRONMENTS_PATH (dossiers séparés par des virgules) élargit la recherche : un environnement
# installé ailleurs — par exemple cloné depuis un dépôt Git par l'administration — peut ainsi
# prolonger un environnement livré avec le moteur. Même ordre et mêmes règles que côté PHP
# (App\Content\EnvironmentRegistry).
set -euo pipefail

BIN="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$BIN/.." && pwd)"

IFS=',' read -ra ROOTS <<< "${ENVIRONMENTS_PATH:-$ROOT}"

# Le dossier d'un environnement, cherché dans l'ordre des racines.
dossier_de() {
    local id="$1" racine
    for racine in "${ROOTS[@]}"; do
        [ -f "$racine/$id/environment.yaml" ] && { echo "$racine/$id"; return 0; }
    done
    echo "Environnement introuvable : $id (cherché dans ${ROOTS[*]})" >&2
    return 1
}

# Sans nom d'environnement : tous ceux qui déclarent un environment.yaml (bin/ n'en est donc pas un).
if [ $# -eq 0 ]; then
    trouve=0
    for racine in "${ROOTS[@]}"; do
        for manifeste in "$racine"/*/environment.yaml; do
            [ -e "$manifeste" ] || continue
            trouve=1
            "$0" "$(basename "$(dirname "$manifeste")")"
        done
    done
    [ "$trouve" -eq 1 ] || { echo "Aucun environnement dans ${ROOTS[*]}." >&2; exit 1; }
    exit 0
fi

ENV_NAME="$1"
ENV_DIR="$(dossier_de "$ENV_NAME")"
OUT_DIR="${2:-${ENVIRONMENTS_OUT:-$ROOT/../platform/public/envs}}"
OUT="$OUT_DIR/$ENV_NAME.zip"

[ -d "$ENV_DIR" ] || { echo "Environnement introuvable : $ENV_DIR" >&2; exit 1; }
mkdir -p "$OUT_DIR"

# La chaîne d'héritage, de la base la plus lointaine à cet environnement (voir « extends: » dans
# environment.yaml). Un fichier du plus particulier l'emporte sur le même fichier du plus général.
chaine() {
    local id="$1" vus="${2:-}" dossier parent
    case " $vus " in *" $id "*) echo "« extends » tourne en rond : $vus $id" >&2; exit 1;; esac
    # « || exit 1 » explicite, et non « set -e » : bash **désactive** set -e dans une fonction appelée
    # depuis une liste « && », ce qui était le cas de l'appel récursif ci-dessous. Une base introuvable
    # n'écrivait donc que sur stderr ; la chaîne repartait avec un dossier vide, l'archive était
    # produite sans sa base, et le script sortait en 0. Pire, « cp -a "$dossier/." » plus bas devenait
    # « cp -a "/." » : la racine du serveur recopiée dans un dossier temporaire.
    dossier=$(dossier_de "$id") || exit 1
    parent=$(sed -n 's/^extends:[[:space:]]*\([^[:space:]#]*\).*/\1/p' "$dossier/environment.yaml" | head -1)
    if [ -n "$parent" ]; then
        chaine "$parent" "$vus $id" || exit 1
    fi
    echo "$dossier"
}
# Substitution de commande, et non « < <(…) » : le statut d'une substitution de processus est perdu.
CHAINE_TEXTE="$(chaine "$ENV_NAME")" || exit 1
mapfile -t CHAINE <<< "$CHAINE_TEXTE"

# Le dossier qui installe : le plus particulier de la chaîne qui déclare un composer.json. Un
# environnement qui n'ajoute aucune dépendance hérite du vendor/ de sa base.
INSTALL_DIR=""
for dossier in "${CHAINE[@]}"; do
    [ -f "$dossier/composer.json" ] && INSTALL_DIR="$dossier"
done

# Un environnement sans Composer nulle part dans sa chaîne (Nuxt, servi par le simulateur du
# navigateur) n'a ni dépendances à installer ni index de complétion PHP à extraire. Il se superpose
# quand même : un environnement qui prolonge une base sans PHP garde les fichiers de sa base.
if [ -n "$INSTALL_DIR" ]; then
    (cd "$INSTALL_DIR" && composer install --no-interaction --no-scripts --quiet && composer dump-autoload --optimize --quiet)
    # Paquets locaux (dépôts « path » copiés, comme le simulateur Docker) : recopiés à chaque empaquetage,
    # sinon une modification de tools/ n'atteindrait pas l'environnement.
    PATH_PACKAGES=$(cd "$INSTALL_DIR" && php -r '$lock = json_decode((string) @file_get_contents("composer.lock"), true) ?: []; foreach ([...($lock["packages"] ?? []), ...($lock["packages-dev"] ?? [])] as $p) { if (($p["dist"]["type"] ?? "") === "path") echo $p["name"], " "; }')
    if [ -n "$PATH_PACKAGES" ]; then
        # shellcheck disable=SC2086
        (cd "$INSTALL_DIR" && composer reinstall --no-interaction --quiet $PATH_PACKAGES && composer dump-autoload --optimize --quiet)
    fi
fi

# Un environnement seul s'empaquette depuis son dossier ; une chaîne se superpose d'abord dans un
# dossier de travail, du plus général au plus particulier. vendor/ ne se superpose pas : il vient du
# seul dossier qui installe, sinon les paquets d'une base que l'enfant a retirés traîneraient dans
# le projet servi à l'apprenant.
SOURCE="$ENV_DIR"
if [ "${#CHAINE[@]}" -gt 1 ]; then
    SOURCE="$(mktemp -d)"
    trap 'rm -rf "$SOURCE"' EXIT
    for dossier in "${CHAINE[@]}"; do
        # Ceinture et bretelles : un maillon vide ferait de « cp -a "$dossier/." » un « cp -a "/." ».
        [ -n "$dossier" ] && [ -d "$dossier" ] || { echo "Chaîne d'environnements incohérente pour $ENV_NAME." >&2; exit 1; }
        cp -a "$dossier/." "$SOURCE/"
    done
    rm -rf "$SOURCE/vendor"
    [ -n "$INSTALL_DIR" ] && [ -d "$INSTALL_DIR/vendor" ] && cp -a "$INSTALL_DIR/vendor" "$SOURCE/vendor"
fi

rm -f "$OUT"
# .forelse.json est l'état que le moteur dépose dans un environnement installé depuis un dépôt : il
# porte l'adresse de ce dépôt, jeton d'accès compris le cas échéant. Il n'a rien à faire dans une
# archive téléchargée par chaque apprenant.
if [ -z "$INSTALL_DIR" ]; then
    EXCLUDES=(-x 'node_modules/*' '.nuxt/*' '.output/*' '.git/*' 'environment.yaml' '.forelse.json' '*.DS_Store' '*/.gitkeep')
else
    EXCLUDES=(-x 'var/*' '.phpunit.cache/*' '.git/*' 'CLAUDE.md' 'AGENTS.md' 'environment.yaml' '.forelse.json' '.archiveignore' '.editorconfig' '*.DS_Store')
fi
# .archiveignore : motifs zip supplémentaires, un par ligne (ex. traductions de vendor/ inutiles à l'apprenant).
[ -f "$SOURCE/.archiveignore" ] && EXCLUDES+=("-x@$SOURCE/.archiveignore")
(cd "$SOURCE" && zip -qr9X "$OUT" . "${EXCLUDES[@]}")

if [ -z "$INSTALL_DIR" ]; then
    echo '{"classes":{}}' > "$OUT_DIR/$ENV_NAME.completion.json"
else
    php "$BIN/build-completion.php" "$INSTALL_DIR" "$OUT_DIR/$ENV_NAME.completion.json"
fi

echo "$(du -h "$OUT" | cut -f1)  $OUT"
echo "$(du -h "$OUT_DIR/$ENV_NAME.completion.json" | cut -f1)  $OUT_DIR/$ENV_NAME.completion.json"
