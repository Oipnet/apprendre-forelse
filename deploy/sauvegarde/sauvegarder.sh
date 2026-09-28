#!/bin/sh
# Sauvegarde quotidienne de l'instance, déposée hors du serveur sur le NAS de la Freebox Pro (#5).
#
# Programmée par la crontab de l'utilisateur deploy (voir deploy/README.md, « Sauvegarde quotidienne ») :
#   17 3 * * * sh /srv/forelse/sauvegarde/sauvegarder.sh >> /srv/forelse/sauvegardes/sauvegarde.log 2>&1
#
# 1. La base PostgreSQL (pg_dump -Fc, relue par pg_restore -l avant d'être gardée).
# 2. Le volume « data » de l'application : APP_SECRET et les certificats de Caddy. Sans APP_SECRET, une restauration
#    invaliderait les sessions et les liens signés (confirmation d'adresse, mot de passe oublié).
# 3. L'envoi : un conteneur éphémère ouvre le VPN IKEv2 de la Freebox Pro, dépose les deux fichiers sur le NAS, retire
#    les sauvegardes de plus de NAS_GARDER jours, puis s'arrête (envoi/). Le tunnel n'existe que dans ce conteneur et
#    n'atteint que l'adresse du NAS : ni le serveur ni la production n'y passent.
#
# Une copie reste aussi sur le serveur (SAUVEGARDE_GARDER_LOCAL jours) : elle ne protège pas d'une perte du serveur,
# mais évite d'aller chercher la dernière sur le NAS pour une restauration courante.
set -eu

ICI="$(cd "$(dirname "$0")" && pwd)"
cd "$ICI/.."
CONF="$ICI/sauvegarde.env"
[ -r "$CONF" ] || { echo "$CONF introuvable : copiez sauvegarde.env.example et remplissez-le." >&2; exit 1; }

# Réglages facultatifs, lus dans le même fichier (les autres variables ne servent qu'au conteneur d'envoi).
reglage() { sed -n "s/^$1=//p" "$CONF" | tail -n 1; }
LOCAL="$(reglage SAUVEGARDE_DIR)"; LOCAL="${LOCAL:-sauvegardes/quotidiennes}"
GARDER_LOCAL="$(reglage SAUVEGARDE_GARDER_LOCAL)"; GARDER_LOCAL="${GARDER_LOCAL:-7}"
PING="$(reglage SAUVEGARDE_PING_URL)"

JOUR="$(date -u +%Y-%m-%d)"
DOSSIER="$LOCAL/$JOUR"

# Un échec prévient le service de surveillance, s'il y en a un : une sauvegarde qui s'arrête sans bruit ne vaut rien.
echec() {
    echo "$(date -u +%FT%TZ) Sauvegarde en ÉCHEC." >&2
    [ -n "$PING" ] && curl -fsS -m 10 --retry 3 -o /dev/null "$PING/fail" || true
}
trap 'echec' EXIT

echo "$(date -u +%FT%TZ) Sauvegarde du $JOUR."
mkdir -p "$DOSSIER"

# 1. La base, relue avant d'être gardée (../copier-base.sh, partagé avec deployer.sh).
sh copier-base.sh "$DOSSIER/base.dump"

# 2. Le volume data, lu à travers le conteneur de l'application (pas besoin de connaître le nom du volume).
APP="$(docker compose ps -q app)"
[ -n "$APP" ] || { echo "Le service app ne tourne pas : volume data introuvable." >&2; exit 1; }
docker run --rm --volumes-from "$APP":ro alpine:3.22 tar -czf - -C /data . > "$DOSSIER/data.tar.gz.partiel"
tar -tzf "$DOSSIER/data.tar.gz.partiel" > /dev/null
mv "$DOSSIER/data.tar.gz.partiel" "$DOSSIER/data.tar.gz"

ls -l "$DOSSIER"

# 3. L'envoi sur le NAS. L'image se construit la première fois, puis vient du cache.
docker build -q -t forelse-sauvegarde-envoi "$ICI/envoi" > /dev/null
docker run --rm --cap-add NET_ADMIN --env-file "$CONF" -e JOUR="$JOUR" \
    -v "$(pwd)/$DOSSIER:/sauvegarde:ro" forelse-sauvegarde-envoi

# 4. Les copies locales, les $GARDER_LOCAL plus récentes.
# shellcheck disable=SC2012 # dossiers AAAA-MM-JJ : l'ordre alphabétique est l'ordre des dates.
ls -1d "$LOCAL"/????-??-?? | sort -r | tail -n "+$((GARDER_LOCAL + 1))" | xargs -r rm -rf

trap - EXIT
[ -n "$PING" ] && curl -fsS -m 10 --retry 3 -o /dev/null "$PING" || true
echo "$(date -u +%FT%TZ) Sauvegarde du $JOUR terminée."
