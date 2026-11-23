#!/usr/bin/env bash
# Estado da instalação num relance (docs/DEPLOY.md, secção 14). Só lê; não muda nada.
#
#   sudo deploy/status.sh
set -uo pipefail

# shellcheck source=deploy/lib.sh
source "$(cd "$(dirname "$0")" && pwd)/lib.sh"
require_root
load_config

row() { printf '  %-22s %s\n' "$1" "$2"; }

log "Release"
if [[ -L "$CURRENT" ]]; then
    row "no ar" "$(basename "$(readlink -f "$CURRENT")") ($(cut -d' ' -f2 "${CURRENT}/REVISION" 2>/dev/null))"
else
    row "no ar" "nenhuma"
fi

log "Serviços"
for s in nginx "$FPM_SERVICE" mariadb redis-server supervisor docker cron; do
    row "$s" "$(systemctl is-active "$s" 2>/dev/null)"
done
supervisorctl status 'micomoc:*' 2>/dev/null | sed 's/^/  /'

if [[ -L "$CURRENT" ]]; then
    log "Aplicação"
    row "horizon" "$(artisan horizon:status --no-ansi 2>&1 | tail -n 1 | sed 's/^ *//')"
    if [[ -n "${DOMAIN:-}" && -f "/etc/letsencrypt/live/${CERT_NAME}/fullchain.pem" ]]; then
        row "/up" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/up")"
    fi
fi

log "Certificado"
if [[ -f "/etc/letsencrypt/live/${CERT_NAME}/cert.pem" ]]; then
    row "expira" "$(openssl x509 -in "/etc/letsencrypt/live/${CERT_NAME}/cert.pem" -noout -enddate | cut -d= -f2)"
else
    row "expira" "sem certificado"
fi

log "Cópias de segurança"
row "última diária" "$(cat "${BACKUP_DIR}/last-success" 2>/dev/null || echo 'nenhuma')"
row "ficheiros" "$(find "$BACKUP_DIR" -type f 2>/dev/null | wc -l) em ${BACKUP_DIR} ($(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1))"

log "Disco e memória"
df -h / "$APP_ROOT" "$BACKUP_DIR" 2>/dev/null | awk 'NR == 1 || !seen[$1]++' | sed 's/^/  /'
free -h | sed 's/^/  /'
