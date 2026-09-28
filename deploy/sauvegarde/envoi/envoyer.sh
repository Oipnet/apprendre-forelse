#!/bin/sh
# Dépose la sauvegarde du jour (/sauvegarde) sur le NAS de la Freebox Pro, à travers son VPN IPsec IKEv2.
# Lancé par ../sauvegarder.sh dans un conteneur éphémère (--cap-add NET_ADMIN) : le tunnel vit dans l'espace réseau du
# conteneur, et ne mène qu'à l'adresse du NAS (remote_ts). Variables : voir ../sauvegarde.env.example.
set -eu

# ENVOI_ESSAI=1 (tests seulement) : pas de VPN, le NAS est joignable directement.
: "${VPN_SERVEUR:?}" "${VPN_UTILISATEUR:?}" "${VPN_MOT_DE_PASSE:?}"
: "${NAS_IP:?}" "${NAS_PARTAGE:?}" "${JOUR:?}"
GARDER="${NAS_GARDER:-30}"
CIBLE="forelse-$JOUR"

# Le mot de passe en base64 (préfixe 0s de swanctl) : un guillemet ou un antislash ne casse rien. L'identifiant, lui,
# est une identité strongSwan, sans préfixe : elle ne doit contenir ni guillemet ni espace.
b64() { printf '%s' "$1" | base64 | tr -d '\n'; }
case "$VPN_UTILISATEUR" in *[\"\ ]*) echo "VPN_UTILISATEUR : ni guillemet ni espace." >&2; exit 1 ;; esac
cat > /etc/swanctl/conf.d/freebox.conf <<EOF
connections {
    freebox {
        version = 2
        remote_addrs = $VPN_SERVEUR
        vips = 0.0.0.0
        local {
            auth = eap-mschapv2
            eap_id = "$VPN_UTILISATEUR"
        }
        remote {
            auth = pubkey
            id = $VPN_SERVEUR
        }
        children {
            nas {
                remote_ts = $NAS_IP/32
            }
        }
    }
}
secrets {
    eap-freebox {
        id = "$VPN_UTILISATEUR"
        secret = 0s$(b64 "$VPN_MOT_DE_PASSE")
    }
}
EOF

ouvrir_vpn() {
    # Son emplacement dépend de la distribution (Alpine ne suit pas /usr/libexec/ipsec) : on le cherche.
    CHARON_BIN="$(find /usr/lib /usr/libexec /usr/sbin -name charon -type f 2>/dev/null | head -n 1)"
    [ -n "$CHARON_BIN" ] || { echo "charon introuvable." >&2; exit 1; }
    "$CHARON_BIN" > /tmp/charon.log 2>&1 &
    CHARON=$!
    trap 'swanctl --terminate --ike freebox > /dev/null 2>&1 || true; kill "$CHARON" 2> /dev/null || true' EXIT

    i=0
    until [ -S /var/run/charon.vici ]; do
        i=$((i + 1)); [ "$i" -le 50 ] || { cat /tmp/charon.log >&2; echo "strongSwan ne démarre pas." >&2; exit 1; }
        sleep 0.2
    done
    swanctl --load-all --noprompt > /dev/null
    if ! swanctl --initiate --child nas --timeout 30; then
        tail -n 30 /tmp/charon.log >&2
        echo "VPN de la Freebox Pro injoignable ou refusé." >&2
        exit 1
    fi
}
[ "${ENVOI_ESSAI:-0}" = 1 ] || ouvrir_vpn

# Le NAS de la Freebox Pro s'ouvre en invité une fois sur son réseau : pas d'identifiants (-N). Un compte, s'il y en a
# un, passe par un fichier (-A), jamais par la ligne de commande.
if [ -n "${NAS_UTILISATEUR:-}" ]; then
    umask 077
    printf 'username = %s\npassword = %s\n' "$NAS_UTILISATEUR" "${NAS_MOT_DE_PASSE:-}" > /tmp/nas.auth
    AUTH="-A /tmp/nas.auth"
else
    AUTH="-N"
fi
# shellcheck disable=SC2086 # $AUTH : une ou deux options, découpées à dessein.
nas() { smbclient "//$NAS_IP/$NAS_PARTAGE" $AUTH -c "$1" < /dev/null; }

nas "mkdir $CIBLE" > /dev/null 2>&1 || true # déjà là si la sauvegarde du jour est relancée
nas "cd $CIBLE; lcd /sauvegarde; put base.dump; put data.tar.gz"

# Relecture : chaque fichier doit être arrivé entier.
nas "ls $CIBLE/*" > /tmp/liste
for f in base.dump data.tar.gz; do
    attendu="$(stat -c %s "/sauvegarde/$f")"
    recu="$(awk -v f="$f" '$1 == f { print $3 }' /tmp/liste)"
    [ "$recu" = "$attendu" ] || { echo "$f : $recu octets sur le NAS, $attendu attendus." >&2; exit 1; }
done
echo "Déposé sur le NAS : $CIBLE ($(du -sh /sauvegarde | cut -f1))."

# Rétention : les dossiers forelse-AAAA-MM-JJ plus vieux que $GARDER jours s'en vont.
LIMITE="$(date -u -d "@$(($(date +%s) - GARDER * 86400))" +%Y-%m-%d)"
nas "ls" | awk -v limite="forelse-$LIMITE" '$1 ~ /^forelse-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]$/ && $1 < limite { print $1 }' |
    while read -r vieux; do
        nas "deltree $vieux" > /dev/null && echo "Retiré du NAS : $vieux."
    done
