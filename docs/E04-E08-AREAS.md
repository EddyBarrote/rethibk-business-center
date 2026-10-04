# E04 a E08 — Direcção e áreas de negócio

Estado das entregas E04 a E08 (secção 19 da [especificação](SPEC.md)). Os seis agentes da proposta são **modelos** (`App\Ai\Templates\AgentTemplates`) que o super admin instala num tenant com um clique (Organizações › tenant › Modelos de agentes) ou com `agents:install-templates`. Depois de instalados são agentes genéricos como outros: o super admin muda personalidade, instruções, skills, rotinas e nível de autonomia.

| Modelo | Agente | Nível inicial | Caixa | Departamento |
|---|---|---|---|---|
| `triage` | Agente de Triagem | N3 | `triagem@` | Direcção Comercial |
| `chief_of_staff` | Chief of Staff | N3 | `direccao@` | Direcção-Geral |
| `finance` | Agente de Finanças | N2 | `financas@` | Direcção Financeira |
| `procurement` | Agente de Procurement | N2 | `compras@` | Direcção de Operações |
| `hr` | Agente de Recursos Humanos | N2 | `rh@` | Direcção de RH |
| `client_manager` | Gestor de Clientes | N2 | `clientes@` | Direcção Comercial |

As caixas ficam em `{local}@{mail_domain do tenant}` e são criadas **desactivadas**: activam-se em Super admin › agente › Caixa de correio depois de pôr as credenciais IMAP/SMTP do Hostinger.

Os limiares de negócio estão em `config/business.php` e cada tenant pode mudá-los (Super admin › Organização › Regras de negócio): SLA de resposta a clientes (24 h), aviso de prazo (48 h), margem mínima (15%), alerta de orçamento (90%), aviso de fim de contrato (60 dias), movimentos por reconciliar (7 dias).

## E04 — Chief of Staff

- [x] Visão transversal (`platform.overview`) e detector de problemas entre áreas (`platform.detect_issues`): aprovações paradas há mais de 24 h, agentes suspensos ou a falhar, leads sem registo no ERP, concursos a fechar sem decisão, seguimentos em atraso, contratos em pré-aviso, requisições atrasadas ou sem nota de encomenda, movimentos por reconciliar, pedidos de clientes fora do SLA
- [x] Briefing diário (06:30, dias úteis) e semanal (segunda, 07:00), por email e na consola, com ligações para o que precisa de decisão
- [x] Relatórios em rascunho (`reports.draft`) que uma pessoa marca como revistos
- [x] As decisões registadas (`memory.remember_decision`) passam a fazer parte das instruções de todos os agentes durante 90 dias
- **Pronto quando:** às 06:30 de um dia útil o briefing chega ao leitor e abre na consola com ligações para as decisões. Coberto por `tests/Feature/Business/ChiefOfStaffTest.php`.

## E05 — Finanças

- [x] Extractos bancários por upload (CSV) e por email (anexo CSV, ou linhas lidas de PDF pelo agente); formatos portugueses e ingleses; nunca importa um movimento duas vezes
- [x] Candidatos à reconciliação a partir das facturas por receber do ERP (número ou montante); o agente propõe, uma pessoa confirma
- [x] Margens e execução orçamental por projecto com alertas; resumo do mês
- [x] Cobrança: dias úteis às 09:00 o agente prepara emails aos clientes com atraso (o envio espera aprovação)
- [x] Ecrã Finanças (só chefias): upload, propostas, confirmar, ignorar, perguntar ao agente
- **Pronto quando:** um extracto entra, o agente propõe as reconciliações e uma pessoa confirma-as. Coberto por `tests/Feature/Business/FinanceTest.php`.

## E06 — Compras e contratos

- [x] Requisições na consola que acordam o agente de Compras: pedido de cotação, registo das cotações, comparação, rascunho de nota de encomenda (aprovação)
- [x] Comparação ponderada (preço 60, prazo 25, histórico 15) com as avaliações dos fornecedores
- [x] Avaliação de fornecedores depois de cada entrega
- [x] Contratos (clientes e fornecedores) com fim, pré-aviso, renovação e SLA; aviso diário às 07:00 e passagem ao agente certo
- **Pronto quando:** uma requisição feita na consola acorda o agente, que lança o pedido de cotação no ERP e avança o estado da requisição; a comparação usa o histórico dos fornecedores; os contratos em pré-aviso chegam ao agente certo. Coberto por `tests/Feature/Business/AreasTest.php` e `WatchersTest.php`.

## E07 — Recursos Humanos

- [x] Candidaturas por email: avaliação face aos requisitos da vaga (`hr.match_candidate`) e registo no ERP
- [x] Rascunho da folha de salários a partir da assiduidade (`hr.prepare_payroll_draft`, sob o tecto absoluto)
- [x] Planos de integração de novos colaboradores
- **Dependência:** as ferramentas `hr.*` ainda não existem no ERP real: estão pedidas em [ERP-MCP-CONTRACT.md](ERP-MCP-CONTRACT.md) (secção 4.1) e existem no servidor falso.

## E08 — Gestor de Clientes

- [x] Ficha do cliente (ERP + emails + contratos + seguimentos + concursos) na consola e como skill
- [x] SLA de resposta por cliente (do contrato, ou o do tenant) com aviso de hora a hora e rascunho de resposta
- [x] Briefing para reuniões com clientes, a pedido na ficha
- [x] Propostas de renovação quando um contrato de cliente entra em pré-aviso

## Transversal

- `tenant:export {tenant}`: exporta tudo o que é do tenant (dados em JSON e ficheiros) num zip.
- `agents:cost-rollup`: custo de IA do dia por agente, na auditoria (23:50).
- Testes de todas as páginas da consola e da visibilidade por perfil: `tests/Feature/Business/ConsolePagesTest.php`.
