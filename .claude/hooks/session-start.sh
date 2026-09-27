#!/bin/bash
# Prépare une session Claude Code sur le web : dépendances PHP et Node, base PostgreSQL de test.
# Idempotent : une étape déjà faite est sautée (l'état du conteneur est gardé après le hook).
#
# Le proxy des sessions refuse les archives de GitHub (codeload.github.com, api.github.com/…/zipball : 403) mais
# laisse passer git : Composer installe donc depuis les sources. phpstan/phpstan n'est publié qu'en archive : on en
# clone la version voulue, qu'une copie temporaire du lock (ou un dépôt « path ») désigne comme source.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
CACHE="${HOME}/.cache/forelse"
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=0 COMPOSER_NO_INTERACTION=1
mkdir -p "$CACHE"

# La version de PHPStan que fixe le lock de la plateforme, clonée une fois (dernière révision seulement).
PHPSTAN_VERSION=$(php -r '$l = json_decode(file_get_contents($argv[1]), true); foreach ([...$l["packages"], ...$l["packages-dev"]] as $p) { if ("phpstan/phpstan" === $p["name"]) { echo $p["version"]; } }' "$ROOT/platform/composer.lock")
PHPSTAN_SOURCE="$CACHE/phpstan-$PHPSTAN_VERSION"
if [ ! -d "$PHPSTAN_SOURCE/.git" ]; then
    rm -rf "$PHPSTAN_SOURCE"
    git clone --quiet --depth 1 --branch "$PHPSTAN_VERSION" https://github.com/phpstan/phpstan.git "$PHPSTAN_SOURCE"
fi

# composer install depuis les sources, dans $1. Avec un lock : une copie où PHPStan vient du clone local.
# Sans lock (simulateur Docker, dont le lock n'est pas versionné) : un dépôt « path » vers ce même clone.
composer_install() {
    local dir="$1"
    if [ -f "$dir/vendor/autoload.php" ] && [ -x "$dir/vendor/bin/phpunit" ]; then
        echo "Composer : $dir déjà installé."
        return
    fi
    echo "Composer : installation dans $dir (depuis les sources, quelques minutes)."
    (
        cd "$dir"
        trap 'rm -f composer.hook.json composer.hook.lock' EXIT
        php -r '
            [$json, $lock, $phpstan, $version] = array_slice($argv, 1);
            $composer = json_decode(file_get_contents($json), true);
            if (is_file($lock)) {
                $l = json_decode(file_get_contents($lock), true);
                foreach (["packages", "packages-dev"] as $group) {
                    foreach ($l[$group] ?? [] as $i => $p) {
                        if ("phpstan/phpstan" === $p["name"]) {
                            $l[$group][$i]["source"] = ["type" => "git", "url" => "file://".$phpstan, "reference" => $p["dist"]["reference"] ?? $version];
                            unset($l[$group][$i]["dist"]);
                        }
                    }
                }
                file_put_contents("composer.hook.lock", json_encode($l, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $composer["repositories"] = [["type" => "path", "url" => $phpstan, "options" => ["symlink" => false, "versions" => ["phpstan/phpstan" => $version]]], ...($composer["repositories"] ?? [])];
            }
            file_put_contents("composer.hook.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        ' composer.json composer.lock "$PHPSTAN_SOURCE" "$PHPSTAN_VERSION"
        # Une seconde tentative : un clone parmi cent cinquante peut échouer sans raison durable.
        COMPOSER=composer.hook.json composer install --prefer-source --no-cache --no-progress --quiet \
            || COMPOSER=composer.hook.json composer install --prefer-source --no-cache --no-progress --quiet
        # Installé depuis les sources, chaque paquet garde son dépôt git : 4 Go au lieu de 1,3 pour la plateforme.
        # Les tests n'en ont pas l'usage, pas plus que du cache de Composer (un miroir complet de chaque dépôt).
        find vendor -mindepth 3 -maxdepth 3 -name .git -type d -prune -exec rm -rf {} +
        composer clear-cache --quiet
    )
}

composer_install "$ROOT/platform"
composer_install "$ROOT/packages/simulateur-docker"

# Node : le playground et le simulateur Nuxt (runtime-contract n'a pas de dépendances).
for dir in "$ROOT/playground" "$ROOT/packages/simulateur-nuxt"; do
    if [ ! -d "$dir/node_modules" ]; then
        echo "npm : installation dans $dir."
        (cd "$dir" && npm install --no-audit --no-fund --loglevel=error)
    fi
done

# PostgreSQL local, avec le rôle et la base de test que suppose platform/.env.
if command -v pg_ctlcluster > /dev/null; then
    CLUSTER=$(pg_lsclusters --no-header | awk 'NR == 1 { print $1 " " $2 }')
    if [ -n "$CLUSTER" ]; then
        # shellcheck disable=SC2086 # « version nom » : deux arguments.
        pg_ctlcluster $CLUSTER start 2> /dev/null || true
        su postgres -c "psql -tAc \"SELECT 1 FROM pg_roles WHERE rolname = 'app'\"" | grep -q 1 \
            || su postgres -c "psql -qc \"CREATE ROLE app LOGIN SUPERUSER PASSWORD 'app'\""
        (
            cd "$ROOT/platform"
            php bin/console doctrine:database:create --env=test --if-not-exists --quiet
            php bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration --quiet
        )
    fi
fi

echo "Session prête : make test (les environnements d'exécution se construisent avec environments/bin/build-env.sh)."
