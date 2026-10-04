# Decisões sobre as questões em aberto (secção 21 da especificação)

Registo das respostas da direcção. Quando uma resposta altera a especificação, isso fica dito aqui; o `SPEC.md` mantém-se como foi entregue.

## Respostas de 04.10.2026 (Barrote)

| # | Questão | Decisão | Consequência na implementação |
|---|---|---|---|
| 3 | Provedor e modelo de IA | **Todos os provedores, configuráveis globalmente e por agente.** Ex.: Triagem com um modelo barato, Chief of Staff com Claude. | Default global em `config/ai.php`/`.env`; `agents.provider` e `agents.model` (secção 5.2) sobrepõem por agente. O `laravel/ai` 1.0.1 suporta Anthropic, OpenAI, Azure OpenAI, Bedrock, Gemini, Mistral, Groq, DeepSeek, xAI, OpenRouter, Ollama, Cohere e compatíveis com OpenAI. O custo por run tem de usar a tabela de preços do provedor/modelo efectivo. **Orçamento mensal ainda em aberto.** |
| 4 | Servidor MCP do ERP | Construído pela equipa do ERP, sobre o protocolo MCP. | Mantém-se a secção 8: `FakeErpServer` na E01 e `docs/ERP-MCP-CONTRACT.md` entregue a essa equipa. **Prazo ainda em aberto.** |
| 5 | Versão de MySQL | Última versão. | MySQL 9.x: caminho preferido da secção 5.9, com o tipo nativo `VECTOR`. O CI passa a correr em MySQL 9 quando se chegar à E02. |
| 1 | Provedor de email e DNS | Hostinger, com entrada por **IMAP** (decidido a 04.10.2026). | Ver "Entrada de email com Hostinger" abaixo. Altera a secção 9.2: sem webhooks. |
| 10 | Extractos bancários | Por email (extractos recebidos) e por upload na plataforma. | E05: duas entradas, a caixa do agente de Finanças e um ecrã de upload. |

## Respostas de 04.10.2026, à tarde (Barrote)

| # | Questão | Decisão | Consequência na implementação |
|---|---|---|---|
| 3 | Orçamento mensal de IA | **Definido no perfil do tenant**, com tectos por tenant, por agente e por execução. | Os três tectos ficam em `tenants.settings.ai_budget` e editam-se no perfil do tenant. Aviso aos 80%, corte aos 100% (secção 14.3). Valores em USD, como `agent_runs.cost_usd`. |
| 12 | Autonomia inicial dos agentes | **Agentes genéricos, criados pelo super admin**, com personalidade, prompts, ferramentas permitidas, acções de rotina, etc. Flexibilidade na criação. | Deixa de haver seis classes PHP fixas (secção 6.3): há um agente genérico configurado por registo em `agents`. O nível de autonomia é um campo dessa configuração, sem valor fixo por agente. Os seis agentes da proposta passam a ser configurações criadas pelo super admin. Acções de rotina em `agent_routines` (expressão cron + prompt). O tecto absoluto (secção 12.3) continua a aplicar-se acima de qualquer configuração. |

Escolhas por omissão, reversíveis, tomadas para avançar (a confirmar):

- **Super admin** é um operador da plataforma (Rethink), fora de qualquer tenant: tabela e sessão próprias, consola em `admin.{domínio central}`. Gere tenants, o perfil (incluindo o orçamento) e a definição dos agentes de cada tenant.
- **Dentro do tenant**, proprietários e administradores podem suspender e reactivar agentes, afectá-los a pessoas e aprovar acções; não alteram a definição nem o nível de autonomia.
- Quem aprova uma acção: proprietário, administrador, a pessoa a quem o agente responde, ou uma pessoa afecta ao agente.

## Instrução de 04.10.2026, 13:39 (Barrote): construir tudo, testar no fim

O Barrote pediu a plataforma completa (E02 a E08) e testa só no fim. Por isso, a regra da especificação de parar em cada `[CONFIRMAR]` deixa de se aplicar: cada ponto em aberto recebe um valor por omissão razoável, de preferência configurável, registado aqui.

| # | Questão | Valor por omissão | Onde se muda |
|---|---|---|---|
| 2 | Domínio das caixas dos agentes | Definição por tenant (`tenants.settings.mail_domain`); a caixa de cada agente tem endereço próprio. | Perfil do tenant, consola do super admin |
| 4 | Servidor MCP do ERP | Não se espera por ele: usa-se o `FakeErpServer` até existir. Trocar é mudar a ligação do tenant para HTTP. | Definições › Ligação ao ERP |
| 6 | Alojamento | Por decidir pelo Barrote antes do teste final. Até lá, corre em local e no CI. | — |
| 9 | Fontes de concursos | Lista configurável por tenant (nome, URL, palavras-chave), lida por uma rotina. | Perfil do tenant |
| 11 | Retenção de email bruto e anexos | 365 dias por omissão, configurável por tenant; um comando agendado apaga o bruto mais antigo (os metadados ficam). | Perfil do tenant |
| — | `invoices.issue` | O agente pede; a emissão é feita por uma pessoa no ERP. Na plataforma a ferramenta fica sempre sob o tecto absoluto e não há emissão automática. | `config/autonomy.php` |
| 12.3 | "Acima de um valor" no envio de comunicação externa | Sem limiar de valor: o tecto aplica-se a entidades novas (destinatário externo que nunca recebeu email do tenant). | `SendEmail::ceilingReason()` |

## Entrada de email com Hostinger (decidido: IMAP)

A secção 9.2 assume **webhooks de entrada** de um provedor (Mailgun, Postmark). O email do Hostinger não envia webhooks: as mensagens ficam em caixas IMAP. Escolhido por Barrote a 04.10.2026:

- **Saída:** SMTP do Hostinger por caixa, como já previsto na secção 9.3.
- **Entrada:** um job agendado lê cada caixa por IMAP (por exemplo a cada minuto), guarda o bruto e entra no mesmo pipeline da secção 9.2 a partir do passo 2 (deduplicação). A verificação de assinatura do passo 1 deixa de se aplicar; em seu lugar, as credenciais IMAP ficam encriptadas por caixa.
- **Consequências:** o critério da E03 de "classificado em menos de 60 s" continua possível com leitura a cada minuto, mas fica no limite; `mailboxes.inbound_provider` passa a incluir `imap`.

Alternativa descartada: Mailgun/Postmark só nas caixas dos agentes.

## Escolhas da construção E03 a E08 (04.10.2026, ao abrigo da instrução das 13:39)

| Tema | Valor por omissão | Onde se muda |
|---|---|---|
| Os seis agentes da proposta | Modelos (`AgentTemplates`) que o super admin instala num tenant; depois de instalados são agentes genéricos editáveis. O seed instala-os no MICOMOC. | Super admin › Organização › Modelos de agentes |
| Autonomia inicial | Triagem e Chief of Staff em N3 (organizam e escrevem no ERP só rascunhos); Finanças, Procurement, RH e Gestor de Clientes em N2 (tudo o que escreve pede aprovação). O tecto absoluto aplica-se acima. | Super admin › agente |
| Caixas dos agentes | Criadas desactivadas, em `{local}@{mail_domain}`; activam-se com as credenciais do Hostinger. | Super admin › agente › Caixa de correio |
| Passagem entre agentes | A triagem passa facturas e extractos a Finanças, cotações a Procurement, candidaturas a RH e pedidos de clientes ao Gestor de Clientes, uma vez por email. O papel de cada agente é o modelo de que veio. | `EmailCategory::handlerRole()` |
| Limiares de negócio | SLA 24 h, aviso de prazo 48 h, margem mínima 15%, alerta de orçamento 90%, pré-aviso de contrato 60 dias, movimentos por reconciliar 7 dias. | Super admin › Organização › Regras de negócio (`config/business.php`) |
| Briefings | Diário às 06:30 nos dias úteis e semanal à segunda às 07:00 (hora de Maputo), para a pessoa a quem o Chief of Staff responde (no seed, o proprietário). | `routes/console.php` |
| Upload de extractos | Só CSV no ecrã. PDFs chegam por email e o agente lê as linhas do anexo. | `FinanceController::upload` |
| Reconciliação | O agente propõe; só uma pessoa marca como reconciliado. Nada é escrito no ERP. | — |
| Comparação de cotações | Pesos 60 preço / 25 prazo / 15 histórico do fornecedor; o agente pode pedir outros pesos. | `analysis.compare_quotes` |
| Ecrãs de Finanças, Contratos e Clientes | Só chefias (proprietário, administrador, responsável de departamento). Requisições: cada pessoa vê as suas, as chefias vêem todas. Caixa: membros vêem os emails encaminhados a si ou ao seu departamento. | Controladores |
| RH no ERP | O contrato do ERP não tinha RH nem margens por projecto. Pedidas 11 ferramentas novas (`projects.list`, `procurement.record_quote`, `procurement.list_orders`, `hr.*`), já no servidor falso. | [ERP-MCP-CONTRACT.md](ERP-MCP-CONTRACT.md) § 4.1 |
| Decisões em vigor | As decisões registadas nos últimos 90 dias (até 8) entram nas instruções de todos os agentes. | `InstructionComposer` |
