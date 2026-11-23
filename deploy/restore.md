# Repor uma cópia de segurança

Passos para repor a base de dados, os ficheiros ou o servidor inteiro a partir das cópias de `deploy/backup.sh`. Foram escritos contra o que o script produz (nomes, formatos e pastas, ensaiados num Mac com o `mariadb-dump` e o `rclone` simulados), mas **ainda não foram feitos num servidor**: o primeiro ensaio (secção 6) faz parte da lista de entrada em produção em [`docs/DEPLOY.md`](../docs/DEPLOY.md), secção 18.

A base de desenvolvimento perdeu-se a 20.11.2026 por não haver cópia. Uma cópia que nunca foi reposta não é uma cópia: o ensaio repete-se todos os meses.

Todos os comandos correm como root (`sudo -i`), com os valores de `/etc/micomoc/micomoc.conf` (por omissão: aplicação em `/var/www/micomoc`, utilizador `micomoc`, base `micomoc`, cópias em `/var/backups/micomoc`).

## 1. O que há em cada cópia

| Onde | Conteúdo | Quando |
|---|---|---|
| `daily/db-AAAAMMDD-HHMMSS.sql.gz` (+ `.sha256`) | A base de dados inteira (`mariadb-dump --single-transaction`), sem `CREATE DATABASE`: repõe-se em qualquer nome de base. | Todas as noites, 01:00 UTC; 14 dias |
| `storage/AAAAMMDD-HHMMSS/app/` | `storage/app` dessa noite (anexos, emails brutos, Conhecimento, documentos, fotos). Os ficheiros que não mudaram são hard links para os da noite anterior: cada pasta está completa, mas o disco só guarda cada ficheiro uma vez. | Todas as noites; 14 dias |
| `storage/AAAAMMDD-HHMMSS/.env` | O `.env` dessa noite, com a **`APP_KEY`**. | Todas as noites |
| `pre-deploy/db-…-pre-deploy-<commit>.sql.gz` | A base de dados imediatamente antes das migrações de um deploy. | Em cada deploy com migrações; 30 dias |
| `last-success` | Data da última cópia diária completa (também fora do servidor, se houver). | — |

Sem a `APP_KEY` do `.env` daquela altura, as palavras-passe das caixas de correio, os tokens do ERP e as chaves de IA guardadas na base ficam ilegíveis. Por isso o `.env` vai em cada cópia, e a `APP_KEY` deve estar também num cofre de palavras-passe.

Fora do servidor (se `RCLONE_REMOTE` estiver definido, por exemplo `offsite:micomoc`), em `<remote>/<servidor>/`:

- `daily/`: as cópias da base de dados, guardadas `RCLONE_RETENTION_DAYS` (30) dias;
- `storage/current/`: espelho da última noite (`app/` e `.env`);
- `storage/changed/AAAAMMDD-HHMMSS/`: o que nessa noite foi alterado ou apagado no espelho (30 dias).

```bash
ls -lh /var/backups/micomoc/daily /var/backups/micomoc/pre-deploy
ls /var/backups/micomoc/storage
cat /var/backups/micomoc/last-success
rclone ls offsite:micomoc/$(hostname -s)/daily
```

## 2. Confirmar a cópia antes de a usar

```bash
cd /var/backups/micomoc/daily
sha256sum -c db-20261123-010000.sql.gz.sha256                           # OK
gzip -t db-20261123-010000.sql.gz && zcat db-20261123-010000.sql.gz | tail -n 1   # "-- Dump completed on …"
ls /var/backups/micomoc/storage/20261123-010000/app/private | head
```

Para trazer uma cópia de fora do servidor:

```bash
R=offsite:micomoc/<servidor>
mkdir -p /var/backups/micomoc/restore
rclone copy "$R/daily/" /var/backups/micomoc/restore/ --include 'db-20261123-010000*'
rclone copy "$R/storage/current/" /var/backups/micomoc/restore/storage/      # app/ e .env da última noite
```

Um ficheiro alterado ou apagado numa noite anterior está em `"$R/storage/changed/<noite em que mudou>/app/…"`.

## 3. Repor só a base de dados

Casos: uma migração estragou dados, um deploy tem de voltar atrás com a base, alguém apagou o que não devia.

```bash
APP=/var/www/micomoc/current
DUMP=/var/backups/micomoc/pre-deploy/db-20261123-140210-pre-deploy-93b9899.sql.gz   # ou uma diária

# 1) Fotografar o estado actual, mesmo estragado (pode fazer falta).
$APP/deploy/backup.sh --db-only --label antes-de-repor

# 2) Parar a escrita: manutenção, filas e agendador.
sudo -u micomoc php $APP/artisan down --retry=60
supervisorctl stop 'micomoc:*'

# 3) Base nova e vazia, com a cópia lá dentro.
mariadb -e 'DROP DATABASE micomoc; CREATE DATABASE micomoc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
zcat "$DUMP" | mariadb micomoc

# 4) Se o código no ar é de uma versão posterior à cópia e as migrações dela mudaram o que a
#    cópia tem, voltar primeiro ao código dessa altura (deploy/rollback.sh). Confirmar:
sudo -u micomoc php $APP/artisan migrate:status | tail -n 5

# 5) O que estava nas filas e na cache refere-se ao estado que se deitou fora.
for q in agents-high agents agents-low email embeddings default; do   # trabalhos à espera
  sudo -u micomoc php $APP/artisan horizon:clear --queue=$q --force
done
sudo -u micomoc php $APP/artisan cache:clear
sudo -u micomoc php $APP/artisan schedule:clear-cache

# 6) Voltar a pôr no ar.
supervisorctl start 'micomoc:*'
sudo -u micomoc php $APP/artisan up
```

Depois: entrar na plataforma, abrir **Definições › Estado da configuração** e carregar em «Verificar de novo»; confirmar que as últimas conversas e aprovações são as esperadas para a hora da cópia. Tudo o que entrou depois da cópia (emails lidos, aprovações, execuções) perdeu-se na plataforma; os emails continuam nas caixas do Hostinger, mas o `mail:fetch` não volta a ler os que já tinha marcado como lidos, por isso convém avisar quem trata deles.

## 4. Repor só ficheiros

Um ficheiro ou uma pasta (por exemplo, um anexo apagado): copiar da noite certa, sem tocar no resto.

```bash
S=/var/backups/micomoc/storage/20261123-010000
rsync -a --ignore-existing "$S/app/private/attachments/2404/" /var/www/micomoc/shared/storage/app/private/attachments/2404/
chown -R micomoc:micomoc /var/www/micomoc/shared/storage/app/private/attachments/2404
```

Tudo o `storage/app` (depois de perder o disco, por exemplo):

```bash
rsync -a --delete "$S/app/" /var/www/micomoc/shared/storage/app/
chown -R micomoc:micomoc /var/www/micomoc/shared/storage/app
```

Ficheiros e base de dados devem ser da mesma noite: um registo sem ficheiro mostra «ficheiro em falta», um ficheiro sem registo não se vê.

## 5. Servidor novo, do zero

Quando o servidor se perdeu. Precisa de: a última cópia (base de dados e `storage/current` de fora do servidor), acesso ao DNS e o repositório.

1. Servidor Ubuntu 24.04 com o mesmo tamanho ([`docs/DEPLOY.md`](../docs/DEPLOY.md), secção 1). Apontar o DNS para o IP novo.
2. Pôr primeiro o `.env` e os ficheiros: o `.env` tem de ser o da cópia, com a `APP_KEY` antiga.
   ```bash
   apt update && apt -y install rclone && rclone config        # o mesmo remote (e a mesma senha do crypt)
   R=offsite:micomoc/<servidor-antigo>
   mkdir -p /var/www/micomoc/shared/storage /root/restore
   rclone copy "$R/storage/current/" /var/www/micomoc/shared/storage/ --exclude '/.env'
   rclone copy "$R/storage/current/.env" /var/www/micomoc/shared/
   rclone copy "$R/daily/" /root/restore/ --include 'db-20261123-010000*'
   ```
   (`storage/current/` tem `app/` e `.env`; o `app/` fica em `shared/storage/app/`.)
3. Instalar: o `provision.sh` vê que o `.env` existe, não lhe toca e cria o utilizador da base com a `DB_PASSWORD` dele.
   ```bash
   git clone https://github.com/EddyBarrote/rethibk-business-center.git /root/micomoc-kit
   cd /root/micomoc-kit
   DOMAIN=plataforma.rethink.co.mz LETSENCRYPT_EMAIL=… deploy/provision.sh
   ```
4. Repor a base de dados (base vazia acabada de criar) e as permissões:
   ```bash
   sha256sum -c /root/restore/db-20261123-010000.sql.gz.sha256
   zcat /root/restore/db-20261123-010000.sql.gz | mariadb micomoc
   chown -R micomoc:micomoc /var/www/micomoc/shared
   ```
5. Certificado e a mesma versão que estava no ar (o commit está no `REVISION` de cada release e em `/var/log/micomoc/deploy.log` do servidor antigo; na dúvida, a tag da última entrega):
   ```bash
   deploy/tls.sh http micomoc            # ou: deploy/tls.sh cloudflare
   deploy/deploy.sh <commit-ou-tag>
   ```
   O deploy não encontra migrações por correr (a cópia já as tem) e não entra em manutenção.
6. Provar: `deploy/status.sh`, `deploy/sandbox-smoke.sh`, entrar como proprietário, **Estado da configuração** › Verificar de novo, enviar um email de teste para a caixa da Triagem. Em `/etc/micomoc/micomoc.conf`, `RCLONE_REMOTE` outra vez.

## 6. Ensaio mensal (sem tocar na plataforma)

Repõe a cópia da noite numa base à parte, compara com a verdadeira e apaga. Uns 10 minutos.

```bash
D=/var/backups/micomoc/daily
LAST=$(ls -1 $D/db-*.sql.gz | tail -n 1); STAMP=$(basename "$LAST" .sql.gz); STAMP=${STAMP#db-}
sha256sum -c "$LAST.sha256"

mariadb -e 'CREATE DATABASE micomoc_ensaio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
zcat "$LAST" | mariadb micomoc_ensaio
for t in tenants users agents agent_runs approvals email_messages knowledge_items audit_logs; do
  printf '%-16s cópia=%s  agora=%s\n' "$t" \
    "$(mariadb -N -e "SELECT COUNT(*) FROM micomoc_ensaio.$t")" \
    "$(mariadb -N -e "SELECT COUNT(*) FROM micomoc.$t")"
done
mariadb -e 'DROP DATABASE micomoc_ensaio;'

# Os ficheiros da noite estão lá e o .env tem a mesma APP_KEY que o de agora?
S=/var/backups/micomoc/storage/$STAMP
du -sh "$S/app"; du -sh /var/www/micomoc/shared/storage/app
grep '^APP_KEY=' "$S/.env" | sha256sum
grep '^APP_KEY=' /var/www/micomoc/shared/.env | sha256sum

# A cópia de fora do servidor está lá?
rclone ls offsite:micomoc/$(hostname -s)/daily | grep "$STAMP"
rclone size offsite:micomoc/$(hostname -s)/storage/current
```

As contagens da cópia ficam iguais ou um pouco abaixo das de agora (o que entrou desde a noite), as duas somas da `APP_KEY` são iguais e o tamanho fora do servidor é parecido com o de `storage/app`. Registar a data e o resultado no registo de aceitação; se alguma coisa falhar, tratar antes de mais nada.
