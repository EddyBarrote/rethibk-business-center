# Decisões sobre as questões em aberto (secção 21 da especificação)

Registo das respostas da direcção. Quando uma resposta altera a especificação, isso fica dito aqui; o `SPEC.md` mantém-se como foi entregue.

## Respostas de 04.10.2026 (Barrote)

| # | Questão | Decisão | Consequência na implementação |
|---|---|---|---|
| 3 | Provedor e modelo de IA | **Todos os provedores, configuráveis globalmente e por agente.** Ex.: Triagem com um modelo barato, Chief of Staff com Claude. | Default global em `config/ai.php`/`.env`; `agents.provider` e `agents.model` (secção 5.2) sobrepõem por agente. O `laravel/ai` 1.0.1 suporta Anthropic, OpenAI, Azure OpenAI, Bedrock, Gemini, Mistral, Groq, DeepSeek, xAI, OpenRouter, Ollama, Cohere e compatíveis com OpenAI. O custo por run tem de usar a tabela de preços do provedor/modelo efectivo. **Orçamento mensal ainda em aberto.** |
| 4 | Servidor MCP do ERP | Construído pela equipa do ERP, sobre o protocolo MCP. | Mantém-se a secção 8: `FakeErpServer` na E01 e `docs/ERP-MCP-CONTRACT.md` entregue a essa equipa. **Prazo ainda em aberto.** |
| 5 | Versão de MySQL | Última versão. | MySQL 9.x: caminho preferido da secção 5.9, com o tipo nativo `VECTOR`. O CI passa a correr em MySQL 9 quando se chegar à E02. |
| 1 | Provedor de email e DNS | Hostinger. | Ver "Ponto a confirmar" abaixo: o Hostinger dá caixas IMAP/SMTP, não webhooks de entrada. |
| 10 | Extractos bancários | Por email (extractos recebidos) e por upload na plataforma. | E05: duas entradas, a caixa do agente de Finanças e um ecrã de upload. |

## Ainda em aberto

| # | Questão | Bloqueia |
|---|---|---|
| 2 | Domínio das caixas dos agentes | E03 |
| 3 | Orçamento mensal de consumo de IA (tecto por tenant, por agente, por run) | E02 |
| 4 | Prazo da equipa do ERP para o servidor MCP | E01 (mitigado) |
| 6 | Alojamento e deploy da plataforma (também Hostinger?) | staging |
| 9 | Fontes de concursos a monitorizar | E03 |
| 11 | Retenção de email bruto e anexos | E03 |
| 12 | Níveis de autonomia iniciais de cada agente | E02 |

## Ponto a confirmar: entrada de email com Hostinger

A secção 9.2 assume **webhooks de entrada** de um provedor (Mailgun, Postmark). O email do Hostinger não envia webhooks: as mensagens ficam em caixas IMAP. Proposta:

- **Saída:** SMTP do Hostinger por caixa, como já previsto na secção 9.3.
- **Entrada:** um job agendado lê cada caixa por IMAP (por exemplo a cada minuto), guarda o bruto e entra no mesmo pipeline da secção 9.2 a partir do passo 2 (deduplicação). A verificação de assinatura do passo 1 deixa de se aplicar; em seu lugar, as credenciais IMAP ficam encriptadas por caixa.
- **Consequências:** o critério da E03 de "classificado em menos de 60 s" continua possível com leitura a cada minuto, mas fica no limite; `mailboxes.inbound_provider` passa a incluir `imap`.

Alternativa: manter o Hostinger para DNS e caixas humanas e usar um provedor com webhooks (Mailgun/Postmark) só nas caixas dos agentes.
