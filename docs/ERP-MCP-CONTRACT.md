# Contrato do servidor MCP do Rethink ERP

**Para:** equipa do Rethink ERP
**De:** equipa da plataforma de agentes MICOMOC (ref. RT-2026-MCM-01)
**Versão:** 0.1, 04.10.2026 — base: secção 8.3 da especificação

A plataforma de agentes liga-se ao ERP como **cliente MCP**. Este documento diz o que o servidor MCP do ERP tem de expor para os agentes funcionarem. Existe uma **implementação de referência** com dados fictícios, o `FakeErpServer`, no repositório da plataforma: em caso de dúvida, o comportamento dele é o esperado.

## 1. Transporte e autenticação

| Item | Requisito |
|---|---|
| Protocolo | MCP, transporte *Streamable HTTP*, num único endpoint (ex.: `https://erp.exemplo.co.mz/mcp`). |
| Versões do protocolo | O cliente (`laravel/mcp` 1.0.1) negocia `2026-07-28`, `2025-11-25`, `2025-06-18`, `2025-03-26` ou `2024-11-05`. Basta suportar uma delas; recomendamos `2025-06-18` ou posterior. |
| Autenticação | `Authorization: Bearer <token>`. Um token por organização cliente (tenant). O token identifica a organização: o servidor nunca devolve dados de outra. |
| Tempo de resposta | O cliente desiste ao fim de **30 s**. Operações mais longas devem devolver um rascunho e terminar depois no ERP. |
| Paginação de `tools/list` | Suportada pelo cliente; é aceitável devolver todas as ferramentas numa página. |
| Ambientes | Pedimos um ambiente de testes com dados fictícios e um token próprio antes do acesso a produção. |

## 2. Regras para todas as ferramentas

1. **Nomes** exactamente como na tabela da secção 4 (`modulo.accao`).
2. **Anotações:** ferramentas de leitura com `readOnlyHint: true`; ferramentas de escrita com `readOnlyHint: false` e `idempotentHint: true`. A plataforma usa estas anotações para decidir o que precisa de aprovação humana.
3. **Idempotência:** toda a ferramenta que escreve aceita um argumento obrigatório `idempotency_key` (texto).
   - A mesma chave com os mesmos argumentos devolve o **resultado da primeira chamada**, sem voltar a escrever.
   - A mesma chave com argumentos diferentes devolve um erro.
   - Sugerimos guardar as chaves pelo menos 7 dias.
4. **Só rascunhos.** Nenhuma ferramenta emite, paga ou assina. As ferramentas devolvem rascunhos (`*_draft`, estado `draft`) que um humano confirma no ERP ou na consola da plataforma.
5. **Resultados** em `structuredContent` (objecto JSON), repetidos como texto JSON em `content`, como manda a especificação MCP. Também se aceita uma `outputSchema` por ferramenta.
6. **Erros de negócio** (registo inexistente, transição inválida, validação) devolvem um resultado com `isError: true` e uma **mensagem legível em português**. Nunca stack traces, SQL ou caminhos de ficheiros. Erros de protocolo (ferramenta inexistente, JSON inválido) usam os códigos JSON-RPC normais.
7. **Formatos:**
   - Identificadores em texto (ex.: `ACC-0001`). A plataforma não depende do formato.
   - Valores monetários em número decimal com `currency` (`MZN` por omissão). IVA a 16% calculado pelo ERP.
   - Datas em `YYYY-MM-DD`; instantes em ISO 8601 com fuso.
   - NUIT em texto com 9 dígitos.

## 3. O que a plataforma faz do seu lado

- Cada chamada fica registada na auditoria da plataforma (`audit_logs`): tenant, quem pediu (pessoa, agente ou sistema), ferramenta, argumentos, resultado ou erro, e duração.
- A ligação é testada a cada 15 minutos com `tools/list` e `erp.health`.
- O token fica encriptado na base de dados da plataforma e nunca aparece em logs nem no ecrã.

## 4. Ferramentas

`*` = argumento obrigatório. As ferramentas de escrita têm ainda o `idempotency_key*` (secção 2).

| Ferramenta | Tipo | Argumentos | O que faz |
|---|---|---|---|
| `erp.whoami` | leitura | — | Identifica a organização e o utilizador técnico associados ao token. |
| `erp.health` | leitura | — | Estado do servidor do ERP. |
| `erp.search` | leitura | `query`*, `limit` | Pesquisa transversal: clientes, contactos, leads, projectos, facturas e fornecedores. |
| `crm.search_accounts` | leitura | `query`, `status`, `limit` | Procura clientes por nome, NUIT, cidade ou sector. |
| `crm.get_account` | leitura | `account_id`* | Ficha de um cliente com contactos, projectos e saldo em aberto. |
| `crm.create_contact` | escrita | `account_id`*, `name`*, `email`, `phone`, `role` | Cria um contacto num cliente existente. |
| `crm.update_account` | escrita | `account_id`*, `email`, `phone`, `city`, `sector`, `payment_terms_days`, `status` | Actualiza dados de contacto e condições de um cliente. Não altera o NUIT. |
| `leads.create` | escrita | `title`*, `account_id`, `company_name`, `source`*, `estimated_value`, `notes` | Regista uma oportunidade comercial. `company_name` é obrigatório sem `account_id`. |
| `leads.update` | escrita | `lead_id`*, `status`, `estimated_value`, `notes` | Actualiza o estado, valor ou notas de uma oportunidade. |
| `leads.search` | leitura | `query`, `status`, `account_id` | Procura oportunidades por texto, estado ou cliente. |
| `leads.attach_document` | escrita | `lead_id`*, `filename`*, `reference`*, `description` | Associa um documento (por referência) a uma oportunidade. |
| `projects.create` | escrita | `account_id`*, `name`*, `budget`*, `start_date`*, `end_date`, `manager` | Cria um projecto no estado `planned`. |
| `projects.get` | leitura | `project_id`* | Ficha de um projecto com execução orçamental, facturas e despesas. |
| `projects.list_by_account` | leitura | `account_id`*, `status` | Lista os projectos de um cliente. |
| `projects.update_status` | escrita | `project_id`*, `status`*, `reason` | Muda o estado de um projecto, respeitando as transições permitidas. |
| `invoices.create_draft` | escrita | `account_id`*, `project_id`, `lines`* | Cria um rascunho de factura. Não emite. |
| `invoices.issue` | escrita | `invoice_id`*, `approval_reference`* | Ver secção 5. |
| `invoices.list_receivables` | leitura | `account_id`, `overdue_only` | Facturas emitidas por receber, com valor em dívida e dias de atraso. |
| `invoices.get` | leitura | `invoice_id`* | Detalhe de uma factura. |
| `procurement.create_rfq` | escrita | `title`*, `project_id`, `items`*, `supplier_ids`*, `due_date` | Cria um pedido de cotação (rascunho) a enviar a fornecedores. |
| `procurement.list_suppliers` | leitura | `category`, `query` | Lista fornecedores, filtrando por categoria ou texto. |
| `procurement.compare_quotes` | leitura | `rfq_id`* | Compara as cotações de um pedido, da mais barata para a mais cara, e indica a mais rápida. |
| `procurement.create_po_draft` | escrita | `rfq_id`*, `quote_id`* | Cria um rascunho de nota de encomenda a partir de uma cotação. Não confirma a encomenda. |
| `procurement.receive` | escrita | `po_id`*, `lines`*, `notes` | Regista a recepção (total ou parcial) de uma encomenda confirmada. Não paga. |
| `expenses.create` | escrita | `description`*, `amount`*, `date`*, `project_id`, `supplier` | Regista uma despesa em rascunho. Não paga. |
| `expenses.classify` | escrita | `expense_id`*, `category`*, `project_id` | Classifica uma despesa por categoria e, opcionalmente, projecto. |
| `expenses.list_by_project` | leitura | `project_id`* | Lista as despesas de um projecto com o total. |

Os esquemas completos (tipos, enumerações, campos dos objectos em `lines` e `items`) estão no `FakeErpServer`. Para os ver: `php artisan erp:tools <tenant>` ou `php artisan mcp:inspector fake-erp` no repositório da plataforma.

## 5. Ponto por decidir: `invoices.issue` `[CONFIRMAR]`

A especificação lista `invoices.issue` e diz, na mesma secção, que nenhuma ferramenta emite. Até haver decisão, o servidor falso faz assim:

- exige `approval_reference`, a referência da aprovação humana registada na plataforma;
- só aceita facturas em `draft`;
- **não emite**: passa a factura a `pending_confirmation`, e a emissão fiscal é feita por uma pessoa no ERP.

**Pergunta à equipa do ERP e à direcção:** a emissão fica sempre no ERP (como acima), ou o ERP aceita emitir quando recebe uma aprovação válida da plataforma? A segunda hipótese exige que o ERP verifique a aprovação.

## 6. Também por confirmar

- Prazo de entrega do servidor (questão 4 da secção 21). Até lá a plataforma trabalha contra o servidor falso; quando o servidor real estiver pronto, muda-se a configuração da ligação no ecrã **Definições → Ligação ao ERP**, sem alterar código.
- Se o ERP preferir OAuth em vez de token fixo, a tabela `erp_connections` já prevê `auth_type = oauth`; o cliente MCP suporta-o.
