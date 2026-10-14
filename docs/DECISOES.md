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

## Ecrãs de entrada com cara de produto (05.10.2026)

Pedido do Barrote: o login tem de parecer um produto a sério, com "Desenvolvido por Rethink Technologies". Escolhas por omissão:

| Tema | Decisão | Onde está |
|---|---|---|
| Nome do produto | **Rethink Business Center**, com "Plataforma de Agentes" como descrição. O `APP_NAME` do `.env` não mudou. | `PRODUCT_NAME` em `Components/RethinkMark.tsx` |
| Âmbito | Entrar, recuperar acesso, nova palavra-passe (tenant) e entrar do super admin partilham o mesmo `AuthLayout`: painel ilustrado à esquerda (só em ecrãs largos), formulário à direita, claro e escuro, botão de tema. | `Layouts/AuthLayout.tsx` |
| Marca do tenant | O nome da empresa não aparece no login (pedido do Barrote, 05.10). Só o logótipo de Definições › Marca, se existir, servido sem sessão em `/login/logo`. | `Auth\LoginBrand`, `LoginController::logo` |
| Recuperar palavra-passe | Para pessoas dos tenants e para super admins: link por email (60 min), resposta igual quer a conta exista ou não, só contas activas. Nos tenants a procura fica limitada ao tenant do endereço e usa `password_reset_tokens` (chave = email; se o mesmo email existir em dois tenants, o pedido mais recente substitui o anterior). Os super admins têm tokens próprios (`platform_admin_password_reset_tokens`) e o link aponta para o host de administração. Com `APP_ENV=local` e email em `log`, o link aparece também no ecrã, porque o email nunca chega a ninguém. | `Auth\ForgotPasswordController`, `Auth\ResetPasswordController` e as subclasses em `Admin\` |
| Logótipo | Um fluxo de três passos ligados por um caminho, com a faísca da IA ao lado (o Barrote pediu algo sobre IA e workflows, não o "R"). Conceito gerado com nano banana e redesenhado à mão em SVG para ler bem a 24 px, na cor primária do tema; também é o favicon. | `RethinkMark`, `public/favicon.svg` |
| Ilustração | Gerada com nano banana (Gemini 3 Pro Image, 2K), versão clara e escura, optimizadas para WebP 1400 px (~22 e ~32 KB). Prompts em [UI.md](UI.md#imagens-geradas). | `public/images/auth/` |

## Realinhamento: pessoas e agentes como colegas (05.10.2026)

O Barrote achou que muito do que existia estava fora do que espera e do que o Paperclip faz, e respondeu a um questionário de alinhamento. Estas decisões **substituem** as anteriores onde as contradizem. A lista do que muda no código está em `alinhamento/lacunas.md` (pasta partilhada do projecto).

Visão, nas palavras dele: pessoas e agentes de IA colaboram como colegas de trabalho; uma pessoa envia trabalho a um agente da sua área; os agentes têm memória consolidada; cada funcionário só fala com os agentes a que lhe dão acesso; o Chief of Staff consegue reportar ao CEO o que se passa nas conversas.

| # | Tema | Decisão |
|---|---|---|
| 1 | O produto | Um "escritório" como o Paperclip: tarefas, conversas, organigrama, objectivos, projectos, conhecimento. As áreas de negócio são trabalho dos agentes (skills e capacidades), não ecrãs. |
| 2 | Ecrãs de negócio | **Apagar** Concursos, Finanças, Compras, Contratos e Clientes (ecrãs e rotas). Os agentes dessas áreas ficam. |
| 4 | Entrada | "A minha caixa": tarefas atribuídas a mim, perguntas dos agentes, aprovações, e os meus colegas (pessoas e agentes) ao lado. O painel de métricas deixa de ser a entrada. |
| 5 | Organigrama | Uma só árvore com pessoas e agentes. Um agente pode ter uma pessoa como chefia e vice-versa. |
| 6 | Responsável da tarefa | Uma pessoa ou um agente (um só responsável). |
| 7 | Agente → pessoa | Um agente pode atribuir tarefas às pessoas da sua área e à sua chefia. |
| 8 | Área | O departamento. Cada pessoa e cada agente pertence a um. |
| 9 | CEO | Uma pessoa. O Chief of Staff é o agente de topo e trabalha para o CEO. |
| 10 | Assistente pessoal | Não por agora. |
| 11 | Pedir trabalho | Pela conversa: o agente cria a tarefa a partir dela e mostra-a no fio. As chefias também podem criar uma tarefa directamente (por exemplo para atribuir a uma pessoa). |
| 12 | Outra área | Não directamente: pede-se ao agente da própria área, que passa ao agente certo ou ao Chief of Staff. |
| 13 | Tarefa feita | Quem pediu aceita: o agente entrega, a tarefa fica "em revisão", a pessoa aceita ou devolve. |
| 14 | Trabalho autónomo | Como no Paperclip: os agentes acordam quando lhes atribuem uma tarefa e em horário fixo para ver o pendente. |
| 15 | Memória consolidada | Cada agente tem memória própria, que junta o que aprendeu em todas as conversas e tarefas, além da base de conhecimento. |
| 16 | Memória entre pessoas | Factos de trabalho aprendidos com uma pessoa servem para as outras; o que é pessoal ou marcado privado não. |
| 17 | Consolidação | No fim de cada tarefa e uma vez por dia para as conversas. |
| 18 | Ver a memória | A chefia do agente e os administradores, num separador "Memória" do agente, com editar e apagar. |
| 19 | Memória → conhecimento | **Automático**: o que o agente consolida passa a conhecimento da empresa, visível aos outros agentes (respeitando os domínios). |
| 20 | Com quem se fala | Com nenhum agente até lhe darem acesso, agente a agente. |
| 21 | Quem dá acesso | Administradores e a chefia do departamento do agente. |
| 22 | Níveis de acesso | Dois: "conversar" e "pedir trabalho". Aprovar acções fica à parte. |
| 23 | Ler conversas | Administradores e o dono podem ler sempre as conversas dos funcionários com agentes. |
| 24 | Reportar ao CEO | Modelo "a pedido": o CEO pergunta ao Chief of Staff, que tem uma ferramenta para pesquisar as conversas e fazer o report. Por iniciativa própria, um agente só avisa o Chief of Staff em casos urgentes (dinheiro alto, risco legal, prazo crítico). |
| 25 | Forma do report | Resumo com os excertos citados e ligação para cada conversa. |
| 26 | Aviso ao funcionário | Nota fixa na conversa: "as conversas podem ser consultadas pela direcção". |
| 27 | Conversas excluídas | Nenhuma: tudo pode ser pesquisado. |
| 28 | Quem pede reports | O CEO, e quem a matriz de acessos permitir. |
| 28b | Matriz de acessos | Papéis (ex.: CEO, Director, Chefia, Funcionário) com uma grelha de permissões ligadas/desligadas (pesquisar conversas, ler conversas, dar acesso a agentes, aprovar, criar tarefas…). Cada pessoa tem um papel e pode ter excepções individuais. |
| 29 | Confiança dos agentes | Mantém-se um nível de confiança por agente. Uma acção acima do nível vai primeiro ao Chief of Staff, que a revalida e aprova o que está dentro do nível dele; pagamentos, contratos, contratações e o resto do tecto absoluto sobem sempre a uma pessoa. |
| 29c | Mudar o nível | O Chief of Staff propõe subir ou descer com base no histórico do agente; o CEO ou um administrador confirma. |
| 30 | Primeira versão | **Fica:** caixas de email por agente (Hostinger/IMAP). **Adiado:** ERP por MCP (continua o servidor falso), WhatsApp/Telegram, agentes externos (Claude Code, Codex, Hermes). |
| 31 | Projectos | Sim: objectivo → projecto → tarefas. |
| — | Primeiro fluxo a testar | Agente de Triagem: chega um email à caixa da Triagem, ela classifica e passa ao agente da área certa, que cria a tarefa ou fala com a pessoa responsável. |

## Revisão visual de todos os ecrãs (05.10.2026)

Pedido do Barrote: profissionalizar todos os ecrãs, com modais onde fazem falta e paginação a sério. Só apresentação;
regras, rotas e dados ficam iguais. Padrões em `docs/UI.md` › "Padrões de ecrã".

- **Duas correcções no servidor**, mínimas: o detalhe de um email (`InboxController@show`) e a lista de Documentos
  (`ReportController@index`) davam erro 500 quando havia mais de uma linha, por carregarem relações sem as pedir
  (`mailbox`, `reviewer`). Passam a pedi-las; `tests/Feature/Business/ListsWithSeveralRowsTest.php` abre as listas e
  detalhes com várias linhas para apanhar isto.
- **Paginação:** o pager mantém os filtros do ecrã a partir do endereço actual, mesmo onde o servidor não usa
  `withQueryString()`. Os catálogos que já chegam inteiros (capacidades, conhecimento) são paginados no browser.
- **Utilizadores** continuam a criar-se e editar-se numa página própria: a fase 4 do realinhamento (matriz de acessos)
  vai mudar esse formulário, e um modal agora só criaria conflitos.


### Onde ficou o realinhamento (05.10.2026)

| Lacuna | Onde está |
|---|---|
| L1 Ecrãs de negócio apagados | Rotas e páginas removidas; os agentes mantêm as capacidades. A reconciliação bancária confirma-se numa aprovação (`bank.confirm_match`, sempre no tecto). |
| L2 A minha caixa | `HomeController` em `/`; o painel passou para `/painel`; a caixa de email chama-se Emails. |
| L3 Organigrama misto | `users.reports_to_user_id` / `reports_to_agent_id`; `OrgChart::managerOf()`, `loops()`, `assign()`; `PUT /org` com `member` e `manager` (`agent:ID` / `user:ID`). |
| L4 Tarefas para pessoas | `tasks.assignee_user_id` (um só responsável); `TaskThread::assignPerson()`. O agente entrega em revisão o que uma pessoa pediu (`tasks.update_status`), a pessoa aceita ou devolve escrevendo. |
| L5 Pedir na conversa | `tasks.create` com a chave do próprio agente cria a tarefa a partir da conversa; "Nova tarefa" só para quem chefia. |
| L6 Batimentos | `agents:heartbeat` a cada 15 min; `business.heartbeat_minutes` (60 por omissão, 0 desliga). |
| L7 Memória | `agent_memories`, `MemoryConsolidator` (no fim de cada tarefa entregue e `agents:consolidate-memory` às 21:00); artigo "Memória de {agente}" na base de conhecimento; separador Memória no agente. |
| L8 Acesso a agentes | `agent_assignments.role` = `chat` ou `work`; `AgentPolicy::run`, `requestWork`, `manageAccess`. |
| L9 Matriz de acessos | `access_roles` e `App\Enums\Permission`; `User::hasPermission()`; `canManageTenant()` e `isManager()` derivam da matriz. Definições › Papéis e acessos. |
| L10 Reports sobre conversas | `conversations.search` (só do Chief of Staff, só a pedido de quem tem a permissão); `escalate.urgent` para todos os agentes; nota fixa nas conversas. |
| L11 Revalidação e confiança | `approvals.review_stage`; `ApprovalService::reviewerFor()`, `approveByAgent()`, `escalate()`; `approvals.review` e `agents.set_trust_level` (decide quem tem "Confirmar níveis de confiança"). |
| L12 Projectos | `projects`, `tasks.project_id`; Trabalho › Projectos. |
| L13 Triagem como tarefa | `ClassifyEmail::handOff()` abre uma tarefa ligada ao email (`tasks.source`). |

## Segunda passagem da revisão visual (05.10.2026)

A avaliação da primeira passagem deu 6/10 face ao Paperclip. Esta passagem trata o que ficou: aprovações em palavras,
conteúdo técnico e de teste à vista, telemóvel, formatos e densidade. Padrões em `docs/UI.md` › "Segunda passagem".
Continua a ser apresentação; o que tocou no servidor é pouco e fica aqui:

- **Aprovar em lote:** `POST /approvals/approve` (`ApprovalController@approveMany`, até 50 ids, 20 pedidos por minuto)
  aprova as pendentes que a pessoa pode decidir, pela mesma `ApprovalService` e pela mesma política de cada uma. Salta
  as do tecto absoluto, que se decidem uma a uma. `tests/Feature/Agents/ApproveManyTest.php`.
- **Dados para escrever as acções em palavras:** a tarefa passa `action_type` e `payload` das aprovações pendentes
  (`TaskController@show`); a execução passa `tool_names` (chave → nome da capacidade) e o ERP passa o nome da
  ferramenta em cada chamada recente.
- **Ferramentas do ERP com nome português:** o servidor falso devolve um título por ferramenta ("Registar despesa",
  "Criar contacto de cliente"); `capabilities:sync` actualiza os nomes no catálogo. O servidor real deve devolver os seus.
- **Plurais nas mensagens do servidor** (capacidades, agentes criados, avisos de execuções falhadas, contratos a
  vencer) e "Pedir relatórios sobre conversas" em vez de "reports".
- **Dados de semente sem "(dev)":** as pessoas da semente têm nomes e funções plausíveis (Carlos Tembe, CEO; Ana Sitoe,
  Directora Comercial; …) e o super admin chama-se "Equipa Rethink"; o servidor falso aparece como "Servidor de
  demonstração (dados fictícios)". Na base de dados local também se trocaram os nomes "(dev)" e se tiraram as
  etiquetas "[teste do Claude]" do histórico de conversas e execuções.

## Terceira passagem da revisão visual (05.10.2026)

A segunda avaliação deu 7/10. O que ficou mexe sobretudo no texto que as pessoas lêem:

- **Textos escritos para pessoas, instruções só para o agente.** A tarefa que a triagem abre e a tarefa de revalidação
  do Chief of Staff já não dizem "Lê o email com email.read (email_id 1)" nem "Revê-a com approvals.review". A descrição
  fica para as pessoas; `TaskThread::input()` junta ao pedido do agente a origem da tarefa (email ou aprovação) e a
  ferramenta para a abrir. Testes em `TriageFlowTest` e `ChiefOfStaffReviewTest`.
- **`email.search` devolve a categoria em português** ("Factura de fornecedor"), para os agentes não citarem
  "supplier_invoice" às pessoas. O filtro continua a aceitar as chaves.
- **Execuções com nome:** uma execução de uma rotina chama-se como a rotina (`Present::run()` → `title`); a de uma
  tarefa, pela tarefa. Painel, Execuções e Agente recebem `tool_names` para escrever as chaves das ferramentas pelo nome.
- **Notificações antigas** que apontam para ecrãs que já não existem (contratos, clientes…) abrem a lista de
  notificações em vez de um 404 (`NotificationController::open`).
- **Chamadas do ERP:** o pedido de aprovação (auditado com a chave da capacidade) aparece como "Pedido de aprovação"
  com o nome da ferramenta.
- **Botão principal com contraste AA:** o laranja do tema claro passou de `oklch(0.6171 …)` (#C96442, 3,9:1 com
  texto branco) para `oklch(0.575 …)` (#BB5735, 4,6:1). O tema escuro não muda (texto escuro, 5,9:1).
- **Fichas de agente sem 1 600 px em branco:** a lista de capacidades rola dentro de uma caixa sem posição, e as
  caixas de selecção do Radix põem um `<input>` invisível em `position: absolute` que escapava da caixa e esticava a
  página. A lista passou a `relative`.
- **Dados locais:** o título do agente de demonstração passou a "Demonstração das aprovações" (também em
  `DemoScenario`) e a ligação ao ERP chama-se "Rethink ERP (demonstração)"; a semente só a cria se não houver nenhuma.
  As conversas e execuções de teste do Barrote ficam como estão. As contas de entrada da semente (`@micomoc.test`)
  ficam: são as credenciais de desenvolvimento e nunca existem numa organização real.

## Polimento final da revisão visual (05.10.2026)

A terceira avaliação deu 8/10. Esta passagem fecha o que restava e quatro regressões:

- **Abrir uma conversa não cria nada.** `GET /agents/{agent}/chat` leva à conversa se já existir; senão mostra uma
  conversa em branco (`Agents/Chat`), e só a primeira mensagem (`POST /agents/{agent}/chat`) a cria. As conversas
  sem mensagens saem das listas de Tarefas e Conversas; as que já existiam na base local ficam onde estão. Uma
  conversa chama-se sempre "Conversa com {agente}", seja qual for a primeira mensagem.
- **Execuções separam trabalho de conversa.** Por omissão a lista é o trabalho dos agentes; o filtro de origem tem
  Rotinas, Tarefas, Email e Conversas, e em Conversas as mensagens aparecem agrupadas por conversa (número de mensagens,
  custo, última resposta). A actividade recente do Painel também deixa as conversas de fora.
- **Cada execução com nome** (`Present::run()` → `title`): rotina, tarefa ("MIC-11 · …", "Retomar MIC-5 · …" no
  batimento), triagem do email «assunto», contrato a terminar, briefings e cobrança.
- **Revalidação do Chief of Staff em português:** a tarefa chama-se "Revalidar «Registar oportunidade» de {agente}";
  os argumentos da acção vão só para o pedido do agente.
- **Organigrama** abre em tamanho real; "Ajustar ao ecrã" nunca deixa os nomes abaixo de 12 px.

## Caixas de email por pessoa e matriz completa de permissões (05.10.2026)

Pedido do Barrote: cada pessoa tem a sua caixa (uma ou várias), os agentes podem lê-las, nunca enviam para fora em
nome de uma pessoa, e os emails de triagem só aparecem a administradores e a quem tem permissão. Ao mesmo tempo, os
papéis passam a cobrir tudo o que uma pessoa pode fazer no sistema.

| Decisão | Onde |
|---|---|
| Uma caixa é de um agente (`kind = agent`, como a da Triagem) ou de uma ou mais pessoas (`kind = person`, pessoal ou partilhada como financas@). Cada caixa tem os seus dados IMAP/SMTP, cifrados. | migração `2026_10_05_160001`, `Mailbox`, `MailboxOwner` |
| Cada pessoa liga as suas caixas em Definições › Caixas de email. Quem tem "Gerir todas as caixas de email" cria-as para qualquer pessoa e escolhe os donos. As caixas dos agentes continuam a configurar-se com o agente. | `MailboxController`, `Pages/Mailboxes/Index.tsx` |
| Os donos escolhem que agentes lêem a caixa. Por omissão, o primeiro agente activo do departamento da pessoa, que também vê cada email novo. | `MailboxReader` (`processes_new`), `Mailbox::processor()` |
| O agente que lê uma caixa pessoal lê, resume, classifica, cria tarefas para o dono e prepara rascunhos. Não encaminha para outras áreas e só notifica o dono do que é urgente. | `EmailPrompt::forPerson`, `ClassifyEmail`, `CapabilityRegistry::MAILBOX_READER` |
| Os agentes nunca enviam de uma caixa de pessoa: o rascunho fica na caixa dela e só um dono o envia. `MailboxMailer` recusa enviar de uma caixa pessoal sem um dono a pedir; `comms.send_email` só usa a caixa do próprio agente, com as regras de aprovação de sempre. | `MailboxMailer`, `SendEmail`, `DraftEmailReply` |
| Os agentes lêem o email de triagem (caixas de agentes) e as caixas pessoais que os donos lhes deram; nunca as outras. Resumos e alertas da organização (painel, SLA, resumo do mês) contam só a triagem. | `EmailMessage::readableBy`, `EmailMessage::triage` |
| Uma pessoa vê em Emails as suas caixas, os emails de triagem encaminhados para ela e, com "Ver emails de triagem", toda a triagem. Ninguém lê a caixa pessoal de outra pessoa, nem os administradores: "Gerir todas as caixas" dá a configuração, não o conteúdo. Deixou de haver visibilidade por departamento. | `EmailMessage::visibleTo`, `InboxController` |
| A matriz passou de 8 para 25 permissões, em 9 áreas (Trabalho, Agentes, Conversas, Emails, Conhecimento, Documentos, Aprovações, Custos de IA, Empresa). Os papéis passam a ser colunas e as permissões linhas, por área. | `App\Enums\Permission`, `Pages/Settings/Roles.tsx` |
| Cada permissão é verificada no servidor; o menu e os botões só escondem o que a pessoa não pode usar (`auth.user.permissions`, `useCan`). `canManageTenant()` passou a querer dizer só "Administrar a empresa" (departamentos, marca, ERP). | políticas e controladores |
| Os papéis que já existiam mantêm o que podiam fazer: quem administrava a empresa recebe as novas permissões de administração, quem chefiava recebe "Gerir objectivos e projectos", e todos recebem "Ligar as suas caixas de email", "Escrever conhecimento" e "Gerar documentos". | migração `2026_10_05_160001` |
| O papel de CEO mantém sempre "Administrar a empresa" e "Gerir pessoas e papéis": o servidor junta-as, em vez de recusar a gravação. | `Permission::ceoKeeps()`, `AccessRoleController` |
| Os custos de IA (painel e lista de agentes) só aparecem a quem tem "Ver custos de IA"; aprovar o gasto acima do orçamento é "Autorizar gastos acima do orçamento". | `DashboardController`, `ApprovalPolicy` |

Testes: `PersonalMailboxTest`, `PermissionCatalogTest`, e `ConsolePagesTest`/`AccessMatrixTest` actualizados.

## Definições num só lugar (06.10.2026)

Pedido do Barrote: as definições da aplicação (caixas de email, utilizadores, departamentos, marca, integrações,
acessos, skills e capacidades) ficam numa aba de Definições, agrupadas e com uma barra lateral própria. Proposta
aprovada antes de programar (`definicoes-proposta-*.png` na pasta partilhada).

| Decisão | Onde |
|---|---|
| A barra lateral principal perde o grupo "Empresa". O Organigrama passa para Trabalho e fica uma só entrada "Definições", em baixo, para todos. | `Layouts/AppLayout.tsx` |
| Definições tem cinco grupos: Empresa (Marca, Departamentos), Pessoas e acessos (Utilizadores, Papéis e acessos), Comunicação (Caixas de email, Integrações), Agentes de IA (Capacidades, Skills, Consumo de IA) e A minha conta (Perfil, Aparência). | `lib/settings.ts`, `Layouts/SettingsLayout.tsx` |
| Cada secção aparece só a quem tem a permissão que o servidor já pedia (gerir pessoas, administrar a empresa, gerir o catálogo, caixas de email, ver custos); um grupo sem secções desaparece. Departamentos continua visível a todos, só para leitura sem "Administrar a empresa". | `visibleSettings()`, controladores |
| Todas as moradas passam para `/settings/...`, com os mesmos nomes de rota. As antigas (`/mailboxes`, `/capabilities`, `/skills`, `/skills/{id}/edit`, `/settings/erp`) redireccionam. | `routes/web.php` |
| Integrações junta o ERP e os conectores (os da empresa e os globais da Rethink), que antes estavam escondidos num separador de Capacidades. O ERP só aparece a quem administra a empresa; os conectores a quem gere o catálogo. Capacidades fica só com o catálogo. | `Settings\IntegrationController`, `Pages/Settings/Integrations.tsx` |
| Novo: Perfil, onde cada pessoa muda o seu nome e a palavra-passe (com a actual; fica na auditoria). O email, o papel e o departamento continuam com quem gere as pessoas. | `Settings\ProfileController` |
| Novo: Aparência sai do menu do utilizador para uma página com os três temas; continua guardada por browser. O menu do utilizador leva a Perfil, Aparência e Definições. | `Pages/Settings/Appearance.tsx` |
| Novo: Consumo de IA, só leitura, para quem tem "Ver custos de IA": gasto do mês face ao tecto (com excepções aprovadas), previsão ao ritmo actual, gasto por agente face ao tecto de cada um e os últimos seis meses. Os tectos continuam a ser definidos pela Rethink. | `Settings\UsageController`, `Pages/Settings/Usage.tsx` |
| Ficam onde estavam: o modelo de cada agente (na ficha do agente) e os domínios do conhecimento (em Conhecimento). Preferências de notificações ainda não existem. | — |

Testes: `SettingsPagesTest` (novo), `ConsolePagesTest` com todas as páginas de Definições, `PermissionCatalogTest`,
`CatalogScreensTest`, `ErpSettingsTest` e `PersonalMailboxTest` nas moradas novas.

## Fluxos de trabalho (06.10.2026)

Pedido do Barrote: a triagem passa cada tipo de email ao agente certo, que começa a tarefa sozinho, executa o que as
suas capacidades, integrações, skills e nível de confiança permitem e escala o resto; e um mapa visual destes fluxos,
em canvas (com condições, ciclos e esperas) e em lista. Proposta aprovada antes de programar
(`fluxos-mapa-proposta.png`, `fluxos-novo-fluxo-proposta.png`, `fluxos-canvas-proposta.png` na pasta partilhada), com as
recomendações em todas as escolhas.

| Decisão | Onde |
|---|---|
| Novo tipo «Pedido de cotação de cliente» (`client_rfq`): um cliente pede-nos preço. Vai ao Gestor de Clientes; «Cotação de fornecedor» continua a ser a resposta de um fornecedor a um pedido nosso. | `EmailCategory`, `ClassifyEmail` (descrição da categoria), modelo da Triagem |
| Quem trata cada tipo: o agente do fluxo activo; senão a regra de Definições › Regras de email; senão o de sempre (`handlerRole()`). Uma regra sem agente deixa o email com a Triagem e a pessoa notificada. Cada regra pode ter uma pessoa de recurso. | `EmailRouter`, `EmailRoute`, `Settings\EmailRuleController` (quem tem "Gerir agentes") |
| Um fluxo é um grafo de blocos: gatilho, passo do agente, passar a outro agente, condição (sim/não), ciclo «para cada», «repetir até», esperar, aprovação, tarefa a uma pessoa e fim. O canvas e a lista editam o mesmo grafo. Sem ligações para trás: repetir é sempre um bloco de ciclo, com máximo (até 20). | `Workflow`, `WorkflowGraph` (`problems()`), `Pages/Workflows/Edit.tsx`, `lib/workflows.ts` |
| **A plataforma segue o fluxo, bloco a bloco** (não o agente sozinho). O agente do fluxo trabalha na tarefa que a triagem abriu: a plataforma escreve o passo na conversa e acorda-o; ele termina-o com `workflow.complete_step` (resumo, resposta da condição ou itens do ciclo) e, se o passo seguinte também é dele, continua na mesma execução. Outro agente e as pessoas trabalham em sub-tarefas; o fluxo segue quando fecham. | `WorkflowEngine`, `CompleteWorkflowStep` (dada a todos os agentes), ganchos em `TaskThread::recordReply/setStatus` e `ApprovalService::settleRun` |
| Executa ou escala: as regras de sempre (tecto absoluto → pessoa; leitura → passa; escrita → nível ≥ risco, senão revalida o Chief of Staff, que aprova o que cabe no nível dele). Quando a aprovação é decidida, o agente retoma o passo. | `AutonomyGate`, `ApprovalService` (inalterados) |
| Um passo que ninguém consegue (capacidade inexistente, agente sem ela, skill em falta) vai logo à pessoa de recurso, sem gastar a vez do agente; um agente que termina duas vezes sem concluir o passo também. A pessoa faz o passo (ou responde Sim/Não) e o fluxo continua dali. | `WorkflowEngine::block()`, `REMINDERS` |
| Antes de correr, cada bloco é verificado contra o que o agente tem: «Executa sozinho», «Revalida o Chief of Staff», «Decide uma pessoa» ou «Falta capacidade», com o porquê e o que fazer. A mesma conta no mapa, no editor (ao vivo) e no motor. | `WorkflowReadiness`, `POST /workflows/check` |
| **Proposta com preço sobe sempre a uma pessoa**, mesmo a um cliente conhecido: um email para fora que fala de proposta/orçamento/cotação e de um valor cai no tecto absoluto. | `SendEmail::ceilingReason()`, `isPriceProposal()` |
| Quem desenha fluxos: os administradores e quem dá acesso aos agentes do seu departamento (as mesmas pessoas de "Dar acesso a agentes"). Todos vêem o mapa. | `WorkflowPolicy` |
| Um fluxo activo por tipo de email: activar um pausa o outro. Um fluxo com problemas não activa. Os emails a meio seguem o desenho com que começaram (a execução guarda o grafo). Um fluxo que já tratou emails pausa-se, não se apaga. | `WorkflowController::activate()`, `workflow_runs.graph` |
| "Propor passos": o assistente desenha os blocos a partir de uma descrição, só com capacidades e agentes que existem; a pessoa revê no canvas antes de guardar. | `WorkflowDrafter`, `WorkflowDrafting` |
| Ecrãs: Trabalho › Fluxos de trabalho (mapa por tipo de email, por agente e lacunas); editor com canvas (React Flow, MIT; blocos arrastáveis, ciclos como caixas, «Organizar» com dagre, MIT) e lista; a tarefa mostra o fluxo passo a passo e, numa sub-tarefa de aprovação, os botões Aprovar/Rejeitar ou Sim/Não. | `Pages/Workflows/*`, `Components/workflows/*`, `Tasks/Show.tsx` |
| Exemplo instalado com os agentes (`agents:install-templates`): «Responder a pedido de cotação», activo, com o Gestor de Clientes e o Procurement. O passo «Calcular o orçamento» aparece como lacuna: não há capacidade de orçamentação no ERP (`erp.quotes.create`); até haver, vai à pessoa de recurso. | `WorkflowTemplates` |

Fica para depois: gatilhos que não são email (horário, pedido numa conversa), tipos de email criados pelas pessoas (hoje
a lista é a de `EmailCategory`; a descrição do fluxo ajuda a Triagem a reconhecer o tipo) e a capacidade de orçamentação.

Testes: `tests/Feature/Workflows/` (motor, encaminhamento e verificação, ecrãs e permissões).

## Anexos nas conversas (06.10.2026)

Pedido do Barrote: no chat as pessoas enviam imagens, áudio, PDF e outros documentos, os agentes recebem-nos e também
enviam ficheiros, e a Triagem usa os anexos dos emails. Construído sem paragens, com as escolhas por omissão abaixo.

| Decisão | Onde |
|---|---|
| Na conversa (e em qualquer tarefa): botão de clip, arrastar e largar sobre o compositor, colar (capturas de ecrã entram como `captura-HHhMMhSS.png`) e gravar áudio com o microfone. Cada ficheiro carrega logo, por partes, com a barra de progresso; Enviar fica à espera que acabem. Pode-se enviar só ficheiros, sem texto. | `Components/attachments/Composer.tsx`, `hooks/useAttachments.ts`, `Tasks/Show.tsx`, `Agents/Chat.tsx` |
| A gravação é WAV mono a 16 kHz, feita no browser: é o formato que todos os modelos que ouvem aceitam e todos os browsers produzem (o webm do Chrome não está na lista oficial do Gemini). Pára sozinha no limite da empresa. | `hooks/useWavRecorder.ts` |
| Envio por partes (até 4 MB cada, abaixo dos limites do PHP), por isso o tamanho máximo não depende do `upload_max_filesize` do servidor (hoje 2 MB no Mac de desenvolvimento). No fim confirma-se o conteúdo pelos bytes (um `.pdf` tem de ser PDF). Ficheiros carregados e nunca enviados apagam-se ao fim de 24 horas (`attachments:prune`, de hora a hora). | `AttachmentStore`, `AttachmentController` (`/files`), `routes/console.php` |
| **O agente recebe o próprio ficheiro quando o modelo o lê** (Gemini: imagens, PDF, áudio e vídeo; Anthropic e OpenAI: imagens e PDF). O resto chega como texto: o dos documentos (Word, Excel, PowerPoint, texto), OCR das imagens se houver Tesseract, e a transcrição do áudio (pelo primeiro provedor com chave: Gemini, OpenAI…). O texto extraído fica guardado. Até 18 MB de ficheiros por vez (o Gemini aceita 20 MB por pedido); o que não cabe vai como texto. Verificado com o Gemini real: ouviu a gravação, viu a imagem e leu o PDF. | `ModelInput`, `config/attachments.php` (`native`) |
| Na conversa, os últimos 4 ficheiros voltam ao modelo como ficheiros nas respostas seguintes; os mais antigos aparecem pelo nome e o agente lê-os com `files.read`. | `TaskThread::history()` |
| **Os agentes enviam ficheiros:** os documentos que geram numa conversa vão anexados à resposta sozinhos; com `files.attach` anexam um documento gerado antes ou um ficheiro da base de conhecimento a que têm acesso. `files.attach` e `files.read` são dadas a todos os agentes. Respostas faladas ficam para depois. | `AttachFile`, `ReadMessageAttachment`, `TaskThread::recordReply()` |
| **A Triagem usa os anexos dos emails:** imagens, PDF e áudio vão ao modelo como ficheiros, além do texto extraído que já ia; a instrução pede para os usar (o pedido, a referência ou o valor estão muitas vezes lá). Anexos descartados por tamanho ficam de fora. | `TaskThread::attachmentsFor()`, `AgentRunner`, `EmailPrompt` |
| Privacidade: os anexos seguem a conversa. Abre-os quem pode ler a conversa (a própria pessoa, e a direcção com "Ler todas as conversas"); antes de enviados, só quem os carregou. Imagens, áudio, vídeo e PDF abrem no browser; o resto descarrega (`nosniff`). Cada envio fica na auditoria (`task.attachments`). | `AttachmentController::show()`, `TaskPolicy` |
| Limites por empresa em Definições › Comunicação › Anexos (quem administra a empresa): tamanho por ficheiro (20 MB, até 100), ficheiros por mensagem (10, até 20), gravação mais longa (10 min, até 60) e tipos aceites (imagens, PDF, Office, texto e áudio; vídeo desligado). | `AttachmentLimits`, `Settings\AttachmentSettingsController`, `Pages/Settings/Attachments.tsx` |
| O armazenamento é o disco `local` (`ATTACHMENTS_DISK`); o envio por partes precisa de um disco local. | `config/attachments.php` |
| «Gerar documento» passa a ser dada a todos os agentes (pedido do Barrote): só cria um ficheiro, risco N0. | `CapabilityRegistry::ALWAYS_ON` |
| Os ficheiros da conversa abrem ali mesmo, numa janela: o PDF dentro da página; Word, PowerPoint e texto com o conteúdo (o Markdown que o agente escreveu, num documento gerado; o texto extraído, nos outros); Excel como tabela. Com Descarregar. Mesmo acesso que o ficheiro. Não se usa a página de Documentos porque as regras de quem vê são outras (a conversa manda). | `Components/attachments/FilePreview.tsx`, `GET /files/{id}/preview` |

Testes: `tests/Feature/Tasks/AttachmentsTest.php`.

## Email: um cliente de email na plataforma (06.10.2026)

Pedido do Barrote: o menu tinha "Emails" (a caixa triada) e "Caixas de email" (ligar caixas), o que não era coerente.
O objectivo é haver email de triagem, mas cada pessoa deve ter a sua caixa como num provedor de email a sério, sem
sair da plataforma: ler, escrever, guardar rascunho, enviar, anexar ficheiros. Construído sem paragens, com as
escolhas por omissão abaixo.

| Decisão | Onde |
|---|---|
| Uma só entrada **Email** no menu (`/inbox`, os links antigos `/inbox/{id}` continuam a abrir o email). Três colunas: caixas e pastas, lista, leitura ou escrita. No telemóvel, uma coluna de cada vez. Ligar uma caixa continua em Definições › Comunicação › Caixas de email, com atalho no fundo das pastas e no ecrã vazio. | `Pages/Email/Index.tsx`, `InboxController` |
| As pastas são as do servidor IMAP (LIST), com os papéis Entrada, Rascunhos, Enviados, Arquivo, Spam e Lixo lidos dos atributos SPECIAL-USE e, na falta deles, dos nomes habituais em inglês e português. As pastas da pessoa aparecem também, com as sub-pastas indentadas. O «All Mail» do Gmail fica de fora (repetiria tudo). Uma caixa sem IMAP tem as pastas padrão locais. | `MailboxSync`, `EmailFolder` |
| **Sincronização:** a cada minuto (`mail:fetch`) cada caixa lê as mensagens novas de todas as pastas (50 por pasta e passagem; na primeira vez as 50 mais recentes, e «Carregar mais antigos do servidor» busca as anteriores). Lê também o que outro cliente fez: lido, estrela, movido (a mensagem segue para a nova pasta) e apagado (sai das pastas, mas a linha fica para as tarefas que apontam para ela). Ao abrir uma pasta, o botão Actualizar e, de dois em dois minutos, a pasta aberta lê-se outra vez. | `MailboxSync::run()`, `FetchMailbox` |
| **Só a Entrada (a pasta `imap_folder`) vai aos agentes**, como antes; o que está em Enviados, Arquivo ou nas pastas da pessoa só é arquivado. Os emails já lidos pelos agentes antes disto passam para a Entrada com o seu UID. | `InboundEmailIngestor::ingest($process)`, `MailboxSync::adoptInbox()` |
| Cada acção faz-se primeiro no servidor e só depois aqui: marcar lido/não lido, estrela, arquivar (cria a pasta Archive se não houver), mover, apagar (vai para o Lixo; do Lixo ou do Spam apaga de vez, com confirmação). Assim o Outlook e o telemóvel vêem o mesmo. | `MailClient` |
| **Escrever:** novo, responder, responder a todos, reencaminhar (com os anexos do original), Cc e Bcc. Texto simples, com o email citado por baixo (`>`) e escondido atrás de «•••» na leitura. A resposta sai sempre da caixa do email original e fica na mesma conversa (In-Reply-To e References). | `Components/email/EmailComposer.tsx`, `lib/email.ts` |
| **Rascunhos no servidor:** o rascunho guarda-se a pedido e sozinho a cada 30 segundos, e vai para a pasta Rascunhos do servidor (com o mesmo Message-ID, substituindo a versão anterior). Ao enviar sai dos Rascunhos e uma cópia vai para Enviados (excepto Gmail e Microsoft 365, que a guardam sozinhos). | `MailClient::saveDraft()`, `pushDraft()`, `send()` |
| **Os rascunhos dos agentes** (`email.draft_reply`) caem nos Rascunhos do dono da caixa, também no servidor, para a pessoa rever e enviar. Os agentes continuam sem enviar em nome de pessoas. | `DraftEmailReply`, `PushDraftToServer` |
| **Anexos:** os mesmos da conversa (clip, arrastar e largar, colar, carregamento por partes e limites de Definições › Anexos); ao guardar, o email fica com a sua própria cópia. Os anexos recebidos abrem na mesma janela de pré-visualização (PDF na página, Word, Excel e texto com o conteúdo). | `useAttachments` (`mailbox_id`), `AttachmentController::start()`, `GET /attachments/{id}/preview` |
| **HTML seguro:** o email aparece como o remetente o escreveu, numa moldura sem scripts nem formulários, e as imagens externas carregam sozinhas, excepto no Spam (ver [«Email: imagens carregam sozinhas»](#email-imagens-carregam-sozinhas-08102026)). | `Components/email/EmailBody.tsx` |
| Pesquisa no assunto, remetente, destinatários, texto e resumo do agente, em todas as pastas da caixa; filtro «Não lidos»; 50 por página. Pesquisa só no que já foi sincronizado. | `InboxController::listing()` |
| **Triagem:** a caixa da Triagem (e a de cada agente) aparece como mais uma caixa, só para quem tem «Ver emails de triagem», com os filtros por categoria. Quem não tem, mas recebeu emails encaminhados pela triagem, vê-os em «Encaminhados para mim» e pode responder-lhes. Em cada email lido por um agente, um painel mostra o resumo, a categoria (com correcção), a tarefa, o prazo, os dados extraídos e «Triar de novo». | `TriagePanel.tsx` |
| Privacidade mantida: ninguém abre, sincroniza, organiza ou escreve da caixa de outra pessoa, nem administradores. Escrever da caixa da triagem é para quem a lê; mover e apagar são para quem a gere. | `Mailbox::canBeWrittenFromBy()`, `InboxController` |

Fica para depois: editor com formatação (negrito, listas), pesquisa no servidor em mensagens ainda não sincronizadas,
IDLE para os emails chegarem sem esperar o minuto, e cópia em Enviados do que os agentes enviam da sua própria caixa.

Testes: `tests/Feature/Email/EmailClientTest.php` (com um servidor IMAP em memória, `tests/Support/FakeImap.php`), e
verificado de ponta a ponta no browser contra um servidor IMAP/SMTP local.

## Percurso de um caso (07.10.2026)

Pergunta do Barrote: a Triagem passa um email ao Procurement, o Procurement decide passá-lo a outro agente (o Comercial);
como funciona e como se acompanha visualmente. Proposta aprovada antes de programar (`percurso-proposta.png` na pasta
partilhada).

| Decisão | Onde |
|---|---|
| Separador **Percurso** na tarefa (ao lado de Conversa; `?ver=percurso` no endereço), em qualquer tarefa que faça parte de um caso: com tarefa-mãe, sub-tarefas, email de origem ou aprovações. Mostra o caso inteiro desde a raiz, seja qual for a tarefa aberta, com a tarefa aberta destacada. | `Pages/Tasks/Show.tsx`, `Components/tasks/Journey.tsx` |
| Sem dados novos: o percurso lê-se do que já existe. Cada passagem é uma sub-tarefa; o email de origem, os passos de fluxo, as aprovações e as execuções completam o resto. Carrega só quando o separador abre (prop opcional do Inertia) e actualiza ao vivo como a conversa. | `App\Tasks\TaskJourney`, `TaskController::show()` |
| Cada passagem diz **como foi decidida**: «Tipo de email» (triagem), «Fluxo «nome»», «Decisão do agente» (com o texto do agente entre aspas), «Pedido de uma pessoa», «Tecto absoluto» ou «Aprovação». | `TaskJourney::taskHops()`, `approvalHop()` |
| **Agora com**: a pessoa que tem de aprovar ou responder, senão o agente da tarefa aberta mais recente (as outras esperam por ela). Com Aprovar e Rejeitar ali mesmo quando é uma aprovação. | `TaskJourney::holder()` |
| O tempo de cada um é o tempo **até passar o trabalho** (sub-tarefa ou aprovação), fechar a tarefa, ou até agora («e a contar»). O total divide-se em agentes a trabalhar (duração das execuções), à espera de pessoas (aprovações e tarefas de pessoas) e passagens e filas (o resto). | `TaskJourney::for()` |
| Uma tarefa do caso que a pessoa não pode abrir aparece sem o motivo e sem link. | `TaskJourney::for()` (`canView`) |

O que o Percurso mostra é o funcionamento de sempre: um agente só passa trabalho a quem está abaixo dele ou à sua chefia
(`OrgChart::canDelegate`), por isso Procurement → Gestor de Clientes passa pelo Chief of Staff; num fluxo, a passagem é a
desenhada.

Testes: `tests/Feature/Tasks/TaskJourneyTest.php`.

## Email: escolher a caixa num menu (07.10.2026)

Pedido do Barrote: "O admin deve escolher o email através de um dropdown, não pode ficar assim como está
desorganizado." Com várias caixas (a pessoal e as de triagem de cada agente), a barra lateral do Email listava-as
todas umas por baixo das outras, cada uma com as suas pastas.

| Decisão | Onde |
|---|---|
| No topo da barra lateral, um **menu com as caixas**: o nome e o endereço da caixa aberta e, ao abrir, as caixas da pessoa ("A minha caixa"), as de triagem (pelo nome do agente) e "Encaminhados para mim", cada uma com os não lidos da Entrada. Por baixo ficam só as pastas (e, na triagem, as categorias) da caixa escolhida. Quem tem uma só caixa vê o nome dela, sem menu. | `Pages/Email/Index.tsx` (`MailboxPicker`) |
| No telemóvel, dois selectores lado a lado: caixa e pasta. | `Pages/Email/Index.tsx` |
| O Email **abre na última caixa escolhida** pela pessoa (`users.last_mailbox_id`); sem escolha, ou se a caixa deixou de estar acessível, abre a primeira, como antes. | `InboxController::client()` |

Testes: `tests/Feature/Email/EmailClientTest.php` (abre a última caixa escolhida).

## Documentos com a marca (07.10.2026)

Pedido do Barrote: uma skill para os documentos que os agentes geram saírem com a marca da MicoMoc. Escolha dele: a
skill vive só na plataforma (não numa conta Claude).

| Decisão | Onde |
|---|---|
| A marca fica num só sítio, **Definições › Empresa › Marca**; a skill não tem cores nem fontes escritas. Ao carregar, `skills.load` troca `{{marca.*}}` pelos valores de hoje, por isso mudar a Marca muda logo o que os agentes sabem e o que os ficheiros mostram. | `Brand::fill()`, `LoadSkill` |
| A Marca ganha **cor secundária** (subtítulos `###`, marcadores das apresentações, barra da capa e citações no PDF; vazia usa a principal) e **fonte** (lista fechada: Calibri, Aptos, Arial, Helvetica, Segoe UI, Verdana, Montserrat, Open Sans, Roboto, Poppins, Georgia, Cambria, Garamond, Times New Roman). Word, PowerPoint e Excel levam o nome da fonte (aparece se estiver instalada em quem abre); o PDF só tem a DejaVu embutida e usa a da mesma família, com ou sem serifa. | `Brand`, `Renderers/*`, `Settings/Brand.tsx` |
| Uma skill da empresa pode ser de **Todos os agentes** (`skills.all_agents`), sem atribuição um a um, como «Gerar documento». | `AgentSkills::for()`, `SkillEditor.tsx` |
| Skill **«Documentos com a marca»** (`documentos-marca`), de todos os agentes, criada na MicoMoc a partir de `docs/exemplos/skill-documentos-marca.md`: quando usar, a marca, que formato e modelo escolher, estrutura, escrita (pt-MZ, meticais, datas por extenso, «[a confirmar]» em vez de inventar) e o que nunca fazer. | `skills` da MicoMoc |
| Corrigido: guardar a Marca sem nunca ter tido logótipo dava erro 500. | `BrandController::update()` |

| A marca da MicoMoc vem de micomoc.com (indicação do Barrote): logótipo `img/logoa.png` (1023×570, fundo transparente); **cor principal #333334** (o cinzento do texto do logótipo, legível nos títulos), **secundária #D89E28** (o dourado da engrenagem); **fonte Segoe UI** (a do site). O dourado não serve de cor principal: em títulos sobre branco tem contraste baixo. | Marca da MicoMoc |
| Na capa das apresentações o logótipo vai num cartão branco, porque os logótipos (como o da MicoMoc, com texto escuro) desaparecem sobre a cor principal. | `PptxRenderer::cover()` |

Testes: `tests/Feature/Knowledge/DocumentGenerationTest.php` (cor secundária, fonte, `fill`) e
`tests/Feature/Agents/SkillsAndConnectorsTest.php` (todos os agentes, marca ao carregar).

## ERP real: o Rethink ERP (Perfex) em vez do falso (07.10.2026)

Pedido do Barrote: ligar o servidor MCP do Rethink ERP, já em produção (`https://rethink.rethinkerp.online/api/mcp`,
`perfex-mcp-server` 1.2.0, protocolo `2025-06-18`, token Bearer). O servidor expõe **144 ferramentas com os nomes do
Perfex** (`list_customers`, `create_estimate`, …), não os do contrato (`crm.search_accounts`, …). O token actua como o
utilizador do ERP «Edy Barrote» (administrador): tudo o que um agente escrever no ERP fica em nome dele.

| Decisão | Onde |
|---|---|
| A plataforma reconhece o **dialecto** do ERP pelas ferramentas que ele lista (`list_customers` sem `crm.get_account` = Perfex) e guarda essa lista na ligação a cada sincronização. O servidor falso continua a falar o contrato. | `ErpDialect`, `ErpGateway::dialect()` |
| As ferramentas do Perfex entram no catálogo com os nomes delas (`erp.list_customers`). Uma tabela diz que ferramentas do Perfex fazem o trabalho de cada ferramenta do contrato, e o que ainda falta. | `Erp\Perfex\PerfexToolMap` |
| Na sincronização, cada agente que tinha uma **leitura** do contrato recebe as leituras equivalentes do Perfex. As **escritas não se atribuem sozinhas**: mexem no ERP real e um administrador dá-as a cada agente em Agentes › Capacidades. | `CapabilityCatalog::grantPerfexReads()` |
| Uma escrita do Perfex nova no catálogo fica com risco **N4**: espera sempre por uma pessoa (nenhum agente está em N4), até um administrador decidir outra coisa. As escritas do contrato (só rascunhos) continuam em N3. | `CapabilityCatalog::defaultErpRisk()` |
| O tecto absoluto passa a cobrir as acções equivalentes do Perfex: pagamentos, mudar o estado de uma factura (emitir ou anular), facturar a partir de orçamento, notas de crédito, contratos, aprovações de requisições, cotações e encomendas, e confirmar encomendas. | `config/autonomy.php` |
| As instruções dos agentes falam do contrato; num ERP Perfex o prompt junta «Ferramentas do ERP», a dizer que ferramenta usar em vez de cada uma, qual existe mas o agente não tem, e qual não existe. | `InstructionComposer::erpGlossary()` |
| O código da própria plataforma (ficha de cliente, margens, resumo do mês, reconciliação bancária) continua a pedir em termos do contrato; num ERP Perfex a resposta é montada a partir de leituras do Perfex, cada uma auditada. O Perfex não tem orçamento de projecto: usa-se o custo do projecto (`project_cost`). Leads procuram-se pelo nome da empresa, porque no Perfex não estão ligadas a um cliente. | `Erp\Perfex\PerfexAdapter` |
| A ligação da MicoMoc passa a ser a do ERP real; a de demonstração fica desligada (não apagada) para se poder voltar atrás. O token fica só cifrado na base de dados. | Definições › Comunicação › Integrações |

O que o Perfex ainda não tem: RH (assiduidade, férias, folha de salários, vagas, candidaturas, integração), e o
`list_rfqs` do servidor falha com um erro de SQL (`Unknown column 'tblrfq.total'`), que além disso mostra SQL ao
cliente. As escritas do Perfex não aceitam `idempotency_key`, por isso uma aprovação executada duas vezes escreveria
duas vezes: a plataforma executa cada aprovação uma só vez.

Testes: `tests/Feature/Erp/PerfexErpTest.php`.

### Ficheiros do ERP (07.10.2026)

Pedido do Barrote na tarefa 2: pediu o PDF de uma factura e o agente gerou um PDF seu, a imitar a factura, porque não
tinha as ferramentas de PDF do ERP.

| Decisão | Onde |
|---|---|
| Os agentes que lêem facturas, orçamentos, compras ou despesas recebem também os PDFs e a exportação para Excel do ERP (`download_*_pdf`, `export_list_to_excel`, leituras). | `PerfexToolMap` |
| Quando uma ferramenta do ERP devolve um `download_url`, a plataforma descarrega o ficheiro uma vez e anexa-o à resposta do agente (origem «do ERP»); a ligação pública não chega ao modelo nem à pessoa. Só se descarregam ligações do mesmo servidor da ligação ao ERP, sem seguir redirecções, até 20 MB, e cada descarga fica na auditoria (`erp.file_fetch`). | `Erp\ErpFiles`, `CapabilityExecutor` |
| O prompt diz ao agente para usar o PDF do ERP e nunca gerar um documento que imite um documento do ERP. | `InstructionComposer::erpGlossary()` |

Testes: `tests/Feature/Erp/PerfexErpTest.php`.

## Email: imagens carregam sozinhas (08.10.2026)

Pedido do Barrote, ao usar a caixa geral@rethink.co.mz: as imagens dos emails devem carregar sozinhas, sem ter de
carregar em «Mostrar imagens».

| Decisão | Onde |
|---|---|
| **As imagens externas carregam sozinhas** em todas as pastas. O email continua na moldura sem scripts nem formulários; só as imagens (`http:`/`https:`) passam a carregar. | `Components/email/EmailBody.tsx` |
| **No Spam continuam escondidas** até a pessoa carregar em «Mostrar imagens»: é lá que estão os emails que usam as imagens para saber quem os abriu. | `InboxController::conversation()` (`junk`) |
| **As imagens que o email traz dentro de si** (`cid:`, como os logotipos) aparecem no corpo, servidas a partir do próprio anexo, e deixam de aparecer repetidas na lista de anexos. Os emails já lidos ganharam o Content-ID a partir do `.eml` guardado. | `email_attachments.content_id`, `InboxController::withEmbeddedImages()` |
| **Cada pessoa pode voltar a ser perguntada:** Definições › Perfil › Email › «Perguntar antes de mostrar imagens». Desligado por defeito. | `users.email_images_ask`, `ProfileController::emailImages()` |

Testes: `tests/Feature/Email/EmailClientTest.php` e `tests/Feature/Settings/SettingsPagesTest.php`.

## Página de conversa (08.10.2026)

Pedido do Barrote, em `/tasks/2`: o painel do lado direito com o aspecto do painel lateral do Claude desktop, um
separador de execuções onde abre o que hoje é «ver execução», mensagens em lados opostos, a hora de cada uma e as
ferramentas que o agente chamou por baixo da resposta.

| Decisão | Onde |
|---|---|
| **A página ocupa o ecrã todo:** o chat tem o seu próprio scroll e a caixa de escrita fica sempre visível em baixo. As tarefas usam a mesma página. | `AppLayout` (`bleed`), `Pages/Tasks/Show.tsx` |
| **Painel lateral com separadores** (Detalhes, Execuções, Ficheiros), abre e fecha no botão do topo e a escolha fica guardada no navegador. Abaixo de 1280 px desliza por cima do chat. | `Components/tasks/SidePanel.tsx` |
| **«Ver execução» abre no painel**, sem sair da conversa; o endereço ganha `?execucao=` para se poder partilhar, e «Página inteira» leva a `/runs/{id}`. Só abre execuções desta tarefa. | `TaskController::panelRun()` |
| **Pessoas à direita, agentes à esquerda**, cada mensagem com a hora (a data completa no tooltip) e um separador por dia (Hoje, Ontem, data). Mensagens seguidas do mesmo autor em menos de 5 minutos não repetem nome nem avatar. | `Components/tasks/Chat.tsx` |
| **As ferramentas de cada resposta ficam numa linha dobrada** por baixo do balão (quantas, quais, se alguma falhou ou espera aprovação). Aberta, mostra cada chamada com o estado e a duração; carregar numa abre a execução no painel, já nesse passo. | `TaskController::toolCalls()`, `ToolCalls` |
| **A linha temporal de uma execução é um componente só**, usado na página da execução e no painel. | `Components/runs/RunTimeline.tsx` |
| O aviso «As conversas podem ser consultadas pela direcção» passa para baixo da caixa de escrita, onde se lê antes de escrever (decisão 26 do realinhamento). | `Pages/Tasks/Show.tsx` |

Fica de fora, para não chocar com «Memória e conversa única por agente»: como a página do agente abre esta conversa.

Testes: `tests/Feature/Tasks/TaskThreadTest.php` («lists the tools of each turn…»).

## Agente: o que está a fazer (09.10.2026)

A barra lateral dizia que um agente estava a trabalhar, mas a página dele só mostrava «A trabalhar»: não dizia em que conversa nem em que passo, e a lista de execuções não levava à conversa.

| Decisão | Onde |
|---|---|
| **Cartão «A fazer agora» no topo da página do agente**, uma linha por execução na fila ou a correr (a mesma regra da barra lateral): a conversa ou tarefa, o passo em que vai (a pensar, a usar uma ferramenta pelo nome em Capacidades, a escrever a resposta, à espera de aprovação), quando começou e a pedido de quem. | `App\Ai\Runs\AgentNow`, `Components/agents/AgentNow.tsx` (`NowPanel`) |
| **Ao vivo:** cada passo novo da execução actualiza a linha; o início e o fim de execuções actualizam o cartão, a lista e a barra lateral. Sem Reverb, recarrega a cada 3 segundos enquanto houver trabalho. | `useLive` em `Pages/Agents/Show.tsx` |
| **«Abrir conversa» / «Abrir tarefa»** leva a `/tasks/{id}?execucao={run}`, com a execução aberta no painel lateral; sem tarefa, «Ver execução» abre `/runs/{id}`. | `runHref()` |
| **A conversa privada de outra pessoa aparece como «Numa conversa privada»**, sem título nem botão (a regra de `TaskPolicy::view`). | `AgentNow::runTask()` |
| **Parado, uma linha:** «Sem trabalho em curso. Última actividade há X: …», com a última execução terminada. | `NowPanel` |
| O distintivo «A trabalhar» no cabeçalho passa a vir dos dados da própria página, por isso nunca contradiz o cartão. | `Pages/Agents/Show.tsx` |

Contido de propósito, para juntar sem choque com «Memória e conversa única por agente» (separador Tarefas e conversa única): não mexe nos separadores nem no botão «Conversar».

Testes: `tests/Feature/Agents/AgentConsoleTest.php` («shows on the agent page what it is doing now…»).

## Caixa de pessoa que envia para a triagem (09.10.2026)

Pedido do Barrote: chegou um pedido de cotação a geral@rethink.co.mz (email #81) e a triagem «não fez nada». A
triagem correu, mas geral@ é uma caixa de pessoa (dono: Carlos Tembe), e numa caixa de pessoa o agente trabalha
como assistente do dono: classifica sem encaminhar para outras áreas (decisão de 05.10). Tentou dar a tarefa ao
dono, foi recusado (não é da sua área) e acabou por a passar ao Chief of Staff, sem ligação ao email. O pedido
nunca chegou ao Gestor de Clientes.

| Decisão | Onde |
|---|---|
| **Cada caixa de pessoa tem a opção «Enviar o email novo para a triagem»**, desligada por omissão, que o dono (ou quem gere todas as caixas) liga em Definições › Caixas de email. Serve endereços da empresa como geral@. | migração `2026_10_09_060001`, `Mailbox::sendsToTriage()`, `MailboxController`, `Pages/Mailboxes/Index.tsx` |
| **Só o email novo vai:** cada email que entra com a opção ligada guarda `sent_to_triage_at`. O que já estava na caixa continua só do dono. | `InboundEmailIngestor`, `EmailMessage::isTriage()` |
| **Esse email é tratado como triagem da empresa:** o Agente de Triagem classifica-o e a plataforma entrega-o à área certa como tarefa ligada ao email (`EmailRouter`, fluxos, regras), como num email da caixa de triagem. | `ProcessInboundEmail`, `EmailPrompt::forTriage`, `ClassifyEmail` |
| **Quem vê:** quem tem «Ver emails de triagem» e a pessoa para quem foi encaminhado; os agentes lêem-no (o da área precisa dele para a tarefa). Os resumos e alertas da organização passam a contá-lo. O resto da caixa continua privado. | `EmailMessage::triage`, `visibleTo`, `readableBy` |
| **A triagem nunca responde nem envia** de uma caixa de pessoa: `email.draft_reply` e `comms.send_email` recusam-no no servidor, além do aviso no pedido. Quem recebe a tarefa prepara a resposta como rascunho na caixa, e só um dono a envia (regra de sempre do `MailboxMailer`). | `DraftEmailReply`, `SendEmail` |
| Ligada a opção, o «Quem vê cada email novo» da caixa fica sem efeito (é a triagem). | `Mailbox::processor()` |
| **geral@rethink.co.mz fica com a opção ligada**, por ser o endereço geral da empresa. O email #81 foi enviado de novo para a triagem; os emails anteriores ficaram como estavam. A tarefa MIC-23, criada por engano para o Chief of Staff, foi cancelada. | dados (09.10.2026) |

Testes: `tests/Feature/Email/TriageFromPersonalMailboxTest.php`.

## Memória por pessoa e tarefas dentro do agente (09.10.2026)

O Barrote: cada agente tem a sua memória em cada pessoa, há uma só conversa com cada agente, e as tarefas vivem na vista do agente. Respostas dele às perguntas, uma a uma:

| Decisão | Onde |
|---|---|
| **Cada pessoa tem a sua memória com cada agente** (`agent_memories.user_id`). Um facto pertence à pessoa da conversa ou tarefa de onde veio; o que vem de trabalho sem pessoa (rotinas, email) fica do agente e serve todas as conversas. A memória que já existia passou para a pessoa da tarefa de onde veio. | migração `2026_10_09_090001`, `MemoryConsolidator` |
| **Partilha pela base de conhecimento:** os factos de trabalho continuam a ir para o artigo «Memória de {agente}», e é por aí (`memory.search`) que o que uma pessoa ensinou serve as outras. Os pessoais nunca saem da memória da pessoa. Substitui a decisão 16. | `MemoryConsolidator::forPrompt()`, `publish()` |
| **Só os administradores** (permissão «Corrigir a memória de qualquer agente») vêem, corrigem e apagam a memória; a chefia do agente e a própria pessoa não. O separador Memória filtra por pessoa. Substitui a decisão 18. | `AgentPolicy::viewMemory()`, `MemoryList` |
| **«Executar» na página do agente vai para a conversa única**: «Enviar» manda uma mensagem e «Executar» uma acção directa (só com «Pedir trabalho»), ambas na conversa da pessoa com o agente, que abre a seguir. Deixa de haver execuções soltas a partir da página. | `TaskController::chat()` (`mode`), `Pages/Agents/Show.tsx` |
| **Separador «Tarefas» na página do agente**, com as tarefas da pessoa com esse agente: atribuídas a ele ou delegadas por ele, pedidas pela pessoa, para ela ou atribuídas a ela. Abertas primeiro; cada uma abre o seu fio. A lista geral de Tarefas mantém-se. | `AgentController::tasks()` |

Testes: `tests/Feature/Agents/AgentMemoryTest.php`, `tests/Feature/Agents/AgentPageTest.php`.
## Centro de notificações (09.10.2026)

Pedido do Barrote: a página `/notifications` passa a ser o centro de notificações completo. Antes era uma lista
simples; as aprovações não ficavam lá (só um aviso ao vivo), não havia sino no topo nem preferências.

| Decisão | Onde |
|---|---|
| **Oito tipos de notificação:** Aprovações, À sua espera (agente à espera de resposta, tarefa pronta para rever), Tarefas (atribuídas por pessoas, agentes ou fluxos, e concluídas), Conversas, Email (encaminhados, fora do SLA), Prazos e lembretes (prazos, concursos, contratos, seguimentos), Documentos (briefings, documentos, conhecimento) e Avisos dos agentes. Quem notifica diz o tipo. | `App\Enums\NotificationCategory`, `Notifier::notify()` |
| **O tipo vai na coluna `notifications.type`** (`databaseType()`), para filtrar e contar na base de dados. As notificações antigas ganharam o tipo pela ligação e pelo título, numa migração nova. | `PlatformNotice`, `2026_10_09_090002_notifications_hub` |
| **As aprovações para pessoas chegam ao centro** de quem as pode decidir (a chefia e as pessoas do agente; se nenhuma puder, quem a matriz deixa: orçamento, confiança, tudo). **Aprovar e Rejeitar ali mesmo**, Rejeitar pede o motivo como na página Aprovações; depois de decidida mostra quem decidiu. Uma faixa no topo diz quantas esperam por si. | `ApprovalService::notifyDeciders()`, `ApprovalActions` |
| **Filtros por tipo** (coluna à esquerda, em linha no telemóvel, com as por ler de cada tipo) **e Todas / Por ler**; o endereço guarda os filtros (`?tipo=approval&estado=por-ler`). | `NotificationController::index()` |
| **Agrupadas por dia** (Hoje, Ontem, «Quarta-feira, 7 de outubro»), **paginadas** com 20, 50 ou 100 por página (20 por defeito; `?por_pagina=`). | `Pages/Notifications/Index.tsx` |
| **Pesquisa** no título, no texto e em quem enviou, palavra a palavra (todas têm de aparecer), sem distinguir maiúsculas; pesquisa enquanto se escreve e junta-se aos filtros e às contagens por tipo (`?q=`). Guarda-se um texto pesquisável em `notifications.search`, porque o JSON de `data` escapa os acentos; as antigas foram preenchidas na migração. | `SearchableDatabaseChannel`, `2026_10_09_100001_notifications_search` |
| **Marcar em bloco:** seleccionar e «Marcar como lidas» / «Marcar como por ler»; «Marcar todas como lidas» respeita o tipo aberto. Uma a uma no ícone à direita. Não há apagar: a notificação é o registo do que foi dito. | `read()`, `unread()` |
| **Abrir leva ao assunto:** tarefas e conversas abrem na página da conversa; quando a notificação vem de uma execução, a execução abre no painel lateral (`/tasks/{id}?execucao={run}`), também nas antigas que apontavam para `/runs/{id}`. Aprovações abrem em Aprovações, com o detalhe aberto ao lado. | `NotificationController::destination()` |
| **Sino no topo, à direita:** conta as por ler e abre as últimas 8, com «Marcar todas como lidas», «Ver todas as notificações» e o atalho para as preferências. O item da barra lateral fica. | `Components/notifications/NotificationBell.tsx` |
| **Ao vivo pelo Reverb:** cada notificação nova actualiza o sino e o centro; sem socket, o centro recarrega a cada 15 s. | `UserNotified`, `useLive` |
| **Preferências por pessoa** em Definições › A minha conta › Notificações: por tipo, «No centro», «Alerta no ecrã» e «Por email». Por defeito tudo vai para o centro; alerta no ecrã para Aprovações, À sua espera, Tarefas e Conversas; email só para Aprovações. **As aprovações não saem do centro** (só uma pessoa as decide). O alerta só existe para o que fica no centro. | `NotificationPreferences`, `NotificationPreferencesController`, `users.notification_preferences` |
| **Email da notificação** em texto simples, com o link que a abre (e a marca como lida). Uma falha de envio nunca pára o agente. | `NoticeMail`, `mail/notice.blade.php` |
| `useLive` passa a desligar só o seu próprio ouvinte, para o sino e a página poderem ouvir o mesmo evento no mesmo canal. | `hooks/useLive.ts` |

Fica de fora por não existir ainda na plataforma: menções (@pessoa) e avisos directos do ERP (as escritas no ERP acima
do nível passam pelas aprovações, que já chegam ao centro). Quando existirem, basta um tipo novo no enum.

Testes: `tests/Feature/Notifications/NotificationsHubTest.php`.

## Página de aprovações (09.10.2026)

Pedido do Barrote: levar `/approvals` ao nível do centro de notificações. Antes eram quatro separadores por estado e uma
lista; o detalhe abria num diálogo e não dizia de que conversa vinha a acção.

| Decisão | Onde |
|---|---|
| **Abre no que espera por si** («À sua espera»: pendentes que esta pessoa pode decidir e que já não estão com o Chief of Staff). As outras vistas: Com o Chief of Staff, Todas as pendentes, Aprovadas, Rejeitadas, Expiradas (só aparece se houver) e Todas, cada uma com a contagem. Os endereços antigos (`?status=pending`) continuam a funcionar. | `ApprovalController::query()` |
| **Quem vê o quê:** a direcção vê tudo; as outras pessoas vêem as dos agentes por quem respondem e as que a matriz as deixa decidir (orçamento, níveis de confiança). A regra de `ApprovalPolicy::decide()` existe também numa consulta (`Approval::decidableBy()`), com um teste que garante que dão o mesmo. | `Approval::decidableBy()` |
| **Números no topo:** à sua espera (e quantas no tecto absoluto), a mais antiga, com o Chief of Staff, e decididas em 7 dias (aprovadas, rejeitadas e a mediana do tempo até uma pessoa decidir, nos últimos 30 dias). | `ApprovalController::stats()` |
| **Filtro por área** (Comunicação, Finanças, Comercial, Compras, Pessoas, Projectos, Contratos, Agentes e orçamento, Outras), lida do tipo da acção; **filtro por agente**; **pesquisa** no que a acção diz, nos argumentos, no agente e nas notas, palavra a palavra. Tudo no endereço (`?vista=&area=&agente=&q=`). | `App\Enums\ApprovalArea` |
| **Agrupadas por dia**, como as notificações, paginadas com 25, 50 ou 100 (`?por_pagina=`). | `Pages/Approvals/Index.tsx` |
| **Detalhe no painel lateral** (como na página de conversa), com o endereço `?aprovacao=` para partilhar: a acção, porque decide uma pessoa, a revisão do Chief of Staff, o que vai ser feito, **de onde vem** (a conversa, aberta com a execução ao lado, e a execução) e o **percurso** (pedido, revisão, decisão, execução). Aprovar e Rejeitar em baixo; depois de decidir passa à seguinte que espera, sem fechar. J e K andam pela lista. | `Components/approvals/ApprovalPanel.tsx`, `ApprovalController::open()` |
| **Decidir em bloco:** seleccionar e Aprovar ou Rejeitar. Aprovar em bloco continua a deixar de fora o tecto absoluto (decide-se uma a uma); **Rejeitar em bloco** pede um motivo, que vai para todas, e inclui o tecto absoluto, porque dizer que não é seguro. | `ApprovalController::rejectMany()` |
| **A notificação de uma aprovação abre-a no painel** (`/approvals?aprovacao=`), também as antigas que apontavam só para `/approvals`. | `ApprovalService::notifyDeciders()`, `NotificationController::destination()` |
| Ao vivo pelo Reverb, como antes; sem socket, recarrega a cada 15 s. | `useLive` |

Mantém-se: o Chief of Staff revalida o que está acima do nível; pagamentos, contratos, contratações, comunicação a
entidades novas e emissão de facturas vão sempre a uma pessoa.

Testes: `tests/Feature/Agents/ApprovalsPageTest.php`.
## Página de tarefas (09.10.2026)

Pedido do Barrote: a lista `/tasks` ao nível do centro de notificações. Antes eram cinco separadores, um filtro por
agente e uma pesquisa no título, com as 200 mais recentes de uma vez.

| Decisão | Onde |
|---|---|
| **Vistas na coluna à esquerda** (em linha no telemóvel), cada uma com a sua contagem: Minhas, À sua espera, Todas abertas, Fechadas e Conversas. As contagens seguem os mesmos filtros e a mesma pesquisa da lista. Por baixo, **Por estado**, com as tarefas de cada estado na vista aberta; um clique filtra. | `TaskController::index()`, `inView()` |
| **Filtros que ficam no endereço:** Responsável (agente, pessoa ou por atribuir), Prioridade, Prazo (ultrapassado, próximos 7 dias, sem prazo), Estado, Projecto e Objectivo, com fichas para retirar cada um e «Limpar filtros». Os links antigos `?agent=` e `?person=` continuam a funcionar. | `?responsavel=agent:3&prioridade=urgent&prazo=atrasadas` |
| **Pesquisa** no título, na descrição e no nome do responsável, palavra a palavra; «MIC-12» ou «12» encontra a tarefa nº 12. Pesquisa enquanto se escreve. | `TaskController::search()` |
| **Agrupar** por estado (por defeito), prioridade, responsável ou sem grupos, e **ordenar** por actividade recente (por defeito), prioridade, prazo ou criação, no botão «Mostrar». O grupo ordena primeiro na base de dados, para um grupo nunca se partir fora de ordem entre páginas. | `TaskController::sorted()` |
| **Paginada** com 20, 50 ou 100 por página (20 por defeito), em vez das 200 de uma vez. | `Pages/Tasks/Index.tsx` |
| **Mudar várias de uma vez:** seleccionar e mudar o estado ou a prioridade; as que a pessoa não pode alterar ficam como estão. Uma a uma, o ícone de estado abre o menu para o mudar ali mesmo (só tarefas; as conversas mudam-se na conversa). Não há apagar nem atribuir em bloco: atribuir acorda o agente e merece ser feito tarefa a tarefa. | `TaskController::bulk()`, `POST /tasks/bulk` |
| **Faixas no topo:** quantas tarefas esperam pela sua resposta e quantas suas passaram do prazo, cada uma com o atalho para as ver. | `waiting_total`, `overdue_total` |
| **Cada linha** mostra o prazo (vermelho quando passou, âmbar a menos de dois dias), o projecto ou objectivo, o responsável, as mensagens e a última actividade. | `TaskRow`, `DueChip` |
| As conversas continuam privadas: só quem tem «Ler as conversas de todos» vê as dos outros, também nas contagens. A vista Conversas fica como estava, porque o ecrã das conversas está a ser repensado noutra conversa. | `TaskController::visible()` |
| O estado «À tua espera» passa a «À sua espera», como no centro de notificações. | `TaskStatus::label()` |

Testes: `tests/Feature/Tasks/TaskListTest.php`.

## Conversas a partir dos agentes (09.10.2026)

Pedido do Barrote: a vista `/tasks?view=chats` já não fazia sentido e a conversa devia ser o ecrã de destaque. Depois de ver a proposta com um ecrã «Conversas» próprio, decidiu que não: clica-se no agente para conversar. Escolheu a opção A para a entrada (a conversa mais recente).

| Decisão | Onde |
|---|---|
| **Entrar abre a conversa mais recente** da pessoa (a de actividade mais recente com um agente com quem ainda pode falar). Sem nenhuma, abre uma conversa em branco com o primeiro agente a quem pode falar; sem agentes, Tarefas. «A minha caixa» deixa de existir: o que precisa da pessoa está no sino, nos contadores da barra (Tarefas, Aprovações, Notificações) e em Tarefas › Minhas. | `HomeController`; sai `Pages/Home.tsx` |
| **O grupo «Agentes» da barra é a lista de conversas**, logo abaixo de Tarefas, Email, Aprovações e Notificações: primeiro os agentes com quem a pessoa já falou, a conversa mais recente em cima, e por baixo de «Começar uma conversa» os outros, por nome. Cada um mostra se está a trabalhar, se está à espera da pessoa e quantas respostas estão por ler; com a barra fechada em ícones, um ponto no avatar. O lápis de editar sai da barra (está no topo da conversa). | `HandleInertiaRequests::sidebarAgents()`, `AppLayout` (`AgentsGroup`) |
| **Por ler:** respostas do agente (mensagens e relatórios) depois da última vez que a pessoa abriu a conversa (`tasks.chat_read_at`, guardado ao abrir). Quem só lê a conversa de outra pessoa não a marca como lida. As conversas que já existiam começam como lidas. | migração `2026_10_09_110001`, `TaskController::show()` |
| **Sai o item «Conversas» e a vista de conversas em Tarefas:** `/tasks?view=chats` leva à entrada (a conversa mais recente). A lista de tarefas fica só com tarefas. | `TaskController::index()`, `Pages/Tasks/Index.tsx` |
| **Separador «Tarefas» no painel da conversa**, só na conversa da própria pessoa: as aprovações à espera desse agente que a pessoa pode ver (cada uma abre onde se decide: a tarefa, ou Aprovações) e as tarefas da pessoa com o agente, abertas primeiro (a mesma regra do separador Tarefas da página do agente, agora `Task::withAgentFor()`). Os quatro separadores mostram só o nome no painel estreito. | `TaskController::agentWork()`, `Components/tasks/SidePanel.tsx` |
| **Conversas de outras pessoas só para leitura.** Quem tem «Ler as conversas de todos» encontra-as no separador «Conversas da equipa» da página do agente; abrem sem caixa de escrita («Conversa de X com Y. Pode lê-la, mas só X escreve aqui.»). O servidor deixa de aceitar mensagens ou mudanças de estado de quem não é a pessoa da conversa (antes, essa permissão também deixava escrever). | `TaskPolicy::reply()`, `update()`, `AgentController::conversations()`, `Pages/Agents/Show.tsx` |
| O caminho no topo de uma conversa passa a ser «Agentes › {agente}» (e «› Conversa de {pessoa}» na de outra pessoa). | `Pages/Tasks/Show.tsx` |

Testes: `tests/Feature/Tasks/AgentConversationsTest.php`; sai `HomeTest.php`.

## Rotinas e fluxos agendados (09.10.2026)

Pergunta do Barrote: os agentes não eram proactivos. Já havia rotinas (um pedido num horário cron, só no super admin), vigias e o batimento, mas o resultado de uma rotina não chegava a ninguém, só o super admin as criava, uma hora perdida com o Mac a dormir não voltava a correr e os fluxos só começavam com um email. Decisões dele: o admin cria as rotinas, o resultado vai para a pessoa indicada e o admin recebe sempre, há uma página para as criar e os fluxos também podem começar por agenda.

| Decisão | Onde |
|---|---|
| **Página Rotinas** (`/routines`), só para quem administra a empresa (`company.manage`), na barra a seguir a Fluxos de trabalho. Cada rotina diz o que acontece (um agente faz um pedido, ou começa um fluxo), quando, e quem recebe o resultado. Pode pausar-se, editar-se, apagar-se e «Correr agora». | `RoutineController`, `Pages/Routines/Index.tsx` |
| **Horário em palavras:** Dias úteis, Todos os dias, Certos dias da semana, Uma vez por mês, De hora a hora, ou cron à mão. Guarda-se em cron, lido na hora de Maputo; mostra-se como «Dias úteis às 08:30». | `App\Support\ScheduleLabel`, `lib/schedule.ts` |
| **O resultado vai para a pessoa indicada:** o pedido entra na conversa dessa pessoa com o agente, como mensagem da plataforma, e o agente responde ali (a pessoa recebe «X respondeu» como em qualquer conversa). Sem pessoa escolhida, é quem criou a rotina; sem essa, a chefia do agente. A pessoa tem de ter acesso ao agente. | `RoutineRunner::ask()` |
| **Os admins recebem sempre** um aviso com o resultado (ou a falha, ou a aprovação pendente), com a ligação para a conversa quando a podem ler e, se não, para a rotina. Quem é a própria pessoa da rotina não recebe dois avisos. | `RoutineRunner::delivered()`, chamado pelo `AgentRunner` no fim de cada execução |
| **Fluxos por agenda:** no editor, «Como começa»: Email depois da triagem ou Por agenda. Um fluxo por agenda não tem tipo de email, não pausa outros fluxos ao activar e começa nos horários das suas rotinas; a rotina abre a tarefa do fluxo com a pessoa indicada (ou a pessoa de recurso do fluxo) e avisa-a e aos admins. No mapa dos fluxos há a secção «Por agenda». | `workflows.trigger`, `RoutineRunner::startFlow()`, `Pages/Workflows/*` |
| **Hora perdida recupera uma vez:** se o scheduler estava parado (o Mac a dormir), a rotina corre quando ele volta, uma vez, até 12 horas depois (`agents.routine_catch_up_hours`). | `AgentRoutine::dueAt()` |
| Mantém-se: aprovações, revisão do Chief of Staff, orçamento e tecto absoluto aplicam-se como a qualquer execução; o custo vai para Consumo de IA. As rotinas do super admin continuam em Admin › Agentes. | |

Migração `2026_10_09_140001`. Testes: `tests/Feature/Agents/RoutinesTest.php`.

## Fluxos, Rotinas e Execuções nas Definições (09.10.2026)

Pedido do Barrote: Fluxos de trabalho, Rotinas e Execuções saem da barra principal e passam para as Definições, e a página das Rotinas fica mais completa.

| Decisão | Onde |
|---|---|
| **No grupo «Agentes de IA» das Definições**, à frente de Capacidades: Fluxos de trabalho e Execuções para quem já os via, Rotinas só para quem administra a empresa. Saem da barra principal. | `lib/settings.ts`, `AppLayout` |
| **Endereços novos** `/settings/workflows`, `/settings/routines` e `/settings/runs` (os nomes das rotas não mudam). Os antigos (`/workflows`, `/workflows/new`, `/workflows/{id}/edit`, `/routines`, `/runs`, `/runs/{id}`) levam aos novos. A decisão de um passo de fluxo numa tarefa (`/workflow-steps/{id}/decide`) fica onde estava. As notificações antigas com `/runs/{id}` continuam a abrir a tarefa da execução. | `routes/web.php`, `NotificationController::target()` |
| As listas (Fluxos e Execuções) abrem com a barra das Definições ao lado; o editor de um fluxo e o detalhe de uma execução ficam em ecrã inteiro, com «Definições ›» no caminho. | `Pages/Workflows/*`, `Pages/Runs/*` |
| **Rotinas com detalhe:** em cima, as activas, a próxima a correr, as que falharam e o custo dos últimos 30 dias. À esquerda, a lista (horário, próxima execução, quem recebe e um ponto com o estado da última). À direita, a rotina escolhida: o pedido, o último resultado (texto, estado, duração, custo e ligação para a conversa ou a tarefa), o histórico das últimas 15 execuções, as próximas três horas, quem recebe e os números de 30 dias, com «Correr agora», Pausar, Editar e Apagar. | `RoutineController::index()`, `history()`, `Pages/Routines/Index.tsx` |
| Cada execução que uma rotina começa guarda a rotina (`agent_runs.agent_routine_id`); as que já existiam foram ligadas pela origem. Um fluxo por agenda conta as tarefas que abriu. | migração `2026_10_09_150001` |

Testes: `tests/Feature/Agents/RoutinesTest.php`.

## Perguntas dos agentes (09.10.2026)

Pedido do Barrote: os agentes precisam de uma ferramenta como o AskUserQuestion do Claude. Feito com as escolhas por omissão abaixo; nenhuma ficou por confirmar.

| Decisão | Onde |
|---|---|
| **Capacidade base `questions.ask` («Fazer perguntas de escolha»)**, dada a todos os agentes como `tasks.ask_human` e sem passar pelo portão de autonomia (risco Observe). O agente faz 1 a 4 perguntas; cada uma tem pergunta, etiqueta curta, 2 a 4 opções com rótulo e descrição de uma linha, e escolha múltipla opcional. «Outra» (texto livre) é sempre da plataforma: se o modelo a puser nas opções, sai. | `App\Ai\Capabilities\Local\AskQuestions`, `CapabilityRegistry::ALWAYS_ON`, `InstructionComposer` |
| **A execução pausa:** a execução fica `awaiting_input` («À espera de resposta», sem `finished_at`) e a tarefa fica «À sua espera». Depois de perguntar, qualquer outra ferramenta nessa execução devolve «estás à espera das respostas», e uma segunda ronda de perguntas também. | `RunStatus::AwaitingInput`, `AgentRunner`, `GatedTool` |
| **Responder retoma a mesma execução**, não abre outra: a execução volta à fila e o modelo recebe a conversa anterior, o pedido original, o que já tinha escrito com as perguntas e, como nova mensagem, as respostas. Tokens, custo e duração somam-se na mesma execução. | `QuestionService::answer()`, `AgentRunner::run()` |
| **Cartão interactivo na bolha do agente:** separadores com as etiquetas quando há várias perguntas, opções numeradas (teclas 1–4), «Outra» com caixa de texto, Anterior/Seguinte e «Enviar respostas». Respondido, o cartão fica com as escolhas e «Respondido por X às hh:mm» — é o registo da resposta na conversa, e entra no histórico do agente nas conversas seguintes. Uma execução que falha antes da resposta deixa o cartão «sem resposta». | `Components/tasks/QuestionCard.tsx`, `Chat.tsx`, `TaskController::show()` |
| **Escrever na conversa também responde:** enquanto há perguntas pendentes, uma mensagem da pessoa responde a todas como «Outra» e retoma a execução, em vez de acordar o agente outra vez. | `TaskThread::post()`, `QuestionService::answerWithMessage()` |
| **Para quem vai a pergunta:** a pessoa da tarefa (ou quem a criou), senão quem pediu a execução, senão a chefia do agente. Vai para a própria tarefa quando é do agente e dessa pessoa; senão, para a conversa da pessoa com o agente. Uma execução sem tarefa (triagem de email, rotina sem pessoa) passa a viver nessa conversa, e a resposta final aparece lá. | `QuestionService::ask()` |
| **Fora de uma conversa ao vivo** (rotina, fluxo, triagem, pedido de outro agente) a pessoa recebe a notificação «X tem perguntas para si» na categoria «À sua espera», com ligação para a conversa. Quem está a conversar com o agente vê o cartão aparecer e não recebe notificação. Os admins de uma rotina recebem «A rotina espera uma resposta». | `QuestionService::ask()`, `RoutineRunner::delivered()` |
| **Quem pode responder:** a pessoa a quem se perguntou, ou quem pode escrever na tarefa. Responde-se uma vez; respostas validadas (uma só escolha em perguntas simples, todas respondidas). Fica no registo de auditoria (`question.answered`). | `QuestionController`, `POST /questions/{id}/answer` |
| Fluxos: um passo cujo agente fez perguntas continua com ele (não conta como «terminou sem concluir»). O batimento e o painel contam `awaiting_input` como em espera. | `WorkflowEngine::runEnded()`, `Heartbeat`, `DashboardController` |

Migração `2026_10_09_170001` (tabela `agent_questions` e o estado novo em `agent_runs.status`). Testes: `tests/Feature/Agents/AgentQuestionsTest.php`.

## Memória da empresa (12.10.2026)

Pedido do Barrote: uma memória geral da empresa. Antes, só as decisões (as 8 mais recentes de 90 dias) chegavam sempre a todos os agentes; o resto estava espalhado pela base de conhecimento e pelos artigos «Memória de {agente}», que os agentes só liam se pesquisassem. Ele aprovou a proposta abaixo.

| Decisão | Onde |
|---|---|
| **Uma lista de factos com seis secções fixas:** Quem somos, Clientes e parceiros, Regras e políticas, Preços e condições, Pessoas e papéis, Decisões. Cada facto é uma ou duas frases (até 1000 caracteres), com quem o escreveu ou propôs, quem o aprovou e a data. | `company_facts`, `App\Enums\CompanySection`, `App\Models\CompanyFact` |
| **Entra sempre no prompt de todos os agentes** («## Memória da empresa», por secção, no lugar de «Decisões em vigor»). Tem um limite de caracteres por tenant (`company_memory_chars`, 8000 por omissão, no super admin); acima dele ficam de fora os factos mais antigos e o agente é avisado. | `CompanyMemory::forPrompt()`, `InstructionComposer`, `config/business.php` |
| **Um facto pode ficar limitado a um domínio do conhecimento:** só o sabem os agentes (e só o vêem as pessoas) que abrem esse domínio. Sem domínio, é de todos. | `CompanyMemory::visibleTo()` |
| **Os agentes propõem, uma pessoa aprova.** Capacidade `company.propose_fact`, dada a todos os agentes e sem passar pelo portão de autonomia (só cria uma proposta). Pode indicar o facto que corrige (`replaces_id`); ao aprovar, o antigo sai («substituído»). A mesma frase não é proposta duas vezes. | `ProposeCompanyFact`, `CompanyMemory::propose()`, `approve()` |
| **A consolidação da memória também propõe:** o extractor marca os factos de trabalho que valem para toda a empresa (`company`, com a secção) e esses viram propostas, além de irem para a memória do agente como antes. O extractor recebe a memória da empresa para não repetir. | `MemoryExtractor`, `MemoryConsolidator::consolidate()` |
| **Quem aprova e escreve:** factos de toda a empresa, quem tem «Gerir todo o conhecimento»; factos de um domínio, também as chefias que abrem esse domínio (a regra de revisão do conhecimento). Recebem uma notificação (Documentos) por cada proposta. Criar, editar, aprovar, rejeitar e apagar fica na auditoria (`company_memory.*`). | `CompanyMemory::canCurate()`, `CompanyMemoryController` |
| **Decisões:** `memory.remember_decision` continua a guardar o artigo na base de conhecimento e passa a pôr a decisão, já publicada, na secção Decisões (o portão já pediu a aprovação de uma pessoa). As decisões deixam de expirar aos 90 dias. As que já existiam foram copiadas pela migração. Um artigo de tipo «decisão» gravado com `knowledge.save` já não entra no prompt. | `RememberDecision`, migração `2026_10_12_150001` |
| **Ecrã Conhecimento › Memória da empresa** (`/knowledge/company`; desde 14.10 em Definições › Memória): espaço usado no prompt, propostas dos agentes (Aprovar, Editar e aprovar, Rejeitar), e as seis secções com Adicionar, editar e apagar. Na barra do Conhecimento, com o número de propostas à espera. | `Pages/Knowledge/Company.tsx`, `KnowledgeController::index()` |
| Fica como estava: a memória de cada agente com cada pessoa e os artigos «Memória de {agente}». | — |

Testes: `tests/Feature/Knowledge/CompanyMemoryTest.php`.

## Contexto dos agentes ao longo de dias (12.10.2026)

Pergunta do Barrote: como gerimos o contexto de um agente que corre dias a fio, com várias conversas com a mesma pessoa. O agente lê as últimas 40 mensagens do fio, a sua memória com essa pessoa e a memória da empresa; não havia resumo, as mensagens não tinham data e as conversas só passavam à memória às 21:00. Ele aprovou as três correcções abaixo.

| Decisão | Onde |
|---|---|
| **Consolidar quando há mensagens novas suficientes:** no fim de cada resposta do agente, uma conversa ou tarefa com `memory_every_messages` mensagens ainda por consolidar (30 por omissão, no super admin; 0 deixa só as 21:00) passa logo à memória, antes de essas mensagens saírem do histórico de 40. Uma consolidação de cada vez por fio. As 21:00 e a entrega da tarefa continuam. | `TaskThread::consolidateIfDue()`, `ConsolidateAgentMemory` (único), `config/business.php` |
| **Data e hora em cada mensagem do histórico:** as mensagens de pessoas, outros agentes e notas levam `[Nome · AAAA-MM-DD HH:MM]`; as respostas do próprio agente não, para ele não copiar o prefixo. O prompt diz-lhe que repare no tempo que passou. | `TaskThread::history()`, `speaker()`, `InstructionComposer::task()` |
| **Resumo rolante por fio:** cada consolidação também reescreve `tasks.summary` (onde está o assunto, o que ficou combinado, o que falta e quem o deve; até 2000 caracteres), partindo do resumo anterior. Entra no bloco «Tarefa em curso», como «O que veio antes», só quando o fio já tem mais mensagens do que o histórico mostra. | migração `2026_10_12_180001`, `MemoryExtractor`, `MemoryConsolidator::consolidate()`, `InstructionComposer::summary()` |
| ~~Ficam por decidir~~ Decididas a 14.10.2026 (ver «Falhas de contexto»): cortar o histórico por tamanho em vez de por número; factos que substituem ou apagam outros na memória; consolidar o trabalho sem pessoa; a mesma regra de pessoa ao gravar e ao ler a memória de uma tarefa. | — |

Testes: `tests/Feature/Agents/AgentMemoryTest.php`.

## Contador de email por ler na barra lateral (12.10.2026)

O Barrote reparou que o item Email da barra lateral não mostrava quantos emails havia por ler, mesmo com email novo a chegar (a geral@rethink.co.mz tinha 39 na Entrada). O item nunca tinha tido contador.

| Decisão | Onde |
|---|---|
| **O contador soma o que está por ler na Entrada de cada caixa que a pessoa lê no cliente de email** (as suas e, com «Ler a triagem», as dos agentes), os mesmos números do menu das caixas; quem não lê a triagem soma também o email de triagem encaminhado para si e ainda por ler. As outras pastas (Spam, pastas próprias) não contam. Nunca conta a caixa pessoal de outra pessoa. | `Mailbox::unreadFor()`, scope `Mailbox::inClientOf()`, `auth.unread_emails` |
| **Actualiza-se em directo como os outros contadores:** quando o número de por ler de uma pasta muda (email novo na sincronização, ler ou marcar como não lido), sai `MailboxUpdated` no canal `tenant.{id}.mail`, só com o id da caixa; cada ecrã recarrega a sua contagem. Sem ligação ao Reverb, a barra recarrega o contador de 30 em 30 segundos. | `App\Events\MailboxUpdated`, `MailboxSync`, `MailClient::recount()`, `Layouts/AppLayout.tsx` |

Testes: `tests/Feature/Email/EmailClientTest.php`.

## Nomes nos emails de cobrança vêm do ERP (12.10.2026)

Queixa do Barrote: na aprovação 16 o Agente de Finanças escrevia a «Felício Guelume» da Loop Experience e parecia um nome inventado. Comparado com o ERP real: o contacto existe (Felicio Guelume, Contracts Manager, contacto principal da Loop Experience, Lda), e as três facturas e valores batem. O agente só acrescentou o acento. O que fazia parecer inventado era o registo da execução: guardava só os primeiros 4000 caracteres de cada resposta do ERP, e o contacto vinha depois do corte, por isso não se via de onde vinha.

| Decisão | Onde |
|---|---|
| **O registo da execução guarda a resposta completa de cada ferramenta** (até 200 000 caracteres). O agente sempre recebeu a resposta inteira; agora quem revê vê o mesmo. | `GatedTool::TRACE_LIMIT` |
| **A aprovação de um email mostra cada destinatário como o ERP o tem** (secção «Destinatário no ERP»: nome, cargo, cliente, contacto principal), procurado no que o agente leu do ERP nessa execução. Quando nenhum resultado do ERP da execução traz esse email, aparece um aviso para confirmar o contacto antes de aprovar. Nas execuções antigas, gravadas com o corte, o aviso diz que o registo ficou cortado e que se confirme no ERP. | `App\Ai\Runs\RecipientSources`, `ApprovalController::open()`, `ApprovalPanel.tsx` |
| **Regra no prompt:** nomes, emails, números e valores copiam-se tal como o ERP os devolve, sem acentos nem correcções; sem contacto no ERP, não se adivinha. | `InstructionComposer` |
| O ERP continua só de leitura para os agentes; nada mudou nas capacidades do ERP. | — |

Testes: `tests/Feature/Agents/ApprovalsPageTest.php`.

## Email: pesquisa e filtros (12.10.2026)

O Barrote pediu filtro e pesquisa nas páginas de Email. Já havia uma caixa de pesquisa (Enter, sempre em todas as pastas) e o botão «Não lidos»; ficam agora como nas outras listas (/tasks, /notifications, /approvals).

| Decisão | Onde |
|---|---|
| **A pesquisa procura enquanto se escreve** (350 ms depois de parar), no assunto, remetente (nome e endereço), destinatários, texto e resumo da triagem. **Procura na pasta aberta da caixa escolhida no menu**; «Procurar em: Todas as pastas» (`em=todas`) alarga à caixa inteira, e uma pesquisa sem resultados oferece esse botão. Antes, pesquisar ia sempre a todas as pastas. | `InboxController::listing()`, `Components/email/EmailFilters.tsx` |
| **Filtros num botão «Filtros»** com o número dos que estão ligados: leitura (`leitura=nao_lidos|lidos`; o antigo `unread=1` continua a abrir os não lidos), com anexos (`anexos=1`), com estrela (`estrela=1`), data (`periodo=hoje|7d|30d|90d`, ou `de`/`ate` em AAAA-MM-DD; datas trocadas valem como o intervalo entre elas) e, só nas caixas com triagem e nos encaminhados, o estado da triagem (`triagem=por_triar|triado|falhou`, pelo `status` do email) e a categoria. Cada filtro ligado aparece como etiqueta que o retira; «Limpar» retira todos. A data é a do email (recebido, enviado, ou a da última alteração de um rascunho). | `InboxController::PERIODS`, `TRIAGE_STATES`, `period()` |
| **Tudo vai no endereço**, sem os valores por omissão, por isso um filtro sobrevive a recarregar, abrir um email e voltar, e paginar. Mudar de pasta ou de caixa começa sem filtros. | `filterParams()` |

Testes: `tests/Feature/Email/EmailClientTest.php`.

## Agente ocupado noutro sítio (12.10.2026)

A barra lateral mostrava o Gestor de Clientes «a trabalhar», mas a conversa com ele (tarefa 10) não mostrava nada. O estado não estava preso: o agente corria um batimento na tarefa 15, e a conversa só olhava para as execuções dela própria. Ao mesmo tempo, nada fechava uma execução cujo processo morreu, e essa ficaria «a trabalhar» para sempre (e impedia o agente de responder nessa conversa).

| Decisão | Onde |
|---|---|
| **Na conversa, «A fazer agora, fora desta conversa»**: quando o agente tem execuções vivas noutro sítio (tarefa, rotina, fluxo, outra conversa), um cartão no fim da conversa diz o quê, em que passo e quando começou, com «Abrir tarefa»/«Abrir conversa». É o mesmo `NowRow` da página do agente; a conversa privada de outra pessoa aparece sem título nem botão. O cabeçalho diz «a trabalhar noutro sítio». A execução da própria conversa continua a ser a bolha «a trabalhar», não o cartão. | `AgentNow::for(exceptTask:)`, prop `agent_elsewhere` em `TaskController::show`, `ElsewherePanel` |
| **«A trabalhar» só conta execuções vivas** (`AgentRun::working()`): na fila há menos de 60 min, ou a correr com última actualização há menos de 10 min (o job morre aos 300 s). Usado na barra lateral, na página do agente, no organigrama, na lista de tarefas, na bolha da conversa e na regra que impede duas execuções na mesma tarefa. | `config/agents.php` (`stale_running_minutes`, `stale_queued_minutes`) |
| **Execuções presas fecham-se sozinhas como falhadas**, com nota na conversa («parou a meio sem terminar» / «ficou na fila sem nunca começar») e o evento de fim, para a barra lateral se actualizar: de imediato quando o job falha ou é morto por tempo (`RunAgent::failed`), e de 5 em 5 minutos pelo `agents:close-stale-runs`. | `App\Ai\Runs\StaleRuns`, `routes/console.php` |

## Memória em entradas, como no claude.ai (12.10.2026)

O Barrote enviou as definições de memória do claude.ai e pediu que a memória dos agentes funcione assim: três interruptores por pessoa e a memória organizada em entradas com nome (título, uma linha de descrição, «Actualizada a»), agrupadas em Sobre si (Preferências, Perfil), Temas, Áreas, Pessoas e Projectos. Antes, cada pessoa tinha com cada agente uma lista de factos soltos (os 40 mais recentes e 20 sem pessoa entravam em todos os prompts).

| Decisão | Onde |
|---|---|
| **A memória continua por pessoa e por agente, mas em entradas.** Cada linha de `agent_memories` é uma entrada: `section` (preferences, profile, topics, areas, people, projects), `title` (até 120), `description` (uma linha, até 200) e `content` como corpo (até 4000). Preferências e Perfil são «Sobre si» e sempre pessoais. O trabalho sem pessoa (rotinas, email) tem as mesmas secções, menos «Sobre si». | `App\Enums\MemorySection`, migração `2026_10_12_200001`, `AgentMemory` |
| **A consolidação actualiza ou cria entradas pelo título**, em vez de acrescentar factos: o extractor recebe as entradas que já existem, inteiras, e devolve só as que mudam, com o corpo completo já actualizado; o mesmo título (sem olhar a maiúsculas) actualiza a entrada. `forget` apaga as que deixaram de ser verdade. Os factos para a empresa inteira vêm à parte (`company`) e seguem para a memória da empresa como antes. Isto resolve a pendente de 12.10 «factos que substituem ou apagam outros». | `MemoryExtractor`, `MemoryConsolidator::apply()` |
| **O prompt leva «Sobre si» inteiro e o índice das outras entradas** (número, secção, título, descrição, data), da memória com a pessoa e do trabalho sem pessoa, até 60 de cada. O agente abre uma entrada com a nova ferramenta `memory.read` (pelo número ou pelo título), que só lê a memória da pessoa da execução e a do trabalho sem pessoa. | `MemoryConsolidator::forPrompt()`, `entry()`, `ReadMemory` |
| **Três interruptores por pessoa, ligados por omissão**, em Definições › Memória › A minha memória: «Procurar nas conversas anteriores» (dá sentido à nova `memory.search_chats`, que procura só nas conversas e tarefas da pessoa com aquele agente); «Gerar memória a partir das conversas» (desligado, o agente não actualiza nem usa a memória daquela pessoa; o que lá está fica guardado e o resumo rolante do fio continua, porque é contexto do fio e não memória); «Incluir temas sensíveis» (desligado, o extractor não guarda saúde, religião, política, vida familiar, dinheiro pessoal ou questões legais). | `users.memory_preferences`, `App\Support\MemoryPreferences`, `SearchPastChats` |
| **Cada pessoa vê, corrige e apaga a sua memória** com cada agente nessa página (entradas por secção; ao abrir, o corpo, de onde veio e se é partilhada; «Esquecer tudo» por agente). Só a sua: entradas de outra pessoa dão 404. Os administradores com «Corrigir a memória de qualquer agente» corrigem a de todos em Definições › Memória › Memória dos agentes (escolhem o agente e filtram por pessoa); a página do agente já não tem a aba Memória, só o número de entradas com ligação para lá. | `Settings\MemoryController`, `Settings\AgentMemoryController`, `Pages/Settings/Memory.tsx`, `Pages/Settings/AgentMemory.tsx`, `Components/memory/MemoryEntries.tsx`, `AgentController` |
| **As entradas de trabalho continuam a ir para a base de conhecimento** («Memória de {agente}»), agora uma secção por entrada (título, descrição, corpo). As pessoais nunca vão. | `MemoryConsolidator::publish()` |
| **Migração dos factos existentes:** em cada memória, os factos pessoais juntam-se numa entrada «Preferências» e os de trabalho numa «Notas de trabalho», ambas marcadas «por organizar». `php artisan agents:organise-memory` (uma vez) pede ao modelo que as arrume em entradas por assunto e apaga as notas quando ficam arrumadas. | migração `2026_10_12_200001`, `MemoryConsolidator::organise()`, `OrganiseAgentMemory` |
| **Fora deste trabalho:** a Memória da empresa fica como está (seis secções fixas, curada por pessoas); importar memória de outros fornecedores de IA não existe. | — |

Testes: `tests/Feature/Agents/AgentMemoryTest.php`, `tests/Feature/Knowledge/CompanyMemoryTest.php`.

A memória tem um grupo seu nas Definições, «Memória», com «A minha memória» (toda a gente) e «Memória dos agentes» (quem tem a permissão), a pedido do Barrote no mesmo dia: a memória deixa de estar escondida em A minha conta e na página de cada agente.

## Página dos fluxos de trabalho (12.10.2026)

Pedido do Barrote: melhorar Definições › Agentes de IA › Fluxos de trabalho, sem mudar o comportamento dos fluxos. Antes, a lista dos 12 tipos de email misturava os que têm fluxo com os que não têm, o detalhe ficava apertado entre a lista e uma terceira coluna que só aparecia em ecrãs muito largos (as propriedades caíam para baixo dos passos), e no telemóvel o fluxo escolhido aparecia depois de toda a lista, fora da vista.

| Decisão | Onde |
|---|---|
| **Números no topo**, como nas Rotinas: tipos de email com fluxo (de 12), fluxos activos (rascunhos e em pausa por baixo), lacunas (um clique abre a vista Lacunas) e execuções em 30 dias. | `Pages/Workflows/Index.tsx` (`Metric`) |
| **A lista à esquerda agrupa por situação:** Email com fluxo, Por agenda, Rascunhos e em pausa, Email sem fluxo (em tom mais discreto). Cada linha diz quem trata e, quando há lacunas, o número a vermelho em vez do número de passos. Pesquisa no tipo, no fluxo e no agente. A lista fica fixa ao descer pelos passos. | `PickRow`, `StepCount` |
| **O detalhe é uma só coluna:** nome, estado, descrição e acções (Editar; no menu, Pausar ou Activar e as regras de email ou as rotinas), o caminho do email, quatro factos (quem trata, nível, se não conseguir, 30 dias), a barra de passos com a contagem de cada estado (substitui a legenda solta), os passos e as execuções recentes, com quando começaram. Pausar e Activar usam o endpoint que já existia (`PUT /settings/workflows/{id}/status`), com as mesmas regras. | `FlowDetail`, `NoFlow` |
| **No telemóvel e em ecrãs estreitos (abaixo de 1024 px) o detalhe abre num painel por cima da lista**, com «‹ Fluxos» para voltar; os passos mostram o estado por baixo do nome. | `Sheet`, `Components/workflows/Readiness.tsx` |
| **Vista, escolha e pesquisa no endereço** (`?vista=agentes|lacunas&tipo=client_rfq&fluxo=3&q=`); o `?w=` antigo (Regras de email, cartão do fluxo na tarefa) continua a abrir o fluxo. | `readUrl()` |
| **Novo fluxo** pergunta logo como começa: para um tipo de email ou por agenda. | `PageHeader` |
| **Por agente** passa a cartões, os que entram em fluxos primeiro, com Recebe, Conduz e Ajuda em; cada um abre o fluxo. **Lacunas** agrupa por fluxo, com as soluções em fichas e «Abrir o passo». | `AgentsView` |

Sem mudanças no servidor nem nos dados que a página recebe. Testes: `tests/Feature/Workflows/WorkflowScreensTest.php` (inalterado).

## Falhas de contexto e Memória da empresa nas Definições (14.10.2026)

O Barrote disse «sim» às quatro falhas que ficaram por decidir na análise do contexto de 12.10, e a mudar a Memória da empresa para as Definições.

| Decisão | Onde |
|---|---|
| **O histórico corta-se por tamanho, não só por número:** o agente lê as mensagens mais recentes do fio até `history_chars` caracteres (60 000 por omissão, cerca de 15 000 tokens; no super admin, ao lado de «Consolidar a memória a cada»), e nunca mais de 40. A mais recente entra sempre, cortada se sozinha passar o limite. O resumo «O que veio antes» aparece sempre que há mensagens mais antigas do que as do histórico. | `TaskThread::window()`, `trimmed()`, `InstructionComposer::summary()`, `config/business.php` |
| **Consolidar antes de as mensagens saírem do histórico:** além das 30 mensagens novas, um fio passa logo à memória quando há mensagens por consolidar que já não cabem no histórico (respostas longas). | `TaskThread::consolidateIfDue()` |
| **Factos que substituem outros:** já resolvido pela memória em entradas (actualizar pelo título, `forget` apaga). Faltava avisar o agente do que fica de fora: quando há mais de 60 entradas, o índice diz quantas ficaram de fora e que as pode abrir com `memory.read` pelo título, como na memória da empresa. | `MemoryConsolidator::forPrompt()`, `leftOut()` |
| **O trabalho sem pessoa passa à memória:** execuções sem tarefa e sem pessoa que as pediu (rotinas sem destinatário, triagem de email, seguimentos, vigias), concluídas, consolidam-se na memória de trabalho do próprio agente (sem pessoa, a que todas as conversas lêem), a cada 10 execuções e às 21:00. O que já foi lido fica em `agents.memory_run_id`. Com «Consolidar a memória a cada» a 0, só às 21:00. | migração `2026_10_14_150001`, `MemoryConsolidator::consolidateWork()`, `workRuns()`, `ConsolidateAgentWork`, `AgentRunner`, `agents:consolidate-memory` |
| **Uma regra de pessoa para a memória de uma tarefa:** a pessoa da tarefa, senão quem a criou, ao gravar (consolidação) e ao ler (prompt, `memory.read`, `memory.search_chats`). Uma tarefa sem `user_id` criada por uma pessoa já não esconde o que o agente aprendeu nela. | `Task::memoryOwner()`, scope `memoryOf`, `MemoryConsolidator`, `InstructionComposer`, `ReadMemory`, `SearchPastChats` |
| **Memória da empresa em Definições › Memória**, ao lado de «A minha memória» e «Memória dos agentes» (`/settings/company-memory`), visível a todos como antes. Comportamento e permissões iguais: quem gere o conhecimento (e as chefias do domínio) aprova e escreve. Sai da barra do Conhecimento; `/knowledge/company` e as notificações antigas redireccionam. | `Settings\CompanyMemoryController`, `Pages/Settings/CompanyMemory.tsx`, `lib/settings.ts`, `routes/web.php` |

Testes: `tests/Feature/Agents/AgentMemoryTest.php`, `tests/Feature/Knowledge/CompanyMemoryTest.php`.
