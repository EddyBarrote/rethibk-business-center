#!/usr/bin/env bash
# Volta a pôr no ar uma release anterior (docs/DEPLOY.md, secção 10).
#
#   sudo deploy/rollback.sh            a release imediatamente anterior à actual
#   sudo deploy/rollback.sh <pasta>    uma release concreta (nome em /var/www/micomoc/releases)
#
# Só aceita releases que já estiveram no ar (marcadas com .deployed pelo deploy.sh).
#
# Só troca o código. Se a versão de que se volta correu migrações, a base de dados fica como
# está: normalmente a versão anterior funciona na mesma (as migrações acrescentam); se não,
# repor a cópia pre-deploy-* (deploy/restore.md, secção 3).
set -euo pipefail

# shellcheck source=deploy/lib.sh
source "$(cd "$(dirname "$0")" && pwd)/lib.sh"
require_root
load_config

cd "$APP_ROOT"
exec 9>"$DEPLOY_LOCK"
flock -n 9 || die "Há um deploy a correr."

[[ -L "$CURRENT" ]] || die "Ainda não há nenhuma release no ar."
active="$(readlink -f "$CURRENT")"

if [[ -n "${1:-}" ]]; then
    target="${RELEASES}/$(basename "$1")"
else
    # A mais recente antes da actual que chegou a estar no ar (o deploy marca-a com .deployed).
    target=""
    while IFS= read -r path; do
        if [[ "$path" < "$active" && -f "${path}/.deployed" ]]; then
            target="$path"
        fi
    done < <(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d | sort)
fi
[[ -n "$target" && -d "$target" ]] || die "Não há release anterior a ${active} que tenha estado no ar."
[[ "$target" != "$active" ]] || die "${target} já é a release no ar."
[[ -f "${target}/.deployed" || "${FORCE:-0}" == "1" ]] ||
    die "${target} nunca esteve no ar (o deploy falhou antes da troca). FORCE=1 para usar na mesma."
[[ -f "${target}/vendor/autoload.php" && -f "${target}/public/build/manifest.json" ]] ||
    die "${target} está incompleta (sem vendor/ ou public/build/)."

log "De $(basename "$active") para $(basename "$target")"
# A cache da configuração é refeita com o .env de agora.
ARTISAN_DIR="$target" artisan optimize --no-interaction

as_app ln -sfn "$target" "${APP_ROOT}/current.next"
as_app mv -Tf "${APP_ROOT}/current.next" "$CURRENT"
systemctl reload "$FPM_SERVICE"

artisan horizon:terminate --no-interaction
artisan reverb:restart --no-interaction
artisan queue:restart --no-interaction
supervisorctl restart micomoc:micomoc-scheduler >/dev/null

install -d -m 0755 "$LOG_DIR"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) rollback de $(basename "$active") para $(basename "$target") por ${SUDO_USER:-root}" >>"${LOG_DIR}/deploy.log"

log "No ar: $(cat "${target}/REVISION" 2>/dev/null || basename "$target")"
warn "Se $(basename "$active") correu migrações e esta versão se queixar da base de dados: deploy/restore.md, secção 3."
