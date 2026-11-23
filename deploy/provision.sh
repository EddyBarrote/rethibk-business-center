#!/usr/bin/env bash
# Prepara um Ubuntu 24.04 LTS limpo para a MICOMOC (docs/DEPLOY.md, secção 3).
#
# Instala e configura PHP-FPM, Composer, Node (só para compilar o frontend), MariaDB,
# Redis, Nginx, Supervisor, Docker (caixa do code.run), Tesseract, poppler-utils,
# certbot e rclone; cria o utilizador da aplicação, as pastas, a base de dados e o
# .env partilhado. Pode correr outra vez: só acrescenta o que falta e nunca mexe
# num .env que já exista (a APP_KEY decifra as credenciais guardadas).
#
# Uso, como root, a partir de um clone do repositório:
#   sudo DOMAIN=plataforma.rethink.co.mz LETSENCRYPT_EMAIL=ops@rethink.co.mz deploy/provision.sh
#
# Variáveis (todas opcionais menos DOMAIN; ficam em /etc/micomoc/micomoc.conf):
#   DOMAIN              domínio central: os tenants ficam em {slug}.DOMAIN, a consola em admin.DOMAIN
#   APP_ENV             production (omissão) ou staging
#   LETSENCRYPT_EMAIL   contacto do Let's Encrypt (avisos de expiração)
#   APP_USER APP_ROOT   micomoc, /var/www/micomoc
#   PHP_VERSION         8.4 (o composer.lock traz Symfony 8, que exige PHP >= 8.4.1)
#   NODE_MAJOR          22 (igual ao CI)
#   MARIADB_VERSION     11.8 (LTS, com o tipo VECTOR; docs/DECISOES.md, 14.10.2026)
#   MARIADB_FROM_UBUNTU 1 para usar o MariaDB 10.11 do Ubuntu em vez do repositório da MariaDB
#   REPO_URL DEPLOY_BRANCH
#   SWAP_SIZE           2G (0 para não criar)
#   REDIS_MAXMEMORY     1gb
#   FPM_MAX_CHILDREN    24
#   CONFIGURE_FIREWALL  1 (ufw: só SSH, 80 e 443)
#   TLS_MODE            http (um nome por tenant) ou cloudflare (certificado wildcard por DNS)
set -euo pipefail

# shellcheck source=deploy/lib.sh
source "$(cd "$(dirname "$0")" && pwd)/lib.sh"
require_root
load_config

[[ -n "${DOMAIN:-}" ]] || die "Falta DOMAIN (ex.: DOMAIN=plataforma.rethink.co.mz)."
: "${APP_ENV:=production}"
: "${LETSENCRYPT_EMAIL:=}"
: "${MARIADB_FROM_UBUNTU:=0}"
: "${MARIADB_APT_URI:=https://deb.mariadb.org/${MARIADB_VERSION}/ubuntu}"
: "${SWAP_SIZE:=2G}"
: "${REDIS_MAXMEMORY:=1gb}"
: "${CONFIGURE_FIREWALL:=1}"
: "${TLS_MODE:=http}"
export REDIS_MAXMEMORY

# shellcheck source=/dev/null
. /etc/os-release
if [[ "${VERSION_ID:-}" != "24.04" && "${FORCE_OS:-0}" != "1" ]]; then
    die "Feito para Ubuntu 24.04 LTS (encontrado: ${PRETTY_NAME:-?}). FORCE_OS=1 para tentar na mesma."
fi

export DEBIAN_FRONTEND=noninteractive
APT_OPTS=(-y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)
apt_install() { apt-get install "${APT_OPTS[@]}" --no-install-recommends "$@"; }

# --- 1. Configuração (sem segredos) ------------------------------------------------
log "A escrever ${MICOMOC_CONF}"
install -d -m 0755 "$(dirname "$MICOMOC_CONF")"
cat >"$MICOMOC_CONF" <<EOF
# Escrito por deploy/provision.sh em $(date -u +%Y-%m-%dT%H:%M:%SZ). Sem segredos: esses ficam em ${APP_ROOT}/shared/.env.
DOMAIN="${DOMAIN}"
APP_ENV="${APP_ENV}"
APP_USER="${APP_USER}"
APP_ROOT="${APP_ROOT}"
PHP_VERSION="${PHP_VERSION}"
NODE_MAJOR="${NODE_MAJOR}"
MARIADB_VERSION="${MARIADB_VERSION}"
REPO_URL="${REPO_URL}"
DEPLOY_BRANCH="${DEPLOY_BRANCH}"
DB_NAME="${DB_NAME}"
DB_USER="${DB_USER}"
BACKUP_DIR="${BACKUP_DIR}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS}"
RCLONE_REMOTE="${RCLONE_REMOTE}"
RCLONE_RETENTION_DAYS="${RCLONE_RETENTION_DAYS}"
KEEP_RELEASES="${KEEP_RELEASES}"
LOG_DIR="${LOG_DIR}"
CERT_NAME="${CERT_NAME}"
ACME_ROOT="${ACME_ROOT}"
TLS_MODE="${TLS_MODE}"
LETSENCRYPT_EMAIL="${LETSENCRYPT_EMAIL}"
SANDBOX_IMAGE="${SANDBOX_IMAGE}"
FPM_MAX_CHILDREN="${FPM_MAX_CHILDREN}"
EOF
chmod 0644 "$MICOMOC_CONF"

# --- 2. Pacotes de base, swap e fuso horário ------------------------------------------
log "Pacotes de base"
apt-get update -q
apt_install ca-certificates curl gnupg git unzip zip acl software-properties-common \
    cron logrotate rsync gzip openssl sudo ufw fail2ban unattended-upgrades

# O servidor fica em UTC; as rotinas dos agentes usam Africa/Maputo (AGENTS_SCHEDULE_TIMEZONE).
timedatectl set-timezone UTC 2>/dev/null || true

if [[ "$SWAP_SIZE" != "0" && -z "$(swapon --show=NAME --noheadings)" ]]; then
    log "A criar /swapfile (${SWAP_SIZE})"
    fallocate -l "$SWAP_SIZE" /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
fi
cat >/etc/sysctl.d/60-micomoc.conf <<'EOF'
# Redis: guardar em segundo plano sem falhar por falta de memória virtual.
vm.overcommit_memory = 1
# Usar a swap só em último caso.
vm.swappiness = 10
EOF
sysctl --system >/dev/null

install -d -m 0755 /etc/apt/keyrings

# --- 3. PHP (PPA ondrej/php: o Ubuntu 24.04 só traz o 8.3) -----------------------------
if ! grep -rqs 'ondrej/php' /etc/apt/sources.list.d/; then
    log "A acrescentar o PPA ondrej/php"
    add-apt-repository -y ppa:ondrej/php
fi

# --- 4. Node (NodeSource), só para `npm run build` ------------------------------------
if [[ ! -f /etc/apt/sources.list.d/nodesource.list ]] || ! grep -q "node_${NODE_MAJOR}.x" /etc/apt/sources.list.d/nodesource.list; then
    log "A acrescentar o repositório do Node ${NODE_MAJOR}"
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
        >/etc/apt/sources.list.d/nodesource.list
fi

# --- 5. MariaDB 11.8 (repositório da MariaDB Foundation) ------------------------------
if [[ "$MARIADB_FROM_UBUNTU" != "1" && ! -f /etc/apt/sources.list.d/mariadb.sources ]]; then
    log "A acrescentar o repositório do MariaDB ${MARIADB_VERSION}"
    curl -fsSL https://mariadb.org/mariadb_release_signing_key.pgp -o /etc/apt/keyrings/mariadb-keyring.pgp
    cat >/etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: ${MARIADB_APT_URI}
Suites: ${VERSION_CODENAME}
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOF
fi

apt-get update -q

log "PHP ${PHP_VERSION}, MariaDB, Redis, Nginx, Supervisor, Node, Docker, Tesseract, poppler, certbot"
P="php${PHP_VERSION}"
apt_install "${P}-cli" "${P}-fpm" "${P}-common" "${P}-mysql" "${P}-redis" "${P}-intl" "${P}-zip" \
    "${P}-gd" "${P}-mbstring" "${P}-bcmath" "${P}-xml" "${P}-curl" "${P}-readline" "${P}-opcache" \
    mariadb-server mariadb-client redis-server nginx supervisor nodejs \
    docker.io tesseract-ocr tesseract-ocr-por tesseract-ocr-eng poppler-utils certbot rclone
# BuildKit para `docker build`; sem ele o Docker usa o construtor antigo, que também serve.
apt_install docker-buildx 2>/dev/null || warn "docker-buildx indisponível: docker build usa o construtor antigo."
if [[ "$TLS_MODE" == "cloudflare" ]]; then
    apt_install python3-certbot-dns-cloudflare
fi

update-alternatives --set php "$PHP_BIN" >/dev/null

log "A confirmar as extensões do PHP"
missing=()
# Uma só leitura: `php -m | grep -q` com pipefail pode falhar por SIGPIPE.
modules="$("$PHP_BIN" -m)"
for ext in pcntl posix redis intl zip gd mbstring bcmath pdo_mysql dom xml simplexml xmlreader xmlwriter \
    curl fileinfo openssl tokenizer iconv zlib ctype filter sodium; do
    grep -qix "$ext" <<<"$modules" || missing+=("$ext")
done
[[ ${#missing[@]} -eq 0 ]] || die "Extensões do PHP em falta: ${missing[*]}"

node_major="$(node -p 'process.versions.node.split(".")[0]')"
[[ "$node_major" == "$NODE_MAJOR" ]] || warn "Node ${node_major} instalado; esperado ${NODE_MAJOR}."

# --- 6. Composer (instalador oficial, com a assinatura conferida) ----------------------
if ! command -v composer >/dev/null; then
    log "Composer"
    tmp="$(mktemp -d)"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
    actual="$("$PHP_BIN" -r "echo hash_file('sha384', '$tmp/composer-setup.php');")"
    [[ "$expected" == "$actual" ]] || die "Assinatura do instalador do Composer não confere."
    "$PHP_BIN" "$tmp/composer-setup.php" --quiet --2 --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp"
fi

# --- 7. Utilizador e pastas ------------------------------------------------------------
if ! id "$APP_USER" >/dev/null 2>&1; then
    log "A criar o utilizador ${APP_USER}"
    useradd --create-home --shell /bin/bash --user-group "$APP_USER"
    passwd -l "$APP_USER" >/dev/null
fi
# O code.run lança contentores (docs/DECISOES.md, «Correr código localmente»). Quem está no grupo
# docker equivale a root na máquina: ver docs/DEPLOY.md, «Caixa do code.run», sobre este risco.
usermod -aG docker "$APP_USER"

log "Pastas em ${APP_ROOT}"
install -d -m 0755 -o "$APP_USER" -g "$APP_USER" "$APP_ROOT" "$RELEASES" "$SHARED"
for d in storage storage/app storage/app/private storage/app/public storage/framework \
    storage/framework/cache storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs; do
    install -d -m 0755 -o "$APP_USER" -g "$APP_USER" "${SHARED}/${d}"
done
install -d -m 0755 "$LOG_DIR"
install -d -m 0700 "$BACKUP_DIR" "${BACKUP_DIR}/daily" "${BACKUP_DIR}/pre-deploy"
install -d -m 0755 "$ACME_ROOT"

# --- 8. MariaDB ------------------------------------------------------------------------
log "MariaDB: configuração, base de dados e utilizador"
mem_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
INNODB_BUFFER_POOL="$((mem_mb / 4))M"
export INNODB_BUFFER_POOL
install -d -m 2750 -o mysql -g adm /var/log/mysql
render "${DEPLOY_DIR}/mariadb/60-micomoc.cnf" /etc/mysql/mariadb.conf.d/60-micomoc.cnf
systemctl enable --now mariadb >/dev/null
systemctl restart mariadb

# --- 9. .env partilhado (só se ainda não existir) -------------------------------------
ENV_FILE="${SHARED}/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    log "A criar ${ENV_FILE} a partir de deploy/.env.production.example"
    install -m 0640 -o "$APP_USER" -g "$APP_USER" "${DEPLOY_DIR}/.env.production.example" "$ENV_FILE"
    set_env "$ENV_FILE" APP_ENV "$APP_ENV"
    set_env "$ENV_FILE" APP_URL "https://${DOMAIN}"
    set_env "$ENV_FILE" TENANCY_CENTRAL_DOMAIN "$DOMAIN"
    set_env "$ENV_FILE" VITE_REVERB_HOST "ws.${DOMAIN}"
    set_env "$ENV_FILE" DB_DATABASE "$DB_NAME"
    set_env "$ENV_FILE" DB_USERNAME "$DB_USER"
    set_env "$ENV_FILE" DB_PASSWORD "$(random_token 32)"
    # O mesmo formato de `php artisan key:generate`: 32 bytes aleatórios em base64.
    set_env "$ENV_FILE" APP_KEY "base64:$(openssl rand -base64 32)"
    set_env "$ENV_FILE" REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
    set_env "$ENV_FILE" REVERB_APP_KEY "$(random_token 20 | tr '[:upper:]' '[:lower:]')"
    set_env "$ENV_FILE" REVERB_APP_SECRET "$(random_token 20 | tr '[:upper:]' '[:lower:]')"
    if [[ "$APP_ENV" != "production" ]]; then
        set_env "$ENV_FILE" LOG_LEVEL debug
    fi
    ENV_CREATED=1
else
    log "${ENV_FILE} já existe: fica como está."
    ENV_CREATED=0
fi
chown "$APP_USER:$APP_USER" "$ENV_FILE"
chmod 0640 "$ENV_FILE"

db_password="$(env_value "$ENV_FILE" DB_PASSWORD)"
[[ -n "$db_password" ]] || die "DB_PASSWORD vazio em ${ENV_FILE}."
[[ "$db_password" =~ ^[A-Za-z0-9._@%+=-]+$ ]] || die "Use só letras, números e ._@%+=- em DB_PASSWORD (vai para SQL sem escapar)."
# O .env manda: cria o utilizador se faltar e acerta a palavra-passe pela do .env.
mariadb --protocol=socket -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${db_password}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${db_password}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${db_password}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${db_password}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# --- 10. Redis ---------------------------------------------------------------------------
log "Redis: filas sem despejo, com AOF"
render "${DEPLOY_DIR}/redis/micomoc.conf" /etc/redis/micomoc.conf
grep -q '^include /etc/redis/micomoc.conf' /etc/redis/redis.conf ||
    echo 'include /etc/redis/micomoc.conf' >>/etc/redis/redis.conf
systemctl enable --now redis-server >/dev/null
systemctl restart redis-server

# --- 11. PHP-FPM -------------------------------------------------------------------------
log "PHP-FPM: pool ${APP_USER}"
render "${DEPLOY_DIR}/php/micomoc-fpm.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/micomoc.conf"
render "${DEPLOY_DIR}/php/90-micomoc.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/90-micomoc.ini"
render "${DEPLOY_DIR}/php/90-micomoc.ini" "/etc/php/${PHP_VERSION}/cli/conf.d/90-micomoc.ini"
"/usr/sbin/php-fpm${PHP_VERSION}" -t 2>&1 | tail -n 1
systemctl enable --now "$FPM_SERVICE" >/dev/null
systemctl restart "$FPM_SERVICE"

# --- 12. Nginx ---------------------------------------------------------------------------
log "Nginx"
install -m 0644 "${DEPLOY_DIR}/nginx/micomoc-security-headers.conf" /etc/nginx/snippets/micomoc-security-headers.conf
rm -f /etc/nginx/sites-enabled/default
if [[ -f "/etc/letsencrypt/live/${CERT_NAME}/fullchain.pem" ]]; then
    render "${DEPLOY_DIR}/nginx/micomoc.conf" /etc/nginx/sites-available/micomoc.conf
    ln -sfn /etc/nginx/sites-available/micomoc.conf /etc/nginx/sites-enabled/micomoc.conf
    rm -f /etc/nginx/sites-enabled/micomoc-acme.conf
else
    # Ainda sem certificado: só o desafio do Let's Encrypt na porta 80 (deploy/tls.sh trata do resto).
    render "${DEPLOY_DIR}/nginx/micomoc-acme.conf" /etc/nginx/sites-available/micomoc-acme.conf
    ln -sfn /etc/nginx/sites-available/micomoc-acme.conf /etc/nginx/sites-enabled/micomoc-acme.conf
fi
nginx -t
systemctl enable --now nginx >/dev/null
systemctl reload nginx

install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
cat >/etc/letsencrypt/renewal-hooks/deploy/micomoc-reload-nginx <<'EOF'
#!/bin/sh
# Certificado renovado: o Nginx passa a servi-lo.
systemctl reload nginx
EOF
chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/micomoc-reload-nginx

# --- 13. Supervisor: Horizon, Reverb e agendador ----------------------------------------
log "Supervisor"
for f in "${DEPLOY_DIR}"/supervisor/*.conf; do
    render "$f" "/etc/supervisor/conf.d/$(basename "$f")"
done
systemctl enable --now supervisor >/dev/null
supervisorctl reread >/dev/null
if [[ -e "$CURRENT" ]]; then
    supervisorctl update
fi

# --- 14. Docker: a imagem da caixa constrói-se em cada deploy ---------------------------
systemctl enable --now docker >/dev/null

# --- 15. Cron (cópias de segurança) e rotação de registos -----------------------------
render "${DEPLOY_DIR}/cron/micomoc" /etc/cron.d/micomoc
chmod 0644 /etc/cron.d/micomoc
render "${DEPLOY_DIR}/logrotate/micomoc" /etc/logrotate.d/micomoc

# --- 16. Firewall --------------------------------------------------------------------
if [[ "$CONFIGURE_FIREWALL" == "1" ]]; then
    log "ufw: SSH, 80 e 443"
    ufw allow OpenSSH >/dev/null
    ufw allow 'Nginx Full' >/dev/null
    ufw --force enable >/dev/null
fi
systemctl enable --now fail2ban >/dev/null 2>&1 || true

log "Pronto."
cat <<EOF

Versões: $("$PHP_BIN" -r 'echo PHP_VERSION;') · $(mariadb --version | awk '{print $3, $4, $5}') · Node $(node -v) · $(redis-server --version | awk '{print $3}') · $(nginx -v 2>&1 | cut -d/ -f2) · Docker $(docker --version | awk '{print $3}' | tr -d ,)

Seguinte (docs/DEPLOY.md, secção 4):
EOF
if [[ "$ENV_CREATED" == "1" ]]; then
    echo "  1. Completar ${ENV_FILE}: email da plataforma (MAIL_*), chaves de IA, HORIZON_ALLOWED_EMAILS, Google Calendar."
    echo "     APP_KEY, DB_PASSWORD e REVERB_* foram gerados agora. Guarde a APP_KEY num cofre de palavras-passe."
else
    echo "  1. Rever ${ENV_FILE} (não foi alterado)."
fi
cat <<EOF
  2. DNS: ${DOMAIN}, admin.${DOMAIN}, ws.${DOMAIN} e os tenants (ou *.${DOMAIN}) a apontar para este servidor.
  3. Certificado:   sudo ${DEPLOY_DIR}/tls.sh http micomoc      (ou: tls.sh cloudflare)
  4. Primeiro deploy: sudo ${DEPLOY_DIR}/deploy.sh ${DEPLOY_BRANCH}
EOF
