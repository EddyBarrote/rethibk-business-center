#!/usr/bin/env bash
# Certificado do Let's Encrypt e Nginx completo (docs/DEPLOY.md, secção 2 e 4).
#
#   sudo deploy/tls.sh http [slug…] [--domain outro.dominio…]
#       Opção B, um nome por tenant (desafio HTTP-01, sem API de DNS). Pede um certificado para
#       DOMAIN, admin.DOMAIN, ws.DOMAIN e {slug}.DOMAIN de cada slug dado. Os nomes que o
#       certificado já tem mantêm-se: para um tenant novo basta `tls.sh http novo`.
#
#   sudo deploy/tls.sh cloudflare
#       Opção A, wildcard (DOMAIN e *.DOMAIN) por DNS-01. O DNS do domínio tem de estar na
#       Cloudflare e o token (Zone:DNS:Edit) em /etc/letsencrypt/cloudflare.ini (chmod 600):
#           dns_cloudflare_api_token = …
#       Tenants novos não pedem nada.
#
#   sudo deploy/tls.sh nginx
#       Só volta a escrever e recarregar o Nginx (o certificado já existe).
#
# A renovação é automática (certbot.timer) e recarrega o Nginx (gancho do provision.sh).
set -Eeuo pipefail

# shellcheck source=deploy/lib.sh
source "$(cd "$(dirname "$0")" && pwd)/lib.sh"
require_root
load_config

[[ -n "${DOMAIN:-}" ]] || die "Falta DOMAIN (em ${MICOMOC_CONF} ou no ambiente)."
mode="${1:-}"
[[ -n "$mode" ]] || die "Uso: $0 http [slug…] [--domain d…] | cloudflare | nginx"
shift

CERT_DIR="/etc/letsencrypt/live/${CERT_NAME}"
email_args=(--register-unsafely-without-email)
if [[ -n "${LETSENCRYPT_EMAIL:-}" ]]; then
    email_args=(--email "$LETSENCRYPT_EMAIL" --no-eff-email)
fi

# Os nomes que o certificado actual já cobre.
current_names() {
    [[ -f "${CERT_DIR}/cert.pem" ]] || return 0
    openssl x509 -in "${CERT_DIR}/cert.pem" -noout -ext subjectAltName 2>/dev/null |
        tr ',' '\n' | sed -n 's/^ *DNS://p'
}

case "$mode" in
http)
    names=("$DOMAIN" "admin.${DOMAIN}" "ws.${DOMAIN}")
    while [[ $# -gt 0 ]]; do
        case "$1" in
        --domain)
            [[ -n "${2:-}" ]] || die "--domain precisa de um nome."
            names+=("$2")
            shift 2
            ;;
        *)
            [[ "$1" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]] || die "Slug inválido: $1"
            names+=("${1}.${DOMAIN}")
            shift
            ;;
        esac
    done
    while IFS= read -r name; do
        # Um certificado wildcard não passa para HTTP-01: esses nomes ficam de fora.
        [[ -n "$name" && "$name" != \** ]] && names+=("$name")
    done < <(current_names)
    mapfile -t names < <(printf '%s\n' "${names[@]}" | awk '!seen[$0]++')

    log "Certificado ${CERT_NAME} (HTTP-01): ${names[*]}"
    domain_args=()
    for n in "${names[@]}"; do domain_args+=(-d "$n"); done
    certbot certonly --webroot -w "$ACME_ROOT" --cert-name "$CERT_NAME" --expand \
        --non-interactive --agree-tos "${email_args[@]}" "${domain_args[@]}"
    ;;
cloudflare)
    creds=/etc/letsencrypt/cloudflare.ini
    [[ -f "$creds" ]] || die "Falta ${creds} com dns_cloudflare_api_token = …"
    chmod 600 "$creds"
    dpkg -s python3-certbot-dns-cloudflare >/dev/null 2>&1 || apt-get install -y -q python3-certbot-dns-cloudflare
    log "Certificado ${CERT_NAME} (DNS-01, Cloudflare): ${DOMAIN} e *.${DOMAIN}"
    certbot certonly --dns-cloudflare --dns-cloudflare-credentials "$creds" \
        --dns-cloudflare-propagation-seconds 30 --cert-name "$CERT_NAME" \
        --non-interactive --agree-tos "${email_args[@]}" -d "$DOMAIN" -d "*.${DOMAIN}"
    ;;
nginx) ;;
*)
    die "Modo desconhecido: ${mode} (http, cloudflare ou nginx)."
    ;;
esac

[[ -f "${CERT_DIR}/fullchain.pem" ]] || die "Sem certificado em ${CERT_DIR}."

log "Nginx: site completo (HTTPS, tenants e Reverb)"
install -m 0644 "${DEPLOY_DIR}/nginx/micomoc-security-headers.conf" /etc/nginx/snippets/micomoc-security-headers.conf
render "${DEPLOY_DIR}/nginx/micomoc.conf" /etc/nginx/sites-available/micomoc.conf
ln -sfn /etc/nginx/sites-available/micomoc.conf /etc/nginx/sites-enabled/micomoc.conf
rm -f /etc/nginx/sites-enabled/micomoc-acme.conf
nginx -t
systemctl reload nginx

log "Nomes no certificado:"
current_names | sed 's/^/    /'
echo "    expira: $(openssl x509 -in "${CERT_DIR}/cert.pem" -noout -enddate | cut -d= -f2)"
