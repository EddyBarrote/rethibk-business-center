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

## Inspiração Paperclip: tarefas, organigrama, objectivos e orçamento (04.10.2026)

O Barrote pediu para adaptar o MICOMOC ao modelo do [Paperclip](https://github.com/paperclipai/paperclip) (licença MIT) e aprovou quatro mudanças, com uma precisão: os agentes funcionam como assistentes, em conversa ou em acção directa, como o Hermes.

| Tema | Decisão | Onde está |
|---|---|---|
| Tarefas | A unidade de trabalho é a tarefa (`tasks`), e cada tarefa é também uma conversa (`task_messages`). Uma "Conversa" é uma tarefa `kind=chat`, sem fluxo de estados. Estados: por fazer, em curso, à tua espera, em revisão, bloqueada, feita, cancelada. Identificador por tenant (MIC-12). | `App\Tasks\TaskThread`, `TaskController` |
| Conversar e executar | Cada mensagem acorda o agente atribuído, que responde com o histórico do fio (`GenericAgent` implementa `Conversational`). O modo "Executar" envia uma acção directa: o agente executa já e reporta. Uma execução de cada vez por tarefa; mensagens que chegam entretanto são respondidas a seguir. | `TaskThread::wake`, `recordReply` |
| Agente fala primeiro | `tasks.ask_human` faz uma pergunta a uma pessoa: dentro de uma tarefa põe-na "à tua espera"; fora abre uma conversa nova. A resposta acorda o agente. | Skill `tasks.ask_human` |
| Entre agentes | `tasks.create` delega trabalho a outro agente seguindo o organigrama (para baixo, ou à chefia directa; um agente fora do organigrama pode pedir a qualquer um). Profundidade máxima 3. Quando a tarefa delegada fica feita, o relatório entra no fio de origem e acorda o agente que delegou. | Skills `tasks.*` (sempre activas), `OrgChart` |
| Organigrama | `agents.reports_to_agent_id` (o agente reporta a outro agente), além de `reports_to_user_id` (a pessoa responsável). Sem ciclos. Editável por proprietários e administradores no ecrã Organigrama. | `OrgController` |
| Objectivos | `goals` em árvore; as tarefas ligam-se a um objectivo e o progresso é tarefas feitas / total. Chefias criam e editam. | `GoalController` |
| Excepção de orçamento | A paragem a 100% já existia. Agora, ao atingir o tecto, nasce uma aprovação `budget.override` (tecto absoluto: decide sempre um proprietário ou administrador) que propõe +50% do tecto até ao fim do mês. Aprovada, aumenta o tecto do mês (`tenants.settings.ai_budget_extra`) e reactiva os agentes que o tecto parou. | `BudgetGuard::grantExtra`, skill `budget.override` |
| Uma conversa por pessoa e agente | Como no Grok: cada pessoa tem uma só conversa contínua com cada agente (`tasks.chat_key = "{user}:{agent}"`, única por tenant). "Conversar" (página do agente, barra lateral, nova conversa) abre sempre essa conversa; as perguntas do agente fora de uma tarefa também lá caem. As conversas antigas separadas foram juntadas na mais antiga. As tarefas de trabalho continuam a ser fios próprios. | `TaskThread::conversation` |
| Canais externos | WhatsApp, Telegram e afins ficam para depois; tudo acontece na consola, com notificações. | — |
| Interface | Regras de desenho em [UI.md](UI.md). | — |

## Base de conhecimento e geração de documentos (04.10.2026)

Pedido do Barrote: uma base de conhecimento com artigos, uploads, pastas e domínios de informação, onde os agentes também guardam informação nova; e agentes capazes de gerar documentos, apresentações e folhas de cálculo. Valores por omissão escolhidos para avançar sem bloquear (detalhe em [CONHECIMENTO.md](CONHECIMENTO.md)):

| Tema | Decisão | Onde se muda |
|---|---|---|
| Domínios | Cinco por omissão, criados na primeira abertura: Geral, Finanças, Recursos Humanos, Clientes, Operações. | Conhecimento › Domínios (proprietários e administradores) |
| Acesso | Um domínio é aberto a todos ou restrito a departamentos. Finanças e RH nascem restritos aos departamentos cujo identificador contém "financ" e "rh"/"recursos-humanos"; proprietários e administradores vêem tudo. Agentes seguem o departamento do agente. | `KnowledgeAccess` |
| O que os agentes escrevem | Fica marcado como escrito pelo agente. A partir de N3 é publicado logo; abaixo de N3 fica "para rever": as pessoas vêem-no, os agentes não, até um proprietário, administrador ou chefia com acesso ao domínio o aprovar. A chefia do agente é notificada. O gate de autonomia continua a aplicar-se à capacidade `knowledge.save` (risco N1 por omissão). | `AgentKnowledgeWriter`, Super admin › capacidade |
| Quem edita | O autor (pessoa) e os curadores do domínio editam e apagam. Qualquer pessoa com acesso ao domínio escreve artigos, carrega ficheiros e cria pastas. Apagar uma pasta move o conteúdo para a raiz; um domínio com documentos não se apaga. | `KnowledgeController`, `FolderController` |
| Formatos aceites | PDF, Word, Excel, PowerPoint, texto, Markdown, CSV, HTML e imagens (com OCR se o Tesseract existir), até 25 MB e 20 ficheiros de cada vez. | `KnowledgeController::UPLOAD_TYPES` |
| Geração | Markdown → DOCX (PhpWord), PPTX (PhpPresentation), XLSX (PhpSpreadsheet) e PDF (Dompdf). Três modelos para Word e PDF: documento, relatório com capa, carta. PhpSpreadsheet fica na 3.10 (≥ 3.10.8, sem avisos de segurança) porque o PhpPresentation ainda não aceita a 5. | `App\Documents` |
| Marca | Cor, logótipo e rodapé por tenant em `settings.brand`; sem marca, azul #1E3A5F e o nome da organização. | Definições › Marca |
| Ficheiros gerados | Ficam na consola (Ficheiros), nunca saem sozinhos. Vê-os quem os criou, a chefia e o departamento do agente que os gerou, e os administradores. Podem ser arquivados na base de conhecimento. | `DocumentController` |
| "Memória" | O ecrã passou a chamar-se Conhecimento; `memory.search` mantém a chave e passou a respeitar domínios e estado. | — |

## Capacidades, skills e agentes como colegas (04.10.2026)

Pedido do Barrote: qualquer administrador de uma empresa deve conseguir criar ferramentas e skills, com as globais criadas pelo super admin e usadas pelos tenants, "como o Claude faz"; os agentes são colegas de trabalho, com nome e foto, e a própria IA ajuda a criá-los. Escolhas por omissão (guia técnico em [CAPACIDADES.md](CAPACIDADES.md)):

| Tema | Decisão | Onde está |
|---|---|---|
| Nomes | O que se chamava "skill" (ferramenta executável) passa a **capacidade** (`capabilities`, `App\Ai\Capabilities`). **Skill** passa a ser um pacote de instruções ao estilo das Agent Skills do Claude. As chaves das capacidades não mudaram. | Migração `rename_skills_to_capabilities` |
| Skills | Nome, "quando usar" (o agente decide por isto), instruções em markdown e ficheiros anexos (o texto é extraído no upload). O prompt lista só nome e "quando usar"; o agente carrega o resto com `skills.load` e lê ficheiros com `skills.read_file`, que só recebe quem tem skills. | `AgentSkills`, `LoadSkill`, `ReadSkillFile` |
| Âmbitos | **Global** (super admin, todas as empresas; cada uma activa as que quer) e **da empresa** (proprietários e administradores, privadas). Uma skill global activada lê sempre a versão actual da Rethink. | `platform_skills`, `platform_connectors`; `skills`, `connectors` |
| Capacidades novas sem código | **Conectores**: um servidor MCP remoto (cada ferramenta vira uma capacidade) ou um pedido HTTP com argumentos em JSON Schema. Token Bearer cifrado, nunca volta ao browser. As respostas chegam ao modelo marcadas como conteúdo externo não confiável. | `App\Connectors\ConnectorGateway` |
| Segurança dos conectores | Os da empresa só chegam a endereços https públicos (como o `SafeHttp`); os globais são confiáveis. Cada chamada fica em `audit_logs`. Escritas de um conector começam com risco N4 (pedem aprovação a quase todos os agentes) até um administrador o baixar. O risco das capacidades da plataforma e do ERP continua com a Rethink. | `CapabilityCatalog::syncConnector` |
| Quem cria agentes | Proprietários e administradores da empresa criam e editam agentes (identidade, personalidade, instruções, modelo, autonomia, capacidades, skills), além do super admin. Os rascunhos só aparecem a quem os pode editar. | `AgentDefinitionController`, `AgentEditor` |
| Cara do agente | Foto carregada, ou gerada com o modelo de imagem do Gemini quando há chave; sem foto, as iniciais. Ficheiro privado, servido só a pessoas do mesmo tenant. | `AgentAvatars`, `AgentAvatarController` |
| Assistente de criação | "Descreva o colega de que precisa": a IA propõe nome (próprio, de pessoa), função, personalidade, instruções, nível de autonomia, capacidades e skills existentes, e sugere skills que fariam falta. Fica em rascunho no formulário; nada é guardado sem rever. Sem chave de IA, o formulário funciona à mão. O custo desta chamada não entra no orçamento mensal (é pequena); a chamada é recusada se o orçamento já estiver esgotado. | `AgentDrafting`, `AgentDrafter` |
| Para outros módulos | Capacidades locais registam-se com `CapabilityRegistry::register()` num service provider, e `giveToEveryAgent()` dá-as a todos os agentes. | [CAPACIDADES.md](CAPACIDADES.md) |
