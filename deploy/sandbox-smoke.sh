#!/usr/bin/env bash
# Prova a caixa do code.run neste servidor (docs/DEPLOY.md, secção 8). Corre depois do primeiro deploy.
#
#   sudo deploy/sandbox-smoke.sh
#
# 1. A imagem existe (constrói-a de docker/sandbox se faltar).
# 2. Com as mesmas opções de App\Ai\Code\Sandbox::docker(): as bibliotecas carregam, o código
#    corre como nobody, escreve só na pasta de trabalho, não tem rede nem segredos no ambiente.
# 3. O caminho completo pela aplicação, como o utilizador dela (grupo docker, /tmp, imagem).
# Sai com 1 se alguma prova falhar.
set -euo pipefail

# shellcheck source=deploy/lib.sh
source "$(cd "$(dirname "$0")" && pwd)/lib.sh"
require_root
load_config

ENV_FILE="${SHARED}/.env"
image="$(env_value "$ENV_FILE" AI_CODE_DOCKER_IMAGE)"
image="${image:-$SANDBOX_IMAGE}"
memory="$(env_value "$ENV_FILE" AI_CODE_MEMORY_MB)"
memory="${memory:-1024}"

command -v docker >/dev/null || die "Docker não está instalado (deploy/provision.sh)."
[[ "$(env_value "$ENV_FILE" AI_CODE_SANDBOX)" != "off" ]] || warn "AI_CODE_SANDBOX=off no .env: a aplicação não vai usar a caixa."

if ! docker image inspect "$image" >/dev/null 2>&1; then
    [[ -d "${CURRENT}/docker/sandbox" ]] || die "Sem imagem ${image} e sem ${CURRENT}/docker/sandbox para a construir."
    log "A construir ${image}"
    docker build -t "$image" "${CURRENT}/docker/sandbox"
fi

work="$(mktemp -d /tmp/micomoc-smoke-XXXXXX)"
chmod 0777 "$work"
trap 'rm -rf "$work"' EXIT

failures=0
box() {
    docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
        --memory "${memory}m" --cpus 1 --pids-limit 64 --user 65534:65534 --tmpfs /tmp:size=64m \
        -v "${work}:/work" -w /work -e HOME=/work "$image" python -I -c "$1" 2>&1
}
expect() {
    local label="$1" code="$2" want="$3" out
    out="$(box "$code" || true)"
    if [[ "$out" == *"$want"* ]]; then
        printf '  ok     %s\n' "$label"
    else
        printf '  FALHOU %s\n         esperado: %s\n         obtido:   %s\n' "$label" "$want" "${out//$'\n'/ | }"
        failures=$((failures + 1))
    fi
}

log "Contentor (${image})"
expect "pandas, numpy e openpyxl" 'import pandas, numpy, openpyxl; print("libs-ok")' 'libs-ok'
expect "corre como nobody" 'import os; print("uid", os.getuid())' 'uid 65534'
expect "escreve na pasta de trabalho" 'open("/work/prova.txt", "w").write("x"); print("write-ok")' 'write-ok'
expect "não escreve fora dela" '
try:
    open("/etc/prova", "w")
    print("escreveu")
except OSError:
    print("ro-ok")' 'ro-ok'
expect "sem rede" '
import socket
try:
    socket.create_connection(("1.1.1.1", 53), 3)
    print("com rede")
except OSError:
    print("net-ok")' 'net-ok'
expect "sem segredos no ambiente" '
import os
print("env", sorted(k for k in os.environ if any(s in k for s in ("KEY", "SECRET", "PASSWORD", "TOKEN"))))' 'env []'

log "Pela aplicação, como ${APP_USER}"
[[ -L "$CURRENT" ]] || die "Ainda não há release no ar: faça o primeiro deploy."
php_code="$(
    cat <<'PHP'
$s = app(App\Ai\Code\Sandbox::class);
echo 'driver=', $s->driver() ?? 'nenhum', PHP_EOL;
$r = $s->run("import pandas as pd\nprint('soma', int(pd.DataFrame({'a': [1, 2, 3]})['a'].sum()))", []);
echo 'stdout=', trim($r['stdout']), PHP_EOL, 'exit=', var_export($r['exit'], true), PHP_EOL, 'stderr=', trim($r['stderr']), PHP_EOL;
PHP
)"
out="$(artisan tinker --execute="$php_code" 2>&1 || true)"
while IFS= read -r line; do printf '    %s\n' "$line"; done <<<"$out"
if [[ "$out" == *"driver=docker"* && "$out" == *"stdout=soma 6"* && "$out" == *"exit=0"* ]]; then
    printf '  ok     code.run pela aplicação\n'
else
    printf '  FALHOU code.run pela aplicação (esperado driver=docker, stdout=soma 6, exit=0)\n'
    failures=$((failures + 1))
fi

if [[ "$failures" -gt 0 ]]; then
    die "${failures} prova(s) falharam. Sem caixa, AI_CODE_SANDBOX=off e os agentes usam files.analyze."
fi
log "A caixa do code.run está pronta."
