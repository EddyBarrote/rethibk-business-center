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
| L9 Matriz de acessos | `access_roles` e `App\Enums\Permission`; `User::hasPermission()`; `canManageTenant()` e `isManager()` derivam da matriz. Empresa › Papéis e acessos. |
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

