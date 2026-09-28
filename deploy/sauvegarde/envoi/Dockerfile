# Conteneur d'envoi des sauvegardes (voir ../sauvegarder.sh) : un client IPsec IKEv2 (strongSwan) pour le VPN de la
# Freebox Pro, et un client SMB pour son NAS. Construit sur le serveur, à la première sauvegarde.
FROM alpine:3.22

RUN apk add --no-cache strongswan samba-client ca-certificates \
    # strongSwan vérifie le certificat du serveur VPN avec ses propres autorités (x509ca), un fichier par certificat :
    # on y découpe le magasin du système, qui est celui qu'utilise un Mac pour la même connexion.
    && mkdir -p /etc/swanctl/x509ca \
    && awk '/BEGIN CERTIFICATE/{n++} {print > ("/etc/swanctl/x509ca/ca-" n ".pem")}' /etc/ssl/certs/ca-certificates.crt

COPY envoyer.sh /usr/local/bin/envoyer
ENTRYPOINT ["sh", "/usr/local/bin/envoyer"]
