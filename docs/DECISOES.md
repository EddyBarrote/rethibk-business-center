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

## Ainda em aberto

| # | Questão | Bloqueia |
|---|---|---|
| 2 | Domínio das caixas dos agentes | E03 |
| 4 | Prazo da equipa do ERP para o servidor MCP | E01 (mitigado) |
| 6 | Alojamento e deploy da plataforma (também Hostinger?) | staging |
| 9 | Fontes de concursos a monitorizar | E03 |
| 11 | Retenção de email bruto e anexos | E03 |
| — | `invoices.issue`: a emissão fica sempre no ERP, ou o ERP emite com uma aprovação da plataforma? (contradição na secção 8.3; ver `ERP-MCP-CONTRACT.md`, secção 5) | contrato do ERP |

## Entrada de email com Hostinger (decidido: IMAP)

A secção 9.2 assume **webhooks de entrada** de um provedor (Mailgun, Postmark). O email do Hostinger não envia webhooks: as mensagens ficam em caixas IMAP. Escolhido por Barrote a 04.10.2026:

- **Saída:** SMTP do Hostinger por caixa, como já previsto na secção 9.3.
- **Entrada:** um job agendado lê cada caixa por IMAP (por exemplo a cada minuto), guarda o bruto e entra no mesmo pipeline da secção 9.2 a partir do passo 2 (deduplicação). A verificação de assinatura do passo 1 deixa de se aplicar; em seu lugar, as credenciais IMAP ficam encriptadas por caixa.
- **Consequências:** o critério da E03 de "classificado em menos de 60 s" continua possível com leitura a cada minuto, mas fica no limite; `mailboxes.inbound_provider` passa a incluir `imap`.

Alternativa descartada: Mailgun/Postmark só nas caixas dos agentes.
