# Como testar a plataforma

Guia para o teste de aceitação das entregas E00 a E08. Tudo corre em local, contra o **ERP falso** (dados fictícios em MZN), até haver alojamento e o servidor MCP do ERP real.

## 1. Arrancar no computador local

**Precisa de:** PHP 8.3+ (com `pdo_mysql`, `redis`, `pcntl`, `zip`, `intl`), Composer, Node 22+, MySQL e Redis.

- **macOS:** `brew install php composer node`, e depois `pecl install redis`. Para o MySQL e o Redis, usar o Docker Desktop com o `compose.yaml` do repositório.
- **Windows:** usar o **WSL2 com Ubuntu**, porque o Horizon (as filas dos agentes) precisa de `pcntl`, que não existe no PHP de Windows. Dentro do Ubuntu 24.04, instalar o PHP com `sudo apt install php8.3-cli php8.3-{mysql,redis,xml,curl,mbstring,zip,intl,bcmath} composer unzip`. O Node do Ubuntu é antigo: instalar o Node 22 com o [nvm](https://github.com/nvm-sh/nvm) (`nvm install 22`). Para o MySQL e o Redis, usar o Docker Desktop (ligado ao WSL) ou `sudo apt install mysql-server redis-server`.
- **Linux:** como no Windows, mas directamente.

**MySQL e Redis com Docker:** na pasta do projecto, `docker compose up -d`. Fica com a base `micomoc_agents`, o utilizador `root` sem palavra-passe e o Redis na porta 6379, que é o que o `.env.example` espera.

```bash
git clone https://github.com/EddyBarrote/rethibk-business-center.git
cd rethibk-business-center
git checkout claude/micomoc-e00-foundation-5cr4b0
docker compose up -d            # ou MySQL e Redis instalados à parte
composer install && npm install
cp .env.example .env
php artisan key:generate
php artisan reverb:install      # escreve as chaves REVERB_* no .env
# no .env: AI_PROVIDER=gemini, AI_EMBEDDINGS_PROVIDER=gemini, GEMINI_API_KEY=a-tua-chave
php artisan migrate --seed
composer dev                    # servidor, filas (Horizon), agendador, Reverb, logs e Vite
```

O `composer dev` corre tudo num só terminal. Para parar, usar Ctrl+C. Se mudar alguma coisa no `.env`, é preciso reiniciá-lo.

O seed cria o tenant **micomoc** com:

| Quem | Email | Palavra-passe | Perfil |
|---|---|---|---|
| Proprietário | `owner@micomoc.test` | `password` | Direcção-Geral, vê tudo |
| Directora Comercial | `comercial@micomoc.test` | `password` | chefia |
| Director Financeiro | `financas@micomoc.test` | `password` | chefia |
| Director de Operações | `operacoes@micomoc.test` | `password` | chefia |
| Directora de RH | `rh@micomoc.test` | `password` | chefia |
| Técnico | `tecnico@micomoc.test` | `password` | membro (vê só o que é seu) |
| Super admin (Rethink) | `admin@rethink.test` | `password` | consola em <http://admin.localhost:8000> |

Também instala os seis agentes, com caixas em `@agentes.micomoc.test` (desactivadas até haver credenciais do Hostinger), e dois contratos de exemplo.

Consola do tenant: <http://micomoc.localhost:8000>.

## 2. Sem chave de IA

`php artisan agents:demo micomoc --scripted` simula o modelo e mostra o ciclo completo: o agente tenta criar uma lead, a tentativa vira aprovação em **Aprovações**, e ao aprovar a lead é criada no ERP falso. Os testes automáticos (`php artisan test`, 329 testes) simulam o modelo da mesma forma em todas as entregas.

## 3. Com chave de IA: percurso por entrega

Os emails de exemplo estão em `docs/exemplos`. `mail:ingest` faz de conta que chegaram por IMAP.

| Entrega | O que fazer | O que deve acontecer |
|---|---|---|
| E03 Triagem | `php artisan mail:ingest micomoc triagem@agentes.micomoc.test docs/exemplos/lead.eml` | Em **Caixa**, o email aparece classificado como lead, com resumo, prazo e encaminhamento; a lead está no ERP (`php artisan erp:call micomoc leads.search '{"query":"Beira"}'`); a Directora Comercial tem uma notificação. |
| E03 Segurança | `… docs/exemplos/prompt-injection.eml` | O email fica assinalado e nada do que pede é feito. |
| E03 Resposta | Abrir o email da lead › rascunho de resposta › Enviar | Precisa de SMTP activo na caixa (Hostinger). |
| E04 Briefing | `php artisan agents:daily-briefing` | Em **Briefings** e no **Painel**: o briefing com o que precisa de decisão e ligações; também por email (em local vai para `storage/logs`). |
| E05 Finanças | **Finanças** › carregar `docs/exemplos/extracto-bci-2026-09.csv`; ou `… financas@agentes.micomoc.test docs/exemplos/bank-statement.eml` | 5 movimentos; o agente propõe a FT 2026/118 (Hotel Baía Azul) e a FT 2026/131 (Agro Zambeze); confirmar ou ignorar cada um. |
| E05 Facturas | `… triagem@… docs/exemplos/supplier-invoice.eml` | Classificada como factura de fornecedor e passada ao agente de Finanças. |
| E06 Compras | **Compras** › Nova requisição | O agente lança o pedido de cotação no ERP, compara e prepara o rascunho de encomenda, que pede aprovação. |
| E06 Contratos | **Contratos** › criar um que termina dentro do pré-aviso; `php artisan agents:watch-contracts` | Notificação ao responsável e trabalho para o agente certo. |
| E07 RH | `… triagem@… docs/exemplos/job-application.eml` | Passa ao agente de RH, que avalia o CV face à vaga e regista a candidatura no ERP. |
| E08 Clientes | **Clientes** › Hotel Baía Azul; `… triagem@… docs/exemplos/client-request.eml` | Ficha completa do cliente; o pedido entra na lista do SLA (8 h pelo contrato de exemplo). |

Em cada caso, **Execuções** mostra o que o agente fez passo a passo e **Aprovações** o que espera por uma pessoa.

## 4. Super admin

Em <http://admin.localhost:8000>: organizações, perfil e orçamento de IA, regras de negócio (SLA, margens, prazos), agentes (personalidade, instruções, capacidades, skills, rotinas, nível de autonomia), caixas de correio e instalação dos modelos de agentes.

## 5. Ligar o email real (Hostinger)

Super admin › agente › Caixa de correio: servidor IMAP e SMTP, utilizador e palavra-passe, e activar. A caixa passa a ser lida a cada minuto (`mail:fetch`).
