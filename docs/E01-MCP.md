# E01 — Camada MCP

Estado da entrega E01 (secção 19 da [especificação](SPEC.md)). A E00 está em [E00-FUNDACAO.md](E00-FUNDACAO.md).

## Checklist

- [x] `laravel/mcp` instalado; `routes/ai.php` regista o servidor falso (`Mcp::local('fake-erp', FakeErpServer::class)`)
- [x] `erp_connections` + ecrã de configuração e teste de ligação (**Definições → Ligação ao ERP**, só proprietário e administradores)
- [x] `McpServiceProvider` com cliente nomeado `rethink_erp`, transporte comutável por tenant (`web` ou `local`)
- [x] `FakeErpServer` com as 27 ferramentas da secção 8.3 e fixtures fictícias em MZN
- [x] `docs/ERP-MCP-CONTRACT.md` escrito — **falta entregar à equipa do ERP**
- [x] Auditoria de todas as chamadas MCP (`audit_logs`, append-only)
- [x] `php artisan mcp:inspector fake-erp` arranca contra o servidor falso
- **Pronto quando:** `php artisan erp:smoke micomoc` lista as ferramentas, chama `crm.search_accounts` (leitura) e `leads.create` (escrita), e mostra as duas em `audit_logs`. Coberto também pelo teste `tests/Feature/Erp/ErpCommandsTest.php`.

## Como está montado

```
App\Erp\ErpGateway          único ponto de acesso ao ERP; audita cada chamada
  └─ ClientManager::build('rethink_erp')   cliente novo por operação, fechado no fim
       └─ App\Erp\ErpClientFactory          escolhe o transporte pela ligação do tenant
            ├─ web   → Client::web(base_url)->withToken(token)->withTimeout(30)
            └─ local → Client::local(php artisan mcp:start fake-erp)
App\Mcp\Servers\FakeErpServer    servidor falso (stdio), estado em ERP_FAKE_STORAGE_PATH
  └─ App\Mcp\FakeErp\Modules\*   ferramentas por módulo: Core, Crm, Leads, Projects, Invoices, Procurement, Expenses
```

- **Um cliente por operação.** O `Mcp::client()` guarda clientes por nome durante a vida do processo; num worker do Horizon isso levaria a ligação de um tenant para o job seguinte. O gateway usa `build()` e fecha o cliente no fim; além disso, todos os clientes são fechados no fim de cada job.
- **Sem tenant não há chamada.** O gateway exige tenant actual (`NoTenantException`).
- **Sem ligação configurada:** fora de produção usa a ligação por omissão do `.env` (`ERP_MCP_*`, servidor falso). Em produção recusa (`ErpNotConfiguredException`), para um tenant nunca usar credenciais que não são suas.
- **O comando local vem só da configuração** (`ERP_MCP_LOCAL_COMMAND`), nunca da base de dados.
- **Auditoria:** acções `erp.tools_list`, `erp.tool_call`, `erp.connection_test` e `erp.connection_updated`, com actor (pessoa, agente ou sistema), argumentos, resultado (resumido acima de 8 KB), erro e duração. `AuditLog` recusa alterações e remoções.
- Um teste de arquitectura impede o uso de `Laravel\Mcp\Client` fora de `App\Erp`. Na E02, as ferramentas do ERP chegam aos agentes através do gateway (e do `GatedTool`), não por `Mcp::client('rethink_erp')->tools()` directamente como no exemplo da secção 8.1, para que nenhuma chamada fuja à auditoria.

## Comandos

| Comando | O que faz |
|---|---|
| `php artisan erp:smoke {tenant}` | Prova da E01 (acima). |
| `php artisan erp:tools {tenant}` | Lista as ferramentas do ERP do tenant. |
| `php artisan erp:call {tenant} {ferramenta} '{json}'` | Chama uma ferramenta, ex.: `erp:call micomoc invoices.list_receivables '{"overdue_only":true}'`. |
| `php artisan erp:health-check` | Despacha um teste de ligação por tenant activo; agendado a cada 15 minutos (secção 14.2). |
| `php artisan mcp:inspector fake-erp` | Abre o MCP Inspector contra o servidor falso. |

O servidor falso guarda o estado em `storage/app/private/fake-erp/state.json`. Apagar o ficheiro repõe as fixtures.

## Desvios e pontos em aberto

- **`invoices.issue`** contradiz a regra "nenhuma ferramenta emite" da própria secção 8.3. O servidor falso exige `approval_reference` e só passa a factura a `pending_confirmation`. `[CONFIRMAR]` com a direcção e a equipa do ERP (ver o contrato, secção 5).
- **`audit_logs`** estava listada na E02; foi criada já na E01 porque a auditoria das chamadas MCP é critério desta entrega.
- **Prazo do servidor real** (questão 4) continua em aberto. Mudar para o servidor real é configuração no ecrã, sem código.
