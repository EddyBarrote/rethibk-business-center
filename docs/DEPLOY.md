# Instalação em produção

Como pôr a plataforma num servidor (produção e staging), mantê-la, actualizá-la e recuperá-la. Os scripts estão em [`deploy/`](../deploy); as decisões, em [DECISOES.md](DECISOES.md#instalação-em-produção-23112026).

Resumo do que fica no servidor: **Ubuntu 24.04 LTS** com **PHP 8.4** (PHP-FPM), **MariaDB 11.8**, **Redis**, **Nginx** com Let's Encrypt, **Supervisor** (Horizon, Reverb e o agendador), **Docker** (só para a caixa do `code.run`), Tesseract e poppler-utils (leitura de PDFs e imagens). Node 22 só serve para compilar o frontend em cada deploy.

```
browser ── https://{empresa}.<central> ─┐
        ── https://admin.<central> ─────┤  Nginx ── PHP-FPM (pool micomoc) ── Laravel
        ── wss://ws.<central> ──────────┘    │                                  │
                                             └─ 127.0.0.1:8080 Reverb          ├─ MariaDB 127.0.0.1:3306
Supervisor: Horizon (33 processos) · Reverb · schedule:work                    ├─ Redis 127.0.0.1:6379 (filas, cache, sessões)
cron: deploy/backup.sh às 01:00 UTC                                            └─ Docker (code.run, sem rede)
Saídas: IMAP 993 / SMTP 465 (Hostinger), HTTPS (IA, ERP por MCP, Google)
```

| Script | Para quê |
|---|---|
| `deploy/provision.sh` | Prepara um Ubuntu 24.04 limpo (uma vez; pode repetir-se). |
| `deploy/tls.sh` | Certificado do Let's Encrypt e o Nginx completo. |
| `deploy/deploy.sh <ref>` | Publica uma versão (ramo, tag ou commit). |
| `deploy/rollback.sh` | Volta à versão anterior. |
| `deploy/backup.sh` | Cópia de segurança (o cron corre-a todas as noites). Repor: [`deploy/restore.md`](../deploy/restore.md). |
| `deploy/sandbox-smoke.sh` | Prova a caixa do `code.run`. |
| `deploy/status.sh` | Estado de tudo num relance. |

Todos correm como root (`sudo`), lêem `/etc/micomoc/micomoc.conf` (escrito pelo `provision.sh`, sem segredos) e aceitam as mesmas variáveis no ambiente. Os segredos ficam só em `/var/www/micomoc/shared/.env`.

## 1. Tamanho do servidor

Para uma empresa de cerca de 50 pessoas:

| | Mínimo | Recomendado |
|---|---|---|
| CPU | 4 vCPU | 4 vCPU |
| Memória | 8 GB (+ 2 GB de swap, criada pelo provision) | 16 GB |
| Disco | 100 GB NVMe | 200 GB NVMe |
| Exemplo | — | Hostinger VPS KVM 4 (confirmar o plano no dia) |

Porquê: o Horizon corre 33 processos em produção (`config/horizon.php`: agents-high 5, agents 10, agents-low 3, email 10, embeddings 3, default 2), cerca de 2,5 GB; o MariaDB fica com um quarto da memória; o Redis até 1 GB; o PHP-FPM até 24 processos; cada `code.run` pode usar até 1 GB enquanto corre; o `npm run build` de cada deploy chega a 1,5 GB. O CPU passa quase todo o tempo à espera dos provedores de IA, excepto na leitura de PDFs digitalizados (Tesseract) e no build. No disco contam o email bruto (365 dias por omissão), os anexos, o repositório de ficheiros (até 100 MB por ficheiro), 5 releases, a imagem da caixa e as cópias de 14 noites (os ficheiros que não mudam ocupam uma vez só).

**Staging:** 2 vCPU, 8 GB, 80 GB chegam (o Horizon de staging corre 10 processos).

Escolher o centro de dados com menos latência para Maputo entre os que o fornecedor tem; produção e staging no mesmo.

## 2. DNS e certificados

Os exemplos usam `plataforma.rethink.co.mz` como domínio central (`TENANCY_CENTRAL_DOMAIN`). Cada empresa fica em `{slug}.plataforma.rethink.co.mz`, a consola do super admin em `admin.…` e o Reverb em `ws.…` (o slug `ws` fica reservado). O domínio central sozinho responde só a `/up` e ao regresso do Google (`/oauth/google/callback`); o resto dá 404.

### Registos de DNS

| Nome | Tipo | Valor |
|---|---|---|
| `plataforma` | A | IP do servidor |
| `*.plataforma` | A | IP do servidor |

O registo wildcard (`*`) cobre `admin`, `ws` e todas as empresas, presentes e futuras, sem mexer no DNS de cada vez. Se o fornecedor de DNS não aceitar wildcards, criar um registo A para `admin.plataforma`, `ws.plataforma` e cada empresa. Com IPv6, os mesmos em AAAA. Os registos de email do domínio (MX, SPF, DKIM) não se tocam.

### Certificado: duas opções

| | **A. Wildcard por DNS** (`tls.sh cloudflare`) | **B. Um nome por empresa** (`tls.sh http …`) |
|---|---|---|
| Certificado | `plataforma…` e `*.plataforma…` | `plataforma…`, `admin.…`, `ws.…` e `{slug}.…` de cada empresa |
| Desafio | DNS-01: o certbot escreve um TXT no DNS | HTTP-01: o Let's Encrypt lê um ficheiro na porta 80 |
| Precisa | DNS na Cloudflare e um token com permissão de editar o DNS, guardado no servidor | Só o DNS acima a apontar para o servidor; qualquer fornecedor (Hostinger incluído) |
| Empresa nova | Nada | `sudo deploy/tls.sh http <slug>` (segundos; os nomes que já lá estavam ficam) |
| Renovação | Automática | Automática |

**Recomendação para a entrega da MICOMOC: opção B**, com o registo wildcard no DNS. Há uma ou duas empresas, o DNS fica onde está (Hostinger) e não fica nenhum token de DNS no servidor. Passar à opção A quando houver mais do que umas poucas empresas ou o DNS for para a Cloudflare: `sudo deploy/tls.sh cloudflare` troca o certificado sem mais nada.

O Nginx (`deploy/nginx/micomoc.conf`) é o mesmo nas duas: `server_name .plataforma…` apanha o domínio central e todos os subdomínios, e `ws.plataforma…` tem o seu bloco à frente do Reverb.

### Domínio próprio de um tenant

Uma empresa pode ter o seu próprio endereço (`tenants.domain`, por exemplo `agentes.micomoc.co.mz`), definido em Super admin › Organizações › Perfil › Domínio próprio. Precisa de: um registo A desse nome para o servidor, um certificado próprio (`certbot certonly --webroot -w /var/www/letsencrypt --cert-name micomoc-dominio -d agentes.micomoc.co.mz`) e um `server` no Nginx igual ao da plataforma, com esse `server_name` e esse certificado (copiar o bloco de `micomoc.conf` para `/etc/nginx/sites-available/micomoc-dominios.conf`). O Reverb continua em `ws.<central>`; o login nesse domínio tem sessão própria.

## 3. Instalar o servidor (`provision.sh`)

O que faz, por esta ordem, e sem nunca escrever por cima de um `.env` que exista:

1. `/etc/micomoc/micomoc.conf` com os parâmetros (domínio, utilizador, pastas, versões, cópias).
2. Pacotes de base, swap de 2 GB, fuso UTC (as rotinas dos agentes usam `Africa/Maputo`), `vm.overcommit_memory=1` para o Redis.
3. Repositórios: PPA `ondrej/php` (o Ubuntu 24.04 só traz o PHP 8.3 e o `composer.lock` tem Symfony 8, que exige 8.4.1), NodeSource 22, MariaDB 11.8 (`deb.mariadb.org`).
4. PHP 8.4 com `cli fpm mysql redis intl zip gd mbstring bcmath xml curl readline opcache` (e confirma `pcntl`, `posix` e as restantes que o `composer.lock` pede), MariaDB, Redis, Nginx, Supervisor, Node, `docker.io`, `tesseract-ocr` com português e inglês, `poppler-utils`, certbot, rclone, ufw e fail2ban. Composer pelo instalador oficial, com a assinatura conferida.
5. Utilizador `micomoc` (sem palavra-passe, no grupo `docker`) e as pastas `/var/www/micomoc/{releases,shared}`, `/var/log/micomoc`, `/var/backups/micomoc`.
6. MariaDB: `60-micomoc.cnf` (só 127.0.0.1, utf8mb4, buffer a 1/4 da RAM, registo de consultas lentas), base `micomoc` e utilizador `micomoc`.
7. `shared/.env` a partir de `deploy/.env.production.example`, com `APP_URL`, domínio, `DB_*`, `APP_KEY` e `REVERB_APP_*` já gerados.
8. Redis sem despejo e com AOF (as filas não se perdem num reinício); pool do PHP-FPM; Nginx só com o desafio do Let's Encrypt até haver certificado; Supervisor; cron das cópias; logrotate; ufw (SSH, 80 e 443).

```bash
sudo DOMAIN=plataforma.rethink.co.mz LETSENCRYPT_EMAIL=ops@rethink.co.mz deploy/provision.sh
```

Outras variáveis: `APP_ENV=staging`, `MARIADB_FROM_UBUNTU=1` (MariaDB 10.11 do Ubuntu, se o repositório da MariaDB falhar; a pesquisa por vectores continua em PHP e funciona), `CONFIGURE_FIREWALL=0`, `SWAP_SIZE=0`, `TLS_MODE=cloudflare`, `REPO_URL` (repositório privado: chave de deploy em `~micomoc/.ssh` e `REPO_URL=git@github.com:…`). A lista está no topo do script.

## 4. Primeira instalação, passo a passo

Antes: um VPS Ubuntu 24.04 com acesso root por chave SSH, os registos de DNS da secção 2, uma caixa do Hostinger para o email da própria plataforma (por exemplo `plataforma@rethink.co.mz`), as chaves de IA, o endereço MCP e o token do ERP da MICOMOC e, se for para usar, o cliente OAuth do Google (secção 12).

```bash
ssh root@<ip>
apt update && apt -y upgrade && reboot          # se pedir reinício
git clone https://github.com/EddyBarrote/rethibk-business-center.git /root/micomoc-kit
cd /root/micomoc-kit

# 1. Servidor
DOMAIN=plataforma.rethink.co.mz LETSENCRYPT_EMAIL=ops@rethink.co.mz deploy/provision.sh

# 2. Segredos e email da plataforma
nano /var/www/micomoc/shared/.env               # ver a tabela abaixo

# 3. Certificado (opção B, com a primeira empresa)
deploy/tls.sh http micomoc

# 4. Primeira versão (de preferência uma tag)
deploy/deploy.sh main
```

O que completar no `.env` (o resto já vem certo; cada bloco está comentado no ficheiro):

| Bloco | Variáveis | Notas |
|---|---|---|
| Email da plataforma | `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Recuperação de palavra-passe e avisos. Hostinger: `smtp.hostinger.com`, 465, `smtps` (já preenchidos). |
| Horizon | `HORIZON_ALLOWED_EMAILS` | Quem abre `/horizon`. |
| IA | `GEMINI_API_KEY` e as outras que se usem | Ou por empresa, em Definições (secção 11). O Gemini é obrigatório para os vectores do Conhecimento. |
| Google | `GOOGLE_CALENDAR_CLIENT_ID`, `…_SECRET` | Opcional (secção 12). |
| Proxy | `TRUSTED_PROXIES` | Só com Cloudflare em modo proxy ou um balanceador à frente. |

**Guardar a `APP_KEY`** num cofre de palavras-passe, acessível a duas pessoas. Ela decifra as palavras-passe das caixas, os tokens do ERP e as chaves de IA guardadas na base de dados; perdê-la é perder esses dados, mesmo com cópias.

O primeiro deploy compila tudo, corre as migrações (sem seed: o `db:seed` cria dados de demonstração com a palavra-passe `password` e **nunca** se corre em produção) e arranca o Horizon, o Reverb e o agendador. No fim:

```bash
deploy/status.sh                                # serviços, processos, /up, certificado, cópias
deploy/sandbox-smoke.sh                         # secção 8
curl -s https://plataforma.rethink.co.mz/up     # ok
```

## 5. Super admin e primeira empresa

Os comandos correm na release no ar, como o utilizador da aplicação:

```bash
cd /var/www/micomoc/current
alias art='sudo -u micomoc php artisan'

# Operador da Rethink (pede a palavra-passe, mínimo 12 caracteres)
art admin:create ops@rethink.co.mz --name="Equipa Rethink"

# A empresa e o seu proprietário (pede a palavra-passe, mínimo 8)
art tenant:create "MICOMOC" micomoc --owner-name="Nome Apelido" --owner-email=direccao@micomoc.co.mz

# Os seis agentes da proposta, em rascunho (não correm até serem activados um a um)
art agents:install-templates micomoc --draft

# Depois de ligar o ERP (secção 6): as ferramentas do ERP entram no catálogo de capacidades
art capabilities:sync micomoc
```

A empresa também se cria na consola (`https://admin.plataforma…` › Organizações › Nova). Lá se completa o perfil: denominação social, NUIT, contacto, **domínio das caixas** (por exemplo `agentes.micomoc.co.mz`), retenção do email bruto, **orçamento de IA** (secção 11) e regras de negócio (SLA, margem mínima, alerta de orçamento, pré-aviso de contratos). Os modelos de agentes também se instalam aí (Modelos de agentes).

Depois, como proprietário em `https://micomoc.plataforma…`: Definições › Departamentos, Utilizadores (cada pessoa com o papel certo e a quem reporta) e Papéis e acessos.

Só num servidor que tenha o tenant de testes da Rethink (staging): `RETHINK_ERP_URL`, `RETHINK_ERP_TOKEN` e `RETHINK_OWNER_PASSWORD` no `.env` e `art db:seed --class=RethinkSeeder --force`. No servidor da MICOMOC, nunca.

## 6. Ligar o ERP

Como proprietário ou administrador: **Definições › Integrações › ERP** › transporte «web» (MCP por HTTPS), o endereço MCP do ERP (`https://…/mcp`) e o token que a equipa do ERP entregar (fica cifrado). «Testar ligação» deve dizer quantas ferramentas há. Depois, no servidor:

```bash
art erp:tools micomoc                           # lista as ferramentas, só leitura
art capabilities:sync micomoc                   # ferramentas do ERP no catálogo
```

Não correr `erp:smoke` contra o ERP real: escreve no ERP (é a prova da E01 contra o ERP falso). O `erp:health-check` corre de 15 em 15 minutos e o Estado da configuração mostra o resultado.

O token deve ter só as permissões de que os agentes precisam; as acções do tecto absoluto (pagamentos, facturas, contratos, RH) pedem sempre uma pessoa, seja qual for o token (`config/autonomy.php`). As variáveis `ERP_MCP_*` do `.env` só servem uma empresa sem ligação própria; em produção, `ERP_MCP_TRANSPORT=web` (o `local` arranca o ERP falso e é só para staging).

## 7. Email (Hostinger)

- **Caixas dos agentes:** Super admin › agente › Caixa de correio. IMAP `imap.hostinger.com` 993 SSL, SMTP `smtp.hostinger.com` 465 SSL (já sugeridos no formulário), endereço `{local}@{domínio das caixas}`, palavra-passe da caixa (cifrada), activar. As caixas têm de existir antes no hPanel do Hostinger.
- **Caixas das pessoas e partilhadas** (financas@, geral@): Definições › Caixas de email. Cada dono escolhe que agentes a lêem.
- **Leitura:** `mail:fetch` a cada minuto pelo agendador (`/var/log/micomoc/scheduler.log`). O Estado da configuração testa o login IMAP de cada caixa.
- **Envio:** cada caixa envia pelo seu SMTP. No DNS do domínio de email (hPanel): SPF com o Hostinger (`include:_spf.mail.hostinger.com`), DKIM activo e um DMARC (`p=none` para começar), senão os emails dos agentes vão para spam.
- O servidor precisa de saída para as portas 993 e 465 (a maioria dos VPS só bloqueia a 25).

## 8. Caixa do code.run

`code.run` corre o Python que um agente escreve (contas sobre tabelas anexadas) neste servidor, num contentor Docker: sem rede, sistema de ficheiros só de leitura, como `nobody`, com limites de memória (1 GB), CPU (1), processos (64) e tempo (60 s), numa pasta temporária só com os anexos (`App\Ai\Code\Sandbox`). A imagem `micomoc-sandbox` (`docker/sandbox`: Python 3.13 com pandas, numpy e openpyxl) é construída em cada deploy.

**Prova, depois do primeiro deploy:**

```bash
sudo deploy/sandbox-smoke.sh
```

Seis provas no contentor (bibliotecas, `nobody`, escreve só na pasta de trabalho, sem rede, sem segredos no ambiente) e uma pela própria aplicação, como `micomoc` (`Sandbox::run()` via `tinker`), que tem de devolver `driver=docker`, `stdout=soma 6`, `exit=0`. Este caminho nunca tinha sido experimentado (o Mac de desenvolvimento não tem Docker): é a primeira coisa a confirmar no servidor.

**O risco:** para lançar contentores, o utilizador `micomoc` está no grupo `docker`, e quem está no grupo `docker` consegue ser root na máquina. Quem conseguisse correr código como `micomoc` (uma falha no PHP) já teria a base de dados, a `APP_KEY` e todas as credenciais; ganharia ainda a máquina. Aceite para a entrega, com o servidor dedicado só à plataforma. Endurecimento possível mais tarde: Docker *rootless* para o utilizador `micomoc` (precisa de configuração de cgroups e AppArmor no Ubuntu 24.04).

**Sem Docker:** `AI_CODE_SANDBOX=off` no `.env` (e um deploy). O `code.run` diz que não está instalado e os agentes usam `files.analyze` (somas, contagens e agrupamentos sem correr código). O outro driver do código, `macos` (`sandbox-exec`), só existe no macOS. O Estado da configuração mostra se a execução de código está disponível.

## 9. Actualizar (`deploy.sh`)

```bash
sudo /var/www/micomoc/current/deploy/deploy.sh v2026.11.24     # tag, ramo ou commit
```

Cada versão vai para `releases/AAAAMMDDHHMMSS-<commit>`:

1. `git archive` do espelho do repositório (`/var/www/micomoc/repo.git`); `storage/` e `.env` são ligações para `shared/`.
2. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` (as `VITE_*` do `.env` entram no JavaScript aqui), `node_modules` apagado.
3. Imagem da caixa (`docker build`, rápido quando nada mudou).
4. `storage:link` e `optimize` (configuração, eventos, rotas e vistas em cache) na release nova. Até aqui, uma falha não mexe no que está no ar.
5. Se houver migrações: cópia da base de dados (`backups/pre-deploy/`), modo de manutenção (503 com nova tentativa automática), `migrate --force`.
6. Troca de `current` (atómica), recarga do PHP-FPM, `horizon:terminate`, `reverb:restart`, `queue:restart`, reinício do agendador; fim da manutenção.
7. Verifica `https://<central>/up` e os processos; apaga releases antigas (ficam 5).

Sem migrações não há manutenção nenhuma. O registo fica em `/var/log/micomoc/deploy.log`. Mudar só o `.env` também pede um deploy (as caches e as `VITE_*`); sem código novo, basta `deploy.sh` com a mesma ref.

**Regra:** primeiro em staging, depois em produção, com tags (`git tag v2026.11.24 && git push --tags`) para se saber sempre o que está no ar (`cat /var/www/micomoc/current/REVISION`).

## 10. Voltar atrás (`rollback.sh`)

```bash
sudo /var/www/micomoc/current/deploy/rollback.sh                    # a release anterior
sudo /var/www/micomoc/current/deploy/rollback.sh 20261124093000-a1b2c3d
```

Troca o código para uma release que já esteve no ar, refaz as caches com o `.env` de agora e reinicia os processos. **Não mexe na base de dados.** As migrações normalmente só acrescentam e a versão anterior funciona na mesma; se não funcionar, repor a cópia `pre-deploy-<commit>` que o deploy fez ([`deploy/restore.md`](../deploy/restore.md), secção 3).

## 11. IA: chaves e orçamento

- **Chaves:** no `.env` (valem para todas as empresas) ou em **Definições › Provedores de IA** (por empresa, cifradas, nunca mostradas). A da empresa ganha à do `.env`. O provedor e o modelo por omissão são `AI_PROVIDER=gemini`; cada agente escolhe o seu no separador Modelo.
- **Vectores:** `AI_EMBEDDINGS_PROVIDER=gemini`, 768 dimensões, comparados em PHP. Sem chave do Gemini a pesquisa no Conhecimento fica só por palavras. Mudar de provedor de vectores pede `art knowledge:reindex`. Com o MariaDB 11.8 a comparação pode passar para a base de dados (tipo `VECTOR`) quando houver volume para isso.
- **Orçamento:** Super admin › Organizações › empresa › Orçamento de IA: tecto mensal da organização, por agente e por execução, em USD. Aviso aos 80%; aos 100% o agente (ou, no tecto da organização, todos) fica suspenso e volta quando alguém aprova um reforço do orçamento; o tecto por execução pára a execução a meio. Valores para arrancar, a confirmar com a MICOMOC: **organização 150 USD/mês, por agente 40 USD/mês, por execução 1 USD**; rever ao fim de duas semanas em Definições › Consumo de IA.
- Modelos sem preço em `config/agents.php` são cobrados ao preço de segurança (`AI_FALLBACK_*`, de propósito alto).

## 12. Google Calendar

Opcional: sem cliente OAuth a opção não aparece na Agenda.

1. Google Cloud Console › projecto novo › activar a **Google Calendar API**.
2. Ecrã de consentimento OAuth: nome da plataforma, email de suporte, domínio `rethink.co.mz` (ou o da empresa), âmbitos `calendar.events.readonly`, `calendar.app.created`, `openid`, `email`.
3. Credenciais › ID de cliente OAuth › **Aplicação Web** › URI de redireccionamento autorizado: `https://plataforma.rethink.co.mz/oauth/google/callback` (o domínio central, o mesmo para todas as empresas).
4. `GOOGLE_CALENDAR_CLIENT_ID` e `GOOGLE_CALENDAR_CLIENT_SECRET` no `.env` e um deploy.

Atenção: com a app em modo «Testing», só entram as contas da lista de testadores e o acesso expira ao fim de 7 dias (as pessoas teriam de voltar a ligar). Para uso real, publicar a app; como `calendar.events.readonly` é um âmbito sensível, o Google pede verificação da app (pode levar semanas). Pedir cedo, ou deixar a Agenda sem Google na entrega.

## 13. Cópias de segurança e reposição

A base de desenvolvimento perdeu-se a 20.11.2026 por não haver cópia. Em produção as cópias são obrigatórias e ensaiadas.

- **Todas as noites às 01:00 UTC** (`/etc/cron.d/micomoc`): `deploy/backup.sh`.
  - Base de dados: `mariadb-dump --single-transaction` (consistente, sem parar a plataforma), comprimida e verificada, em `/var/backups/micomoc/daily/`.
  - `storage/app` e o `.env` (com a `APP_KEY`): uma pasta por noite em `/var/backups/micomoc/storage/`, com hard links para o que não mudou.
  - 14 dias no servidor (`BACKUP_RETENTION_DAYS`).
- **Antes de cada deploy com migrações:** a base de dados em `pre-deploy/` (30 dias).
- **Fora do servidor** (recomendado; obrigatório para a entrega): `rclone`. Como root, `rclone config`: um remote de armazenamento (Backblaze B2, S3, Google Drive…) e por cima um remote **crypt** (o `.env` vai na cópia), por exemplo `offsite`. Depois `RCLONE_REMOTE="offsite:micomoc"` em `/etc/micomoc/micomoc.conf`. Vão as cópias da base de dados (30 dias, `RCLONE_RETENTION_DAYS`) e um espelho de `storage/app`, com o que mudou ou foi apagado em `storage/changed/`.
- **Provar agora:** `sudo deploy/backup.sh` (uns segundos a minutos) e `deploy/status.sh` (linha «última diária»). O cron escreve em `/var/log/micomoc/backup.log`; uma cópia que falha não actualiza `/var/backups/micomoc/last-success`.
- **Repor e ensaiar:** [`deploy/restore.md`](../deploy/restore.md) (base de dados, ficheiros, servidor novo do zero e o ensaio mensal).

## 14. Registos e monitorização

| O quê | Onde |
|---|---|
| Aplicação | `/var/www/micomoc/shared/storage/logs/laravel-AAAA-MM-DD.log` (30 dias) |
| Horizon, Reverb, agendador | `/var/log/micomoc/{horizon,reverb,scheduler}.log` (rodam aos 50/20 MB) |
| Deploys e cópias | `/var/log/micomoc/{deploy,backup}.log` |
| Nginx | `/var/log/nginx/micomoc.{access,error}.log`, `micomoc-ws.*` |
| PHP-FPM, MariaDB | `/var/log/php8.4-fpm.log`, `/var/log/mysql/mariadb-slow.log` |

- **`deploy/status.sh`:** release no ar, serviços, processos do Supervisor, `horizon:status`, `/up`, validade do certificado, última cópia, disco e memória.
- **`/up`:** 200 `ok` quando a base de dados e o Redis respondem, 503 com o que falhou. Pôr um monitor externo gratuito (UptimeRobot, Better Stack) de 5 em 5 minutos a `https://plataforma.rethink.co.mz/up`, com alertas por email para duas pessoas. Durante as migrações de um deploy dá 503 (manutenção).
- **Horizon:** `https://micomoc.plataforma…/horizon`, com uma conta da empresa cujo email esteja em `HORIZON_ALLOWED_EMAILS`: filas, tempos, trabalhos falhados.
- **Na plataforma:** Definições › Estado da configuração (IA, internet, caixas IMAP, ERP, fila, execução de código, pesquisa na internet, Google Calendar; «Verificar de novo» faz os testes a sério), Execuções e Consumo de IA. O resumo da manhã aponta para o Estado da configuração quando algo não se conseguiu verificar.
- **Certificado:** `certbot renew --dry-run` uma vez depois de instalar; a renovação corre sozinha (`certbot.timer`) e recarrega o Nginx.
- **Sistema:** as actualizações de segurança do Ubuntu instalam-se sozinhas (`unattended-upgrades`); uma vez por mês, `apt upgrade` e reinício numa janela combinada.

## 15. Staging

A mesma receita, noutro VPS e noutro domínio central (por exemplo `teste.rethink.co.mz`, com `*.teste` no DNS):

```bash
sudo DOMAIN=teste.rethink.co.mz APP_ENV=staging LETSENCRYPT_EMAIL=ops@rethink.co.mz deploy/provision.sh
```

Diferenças: `APP_ENV=staging` (Horizon com 10 processos, `LOG_LEVEL=debug`, modelos em modo estrito como em desenvolvimento), pode usar o ERP falso (`ERP_MCP_TRANSPORT=local`) e o tenant Rethink (`RethinkSeeder`), caixas de email de teste, orçamento de IA baixo. Cada versão passa por staging antes de produção.

## 16. O que fica fechado

- Só as portas 22, 80 e 443 abertas (ufw); fail2ban no SSH. MariaDB, Redis e Reverb só em 127.0.0.1.
- HTTPS em todo o lado, HSTS, `X-Frame-Options`, `nosniff`, `Referrer-Policy` e `Permissions-Policy` (o microfone só para a própria plataforma, por causa do ditado). Só o `index.php` corre; ficheiros escondidos (`.env`, `.git`) dão 403.
- `APP_DEBUG=false` (o deploy recusa `true` em produção), cookies de sessão só por HTTPS e só do host de cada empresa (`SESSION_DOMAIN=null`), links sempre em `https` (`APP_URL`).
- `.env` com permissões 640 do utilizador `micomoc`; cópias em pastas só do root.
- `REDIS_QUEUE_RETRY_AFTER=930`: maior do que o trabalho mais longo (900 s), para um trabalho a meio nunca ser entregue a um segundo processo (o valor do Laravel, 90 s, repetiria execuções de agentes demoradas).

## 17. Problemas frequentes

| Sintoma | Ver |
|---|---|
| Nada aparece ao vivo (conversas, notificações) | `VITE_REVERB_HOST=ws.<central>` e `VITE_REVERB_PORT=443` antes do build (deploy outra vez); `ws.<central>` no DNS e no certificado; `supervisorctl status micomoc:*`; erros de WebSocket na consola do browser. |
| Os agentes não começam nada | Estado da configuração › Fila; `horizon:status`; `/var/log/micomoc/horizon.log`. |
| Um comando agendado deixou de correr | Um `withoutOverlapping` ficou bloqueado depois de um processo morrer: `art schedule:clear-cache`. |
| Emails não chegam | `scheduler.log` (mail:fetch), Estado da configuração › Caixas, palavra-passe da caixa no Hostinger. |
| 404 numa empresa | O slug existe? O nome está no DNS e no certificado (`tls.sh http <slug>`)? O domínio central sozinho dá 404 de propósito. |
| 419 ou sessão que cai | `SESSION_DOMAIN` tem de ser `null`; `APP_URL` em https. |
| 413 ao carregar um ficheiro | `client_max_body_size` (128m) e `post_max_size` (128M) têm de ser iguais. |
| «Execução de código não instalada» | `deploy/sandbox-smoke.sh`. |
| Mudei o `.env` e nada mudou | As caches: `deploy.sh` com a mesma ref. |

## 18. Lista de entrada em produção (MICOMOC)

Fica preenchida e assinada no registo de aceitação. Nada de valores inventados: o que não estiver acordado com a MICOMOC fica em aberto no registo.

**Servidor e segurança**
- [ ] VPS dimensionado (secção 1), `provision.sh` sem erros, versões registadas (o fim do `provision.sh`).
- [ ] DNS e certificado com `plataforma`, `admin`, `ws` e `micomoc`; `deploy/status.sh` sem falhas; `/up` dá `ok`.
- [ ] `APP_KEY` no cofre, com duas pessoas com acesso; `.env` revisto (sem `APP_DEBUG`, email da plataforma a enviar).
- [ ] Monitor externo a `/up` com alertas para duas pessoas.

**Pessoas e papéis**
- [ ] Super admins da Rethink criados (`admin:create`), um por pessoa.
- [ ] Empresa MICOMOC com perfil completo (denominação, NUIT, contacto, domínio das caixas, retenção).
- [ ] Pessoas reais, uma a uma, da lista entregue pela MICOMOC: departamento, cargo, papel (proprietário, administrador, chefia, membro) e a quem reporta; «Gerir pessoas e papéis» só para quem a MICOMOC indicar. Nenhuma conta de demonstração.

**Agentes**
- [ ] Os seis agentes instalados em rascunho, cada um com responsável (a quem responde), personalidade e instruções revistas com a área.
- [ ] **Autonomia baixa a começar:** todos em N2 (tudo o que escreve pede aprovação) nas primeiras duas semanas, a Triagem incluída; subir caso a caso depois, quando as aprovações forem sempre «Permitir».
- [ ] Rotinas revistas (horas, quem recebe); agentes activados um a um, cada um com uma conversa de prova.
- [ ] **Limites de valor:** o tecto absoluto (pagamentos, facturas, contratos, RH, emails a entidades novas) confirmado em Definições › Regras de aprovação, sem «Permitir sempre» nessas acções; regras de negócio acordadas (SLA, margem mínima, alerta de orçamento, pré-aviso de contratos).

**Ligações**
- [ ] Caixas do Hostinger dos agentes e as partilhadas criadas, ligadas e testadas (Estado da configuração › Caixas); SPF, DKIM e DMARC do domínio de email; um email de teste para a Triagem classificado.
- [ ] ERP: endereço MCP e token de produção, «Testar ligação», `capabilities:sync micomoc`; as ferramentas de escrita que ficam ligadas, revistas com a MICOMOC.
- [ ] IA: chave do Gemini (e as outras), **orçamento acordado** (organização, por agente, por execução) e quem recebe o aviso dos 80%.
- [ ] Google Calendar: ligado e publicado, ou registado como fora da entrega.
- [ ] `code.run`: `sandbox-smoke.sh` passa, ou `AI_CODE_SANDBOX=off` registado.
- [ ] Definições › Estado da configuração: tudo «Pronta» (ou o que falta, registado).

**Cópias**
- [ ] `backup.sh` manual sem erros; cópia fora do servidor (rclone crypt) a chegar.
- [ ] **Ensaio de reposição feito** ([`deploy/restore.md`](../deploy/restore.md), secção 6) e o resultado no registo.

**Entrega**
- [ ] Demo gravada em produção com os dados reais (o percurso de [`docs/demo/GUIAO.md`](demo/GUIAO.md) adaptado: ERP por MCP, triagem de um email, aprovação, briefing).
- [ ] Formação curta: Aprovações, Email, Tarefas, conversa com um agente.
- [ ] **Registo de aceitação** assinado: data; versão no ar (tag e commit de `REVISION`); quem aceitou pela MICOMOC e pela Rethink; valores acordados (orçamento, autonomia, limites, retenção); resultado do ensaio de reposição; pendentes com responsável e data.

**Primeiras duas semanas**
- [ ] Todos os dias: Aprovações, Execuções com erro, Consumo de IA, `/var/log/micomoc/backup.log`.
- [ ] Ao fim de duas semanas: rever autonomia e orçamento com a MICOMOC.

## 19. O que só se confirma no servidor

Os scripts foram verificados com `bash -n` e `shellcheck`, e o `deploy.sh`, o `rollback.sh` e o `backup.sh` foram ensaiados num Mac com as ferramentas do sistema simuladas (Composer, npm, PHP, Docker, Supervisor, MariaDB). A aplicação foi posta em cache com o `.env` de produção (`config:cache`, `route:cache`, `event:cache`, `view:cache`). Fica por confirmar na primeira instalação:

- O `provision.sh` de ponta a ponta num Ubuntu 24.04 limpo (repositórios `ondrej/php`, NodeSource e `deb.mariadb.org`; nomes dos pacotes; `nginx -t` e `php-fpm8.4 -t`).
- O `tls.sh` contra o Let's Encrypt real (HTTP-01 e, se for o caso, Cloudflare).
- O WebSocket pelo Nginx até ao Reverb (`wss://ws.<central>`), com o build feito com as `VITE_REVERB_*` de produção.
- A caixa do `code.run` com Docker (`sandbox-smoke.sh`): nunca foi experimentada.
- O Horizon com os 33 processos e a memória real de cada um; os tamanhos do `pm.max_children` e do buffer do MariaDB.
- O `backup.sh` com o `mariadb-dump` e o `rclone` reais, e a reposição (o ensaio da secção 18).
- O envio por SMTP do Hostinger a partir do IP do servidor (SPF/DKIM) e a leitura IMAP.
