# shellcheck shell=bash
# Funções comuns aos scripts de deploy/ (docs/DEPLOY.md). Não se corre sozinho:
# cada script faz `source "$(dirname "$0")/lib.sh"`.
#
# Configuração: /etc/micomoc/micomoc.conf (escrito pelo provision.sh, sem segredos).
# Uma variável já definida no ambiente ganha ao ficheiro:
#   sudo DOMAIN=outro.exemplo deploy/tls.sh http micomoc

MICOMOC_CONF="${MICOMOC_CONF:-/etc/micomoc/micomoc.conf}"
# Usado pelos scripts que fazem source deste ficheiro.
# shellcheck disable=SC2034
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[aviso]\033[0m %s\n' "$*" >&2; }
die() {
    printf '\033[1;31m[erro]\033[0m %s\n' "$*" >&2
    exit 1
}

require_root() {
    [[ "$(id -u)" -eq 0 ]] || die "Corra como root (sudo $0 …)."
}

# Lê KEY="valor" do ficheiro de configuração sem o executar.
load_config() {
    local key value
    if [[ -r "$MICOMOC_CONF" ]]; then
        while IFS='=' read -r key value; do
            [[ "$key" =~ ^[A-Z_][A-Z0-9_]*$ ]] || continue
            [[ -n "${!key+x}" ]] && continue
            value="${value%\"}"
            value="${value#\"}"
            export "$key=$value"
        done < <(grep -E '^[A-Z_][A-Z0-9_]*=' "$MICOMOC_CONF")
    fi

    : "${APP_USER:=micomoc}"
    : "${APP_ROOT:=/var/www/micomoc}"
    : "${PHP_VERSION:=8.4}"
    : "${NODE_MAJOR:=22}"
    : "${MARIADB_VERSION:=11.8}"
    : "${REPO_URL:=https://github.com/EddyBarrote/rethibk-business-center.git}"
    : "${DEPLOY_BRANCH:=main}"
    : "${DB_NAME:=micomoc}"
    : "${DB_USER:=micomoc}"
    : "${BACKUP_DIR:=/var/backups/micomoc}"
    : "${BACKUP_RETENTION_DAYS:=14}"
    : "${RCLONE_REMOTE:=}"
    : "${RCLONE_RETENTION_DAYS:=30}"
    : "${KEEP_RELEASES:=5}"
    : "${LOG_DIR:=/var/log/micomoc}"
    : "${CERT_NAME:=micomoc}"
    : "${ACME_ROOT:=/var/www/letsencrypt}"
    : "${SANDBOX_IMAGE:=micomoc-sandbox}"
    : "${REVERB_LOCAL_PORT:=8080}"
    : "${FPM_MAX_CHILDREN:=24}"
    : "${DEPLOY_LOCK:=/var/lock/micomoc-deploy.lock}"

    PHP_BIN="${PHP_BIN:-/usr/bin/php${PHP_VERSION}}"
    FPM_SERVICE="php${PHP_VERSION}-fpm"
    FPM_SOCK="/run/php/php${PHP_VERSION}-fpm-micomoc.sock"
    SHARED="${APP_ROOT}/shared"
    RELEASES="${APP_ROOT}/releases"
    CURRENT="${APP_ROOT}/current"
    REPO_MIRROR="${APP_ROOT}/repo.git"
    export PHP_BIN FPM_SERVICE FPM_SOCK SHARED RELEASES CURRENT REPO_MIRROR
}

# Corre um comando como o utilizador da aplicação, com o HOME dele.
as_app() {
    sudo -u "$APP_USER" -H -- "$@"
}

# Corre `php artisan …` numa release (por omissão a actual), como o utilizador da aplicação.
artisan() {
    local dir="${ARTISAN_DIR:-$CURRENT}"
    (cd "$dir" && as_app "$PHP_BIN" artisan "$@")
}

# Copia um modelo de deploy/ para o destino, trocando os @@MARCADORES@@.
render() {
    local src="$1" dest="$2"
    local tmp
    tmp="$(mktemp)"
    sed -e "s|@@DOMAIN@@|${DOMAIN:-}|g" \
        -e "s|@@APP_ROOT@@|${APP_ROOT}|g" \
        -e "s|@@APP_USER@@|${APP_USER}|g" \
        -e "s|@@PHP_VERSION@@|${PHP_VERSION}|g" \
        -e "s|@@PHP_BIN@@|${PHP_BIN}|g" \
        -e "s|@@FPM_SOCK@@|${FPM_SOCK}|g" \
        -e "s|@@FPM_MAX_CHILDREN@@|${FPM_MAX_CHILDREN}|g" \
        -e "s|@@LOG_DIR@@|${LOG_DIR}|g" \
        -e "s|@@CERT_NAME@@|${CERT_NAME}|g" \
        -e "s|@@ACME_ROOT@@|${ACME_ROOT}|g" \
        -e "s|@@REVERB_LOCAL_PORT@@|${REVERB_LOCAL_PORT}|g" \
        -e "s|@@INNODB_BUFFER_POOL@@|${INNODB_BUFFER_POOL:-1G}|g" \
        -e "s|@@REDIS_MAXMEMORY@@|${REDIS_MAXMEMORY:-1gb}|g" \
        "$src" >"$tmp"
    if grep -q '@@[A-Z_]*@@' "$tmp"; then
        rm -f "$tmp"
        die "Ficou um marcador por trocar em $src."
    fi
    install -m 0644 "$tmp" "$dest"
    rm -f "$tmp"
}

# Valor de uma linha KEY=valor de um ficheiro .env (sem aspas à volta).
env_value() {
    local file="$1" key="$2" line
    line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
    line="${line#*=}"
    line="${line%\"}"
    line="${line#\"}"
    printf '%s' "$line"
}

# Escreve KEY=valor no .env, substituindo a linha que exista ou acrescentando-a.
set_env() {
    local file="$1" key="$2" value="$3"
    if grep -qE "^${key}=" "$file"; then
        sed -i -e "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >>"$file"
    fi
}

random_token() {
    # Só letras e números: serve em .env, SQL e URLs sem escapar nada.
    openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "${1:-32}"
}
