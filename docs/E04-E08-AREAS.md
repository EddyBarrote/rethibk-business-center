# E04 a E08 — Direcção e áreas de negócio

Estado das entregas E04 a E08 (secção 19 da [especificação](SPEC.md)). Os seis agentes da proposta são **modelos** (`App\Ai\Templates\AgentTemplates`) que o super admin instala num tenant com um clique (Organizações › tenant › Modelos de agentes) ou com `agents:install-templates`. Depois de instalados são agentes genéricos como outros: o super admin muda personalidade, instruções, skills, rotinas e nível de autonomia. Quando um modelo muda, `agents:install-templates {tenant} --refresh={modelo}` traz o agente que já existe para a versão nova (nome, descrição, personalidade, instruções, capacidades em falta e rotinas pelo nome), sem tirar o que o administrador acrescentou.

| Modelo | Agente | Nível inicial | Caixa | Departamento |
|---|---|---|---|---|
| `triage` | Agente de Triagem | N3 | `triagem@` | Direcção Comercial |
| `chief_of_staff` | Chief of Staff | N3 | `direccao@` | Direcção-Geral |
| `finance` | Agente de Finanças | N2 | `financas@` | Direcção Financeira |
| `procurement` | Agente de Procurement | N2 | `compras@` | Direcção de Operações |
| `hr` | Director de Recursos Humanos | N2 | `rh@` | Direcção de RH |
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

O agente é o **Director de Recursos Humanos** (docs/DECISOES.md, 19.11.2026): o ERP faz o trabalho de RH e produz os documentos; o Director lê, vigia as datas, verifica a assiduidade, propõe actualizações às fichas e reporta à Direcção.

- [x] Assiduidade do dia (dias úteis, 09:30): ontem e hoje, quem faltou sem licença aprovada, chegou tarde ou não tem entrada; só avisa a chefia quando há alguma coisa
- [x] Datas de RH (dias úteis, 07:00): fins de contrato e de período experimental, documentos a expirar, aniversários, anos de casa, prazos; só os itens a 30, 14, 7, 3, 1 ou 0 dias
- [x] Resumo semanal (segunda) e fecho do mês (dia 1) em documento `attendance`: faltas, atrasos repetidos, horas extraordinárias face aos limites que o ERP indica, férias pendentes ou a começar, se o mês está fechado para salários
- [x] Conferência da folha de salários processada no ERP (dia 25) em documento `payroll_check`: totais, número de pessoas e quem precisa de atenção, sem salários individuais
- [x] Actualizações às fichas (`hr.update_employee`) sempre com aprovação (tecto absoluto)
- [x] Recibos, contratos, cartas, folhas de IRPS e declarações anuais vêm em PDF do ERP, anexados à resposta
- [x] Salários, contratos, documentos de identificação e processos disciplinares só a pedido de quem tem «Gerir pessoas e papéis», ou numa rotina do agente para essa pessoa; só essas pessoas vêem esses resultados no registo das execuções (`App\Ai\Autonomy\PeopleData`, `AgentRunPolicy`)
- [x] O Director é um agente confidencial (20.11.2026): só existe para quem tem «Gerir pessoas e papéis» (falar com ele, ver as suas conversas, tarefas, execuções, documentos e aprovações, decidi-las, receber o que envia); os administradores sem a permissão não o lêem; o dono vê tudo
- [x] Candidaturas por email: avaliação face aos requisitos da vaga (`hr.match_candidate`), rascunho de acusação de recepção e aviso à chefia
- **ERP real:** o Rethink ERP tem as ferramentas (`hr_*`, `att_*`, `pay_*`); o mapa do contrato está em [ERP-MCP-CONTRACT.md](ERP-MCP-CONTRACT.md) (secção 4.2). Têm de ser marcadas no token da plataforma no ERP. Vagas, candidaturas, integração e preparar a folha não têm ferramenta no ERP real; existem no servidor falso.
- **Pronto quando:** num dia útil o Director diz à chefia quem faltou sem licença e quem chegou tarde, e um pedido de salários de quem não gere pessoas é recusado. Coberto por `tests/Feature/Business/HrDirectorTest.php`, `PeopleDataTest.php`, `ConfidentialAgentTest.php` e `AreasTest.php`.

## E08 — Gestor de Clientes

- [x] Ficha do cliente (ERP + emails + contratos + seguimentos + concursos) na consola e como skill
- [x] SLA de resposta por cliente (do contrato, ou o do tenant) com aviso de hora a hora e rascunho de resposta
- [x] Briefing para reuniões com clientes, a pedido na ficha
- [x] Propostas de renovação quando um contrato de cliente entra em pré-aviso

## Transversal

- `tenant:export {tenant}`: exporta tudo o que é do tenant (dados em JSON e ficheiros) num zip.
- `agents:cost-rollup`: custo de IA do dia por agente, na auditoria (23:50).
- Testes de todas as páginas da consola e da visibilidade por perfil: `tests/Feature/Business/ConsolePagesTest.php`.
