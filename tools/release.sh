#!/usr/bin/env bash
# Prépare une version du moteur : VERSION, package.json, CHANGELOG, commit et étiquette git.
# Rien n'est poussé : relisez `git show`, puis `git push --follow-tags`.
# L'étiquette poussée publie les images ghcr.io/…:X.Y.Z, :X.Y et :latest, puis déploie la production après
# validation dans GitHub (environnement « production ») : voir .github/workflows/ci.yml et deploy/README.md.
# Usage : tools/release.sh 0.2.0
set -euo pipefail

VERSION="${1:?usage: $0 <version>   (ex. 0.2.0)}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Version attendue au format X.Y.Z : $VERSION" >&2; exit 1; }
git rev-parse "v$VERSION" >/dev/null 2>&1 && { echo "L'étiquette v$VERSION existe déjà." >&2; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "Le dépôt n'est pas propre : commitez ou remisez d'abord." >&2; exit 1; }
grep -q '^## Non publié$' CHANGELOG.md || { echo "CHANGELOG.md : section « ## Non publié » introuvable." >&2; exit 1; }
if [ -z "$(sed -n '/^## Non publié$/,/^## [0-9]/p' CHANGELOG.md | sed '1d;$d' | tr -d '[:space:]')" ]; then
    echo "CHANGELOG.md : la section « Non publié » est vide — décrivez ce qui change avant de publier." >&2
    exit 1
fi
# Une instance auto-hébergée neuve prend l'image de sa majeure (:2…) : elle doit être celle qu'on publie, sinon elle
# démarre sur une branche qui ne reçoit plus rien. Une 0.x ne publie pas d'étiquette de majeure.
MAJEURE="${VERSION%%.*}"
if [ "$MAJEURE" != 0 ]; then
    for f in auto-hebergement/compose.yaml auto-hebergement/.env.example; do
        etiquettes="$(grep -oE 'apprendre-forelse:[0-9.]+' "$f" | sed 's/.*://' | grep -vF . | sort -u)"
        if [ "$etiquettes" != "$MAJEURE" ]; then
            echo "$f : l'image par défaut doit être :$MAJEURE (trouvé : ${etiquettes//$'\n'/ } ) — mettez aussi à jour le tableau des étiquettes de auto-hebergement/README.md." >&2
            exit 1
        fi
    done
fi

echo "$VERSION" > VERSION
# Le playground est publié avec le moteur : même numéro, pour que l'îlot JS soit identifiable.
php -r '$f = "playground/package.json"; $j = file_get_contents($f); file_put_contents($f, preg_replace("/(\"version\": \")[^\"]+/", "\${1}".$argv[1], $j, 1));' "$VERSION"
php -r '$f = "CHANGELOG.md"; $c = file_get_contents($f); file_put_contents($f, str_replace("## Non publié\n", "## Non publié\n\n## ".$argv[1]." — ".date("Y-m-d")."\n", $c, $n)); exit($n === 1 ? 0 : 1);' "$VERSION"

git add VERSION playground/package.json CHANGELOG.md
git commit -m "Version $VERSION"
git tag -a "v$VERSION" -m "Version $VERSION"

echo
echo "Version $VERSION préparée. Relisez :  git show v$VERSION"
echo "Puis publiez :                        git push --follow-tags"
echo "La mise en production attendra votre validation dans l'onglet Actions de GitHub."
