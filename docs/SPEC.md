# MICOMOC — Plataforma de Gestão Empresarial com Agentes de IA
## Documento Técnico de Execução

**Cliente:** MICOMOC · **Executor:** Rethink Technologies · **Ref:** RT-2026-MCM-01
**Versão do documento:** 1.0 · **Data:** 04.10.2026
**Destinatário:** Claude Code — este documento é a especificação de execução do projecto.

---

## 0. Como usar este documento

Este ficheiro é a fonte de verdade da implementação. Quem escrever código — humano ou Claude Code — deve:

1. Ler as secções 1 a 4 antes de qualquer `composer create-project`.
2. Implementar por entregas (secção 19), pela ordem indicada. Cada entrega tem critérios de pronto.
3. Nunca inventar nomes de classes ou APIs: as assinaturas do `laravel/ai` e `laravel/mcp` neste documento foram verificadas na documentação oficial a 04.10.2026 (fontes na secção 22).
4. Tratar a secção 21 como bloqueadores: são decisões que ainda dependem do cliente ou da direcção da Rethink.

> **Convenção:** tudo o que estiver marcado `[CONFIRMAR]` não está decidido. Não inventar um valor — parar e perguntar.

---

## 1. Decisões de arranque

Tomadas no kick-off de 04.10.2026:

| # | Decisão | Opção escolhida |
|---|---|---|
| 1 | Runtime dos agentes | **Tudo em Laravel**, com o package oficial `laravel/ai` |
| 2 | Relação com o ERP | Plataforma **separada** que se liga ao **Rethink ERP** por credenciais fornecidas pela Rethink |
| 3 | Email | **Caixas reais por agente** no domínio + **webhooks de entrada**; SMTP para envio |
| 4 | Tenancy | **Multi-tenant completo desde o início** (produto da Rethink, MICOMOC é o primeiro cliente) |

### 1.1 Correcção de versões — ler antes de começar

O briefing pedia **Laravel 14**. Verificação feita a 04.10.2026:

- **Laravel 14 não existe.** Está em desenvolvimento no branch `master` e é esperado no **1.º trimestre de 2027**.
- A versão estável actual é o **Laravel 13**, lançado a **17 de Março de 2026**.
- O package `laravel/ai` está na **v1.0.1** (29.09.2026) e exige `illuminate/* ^12.0|^13.0` e **PHP ^8.3**. **Não suporta Laravel 14.**

**Decisão imposta pelos factos: o projecto arranca em Laravel 13 e PHP 8.3+.**

Isto não é uma perda. O programa tem 24 semanas; o Laravel 14 chega perto do fim. A actualização para o 14 fica planeada como tarefa de manutenção pós-entrega, depois de o `laravel/ai` o suportar. Fixar versões no `composer.json` e **não** usar `dev-master` de nada.

---

## 2. Stack verificada

| Camada | Tecnologia | Versão | Nota |
|---|---|---|---|
| Linguagem | PHP | `^8.3` | exigido pelo `laravel/ai` |
| Framework | Laravel | `^13.0` | estável desde 17.03.2026 |
| Agentes | `laravel/ai` | `^1.0` | package oficial |
| MCP | `laravel/mcp` | `^1.0` | cliente e servidor |
| Base de dados | MySQL | `8.4` ou `9.x` | ver 5.9 sobre vectores |
| Tempo real | Laravel Reverb | `^1.0` | WebSockets |
| Filas | Redis + Horizon | — | obrigatório, não `database` |
| Frontend | React + Inertia 2 | — | SSR desligado na v1 |
| UI | shadcn/ui + Tailwind | — | tema Rethink |
| Build | Vite | — | — |
| Email saída | SMTP por caixa | — | config em runtime |
| Email entrada | Webhooks do provedor | — | `[CONFIRMAR]` provedor |
| Testes | Pest | `^4` | — |

### 2.1 Arranque do repositório

```bash
composer create-project laravel/laravel micomoc-agents "13.*"
cd micomoc-agents

composer require laravel/ai laravel/mcp laravel/reverb laravel/horizon
composer require inertiajs/inertia-laravel
composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan laravel/pint

npm install @inertiajs/react react react-dom
npx shadcn@latest init

php artisan vendor:publish --tag=ai-routes      # cria routes/ai.php
php artisan reverb:install
php artisan horizon:install
```

Validar imediatamente após instalar:

```bash
php artisan --version          # deve dizer Laravel Framework 13.x
composer show laravel/ai       # deve dizer 1.0.x
php artisan make:agent Probe   # deve existir; se falhar, parar e reportar
```

---

## 3. Arquitectura

### 3.1 Vista geral

```
┌──────────────────────────────────────────────────────────────┐
│  NAVEGADOR — React + Inertia + shadcn                        │
│  consola de agentes · aprovações · caixa triada · briefings  │
└───────────────┬──────────────────────────┬───────────────────┘
                │ Inertia (HTTP)           │ WebSocket
                ▼                          ▼
┌──────────────────────────────────────────────────────────────┐
│  LARAVEL 13 (monólito)                     Reverb            │
│                                                              │
│  HTTP            Domínio              Agentes (laravel/ai)   │
│  controllers  ·  tenancy           ·  TriageAgent            │
│  webhooks     ·  autonomia         ·  ChiefOfStaffAgent      │
│  Inertia      ·  aprovações        ·  FinanceAgent · …       │
│                  auditoria            tools locais           │
│                  memória              tools MCP (ERP)        │
│                                                              │
│  Horizon / Redis — toda a execução de agentes é em fila      │
└──────┬──────────────────┬───────────────────┬────────────────┘
       │ MySQL            │ MCP (cliente)     │ SMTP / Webhooks
       ▼                  ▼                   ▼
   dados da          RETHINK ERP          Provedor de email
   plataforma        (servidor MCP)       (caixa por agente)
```

### 3.2 Princípios não negociáveis

1. **Nenhum agente corre em processo HTTP.** Tudo é despachado para fila. A resposta ao utilizador é imediata; o progresso chega por Reverb.
2. **Nenhum acesso ao ERP fora do cliente MCP.** Sem acesso directo à base de dados do ERP, sem chamadas HTTP avulsas. Se uma funcionalidade precisa de algo que o MCP não expõe, a resposta é acrescentar a ferramenta ao servidor MCP do ERP — não contorná-lo.
3. **Toda a acção com efeito passa pelo portão de autonomia** (secção 12). Sem excepções no código de agente.
4. **Tudo é auditado.** Nenhuma ferramenta executa sem escrever em `audit_logs`.
5. **Toda a consulta é scoped ao tenant.** Ver secção 4.

---

## 4. Multi-tenancy

### 4.1 Modelo

**Base de dados única, discriminador por linha.** Não multi-base. Justificação: os agentes correm em filas partilhadas, o Reverb publica em canais partilhados e o Horizon é um só — multi-base multiplicaria a complexidade operacional por cada cliente novo sem ganho real de isolamento, desde que o scoping seja rigoroso.

### 4.2 Implementação

- Tabela `tenants`. Toda a tabela de domínio tem `tenant_id` (FK, indexado, **não** nullable).
- Trait `App\Concerns\BelongsToTenant`:
  - `static::addGlobalScope(new TenantScope)` — filtra por `tenant_id`
  - `static::creating(fn ($m) => $m->tenant_id ??= Tenant::current()->id)`
- `App\Tenancy\TenantManager` singleton com `current()`, `set()`, `forget()`.
- Resolução do tenant:
  - **HTTP:** middleware `IdentifyTenant` por subdomínio (`micomoc.plataforma.rethink.co.mz`) ou domínio próprio.
  - **Filas:** o `tenant_id` viaja no payload do job. `TenantAwareJob` abstracto faz `TenantManager::set()` no `handle()` e `forget()` no `finally`.
  - **Comandos agendados:** iteram explicitamente sobre tenants activos.
- **Nunca** usar `withoutGlobalScopes()` fora de código de administração explicitamente marcado.

### 4.3 Teste obrigatório

Criar `tests/Feature/Tenancy/IsolationTest.php` que, para **cada** modelo com `tenant_id`, cria registos em dois tenants e afirma que o tenant A nunca vê os de B — via Eloquent, via API e via as ferramentas dos agentes. Este teste corre no CI e bloqueia o merge.

---

## 5. Modelo de dados

Migrações pela ordem abaixo. Todas as tabelas: `id` bigint, `timestamps`, `tenant_id` quando aplicável.

### 5.1 Núcleo

```
tenants
  name, slug (unique), domain (nullable, unique), status enum(active,suspended)
  settings json, trial_ends_at, created_at

users
  tenant_id, name, email, password, department_id (nullable)
  role enum(owner,admin,manager,member), is_active, last_seen_at
  UNIQUE (tenant_id, email)

departments
  tenant_id, name, slug, parent_id (nullable, self FK)
  UNIQUE (tenant_id, slug)
```

### 5.2 Agentes

```
agents
  tenant_id, key, name, class (FQCN), department_id (nullable)
  reports_to_user_id (nullable FK users)
  status enum(draft,active,suspended) default draft
  autonomy_level tinyint 0..4 default 0
  provider, model, temperature, max_tokens
  instructions_override text (nullable)
  settings json
  UNIQUE (tenant_id, key)

agent_assignments            -- agente afecto a uma pessoa
  tenant_id, agent_id, user_id, role (nullable)
  UNIQUE (agent_id, user_id)

agent_skill                  -- competências activas por agente
  tenant_id, agent_id, skill_id, enabled, config json
  UNIQUE (agent_id, skill_id)

skills                       -- catálogo de competências
  tenant_id (nullable = global), key, name, description
  class (FQCN) nullable, source enum(local,mcp)
  mcp_tool_name nullable, is_mutating bool, risk tinyint 0..4
  UNIQUE (tenant_id, key)
```

> `skills.risk` é o nível mínimo de autonomia que o agente precisa para executar a competência sem aprovação. `is_mutating` marca tudo o que escreve, envia ou movimenta.

### 5.3 Execução

```
agent_runs
  tenant_id, agent_id, conversation_id (nullable)
  trigger_type enum(email,schedule,manual,agent,webhook)
  trigger_id (nullable, morphs)
  status enum(queued,running,awaiting_approval,completed,failed,cancelled)
  input longtext, output json (nullable)
  input_tokens, output_tokens, cost_usd decimal(10,6)
  duration_ms, error text (nullable)
  started_at, finished_at
  INDEX (tenant_id, agent_id, status, created_at)

agent_run_steps
  agent_run_id, seq
  type enum(message,reasoning,tool_call,tool_result,approval,error)
  tool_name (nullable), payload json, duration_ms, created_at
  INDEX (agent_run_id, seq)
```

### 5.4 Aprovações e auditoria

```
approvals
  tenant_id, agent_run_id, agent_id
  action_type, action_summary, payload json
  required_level tinyint, agent_level tinyint
  status enum(pending,approved,rejected,expired) default pending
  assigned_to_user_id (nullable), decided_by_user_id (nullable)
  decided_at, decision_note text, expires_at
  INDEX (tenant_id, status, created_at)

audit_logs
  tenant_id, actor_type enum(user,agent,system), actor_id
  action, subject_type, subject_id (morphs, nullable)
  payload json, result enum(ok,denied,error), ip, created_at
  INDEX (tenant_id, created_at), INDEX (actor_type, actor_id)
```

`audit_logs` é **append-only**: sem `updated_at`, sem rotas de edição ou remoção.

### 5.5 Email

```
mailboxes
  tenant_id, agent_id (nullable — caixas partilhadas existem)
  address (unique global), display_name
  inbound_provider enum(mailgun,postmark,other), inbound_secret (encrypted)
  smtp_host, smtp_port, smtp_username, smtp_password (encrypted), smtp_encryption
  status enum(provisioning,active,error,disabled)
  last_inbound_at, last_outbound_at

email_messages
  tenant_id, mailbox_id, direction enum(inbound,outbound)
  provider_message_id (nullable), message_id_header, in_reply_to, references text
  thread_id (nullable, FK email_threads)
  from_address, from_name, to json, cc json, bcc json, reply_to
  subject, text_body longtext, html_body longtext, raw_path
  classification (nullable), classification_confidence
  status enum(received,processing,processed,failed,queued,sent,bounced)
  agent_run_id (nullable), received_at, sent_at
  dedup_hash char(64) 
  UNIQUE (mailbox_id, dedup_hash)
  INDEX (tenant_id, mailbox_id, received_at)

email_threads
  tenant_id, mailbox_id, subject_normalized, last_message_at, message_count

email_attachments
  email_message_id, filename, mime_type, size_bytes, disk, path
  extracted_text longtext (nullable), ocr_status
```

### 5.6 Memória organizacional

```
knowledge_items
  tenant_id, type enum(decision,meeting_brief,document,pattern,entity_note)
  title, content longtext, summary text
  source_type, source_id (morphs, nullable)
  department_id (nullable), visibility enum(tenant,department,agent)
  created_by_type, created_by_id
  INDEX (tenant_id, type, created_at)
  FULLTEXT (title, content)

knowledge_embeddings
  knowledge_item_id, chunk_index, chunk_text, embedding  -- ver 5.9
  model, dimensions
```

### 5.7 Briefings

```
briefings
  tenant_id, agent_id, type enum(daily,weekly,meeting,adhoc)
  for_user_id, period_start, period_end
  content longtext, highlights json, decisions_pending json
  agent_run_id, delivered_at, read_at
```

### 5.8 Ligação ao ERP

```
erp_connections
  tenant_id, name, transport enum(web,local)
  base_url, auth_type enum(token,oauth), credentials (encrypted json)
  status enum(untested,ok,error,disabled)
  last_checked_at, last_error text
  capabilities json           -- cache das ferramentas que o servidor expõe
```

### 5.9 Vectores — decisão pendente

O `laravel/ai` oferece `SimilaritySearch::usingModel(Document::class, 'embedding')`, que pressupõe uma coluna de embeddings pesquisável.

- **MySQL 9.x** tem o tipo nativo `VECTOR` → caminho preferido.
- **MySQL 8.4** não tem → guardar o embedding em `JSON`/`BLOB` e fazer similaridade por força bruta em SQL. Aceitável até à ordem das dezenas de milhares de chunks, que é o horizonte realista da MICOMOC nos primeiros dois anos.

**Tarefa de arranque (E02):** confirmar a versão de MySQL do alojamento e verificar o que o `SimilaritySearch` do SDK suporta nessa versão. `[CONFIRMAR]` versão de MySQL.

---

## 6. Camada de agentes (`laravel/ai`)

### 6.1 API verificada

```php
use Laravel\Ai\Contracts\{Agent, HasTools, HasStructuredOutput, Conversational};
use Laravel\Ai\Concerns\{RemembersConversations, HasConversations};
use Laravel\Ai\Promptable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Illuminate\Contracts\JsonSchema\JsonSchema;
```

Geradores: `php artisan make:agent NomeDoAgente [--structured]` · `php artisan make:tool NomeDaTool`

Métodos do agente verificados:

```php
prompt(string $prompt, provider: ..., model: string, timeout: int): AgentResponse
stream(string $prompt): StreamableAgentResponse
queue(string $prompt): QueuedAgentResponse
broadcast(string $prompt, Channel $channel): void          // ← usar com Reverb
withTools(array|callable $tools): self
forUser(User $user): self
continue(string $conversationId, as: Model): self
continueOrStart(?string $conversationId, as: Model): self
continueLastConversation(Model $participant): self
```

### 6.2 Classe base

Todos os agentes estendem `App\Ai\Agents\BaseAgent`, que concentra o que é comum e impede que cada agente reimplemente as regras:

```php
abstract class BaseAgent implements Agent, HasTools, Conversational
{
    use Promptable, RemembersConversations;

    public function __construct(protected AgentRecord $record) {}

    abstract public function key(): string;

    // instruções = prompt base da classe + override da BD + contexto do tenant
    public function instructions(): string
    {
        return app(InstructionComposer::class)->for($this->record, $this->basePrompt());
    }

    abstract protected function basePrompt(): string;

    // ferramentas locais + ferramentas MCP do ERP, todas passadas pelo portão
    public function tools(): iterable
    {
        return app(ToolResolver::class)->for($this->record);
    }
}
```

### 6.3 Os seis agentes

| Classe | `key` | Responde a | Entrega |
|---|---|---|---|
| `App\Ai\Agents\TriageAgent` | `triage` | Direcção Comercial | E03 |
| `App\Ai\Agents\ChiefOfStaffAgent` | `chief_of_staff` | Direcção-Geral | E04 |
| `App\Ai\Agents\FinanceAgent` | `finance` | Direcção Financeira | E05 |
| `App\Ai\Agents\ProcurementAgent` | `procurement` | Direcção de Operações | E06 |
| `App\Ai\Agents\HrAgent` | `hr` | Direcção de RH | E07 |
| `App\Ai\Agents\ClientManagerAgent` | `client_manager` | Direcção Comercial | E08 |

### 6.4 Execução — sempre em fila

```php
final class RunAgent extends TenantAwareJob
{
    public function __construct(
        public int $tenantId,
        public int $agentId,
        public string $input,
        public string $triggerType,
        public ?int $triggerId = null,
    ) {}

    public function handle(AgentRunner $runner): void
    {
        $runner->run($this->agentId, $this->input, $this->triggerType, $this->triggerId);
    }
}
```

`AgentRunner` é responsável por: criar o `agent_runs`, instanciar o agente, correr o prompt, gravar cada passo em `agent_run_steps`, emitir eventos Reverb, registar tokens e custo, e fechar o run. **Nenhum agente é instanciado fora do `AgentRunner`.**

---

## 7. Competências (tools)

### 7.1 Duas origens

- **Locais** — classes PHP que implementam `Laravel\Ai\Contracts\Tool`. Vivem em `app/Ai/Tools/`.
- **MCP** — vêm do servidor MCP do ERP, descobertas em tempo de execução.

A tabela `skills` é o catálogo das duas, para que a consola possa ligar e desligar competências por agente sem alterar código.

### 7.2 Exemplo de ferramenta local

```php
namespace App\Ai\Tools;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ClassifyEmail implements Tool
{
    public function description(): string
    {
        return 'Classifica uma mensagem de correio recebida numa das categorias '
             . 'operacionais da empresa e extrai os campos relevantes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'categoria' => $schema->string()->enum([
                'lead', 'concurso', 'cotacao', 'factura_fornecedor',
                'candidatura', 'reclamacao', 'irrelevante',
            ])->required(),
            'entidade'  => $schema->string(),
            'objecto'   => $schema->string(),
            'valor'     => $schema->number(),
            'prazo'     => $schema->string()->description('ISO-8601'),
            'confianca' => $schema->string()->enum(['baixa','media','alta'])->required(),
        ];
    }

    public function handle(Request $request): string { /* persiste e devolve resumo */ }
}
```

### 7.3 Catálogo mínimo por entrega

| Família | Competências | Entrega |
|---|---|---|
| Leitura | `ClassifyEmail`, `ExtractDocumentFields`, `OcrAttachment`, `ReadTenderSpec` | E03 |
| Escrita | `DraftEmailReply`, `DraftProposal`, `DraftReport` | E03–E05 |
| Análise | `CompareQuotes`, `ProjectMargin`, `DetectAnomaly`, `MatchCandidate` | E05–E07 |
| ERP (MCP) | descobertas do servidor do ERP | E01 |
| Memória | `RememberDecision`, `SearchKnowledge`, `SummariseMeeting` | E02 |
| Comunicação | `SendEmail`, `NotifyUser`, `ScheduleFollowUp` | E02–E03 |

---

## 8. Ligação ao Rethink ERP (cliente MCP)

### 8.1 Registo do cliente

```php
// app/Providers/McpServiceProvider.php
use Laravel\Mcp\Client;
use Laravel\Mcp\Facades\Mcp;

Mcp::registerClient('rethink_erp', function () {
    $conn = ErpConnection::currentTenant()->firstOrFail();

    return Client::web($conn->base_url)
        ->withToken(fn () => $conn->decryptedToken())
        ->withTimeout(30);
});
```

Uso dentro do agente:

```php
public function tools(): iterable
{
    return [
        ...Mcp::client('rethink_erp')->tools(),
        new ClassifyEmail,
        new SearchKnowledge,
    ];
}
```

### 8.2 Servidor falso para não bloquear o desenvolvimento

As credenciais do ERP chegam mais tarde. **Não esperar por elas.** Construir, na E01, um servidor MCP local que replica a superfície do ERP com dados de fixture:

```php
// routes/ai.php
Mcp::local('fake-erp', App\Mcp\Servers\FakeErpServer::class);
```

```php
// .env de desenvolvimento
ERP_MCP_TRANSPORT=local
ERP_MCP_LOCAL_COMMAND="php artisan mcp:start fake-erp"
```

O `McpServiceProvider` escolhe `Client::local(...)` ou `Client::web(...)` conforme `ERP_MCP_TRANSPORT`. Toda a plataforma — agentes, aprovações, auditoria, UI — desenvolve-se e testa-se contra o servidor falso. Quando as credenciais reais chegarem, muda-se uma variável de ambiente.

### 8.3 Contrato esperado do ERP

Ferramentas que o servidor MCP do ERP tem de expor para o programa funcionar. **Entregar esta lista à equipa do ERP na semana 1.**

| Módulo | Ferramentas mínimas |
|---|---|
| CRM | `crm.search_accounts`, `crm.get_account`, `crm.create_contact`, `crm.update_account` |
| Leads | `leads.create`, `leads.update`, `leads.search`, `leads.attach_document` |
| Projectos | `projects.create`, `projects.get`, `projects.list_by_account`, `projects.update_status` |
| Facturação | `invoices.create_draft`, `invoices.issue`, `invoices.list_receivables`, `invoices.get` |
| Procurement | `procurement.create_rfq`, `procurement.list_suppliers`, `procurement.compare_quotes`, `procurement.create_po_draft`, `procurement.receive` |
| Despesas | `expenses.create`, `expenses.classify`, `expenses.list_by_project` |
| Transversal | `erp.whoami`, `erp.health`, `erp.search` |

Regras do contrato:
- Toda a ferramenta que escreve tem de ser **idempotente por `idempotency_key`**.
- Nenhuma ferramenta emite, paga ou assina — devolvem rascunhos (`*_draft`), que um humano confirma no ERP ou pela consola.
- Erros devolvem `isError` com mensagem legível, nunca stack traces.

`[CONFIRMAR]` quem constrói o servidor MCP do lado do ERP e em que prazo.

---

## 9. Subsistema de email

### 9.1 Caixas por agente

Cada agente activo tem uma caixa própria no domínio do cliente, por exemplo:

```
triagem@micomoc.co.mz
financas@micomoc.co.mz
compras@micomoc.co.mz
rh@micomoc.co.mz
clientes@micomoc.co.mz
direccao@micomoc.co.mz
```

`[CONFIRMAR]` domínio a usar e quem administra o DNS (SPF, DKIM, DMARC obrigatórios antes do primeiro envio).

### 9.2 Entrada — webhooks

```
POST /webhooks/email/{provider}/inbound
```

Pipeline:

1. **Verificar assinatura** do provedor. Assinatura inválida → 401, sem processar.
2. **Deduplicar**: `dedup_hash = sha256(message_id_header + mailbox_id)`. Já existe → 200 e sair.
3. **Guardar o bruto** em disco (`raw_path`) antes de qualquer parsing.
4. **Criar** `email_messages` + `email_attachments`.
5. **Encadear**: resolver `thread_id` por `In-Reply-To` / `References`; em falta, por assunto normalizado + participantes.
6. **Despachar** `ProcessInboundEmail` e responder **200 em menos de 2 s**. Nenhum trabalho pesado no webhook.
7. O job despacha `RunAgent` para o agente dono da caixa.

### 9.3 Saída — SMTP por caixa

Credenciais SMTP vivem por caixa, encriptadas. Mailer construído em runtime:

```php
Mail::build([
    'transport'  => 'smtp',
    'host'       => $mailbox->smtp_host,
    'port'       => $mailbox->smtp_port,
    'encryption' => $mailbox->smtp_encryption,
    'username'   => $mailbox->smtp_username,
    'password'   => decrypt($mailbox->smtp_password),
])->send($mailable);
```

**Todo o envio passa pelo portão de autonomia.** Abaixo de N3 o agente não envia: cria uma aprovação com o rascunho e espera. Nenhuma excepção.

### 9.4 Segurança

- Anexos guardados fora do webroot, verificados por MIME real e não por extensão.
- Limite de tamanho por anexo e por mensagem; acima do limite guarda-se metadados e descarta-se o conteúdo.
- O conteúdo do email é **dados, nunca instruções**. O prompt do agente tem de o declarar explicitamente: texto dentro de um email que pareça uma ordem ao agente é ignorado e sinalizado. Teste de regressão obrigatório com um email de injecção de prompt.

---

## 10. Tempo real (Reverb)

### 10.1 Canais

```php
// routes/channels.php
Broadcast::channel('tenant.{tenantId}.agents', fn ($user, $tenantId) =>
    $user->tenant_id === (int) $tenantId);

Broadcast::channel('tenant.{tenantId}.run.{runId}', function ($user, $tenantId, $runId) {
    return $user->tenant_id === (int) $tenantId
        && AgentRun::whereKey($runId)->where('tenant_id', $tenantId)->exists();
});

Broadcast::channel('tenant.{tenantId}.approvals', fn ($user, $tenantId) =>
    $user->tenant_id === (int) $tenantId && $user->can('approve', Approval::class));

Broadcast::channel('tenant.{tenantId}.user.{userId}', fn ($user, $tenantId, $userId) =>
    $user->tenant_id === (int) $tenantId && $user->id === (int) $userId);
```

### 10.2 Eventos

| Evento | Canal | Quando |
|---|---|---|
| `AgentRunStarted` | `…agents`, `…run.{id}` | run começa |
| `AgentRunStepAdded` | `…run.{id}` | cada passo |
| `AgentRunFinished` | `…agents`, `…run.{id}` | run termina |
| `ApprovalRequested` | `…approvals`, `…user.{id}` | portão bloqueia uma acção |
| `ApprovalDecided` | `…approvals`, `…run.{id}` | humano decide |
| `EmailReceived` | `…agents` | entrada tratada |
| `BriefingReady` | `…user.{id}` | briefing gerado |

O `broadcast()` do `laravel/ai` serve para transmitir a saída do modelo token a token para `tenant.{t}.run.{id}`. Usar na consola de agente; **não** usar em ecrãs de lista.

---

## 11. Frontend

### 11.1 Rotas e páginas

| Rota | Página | Conteúdo |
|---|---|---|
| `/` | `Dashboard` | briefing do dia, aprovações pendentes, estado dos agentes |
| `/agents` | `Agents/Index` | grelha dos seis agentes, estado, nível de autonomia |
| `/agents/{agent}` | `Agents/Show` | ficha, competências, caixa, histórico de runs |
| `/agents/{agent}/settings` | `Agents/Settings` | instruções, modelo, autonomia, chefia, afectação |
| `/runs/{run}` | `Runs/Show` | linha temporal de passos, ao vivo |
| `/approvals` | `Approvals/Index` | fila de aprovações com acção em dois cliques |
| `/inbox` | `Inbox/Index` | correio triado por categoria e por caixa |
| `/knowledge` | `Knowledge/Index` | memória organizacional, pesquisa |
| `/briefings` | `Briefings/Index` | histórico de briefings |
| `/settings/*` | `Settings/*` | tenant, departamentos, utilizadores, ERP, caixas |

### 11.2 Componentes shadcn a instalar

```bash
npx shadcn@latest add button card table badge dialog sheet tabs form input \
  select textarea switch dropdown-menu avatar separator skeleton toast \
  alert alert-dialog tooltip popover command scroll-area progress
```

### 11.3 Regras

- Hook `useEchoChannel(channel, events)` partilhado; nenhum componente abre uma ligação própria.
- Nível de autonomia representado sempre pelo mesmo componente `<AutonomyBadge level={n} />` (N0–N4), em toda a aplicação.
- Estados vazios desenhados, não `null`. Um agente sem runs mostra o que faz e o que está à espera.
- Tema Rethink: azul `#0052CC` como acento único (ver `tailwind.config.js` com os tokens da marca).

---

## 12. Autonomia, aprovações e auditoria

### 12.1 A escada

| Nível | O agente | Exemplo |
|---|---|---|
| **N0** | observa e organiza | lê e classifica correio |
| **N1** | sugere | prepara mapa comparativo; humano escolhe |
| **N2** | executa com aprovação | rascunho pronto, falta um clique |
| **N3** | executa dentro de limites | compras recorrentes até X MZN |
| **N4** | executa e reporta | tarefas repetitivas, auditadas |

### 12.2 O portão — implementação

Nenhum agente chama uma ferramenta mutante directamente. O `ToolResolver` embrulha cada ferramenta num `GatedTool`:

```php
final class GatedTool implements Tool
{
    public function __construct(
        private Tool $inner,
        private Skill $skill,
        private AgentRecord $agent,
        private AgentRun $run,
    ) {}

    public function handle(Request $request): string
    {
        if (! $this->skill->is_mutating || $this->agent->autonomy_level >= $this->skill->risk) {
            $result = $this->inner->handle($request);
            AuditLog::record($this->agent, $this->skill->key, $request->all(), 'ok');
            return $result;
        }

        $approval = Approval::request($this->run, $this->skill, $request->all());
        AuditLog::record($this->agent, $this->skill->key, $request->all(), 'denied');

        return "Acção suspensa e enviada para aprovação humana (#{$approval->id}). "
             . "Não voltes a tentar executá-la nesta sessão. Continua com o resto do trabalho.";
    }
}
```

### 12.3 Tecto absoluto

Independentemente do nível configurado, estas acções **nunca** executam sem decisão humana registada. Implementar como lista fechada em `config/autonomy.php`, verificada antes do nível do agente:

- qualquer saída de dinheiro ou instrução de pagamento
- emissão de factura a cliente
- assinatura ou aceitação de contrato
- contratação, cessação ou alteração contratual de pessoas
- envio de comunicação para fora da organização acima de um valor ou a uma entidade nova
- alteração de permissões de agentes ou utilizadores

---

## 13. Memória organizacional

### 13.1 O que entra

- Decisões registadas pelo Chief of Staff após reuniões de direcção.
- Briefings e resumos de reuniões submetidos por humanos.
- Documentos novos (propostas, contratos, cadernos de encargos) carregados na plataforma.
- Padrões extraídos de runs concluídos — por exemplo, o resultado de propostas submetidas.

### 13.2 Pipeline

`ingest → chunk (≈800 tokens, 15% overlap) → embed → knowledge_embeddings → disponível via SearchKnowledge`

A competência `SearchKnowledge` é dada a todos os agentes. A `RememberDecision` é exclusiva do Chief of Staff — a memória não é escrita por qualquer agente, senão enche-se de ruído.

### 13.3 Regra de confiança

Conteúdo da memória que tenha origem em email ou documento externo é marcado `source=external` e entra no prompt dentro de delimitadores explícitos, declarado como dados não confiáveis.

---

## 14. Filas, agendamento e observabilidade

### 14.1 Filas (Horizon)

| Fila | Trabalho | Concorrência |
|---|---|---|
| `agents-high` | runs disparados por humano | 5 |
| `agents` | runs por email e por agente | 10 |
| `agents-low` | agendados, reprocessamentos | 3 |
| `email` | entrada, parsing, OCR | 10 |
| `embeddings` | chunking e embedding | 3 |

Timeout dos jobs de agente: 300 s. `tries: 3` com backoff exponencial. Falha definitiva → `agent_runs.status = failed` + evento Reverb + entrada de auditoria.

### 14.2 Agendamento

```php
Schedule::command('agents:daily-briefing')->weekdays()->at('06:30');
Schedule::command('agents:scan-tenders')->hourly();
Schedule::command('agents:chase-receivables')->weekdays()->at('09:00');
Schedule::command('erp:health-check')->everyFifteenMinutes();
Schedule::command('agents:cost-rollup')->dailyAt('23:50');
```

Todos iteram sobre tenants activos e despacham por tenant.

### 14.3 Custo e limites

`agent_runs` guarda tokens e custo por run. Limites configuráveis em `tenants.settings`:

- tecto mensal por tenant
- tecto mensal por agente
- tecto por run

Ao atingir 80% → aviso na consola. Aos 100% → o agente passa a `suspended` e notifica o responsável. **Implementar na E02, não depois** — é o que impede uma fuga de custos silenciosa.

---

## 15. Segurança

- Credenciais (SMTP, ERP, provedores) encriptadas com `encrypted` casts. Nunca em logs.
- `APP_KEY` por ambiente; rotação documentada.
- Webhooks: verificação de assinatura obrigatória + `throttle`.
- Rate limiting nas rotas de agente para evitar que um utilizador dispare runs em cadeia.
- Política de retenção: `[CONFIRMAR]` quanto tempo se guarda o email bruto e os anexos.
- Exportação integral dos dados do tenant por comando artisan — é compromisso contratual (secção 7 da proposta).
- Logs de auditoria imutáveis e exportáveis.
- `larastan` nível 6+ e `pint` no CI.

---

## 16. Testes

| Tipo | Alvo | Obrigatório |
|---|---|---|
| Isolamento de tenant | todos os modelos | sim, bloqueia merge |
| Portão de autonomia | cada nível N0–N4 × ferramenta mutante | sim |
| Tecto absoluto | as seis acções da secção 12.3 | sim |
| Webhook de email | assinatura inválida, duplicado, anexo grande | sim |
| Injecção de prompt | email com instruções ao agente | sim |
| Cliente MCP | contra `FakeErpServer` | sim |
| Ferramentas MCP | `WeatherServer::tool(...)` estilo do package | sim |
| Reverb | autorização de canal por tenant | sim |

Agentes testam-se com respostas de modelo em fixture, não com chamadas reais. Reservar um conjunto pequeno de testes de integração com modelo real, fora do CI, corridos à mão antes de cada entrega.

---

## 17. Configuração

```dotenv
APP_NAME="Plataforma de Agentes"
APP_URL=https://plataforma.rethink.co.mz

DB_CONNECTION=mysql
DB_DATABASE=micomoc_agents

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=
REVERB_PORT=443
REVERB_SCHEME=https

# IA — [CONFIRMAR] provedor e modelo
AI_PROVIDER=anthropic
AI_MODEL=
ANTHROPIC_API_KEY=

# ERP
ERP_MCP_TRANSPORT=local          # local | web
ERP_MCP_URL=
ERP_MCP_TOKEN=
ERP_MCP_LOCAL_COMMAND="php artisan mcp:start fake-erp"

# Email — [CONFIRMAR] provedor
MAIL_INBOUND_PROVIDER=
MAIL_INBOUND_SIGNING_KEY=
MAIL_DOMAIN=

# Limites
AI_MONTHLY_BUDGET_USD=
AI_RUN_MAX_TOKENS=
```

---

## 18. Estrutura do repositório

```
app/
  Ai/
    Agents/        BaseAgent, TriageAgent, ChiefOfStaffAgent, …
    Tools/         ClassifyEmail, CompareQuotes, SearchKnowledge, …
    Gates/         GatedTool, ToolResolver, AutonomyPolicy
    Support/       AgentRunner, InstructionComposer, CostMeter
  Mcp/
    Servers/       FakeErpServer
    Tools/         ferramentas do servidor falso
  Tenancy/         TenantManager, TenantScope, BelongsToTenant, IdentifyTenant
  Email/           InboundPipeline, ThreadResolver, MailboxMailer
  Knowledge/       Ingestor, Chunker, Embedder
  Models/
  Jobs/            RunAgent, ProcessInboundEmail, EmbedKnowledgeItem
  Events/          AgentRunStarted, ApprovalRequested, …
  Http/
    Controllers/
    Webhooks/      InboundEmailController
resources/js/
  Pages/           Dashboard, Agents/, Runs/, Approvals/, Inbox/, Knowledge/
  Components/      AutonomyBadge, RunTimeline, ApprovalCard, AgentCard
  Hooks/           useEchoChannel
routes/
  web.php  ai.php  channels.php  console.php
docs/
  ERP-MCP-CONTRACT.md       ← entregar à equipa do ERP
  AGENT-PROMPTS.md          ← prompts base, versionados
```

---

## 19. Plano de execução

Alinhado com as oito entregas contratuais. Cada entrega termina com demonstração e auto de aceitação.

### E00 — Fundação *(incluída na E01, sem facturação própria)*
- [ ] Repositório, Laravel 13, PHP 8.3, Pint, Larastan, Pest, CI
- [ ] Tenancy completa (secção 4) + teste de isolamento a passar
- [ ] Auth, utilizadores, departamentos, papéis
- [ ] Layout Inertia + shadcn com o tema Rethink
- [ ] Horizon, Redis, Reverb a correr em local e em staging

### E01 — Camada MCP · semanas 1–4 · 60.000 MT
- [ ] `laravel/mcp` instalado; `routes/ai.php`
- [ ] `erp_connections` + ecrã de configuração e teste de ligação
- [ ] `McpServiceProvider` com cliente nomeado `rethink_erp`, transporte comutável
- [ ] `FakeErpServer` com as ferramentas da secção 8.3 e fixtures realistas
- [ ] `docs/ERP-MCP-CONTRACT.md` escrito e entregue à equipa do ERP
- [ ] Auditoria de todas as chamadas MCP
- [ ] `php artisan mcp:inspector` a funcionar contra o servidor falso
- **Pronto quando:** um comando artisan lista as ferramentas do ERP, chama uma de leitura e uma de escrita, e ambas aparecem em `audit_logs`.

### E02 — Plataforma base de agentes · semanas 5–8 · 62.000 MT
- [ ] `agents`, `skills`, `agent_skill`, `agent_runs`, `agent_run_steps`, `approvals`, `audit_logs`
- [ ] `BaseAgent`, `AgentRunner`, `InstructionComposer`, `ToolResolver`
- [ ] `GatedTool` + níveis N0–N4 + tecto absoluto
- [ ] Memória: `knowledge_items`, ingestão, chunking, embeddings, `SearchKnowledge`
- [ ] Hierarquia, departamentos, afectação de agentes a pessoas
- [ ] Eventos Reverb e canais autorizados por tenant
- [ ] Consola: lista de agentes, ficha, linha temporal de run ao vivo, fila de aprovações, suspender agente
- [ ] `mailboxes` + envio SMTP por caixa (entrada fica para a E03)
- [ ] Medição de custo e limites com corte automático
- **Pronto quando:** um agente de demonstração corre em fila, usa uma ferramenta do ERP falso, bloqueia numa acção acima do seu nível, cria aprovação, um humano aprova pela consola e a acção conclui — tudo visível ao vivo e registado.

### E03 — Agente Comercial / Triagem · semanas 9–10 · 30.000 MT
- [ ] Webhook de entrada, verificação de assinatura, deduplicação, bruto em disco
- [ ] `email_messages`, `email_threads`, `email_attachments`, encadeamento
- [ ] OCR e extracção de anexos
- [ ] `TriageAgent` + `ClassifyEmail` + extracção de campos
- [ ] Criação e actualização de leads no ERP via MCP
- [ ] Monitorização de portais de concursos `[CONFIRMAR]` fontes
- [ ] Encaminhamento, vigilância de prazo, resumo diário
- [ ] Ecrã `/inbox`
- [ ] Teste de injecção de prompt a passar
- **Pronto quando:** um email real enviado para a caixa de triagem aparece classificado na consola em menos de 60 s, com o lead criado no ERP e o responsável notificado.

### E04 — Chief of Staff · semanas 11–12 · 30.000 MT
- [ ] `ChiefOfStaffAgent`, consolidação transversal
- [ ] `briefings` + geração diária e semanal + entrega por email e consola
- [ ] Detecção de bloqueios e inconsistências entre áreas
- [ ] `RememberDecision` e distribuição de decisões pelos agentes
- [ ] Dashboard de direcção
- **Pronto quando:** às 06:30 de um dia útil o briefing chega ao responsável e abre na consola com ligações para os itens que precisam de decisão.

### E05 — Agente de Finanças e Contabilidade · semanas 13–15 · 42.000 MT
- [ ] Recolha e classificação de facturas de fornecedor
- [ ] Facturação a clientes (rascunho) e perseguição de recebimentos
- [ ] Reconciliação bancária `[CONFIRMAR]` origem dos extractos
- [ ] Margem por projecto e alerta de desvio
- [ ] Preparação do pacote de fecho do mês

### E06 — Agente de Procurement · semanas 16–18 · 42.000 MT
- [ ] Requisição → RFQ, mapa comparativo, rascunho de ordem de compra
- [ ] Acompanhamento de entrega e avaliação de fornecedores
- [ ] Alertas de contratos a expirar

### E07 — Agente de Recursos Humanos · semanas 19–21 · 42.000 MT
- [ ] Consolidação de assiduidade, faltas, férias e horas extraordinárias
- [ ] Preparação da folha de salários para aprovação
- [ ] Triagem de candidaturas e integração de novos colaboradores
- **Pré-requisito contratual:** o módulo de RH do ERP tem de estar activo. **A sua configuração não faz parte deste âmbito.** Se não estiver activo à semana 19, esta entrega não arranca.

### E08 — Gestor de Clientes · semanas 22–24 · 42.000 MT
- [ ] Ficha viva de cliente, briefing pré-reunião
- [ ] Vigilância de SLA, prazos e pendências
- [ ] Detecção de renovações
- [ ] Redacção de comunicação para aprovação

---

## 20. Definição de pronto (todas as entregas)

1. Testes verdes, incluindo isolamento de tenant e portão de autonomia.
2. Larastan sem erros novos; Pint aplicado.
3. Migrações reversíveis e corridas em staging com dados realistas.
4. Documentação da entrega em `docs/`.
5. Demonstração em ambiente real gravada.
6. Auto de aceitação assinado.
7. Nenhuma credencial em repositório ou em logs.

---

## 21. Questões em aberto — resolver no kick-off

| # | Questão | Bloqueia |
|---|---|---|
| 1 | Provedor de email de entrada (Mailgun, Postmark, outro) e quem administra o DNS do domínio | E03 |
| 2 | Domínio das caixas dos agentes | E03 |
| 3 | Provedor e modelo de IA, e orçamento mensal de consumo | E02 |
| 4 | Quem constrói o servidor MCP do lado do ERP, e em que prazo | E01 (mitigado pelo servidor falso) |
| 5 | Versão de MySQL do alojamento — define a estratégia de vectores | E02 |
| 6 | Alojamento e processo de deploy (Forge, VPS, contentores) | E00 |
| 7 | Autenticação: email e palavra-passe, ou SSO | E00 |
| 8 | Idioma da interface — só português, ou português e inglês | E00 |
| 9 | Fontes de concursos a monitorizar | E03 |
| 10 | Origem dos extractos bancários para reconciliação | E05 |
| 11 | Política de retenção de email bruto e anexos | E03 |
| 12 | Níveis de autonomia iniciais de cada agente | E02 |

---

## 22. Fontes verificadas

Consultadas a 04.10.2026 para fixar versões e APIs:

- [Laravel AI SDK — documentação oficial](https://laravel.com/framework/docs/ai-sdk)
- [Laravel MCP — documentação oficial](https://laravel.com/framework/docs/mcp)
- [Building AI Agents with Laravel: No Python Required](https://laravel.com/blog/building-ai-agents-with-laravel-no-python-required)
- [laravel/ai no Packagist — versões e dependências](https://packagist.org/packages/laravel/ai)
- [Laravel 14 — estado e data prevista](https://laravel-news.com/laravel-14)
- [Laravel Versions — datas de lançamento e suporte](https://laravelversions.com/)
