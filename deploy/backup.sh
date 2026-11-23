#!/usr/bin/env bash
# Cópia de segurança da MICOMOC (docs/DEPLOY.md, secção 13; repor: deploy/restore.md).
#
#   sudo deploy/backup.sh                       base de dados + storage/app + .env (o cron faz isto às 01:00 UTC)
#   sudo deploy/backup.sh --db-only --label x   só a base de dados (o deploy faz isto antes das migrações)
#
# - Base de dados: mariadb-dump --single-transaction (cópia consistente sem parar a plataforma),
#   comprimida e verificada (gzip -t e a linha «Dump completed» no fim), em daily/ ou pre-deploy/.
# - Ficheiros: storage/app (anexos, emails, Conhecimento, documentos) e o .env, que tem a APP_KEY:
#   sem ela as credenciais cifradas na base de dados não se recuperam. Cada noite é uma pasta
#   storage/AAAAMMDD-HHMMSS/ completa, mas os ficheiros que não mudaram são ligações (hard links)
#   aos da noite anterior: 14 noites ocupam pouco mais do que uma. Repor é copiar de volta.
# - Retenção local: BACKUP_RETENTION_DAYS (14) dias; as cópias de antes de um deploy, 30 dias.
# - Fora do servidor (opcional): RCLONE_REMOTE (ex.: "offsite:micomoc"), configurado como root com
#   `rclone config`. Usar um remote do tipo crypt: o .env vai na cópia. Vão as cópias diárias da
#   base de dados e um espelho de storage/app; o que muda ou é apagado no espelho fica em changed/.
# A última linha impressa é o caminho da cópia da base de dados; o resto vai para stderr.
set -euo pipefail

# shellcheck source=deploy/lib.sh
source "$(cd "$(dirname "$0")" && pwd)/lib.sh"
require_root
load_config

DB_ONLY=0
LABEL=""
while [[ $# -gt 0 ]]; do
    case "$1" in
    --db-only)
        DB_ONLY=1
        shift
        ;;
    --label)
        [[ "${2:-}" =~ ^[A-Za-z0-9._-]+$ ]] || die "--label só com letras, números, . _ -"
        LABEL="$2"
        shift 2
        ;;
    *) die "Opção desconhecida: $1" ;;
    esac
done

info() { log "$@" >&2; }

ENV_FILE="${SHARED}/.env"
[[ -f "$ENV_FILE" ]] || die "Falta ${ENV_FILE}."
DB="$(env_value "$ENV_FILE" DB_DATABASE)"
DB="${DB:-$DB_NAME}"

# Uma cópia de cada vez (o deploy espera pela da noite, se calharem juntas).
exec 8>"${BACKUP_LOCK:-/run/micomoc-backup.lock}"
flock -w 900 8 || die "Outra cópia está a correr há mais de 15 minutos."

umask 077
stamp="$(date -u +%Y%m%d-%H%M%S)"
name="${stamp}${LABEL:+-${LABEL}}"
if [[ "$LABEL" == pre-deploy* ]]; then
    dir="${BACKUP_DIR}/pre-deploy"
else
    dir="${BACKUP_DIR}/daily"
fi
install -d -m 0700 "$BACKUP_DIR" "${BACKUP_DIR}/daily" "${BACKUP_DIR}/pre-deploy" "${BACKUP_DIR}/storage"

used_pct="$(df --output=pcent "$BACKUP_DIR" | tail -n 1 | tr -dc '0-9')"
if [[ "${used_pct:-0}" -ge 90 ]]; then
    warn "O disco de ${BACKUP_DIR} está a ${used_pct}%."
fi

# --- Base de dados ------------------------------------------------------------------------
db_file="${dir}/db-${name}.sql.gz"
info "Base de dados ${DB} -> ${db_file}"
mariadb-dump --protocol=socket -u root \
    --single-transaction --quick --routines --triggers --events --hex-blob \
    --default-character-set=utf8mb4 "$DB" | gzip -6 >"${db_file}.part"
gzip -t "${db_file}.part"
gzip -dc "${db_file}.part" | tail -n 1 | grep -q 'Dump completed' || die "A cópia da base de dados ficou incompleta."
mv "${db_file}.part" "$db_file"
(cd "$dir" && sha256sum "$(basename "$db_file")" >"$(basename "$db_file").sha256")

# --- storage/app e .env: uma pasta por noite, com hard links ao que não mudou --------------------
snapshot=""
if [[ "$DB_ONLY" == "0" ]]; then
    snapshot="${BACKUP_DIR}/storage/${stamp}"
    previous="$(find "${BACKUP_DIR}/storage" -mindepth 1 -maxdepth 1 -type d -name '2*' ! -name '*.part' | sort | tail -n 1)"
    info "storage/app e .env -> ${snapshot}${previous:+ (ligações a $(basename "$previous"))}"
    link_args=()
    if [[ -n "$previous" ]]; then
        link_args=(--link-dest="${previous}/app/")
    fi
    install -d -m 0700 "${snapshot}.part"
    rsync -a --delete --exclude='/sandbox/' ${link_args[@]+"${link_args[@]}"} "${SHARED}/storage/app/" "${snapshot}.part/app/"
    install -m 0600 "$ENV_FILE" "${snapshot}.part/.env"
    mv "${snapshot}.part" "$snapshot"
fi

# --- Retenção local ----------------------------------------------------------------------------
find "${BACKUP_DIR}/daily" -type f -mtime "+${BACKUP_RETENTION_DAYS}" -delete
find "${BACKUP_DIR}/pre-deploy" -type f -mtime +30 -delete
# As pastas de storage/ contam pelo nome (o rsync copia as datas dos ficheiros); fica sempre a última.
cutoff="$(date -u -d "-${BACKUP_RETENTION_DAYS} days" +%Y%m%d-%H%M%S)"
latest="$(find "${BACKUP_DIR}/storage" -mindepth 1 -maxdepth 1 -type d -name '2*' ! -name '*.part' | sort | tail -n 1)"
while IFS= read -r old; do
    if [[ "$(basename "$old")" < "$cutoff" && "$old" != "$latest" ]]; then
        rm -rf "$old"
    fi
done < <(find "${BACKUP_DIR}/storage" -mindepth 1 -maxdepth 1 -type d)

# --- Fora do servidor: só as cópias diárias ------------------------------------------------
offsite_ok=1
if [[ -n "$RCLONE_REMOTE" && -z "$LABEL" ]]; then
    remote="${RCLONE_REMOTE%/}/$(hostname -s)"
    info "rclone -> ${remote}"
    rclone copy --quiet "$db_file" "${remote}/daily" || offsite_ok=0
    rclone copy --quiet "${db_file}.sha256" "${remote}/daily" || offsite_ok=0
    rclone delete --quiet --min-age "${RCLONE_RETENTION_DAYS}d" "${remote}/daily" || offsite_ok=0
    if [[ -n "$snapshot" ]]; then
        rclone sync --quiet "$snapshot" "${remote}/storage/current" --backup-dir "${remote}/storage/changed/${stamp}" || offsite_ok=0
        rclone delete --quiet --min-age "${RCLONE_RETENTION_DAYS}d" "${remote}/storage/changed" || offsite_ok=0
        rclone rmdirs --quiet --leave-root "${remote}/storage/changed" || true
    fi
    if [[ "$offsite_ok" == "0" ]]; then
        warn "A cópia local está feita, mas o envio para ${remote} falhou."
    fi
fi

info "  $(du -h "$db_file" | cut -f1)  ${db_file}"
if [[ -n "$snapshot" ]]; then
    info "  $(du -sh "$snapshot" | cut -f1)  ${snapshot} (uma noite completa)"
    info "  $(du -sh "${BACKUP_DIR}/storage" | cut -f1)  todas as noites juntas (o que não muda conta uma vez)"
fi
if [[ "$offsite_ok" == "1" && -z "$LABEL" && "$DB_ONLY" == "0" ]]; then
    date -u +%Y-%m-%dT%H:%M:%SZ >"${BACKUP_DIR}/last-success"
fi
echo "$db_file"
[[ "$offsite_ok" == "1" ]] || exit 3
