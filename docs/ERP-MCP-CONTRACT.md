# Contrato do servidor MCP do Rethink ERP

**Para:** equipa do Rethink ERP
**De:** equipa da plataforma de agentes MICOMOC (ref. RT-2026-MCM-01)
**Versão:** 0.3, 19.11.2026 — base: secção 8.3 da especificação, mais 11 ferramentas pedidas para Finanças, Compras e RH (secção 4.1) e 8 para o Director de Recursos Humanos (secção 4.2)

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

### 4.1 Ferramentas adicionais (versão 0.2)

Os agentes de Finanças, Compras e RH (entregas E05 a E07) precisam também destas. Sem elas os agentes funcionam, mas sem margens por projecto, sem registo de cotações recebidas por email e sem nada de RH.

| Ferramenta | Tipo | Argumentos | O que faz |
|---|---|---|---|
| `projects.list` | leitura | `status` | Lista os projectos com execução orçamental e facturação (`budget`, `spent`, `invoiced`), filtrando por estado. |
| `procurement.record_quote` | escrita | `rfq_id`*, `supplier_id`*, `total`*, `delivery_days`, `valid_until`, `notes` | Regista uma cotação recebida de um fornecedor para um pedido de cotação. |
| `procurement.list_orders` | leitura | `status`, `supplier_id` | Lista notas de encomenda com estado, data prevista de entrega (`expected_date`) e recepções. |
| `hr.list_employees` | leitura | `department` | Lista os colaboradores activos, filtrando por departamento: cargo, departamento, chefia, admissão. **Sem contratos, salários nem documentos** (desde a versão 0.3: estão em `hr.get_employee`), para poder ser lida por quem não gere pessoas. |
| `hr.attendance_summary` | leitura | `period`*, `department` | Assiduidade de um mês (`AAAA-MM`) por colaborador: presenças, faltas justificadas e injustificadas, atrasos e horas extraordinárias. Desde a versão 0.3 também: `month_closed` (o mês está fechado para salários), `open_leave_requests`, os limites legais de horas extraordinárias que o ERP aplica (`overtime_limits`) e, por pessoa, as horas do trimestre e do ano e `over_limit`. |
| `hr.list_leave` | leitura | `status`, `employee_id`, `from`, `to` | Pedidos de férias e ausências, filtrando por estado, pessoa e período (os que se sobrepõem a `from`–`to`), com o nome da pessoa. |
| `hr.prepare_payroll_draft` | escrita | `period`* | Prepara o rascunho da folha de salários de um mês a partir da assiduidade. Não processa nem paga: um humano aprova no ERP. |
| `hr.list_openings` | leitura | — | Vagas abertas com os requisitos. |
| `hr.create_candidate` | escrita | `opening_id`*, `name`*, `email`, `score`, `summary`, `recommendation` | Regista uma candidatura a uma vaga, com a avaliação da triagem (`shortlist`, `hold` ou `reject`). |
| `hr.list_candidates` | leitura | `opening_id` | Candidaturas registadas, por vaga. |
| `hr.create_onboarding` | escrita | `name`*, `position`*, `start_date`*, `department` | Abre o plano de integração de um novo colaborador (rascunho de tarefas). |

Os esquemas completos (tipos, enumerações, campos dos objectos em `lines` e `items`) estão no `FakeErpServer`. Para os ver: `php artisan erp:tools <tenant>` ou `php artisan mcp:inspector fake-erp` no repositório da plataforma.

### 4.2 Ferramentas para o Director de Recursos Humanos (versão 0.3)

O agente de RH passou a **Director de Recursos Humanos** (`docs/DECISOES.md`, 19.11.2026): o ERP faz o trabalho de RH e produz os documentos; o agente lê, vigia as datas, verifica a assiduidade, propõe actualizações às fichas e reporta. Folhas de salários: o ERP processa, aprova e paga; a plataforma só lê.

| Ferramenta | Tipo | Argumentos | O que faz |
|---|---|---|---|
| `hr.attendance_today` | leitura | `date` (por omissão hoje), `department` | Um dia: turno, entrada, saída, licença aprovada e o estado de cada pessoa (`present`, `late`, `absent`, `on_leave`, `off`, `no_check_in` antes do início do turno), com as contagens. |
| `hr.attendance_daily` | leitura | `from`*, `to`, `employee_id`, `department` | Dia a dia por pessoa num período (até 62 dias): estado, entrada, saída, minutos de atraso, licença. |
| `hr.upcoming_dates` | leitura | `days` (1 a 365, por omissão 60), `kind` | Datas de RH a chegar: `probation_end`, `contract_end`, `id_expiry`, `document_expiry`, `certificate_expiry`, `birthday`, `anniversary`, `overtime_limit`, `sick_leave`, `disciplinary`, `filing`, `asset_return`. Cada item com `date`, `days_left`, a pessoa e um texto. |
| `hr.get_employee` | leitura | `employee_id`* | Ficha de RH: registo, contrato (com salário base), documentos e validades, pedidos de férias e as datas dos próximos 90 dias. |
| `hr.people_indicators` | leitura | — | Efectivo por departamento, admissões e saídas em 12 meses, rotatividade, absentismo do último mês fechado, horas extraordinárias do ano, custo salarial por colaborador. |
| `hr.list_payroll_runs` | leitura | `period`, `year`, `status` | Folhas processadas no ERP (`draft`, `approved`, `paid`) com os totais: colaboradores, bruto, INSS do trabalhador e da empresa, IRPS, líquido, custo para a empresa. Com `period`, essa folha com uma linha por pessoa e `attendance_closed`. |
| `hr.get_payslip` | leitura | `period`*, `employee_id`*, `pdf` | O recibo de uma pessoa num mês: rendimentos, descontos, líquido. Com `pdf: true`, `download_url` e `file_name` como as outras ferramentas de PDF. |
| `hr.update_employee` | escrita | `employee_id`*, `position`, `department`, `manager_id`, `status`, `termination_date`, `termination_reason`, `id_expiry_date`, `phone`, `email`, `notes` | Actualiza a ficha de RH; só mudam os campos indicados. Cessar (`status: terminated`) exige `termination_date`. Na plataforma fica sempre sob o tecto absoluto (uma pessoa aprova). |

**Só no ERP real.** O servidor falso não serve ficheiros: `hr.get_payslip` com `pdf: true` devolve um erro legível. No Rethink ERP os PDFs (recibos, contratos, cartas, folhas de IRPS, declarações anuais) vêm das ferramentas `*_pdf` (`pay_payslip_pdf`, `hr_get_contract_pdf`, `hr_get_letter_pdf`, `pay_irps_sheet_pdf`, `pay_annual_statement_pdf`), que a plataforma descarrega uma vez e anexa à resposta. Os prazos legais (`filing`), os limites de horas extraordinárias como datas (`overtime_limit`), as baixas para a Junta de Saúde e os prazos disciplinares também só os dá o ERP real. O IRPS das folhas do servidor falso é uma taxa fictícia.

**No Rethink ERP (Perfex).** O ERP já tem estas ferramentas com os nomes dele: 38 de RH (`hr_*`), 15 de Assiduidade (`att_*`) e 8 de Payroll (`pay_*`, só leitura), descritas em `modules/rethink_hr/MCP_TOOLS.md` do ERP. A correspondência está em `App\Erp\Perfex\PerfexToolMap`:

| Contrato | Rethink ERP |
|---|---|
| `hr.list_employees` | `list_staff`, `list_departments`, `hr_list_employees`, `hr_org_chart`, `hr_reference_data` |
| `hr.get_employee` | `hr_get_employee`, `hr_career_history`, `hr_list_documents`, `hr_list_contracts`, `hr_get_contract`, `hr_get_contract_pdf` |
| `hr.people_indicators` | `hr_people_indicators` |
| `hr.upcoming_dates` | `hr_upcoming` (a data vem em `ref_date`, sem `days_left`) |
| `hr.update_employee` | `hr_update_employee` |
| `hr.attendance_today` | `att_today`, `att_who_is_out`, `att_check_ins` |
| `hr.attendance_daily` | `att_daily_attendance`, `att_shift_schedule` |
| `hr.attendance_summary` | `att_attendance_summary`, `att_month_status`, `att_overtime_status` |
| `hr.list_leave` | `att_list_leave_requests`, `att_get_leave_request`, `att_who_is_out`, `att_leave_types`, `att_leave_balance`, `hr_leave_balances`, `att_holidays` |
| `hr.list_payroll_runs` | `pay_list_runs`, `pay_get_run`, `pay_annual_summary`, `pay_irps_sheet_pdf` |
| `hr.get_payslip` | `pay_get_payslip`, `pay_payslip_pdf`, `pay_annual_statement_pdf` |
| `hr.prepare_payroll_draft`, `hr.list_openings`, `hr.create_candidate`, `hr.list_candidates`, `hr.create_onboarding` | — (o ERP processa a folha nos seus ecrãs, de propósito; recrutamento e integração não têm ferramenta) |

As ferramentas só aparecem à plataforma depois de marcadas no token, em **MCP Server › Definições** do ERP. O token actua como administrador, por isso as regras do ERP sobre quem vê o quê não protegem nada aqui: a plataforma limita ela própria os salários, contratos, documentos de identificação e processos disciplinares (`autonomy.people_data`). No Rethink ERP isso cobre todas as `pay_*` e todas as `hr_*` menos `hr_org_chart`, `hr_reference_data` e `hr_leave_balances` (o `hr_list_employees` traz o fim de cada contrato); as `att_*`, `list_staff` e `list_departments` ficam abertas.

## 5. Ponto por decidir: `invoices.issue` `[CONFIRMAR]`

A especificação lista `invoices.issue` e diz, na mesma secção, que nenhuma ferramenta emite. Até haver decisão, o servidor falso faz assim:

- exige `approval_reference`, a referência da aprovação humana registada na plataforma;
- só aceita facturas em `draft`;
- **não emite**: passa a factura a `pending_confirmation`, e a emissão fiscal é feita por uma pessoa no ERP.

**Pergunta à equipa do ERP e à direcção:** a emissão fica sempre no ERP (como acima), ou o ERP aceita emitir quando recebe uma aprovação válida da plataforma? A segunda hipótese exige que o ERP verifique a aprovação.

## 6. Também por confirmar

- Prazo de entrega do servidor (questão 4 da secção 21). Até lá a plataforma trabalha contra o servidor falso; quando o servidor real estiver pronto, muda-se a configuração da ligação no ecrã **Definições → Ligação ao ERP**, sem alterar código.
- Se o ERP preferir OAuth em vez de token fixo, a tabela `erp_connections` já prevê `auth_type = oauth`; o cliente MCP suporta-o.
