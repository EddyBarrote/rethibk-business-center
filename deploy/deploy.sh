#!/usr/bin/env bash
# Publica uma versão da plataforma (docs/DEPLOY.md, secção 9).
#
#   sudo deploy/deploy.sh [ref]        ref: um ramo (main, por omissão), uma tag ou um commit
#
# Cada versão vai para uma pasta nova em releases/ e só troca (`current`) no fim, já com as
# dependências instaladas, o frontend compilado e as caches feitas: se algo falhar antes da
# troca, a versão no ar não mexe. A manutenção (503) só cobre as migrações e a troca, e só
# quando há migrações; antes delas faz-se sempre uma cópia da base de dados.
#
# Variáveis: SKIP_BACKUP=1 (não fazer a cópia antes das migrações; não recomendado),
#            SKIP_SANDBOX=1 (não construir a imagem do code.run).
# Voltar atrás: sudo deploy/rollback.sh (ver docs/DEPLOY.md, secção 10).
set -euo pipefail

# shellcheck source=deploy/lib.sh
source "$(cd "$(dirname "$0")" && pwd)/lib.sh"
require_root
load_config

REF="${1:-$DEPLOY_BRANCH}"
ENV_FILE="${SHARED}/.env"
# O utilizador da aplicação não entra na pasta de quem chamou o script (ex.: /root); o git precisa dela.
[[ -d "$APP_ROOT" ]] || die "Falta ${APP_ROOT}: corra primeiro deploy/provision.sh."
cd "$APP_ROOT"

exec 9>"$DEPLOY_LOCK"
flock -n 9 || die "Já há um deploy a correr."

install -d -m 0755 "$LOG_DIR"
exec > >(tee -a "${LOG_DIR}/deploy.log") 2>&1
log "Deploy de ${REF} em $(date -u +%Y-%m-%dT%H:%M:%SZ) por ${SUDO_USER:-root}"

[[ -f "$ENV_FILE" ]] || die "Falta ${ENV_FILE}: corra primeiro deploy/provision.sh."
[[ -n "$(env_value "$ENV_FILE" APP_KEY)" ]] || die "APP_KEY vazia em ${ENV_FILE}."
[[ -n "$(env_value "$ENV_FILE" REVERB_APP_KEY)" ]] || die "REVERB_APP_KEY vazia em ${ENV_FILE}."
[[ "$(env_value "$ENV_FILE" APP_DEBUG)" != "true" || "$(env_value "$ENV_FILE" APP_ENV)" != "production" ]] ||
    die "APP_DEBUG=true em produção mostraria segredos nas páginas de erro."

RELEASE=""
SWITCHED=0
MAINTENANCE=0
MIGRATED=0
BACKUP_FILE=""
PREVIOUS=""
if [[ -L "$CURRENT" ]]; then
    PREVIOUS="$(readlink -f "$CURRENT")"
fi

on_exit() {
    local code=$?
    if [[ "$code" -eq 0 ]]; then
        return 0
    fi
    warn "O deploy não acabou bem (código ${code})."
    if [[ "$SWITCHED" == "0" ]]; then
        if [[ "$MAINTENANCE" == "1" && -n "$PREVIOUS" ]]; then
            (cd "$PREVIOUS" && as_app "$PHP_BIN" artisan up) || true
        fi
        warn "A versão anterior continua no ar${PREVIOUS:+ (${PREVIOUS})}."
        if [[ -n "$RELEASE" && -d "$RELEASE" ]]; then
            warn "A release incompleta fica em ${RELEASE} para análise."
        fi
        if [[ "$MIGRATED" == "1" ]]; then
            warn "As migrações chegaram a correr. Se a versão anterior se queixar da base de dados,"
            warn "reponha a cópia feita antes: ${BACKUP_FILE:-ver ${BACKUP_DIR}/pre-deploy} (deploy/restore.md, secção 3)."
        fi
    else
        warn "A troca já foi feita. Para voltar à versão anterior: sudo ${DEPLOY_DIR}/rollback.sh"
    fi
}
trap on_exit EXIT

# --- 1. Código: espelho do repositório e uma pasta nova -------------------------------------
if [[ ! -d "$REPO_MIRROR" ]]; then
    log "A clonar ${REPO_URL}"
    as_app git clone --mirror --quiet "$REPO_URL" "$REPO_MIRROR"
else
    as_app git --git-dir="$REPO_MIRROR" remote update --prune >/dev/null
fi
SHA="$(as_app git --git-dir="$REPO_MIRROR" rev-parse --verify --quiet "${REF}^{commit}")" ||
    die "Não encontro ${REF} em ${REPO_URL}."
SHORT="${SHA:0:7}"
RELEASE="${RELEASES}/$(date -u +%Y%m%d%H%M%S)-${SHORT}"
log "Release ${RELEASE} (${REF} = ${SHA})"

as_app mkdir -p "$RELEASE"
as_app git --git-dir="$REPO_MIRROR" archive --format=tar "$SHA" | as_app tar -x -C "$RELEASE"
printf '%s %s\n' "$SHA" "$REF" | as_app tee "${RELEASE}/REVISION" >/dev/null

# storage/ e .env são partilhados entre releases.
as_app rm -rf "${RELEASE}/storage"
as_app ln -s "${SHARED}/storage" "${RELEASE}/storage"
as_app ln -sfn "$ENV_FILE" "${RELEASE}/.env"

# --- 2. Dependências e frontend ----------------------------------------------------------------
log "composer install"
(cd "$RELEASE" && as_app composer install --no-dev --optimize-autoloader \
    --no-interaction --prefer-dist --no-progress)

log "npm ci && npm run build (as VITE_* do .env entram aqui)"
(cd "$RELEASE" && as_app npm ci --no-audit --no-fund --loglevel=error && as_app npm run build)
as_app rm -rf "${RELEASE}/node_modules"

# --- 3. Caixa do code.run ----------------------------------------------------------------------
sandbox="$(env_value "$ENV_FILE" AI_CODE_SANDBOX)"
image="$(env_value "$ENV_FILE" AI_CODE_DOCKER_IMAGE)"
image="${image:-$SANDBOX_IMAGE}"
if [[ "${SKIP_SANDBOX:-0}" != "1" && "$sandbox" != "off" ]] && command -v docker >/dev/null; then
    log "Imagem ${image} (docker/sandbox)"
    docker build --quiet -t "$image" "${RELEASE}/docker/sandbox" >/dev/null ||
        warn "Não consegui construir ${image}: code.run fica indisponível até a imagem existir."
fi

# --- 4. Caches da release nova (falha aqui = nada mudou) ---------------------------------------
log "storage:link e optimize"
ARTISAN_DIR="$RELEASE" artisan storage:link --no-interaction >/dev/null
ARTISAN_DIR="$RELEASE" artisan optimize --no-interaction

# --- 5. Base de dados ----------------------------------------------------------------------------
pending=1
if status="$(ARTISAN_DIR="$RELEASE" artisan migrate:status --pending --no-ansi 2>/dev/null)"; then
    grep -q 'Pending' <<<"$status" || pending=0
fi

if [[ "$pending" == "1" ]]; then
    if [[ "${SKIP_BACKUP:-0}" != "1" ]]; then
        log "Cópia da base de dados antes das migrações"
        BACKUP_FILE="$("${DEPLOY_DIR}/backup.sh" --db-only --label "pre-deploy-${SHORT}" | tail -n 1)"
    fi
    if [[ -n "$PREVIOUS" ]]; then
        log "Modo de manutenção"
        (cd "$PREVIOUS" && as_app "$PHP_BIN" artisan down --retry=60 --refresh=15 --no-interaction)
        MAINTENANCE=1
    fi
    log "migrate --force"
    MIGRATED=1
    ARTISAN_DIR="$RELEASE" artisan migrate --force --no-interaction
else
    log "Sem migrações por correr."
fi

# --- 6. Troca ---------------------------------------------------------------------------------
log "current -> ${RELEASE}"
as_app ln -sfn "$RELEASE" "${APP_ROOT}/current.next"
as_app mv -Tf "${APP_ROOT}/current.next" "$CURRENT"
SWITCHED=1
# Só as releases que chegaram a estar no ar servem para voltar atrás (rollback.sh).
as_app touch "${RELEASE}/.deployed"
systemctl reload "$FPM_SERVICE"

# --- 7. Processos ---------------------------------------------------------------------------
log "Horizon, Reverb e agendador na release nova"
supervisorctl reread >/dev/null
supervisorctl update
artisan horizon:terminate --no-interaction
artisan reverb:restart --no-interaction
artisan queue:restart --no-interaction
supervisorctl restart micomoc:micomoc-scheduler >/dev/null

if [[ "$MAINTENANCE" == "1" ]]; then
    artisan up --no-interaction
    MAINTENANCE=0
fi

# --- 8. Verificação --------------------------------------------------------------------------
sleep 5
healthy=1
if [[ -f "/etc/letsencrypt/live/${CERT_NAME}/fullchain.pem" ]]; then
    if curl -fsS --max-time 15 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/up" >/dev/null; then
        log "https://${DOMAIN}/up responde."
    else
        healthy=0
        warn "https://${DOMAIN}/up não responde: ver ${SHARED}/storage/logs e /var/log/nginx/micomoc.error.log."
    fi
else
    warn "Sem certificado ainda (deploy/tls.sh): não verifiquei /up por HTTPS."
fi
supervisorctl status 'micomoc:*' || healthy=0

# --- 9. Limpeza: ficam as KEEP_RELEASES mais recentes que estiveram no ar ------------------------
# As que falharam antes da troca (sem .deployed) saem já; a actual e a anterior ficam sempre.
active="$(readlink -f "$CURRENT")"
kept=0
find "$RELEASES" -mindepth 1 -maxdepth 1 -type d | sort -r |
    while IFS= read -r path; do
        if [[ "$path" == "$active" || "$path" == "$PREVIOUS" ]]; then
            kept=$((kept + 1))
        elif [[ -f "${path}/.deployed" && "$kept" -lt "$KEEP_RELEASES" ]]; then
            kept=$((kept + 1))
        else
            rm -rf "$path"
        fi
    done

log "Feito: ${REF} (${SHORT}) está no ar."
if [[ -n "$PREVIOUS" ]]; then
    echo "    Anterior: ${PREVIOUS}  ->  voltar: sudo ${DEPLOY_DIR}/rollback.sh"
fi
if [[ "$healthy" != "1" ]]; then
    exit 2
fi
