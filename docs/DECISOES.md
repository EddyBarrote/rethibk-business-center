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
| Ilustração | ~~Gerada com nano banana, versão clara e escura em WebP.~~ Desde 26.10.2026 a entrada não usa imagens: ver «Página de entrada com animação». | `Layouts/AuthLayout.tsx` |

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
| 23 | Ler conversas | Administradores e o dono podem ler sempre as conversas dos funcionários com agentes. *Excepção a 20.11.2026: as de um agente confidencial só quem tem «Gerir pessoas e papéis» (o dono tem-na sempre); ver «Director de Recursos Humanos», «Agente confidencial».* |
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

O que o Perfex ainda não tem: RH (assiduidade, férias, folha de salários, vagas, candidaturas, integração) — *corrigido a 19.11.2026: o ERP já tem RH, Assiduidade e Payroll, ver «Director de Recursos Humanos»* —, e o
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
| ~~**Entrar abre a conversa mais recente**~~ (desde 14.10, o ecrã de entrada: «Ecrã de entrada e modo lite») da pessoa (a de actividade mais recente com um agente com quem ainda pode falar). Sem nenhuma, abre uma conversa em branco com o primeiro agente a quem pode falar; sem agentes, Tarefas. «A minha caixa» deixa de existir: o que precisa da pessoa está no sino, nos contadores da barra (Tarefas, Aprovações, Notificações) e em Tarefas › Minhas. | `HomeController`; sai `Pages/Home.tsx` |
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

## Trabalho noutro sítio que terminou (14.10.2026)

O Barrote viu na conversa com o Agente de Triagem (tarefa 6) o cartão «A fazer agora, fora desta conversa» com a triagem de um email, e quando a triagem acabou o cartão desapareceu sem deixar nada. A execução tinha terminado bem (classificou o email como lead e avisou a Ana Sitoe), mas a triagem de um email não pertence a nenhuma conversa, e o cartão só mostrava execuções vivas.

| Decisão | Onde |
|---|---|
| **«Terminou há pouco, fora desta conversa»**: por baixo do cartão do que está a fazer, a conversa mostra até 5 execuções do agente noutro sítio que terminaram nos últimos 30 minutos, com o estado (concluída, falhada, cancelada), quando terminou, a primeira parte da resposta ou o erro, e «Ver execução»/«Abrir tarefa». A execução da própria conversa continua a ser só a resposta na conversa. | `AgentNow::for(withRecent:)`, `AgentNow::RECENT_MINUTES`, prop `agent_elsewhere_done`, `ElsewherePanel` |

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
| **A memória nunca tem contradições** (pedido do Barrote no mesmo dia: um facto que se sobrepõe a outro actualiza a memória, não acrescenta). Na memória do agente, o extractor actualiza a entrada que o novo desmente, mesmo com outro título ou noutra secção, junta entradas repetidas e esquece a que sobra; recebe as entradas mais antigas que as 60 inteiras pelo título, para também as poder actualizar. Na memória da empresa, cada facto aparece com o número (`#id`) no prompt e no extractor; uma proposta que corrige ou contradiz um facto indica-o (`replaces` na consolidação, `replaces_id` em `company.propose_fact`) e, ao ser aprovada, o antigo sai («substituído»). | `MemoryExtractor`, `MemoryConsolidator::known()`, `apply()`, `CompanyMemory::forPrompt()` |
| **O trabalho sem pessoa passa à memória:** execuções sem tarefa e sem pessoa que as pediu (rotinas sem destinatário, triagem de email, seguimentos, vigias), concluídas, consolidam-se na memória de trabalho do próprio agente (sem pessoa, a que todas as conversas lêem), a cada 10 execuções e às 21:00. O que já foi lido fica em `agents.memory_run_id`. Com «Consolidar a memória a cada» a 0, só às 21:00. | migração `2026_10_14_150001`, `MemoryConsolidator::consolidateWork()`, `workRuns()`, `ConsolidateAgentWork`, `AgentRunner`, `agents:consolidate-memory` |
| **Uma regra de pessoa para a memória de uma tarefa:** a pessoa da tarefa, senão quem a criou, ao gravar (consolidação) e ao ler (prompt, `memory.read`, `memory.search_chats`). Uma tarefa sem `user_id` criada por uma pessoa já não esconde o que o agente aprendeu nela. | `Task::memoryOwner()`, scope `memoryOf`, `MemoryConsolidator`, `InstructionComposer`, `ReadMemory`, `SearchPastChats` |
| **Memória da empresa em Definições › Memória**, ao lado de «A minha memória» e «Memória dos agentes» (`/settings/company-memory`), visível a todos como antes. Comportamento e permissões iguais: quem gere o conhecimento (e as chefias do domínio) aprova e escreve. Sai da barra do Conhecimento; `/knowledge/company` e as notificações antigas redireccionam. | `Settings\CompanyMemoryController`, `Pages/Settings/CompanyMemory.tsx`, `lib/settings.ts`, `routes/web.php` |

Testes: `tests/Feature/Agents/AgentMemoryTest.php`, `tests/Feature/Knowledge/CompanyMemoryTest.php`.

## Repositório de ficheiros da empresa (14.10.2026)

Pedido do Barrote: um repositório de ficheiros da empresa, com os agentes a lerem os PDF. Escolha dele: o repositório fica **dentro do Conhecimento** (as regras de acesso por domínio e departamento já lá estavam), não numa entrada própria. Perguntou também se não eram precisos vectores: já existiam (Gemini, 3 072 dimensões, comparados um a um em PHP); passaram a ser mais leves e a pesquisa passou a ser híbrida.

| Decisão | Onde |
|---|---|
| **«Ficheiros» no Conhecimento**: todos os ficheiros de todos os domínios que a pessoa abre, com filtros por tipo (PDF; Word, Excel, PowerPoint; imagens; texto), também dentro de cada domínio e pasta. Vista em lista ou em grelha, com a primeira página de cada PDF (ou a própria imagem); a escolha fica no browser. | `KnowledgeController::index` (`view=files`, `kind`), `Pages/Knowledge/Index.tsx`, `Components/knowledge/FileCard.tsx` |
| **Envio por partes, até 100 MB por ficheiro e 200 de cada vez**, com o mesmo envio dos anexos das conversas (`/files`, origem `repository`), por isso não depende do limite de upload do PHP (2 MB no Mac). Só quem tem «Escrever conhecimento». Aceita PDF, Office, texto (também HTML) e imagens. | `AttachmentLimits::repository()`, `AttachmentStore::claimForRepository()`, `Components/knowledge/UploadDialog.tsx`, `config/knowledge.php` |
| **Pastas inteiras**: «Carregar › Uma pasta inteira», ou arrastar uma pasta para a página. Cada ficheiro leva o caminho dentro da pasta e as pastas são criadas por baixo da pasta aberta. | `Components/knowledge/dropped.ts`, `KnowledgeController::folderFor()` |
| **Sem repetidos**: cada ficheiro guarda o sha256 dos bytes; o mesmo ficheiro outra vez (pela pessoa, por um agente ou de um anexo) não cria outro item e diz qual já existe. | `knowledge_items.content_hash`, `KnowledgeRepository::duplicateOf()` |
| **Versões**: «Nova versão» na página do ficheiro troca o ficheiro e guarda o anterior em «Versões anteriores», para descarregar. Só a versão actual é lida e pesquisada. Apagar o item apaga as versões. | `knowledge_item_versions`, `KnowledgeBase::replaceFile()` |
| **Leitura na fila, página a página**: ao carregar, o ficheiro fica «A ler» e um job lê-o. Num PDF, o `pdftotext` tira o texto de cada página; cada página fica guardada à parte e o conteúdo do item leva linhas `[Página N]`. Também faz a miniatura da primeira página (`pdftoppm`). Word, Excel, PowerPoint e texto como antes. A página do ficheiro actualiza-se sozinha enquanto lê. | `FileReader`, `ReadKnowledgeFile`, `knowledge_item_pages`, `PdfTools` |
| **Páginas digitalizadas lidas pelo Gemini**: uma página com menos de 80 caracteres é tratada como digitalizada. Essas páginas (só essas, separadas com `pdfseparate`) vão ao modelo 8 de cada vez, cada lote no seu job (o worker corta aos 300 s), até 300 páginas por documento. O modelo transcreve tal como está, tabelas em Markdown, e marca [assinatura], [carimbo: …], [manuscrito ilegível]. Imagens sem texto (não há Tesseract no Mac) vão igual. Sem chave de IA ou sem orçamento, o ficheiro fica guardado e a página diz quantas páginas ficaram por ler, com «Ler outra vez». | `PageReader::transcribe()`, `config/knowledge.php` (`reading`) |
| **O custo destas leituras conta no orçamento**: nova tabela `ai_charges` para chamadas ao modelo fora das execuções dos agentes; entra no gasto do mês da organização (e do agente, quando é um agente a pedir) e aparece em Definições › Consumo de IA. | `AiCharge`, `BudgetGuard::tenantSpent()`, `agentSpent()`, `charge()`, `hasRoom()`, `Settings\UsageController` |
| **Vectores em binário e a 768 dimensões**: o Gemini aceita menos dimensões (Matryoshka) quase sem perder qualidade; cada parte passa de 3 072 números em JSON para 3 KB de float32 normalizados, e a comparação é um produto interno. Cada parte guarda a página onde começa. A pesquisa continua por força bruta em PHP (dezenas de milhares de partes); a base de dados do Mac é MariaDB 10.4, sem tipo vector. Quando o alojamento tiver MariaDB 11.8 (tipo `VECTOR` com índice) a comparação passa para a base de dados; o MySQL 9 gratuito tem o tipo mas não a função de distância. | `VectorCodec`, `KnowledgeBase::embed()`, `semantic()`, `knowledge_embeddings.vector`, `page` |
| **Pesquisa híbrida**: os vectores e as palavras votam juntos (reciprocal rank fusion); antes, as palavras só contavam quando os vectores não davam nada. Um item com as palavras exactas da pesquisa vem primeiro (uma referência como FT-2026-118 ou um NUIT encontra-se por texto, não por significado); depois o índice de texto completo do MariaDB. O excerto mostra onde estão as palavras e em que página; o resultado abre o PDF nessa página. | `KnowledgeBase::search()`, `keyword()`, `pageAt()` |
| **Os agentes lêem por páginas**: `memory.search` diz o ficheiro e a página do excerto; `knowledge.read` aceita `pages` («3» ou «3-5»). | `SearchKnowledge`, `ReadKnowledge` |
| **Os agentes vêem o original** com a nova `knowledge.view`: o agente faz uma pergunta sobre umas páginas (até 15) de um PDF ou imagem e o Gemini olha para essas páginas e responde, com a página de cada coisa. Serve para tabelas, assinaturas, carimbos, manuscritos e gráficos, que o texto perde. Dada a todos os agentes; o custo vai para o orçamento do agente. | `ViewKnowledgeFile`, `PageReader::look()` |
| **Guardar no repositório**: na janela de pré-visualização de um ficheiro da conversa ou de um anexo de email, quem o pode abrir e escrever no Conhecimento guarda uma cópia num domínio e pasta. Os agentes fazem o mesmo com a nova `knowledge.file` (anexos da conversa ou do email em que estão a trabalhar), dada a todos, com as regras do `knowledge.save`: risco N1 e, abaixo de N3, «para rever». Os anexos de email ficam marcados como conteúdo externo. A instrução dos anexos lembra o agente de guardar contratos, facturas e propostas. | `KnowledgeImportController`, `Components/knowledge/SaveToRepository.tsx`, `FileToKnowledge`, `KnowledgeRepository`, `ModelInput` |
| **Ficheiros já existentes**: `php artisan knowledge:reindex` volta a ler todos os ficheiros (página a página, digitalizados pelo Gemini) e a indexar tudo com os vectores novos. Corrido uma vez depois da migração. | `Console/Commands/Knowledge/Reindex.php` |
| **Página do ficheiro**: «Original» e «O que os agentes lêem» (o texto, com as páginas lidas por IA indicadas), páginas, versão, de onde veio o texto, aviso quando a leitura falhou ou ficou incompleta. | `Pages/Knowledge/Show.tsx` |

Testes: `tests/Feature/Knowledge/FileRepositoryTest.php` (leitura por páginas com uma página digitalizada, sem modelo, pasta arrastada e repetidos, permissões, versões, guardar anexos por pessoa e agente, pesquisa híbrida, `knowledge.view` e orçamento, ecrã).

## Ecrã de entrada e modo lite (14.10.2026)

Pedido do Barrote: o ecrã de entrada deve ser como o do Claude.ai, onde se escolhe o agente com quem falar, com saudação e sugestões. Haverá um modo lite e um modo mais completo, de administração. Viu o mockup e aprovou-o, com as opções recomendadas. Isto substitui a primeira linha de «Conversas a partir dos agentes» (entrar já não abre a conversa mais recente).

| Decisão | Onde |
|---|---|
| **Entrar abre o ecrã de entrada**: «Bom dia / Boa tarde / Boa noite, {nome}», a caixa de escrita com anexos e gravação, o agente a quem vai (por omissão o da conversa mais recente, senão o primeiro por nome) e as sugestões desse agente. A caixa não tem sombra. Uma sugestão preenche a caixa; enviar entra na conversa com esse agente (a mesma `agents.chat`). Sem agentes com quem falar, Tarefas. | `HomeController`, `Pages/Home.tsx` |
| **«À sua espera» numa linha**, por cima da caixa: aprovações e tarefas à espera (os contadores da barra) e os agentes com perguntas por responder à pessoa, cada um a abrir onde se resolve. | `HomeController` (`questions`) |
| **Sugestões por agente**: até 4, escritas na configuração do agente (secção «Sugestões»). Em branco, mostra as que as capacidades activas do agente sugerem (uma lista fixa por capacidade, mais duas gerais), que aparecem em cinzento no formulário. | migração `2026_10_14_190001`, `agents.suggestions`, `App\Support\AgentSuggestions`, `AgentEditor`, `Components/agents/AgentDefinitionForm.tsx` |
| **Modo lite**: a barra fica com «Nova conversa», Tarefas, Email, Aprovações e Notificações, e as conversas com os agentes (o grupo «Agentes», sem «Ver todos»). Saem o grupo Trabalho (Painel, Objectivos, Projectos, Briefings, Documentos, Ficheiros, Conhecimento, Organigrama) e Definições da barra; Perfil, Aparência e Definições continuam no menu da pessoa. Só muda o que a barra mostra: as páginas e as permissões são as mesmas. | `AppLayout` |
| **Modo completo**: a barra de sempre, com «Nova conversa» em cima. | `AppLayout` |
| **Quem começa em que modo**: quem não administra a empresa começa no lite e os admins (`company.manage`) no completo. Cada pessoa troca no ícone ao lado do sino (abre «Modo lite» e «Modo completo») ou no seu menu e a escolha fica guardada. | `users.ui_mode`, `User::prefersLite()`, `ProfileController::mode()`, `auth.user.lite` |

Testes: `tests/Feature/HomeTest.php`; sai o teste de entrada de `AgentConversationsTest.php`.

## Rethink Technologies como tenant de teste (15–16.10.2026)

Pedido do Barrote: deixar de testar com a empresa inventada (MICOMOC) e testar com a Rethink Technologies, a empresa dele, com o que já se sabe dela. Escolha por omissão (dita na conversa): um tenant novo, `rethink` (<http://rethink.localhost:8000>), em vez de reescrever o `micomoc`, para a demonstração ficar intacta.

| Decisão | Onde |
|---|---|
| **Tenant `rethink`**, proprietário Edy Barrote (CEO). Marca dos documentos: azul `#0052CC`, Space Grotesk (nova na lista de letras), logótipo da Rethink. Sem `mail_domain`, por isso os agentes não têm caixas próprias. | `Brand::FONTS`, `settings.brand` |
| **As integrações reais passam para a Rethink e saem da MICOMOC**: a ligação ao ERP Perfex (rethink.rethinkerp.online) muda de tenant com as credenciais, só na base de dados; a MICOMOC volta ao ERP de demonstração local. A caixa geral@rethink.co.mz muda com o histórico, para o email não ser triado duas vezes. | `erp_connections`, `tenant:move-mailbox` |
| **`tenant:move-mailbox {endereço} {de} {para} [--active]`**: o endereço é único em toda a plataforma, por isso a caixa muda de dono em vez de ser copiada: pastas, conversas, mensagens e anexos (ficheiros copiados para a pasta do novo tenant), com a posição IMAP, para nada já lido voltar aos agentes. Larga o que só fazia sentido no tenant antigo: donos e leitores, execuções, pessoas e departamentos de cada mensagem, e as tarefas, execuções, seguimentos, fluxos e concursos que apontavam para as mensagens. Muda `tenant_id` com updates em massa, de propósito: os modelos não deixam mudar `tenant_id`. | `Console/Commands/Tenants/MoveMailbox.php` |
| **Só de fontes reais**: pessoas do ERP (`list_staff`: Edy Barrote, Kleyton Macatange, Naira Sitoe; os dois últimos como membros, sem função, com palavra-passe aleatória e sem convite), um só departamento (Direcção-Geral), porque o ERP não tem departamentos da Rethink e não se inventa estrutura. Os seis agentes dos modelos, com descrições e sugestões da Rethink, todos a reportar ao CEO. As rotinas de RH ficam desligadas (o ERP não tem módulo de RH). | dados |
| **Agentes só lêem o ERP e não enviam email**: as 75 ferramentas do ERP que escrevem e `comms.send_email` ficam desligadas no catálogo do tenant (`is_enabled`); a sincronização mantém a escolha. O mailer local continua `log`: ninguém real recebe nada. | `capabilities` |
| **Memória da empresa** com 22 factos aprovados (Quem somos, Pessoas, Clientes, Regras), das propostas ao MTC (2023) e ao FDC (2024), das certidões nos «Documentos da empresa», dos emails da caixa geral@ e do ERP. Os três PDFs da empresa que vieram por email ficam no repositório (Geral) e são lidos pela fila. | `company_facts`, `knowledge_items` |

Testes: `tests/Feature/Tenancy/MoveMailboxTest.php`.

## Passar trabalho a um colega (16.10.2026)

Pedido do Barrote: o Chief of Staff avisou que a subscrição da TVCabo acabava e, quando ele pediu «escreve uma mensagem para a Naira para procedermos ao pagamento», o agente só redigiu um texto para copiar. Ele quer que o agente colabore com a Naira dentro da plataforma. Viu a proposta e disse «avança».

| Decisão | Onde |
|---|---|
| **Numa conversa, o agente dá trabalho em nome de quem fala com ele**: além das pessoas da sua área e da sua chefia, pode dar uma tarefa a qualquer colega activo quando a pessoa da conversa é uma chefia (`work.manage`, quem já pode criar uma tarefa para uma pessoa directamente). Fora de uma conversa, ou para quem não é chefia, a regra da área mantém-se. Não é preciso dar departamentos às pessoas para isto funcionar. | `CreateTask` |
| **A tarefa leva o prazo e o email de origem** (`due_at`, `email_id`, só emails que o agente pode ler) e fica com a pessoa da conversa como «quem pediu» (`user_id`). Abre com a nota «Pedido por {pessoa}, na conversa com {agente}». | `CreateTask` |
| **Quem recebe a tarefa abre o email de onde ela veio** (só esse), também na caixa. | `EmailMessage::visibleTo()` |
| **O agente que deu a tarefa acompanha-a**: o que a pessoa escreve no fio acorda-o, e ele responde às dúvidas; não a marca como feita nem faz o trabalho dela (os pagamentos ficam sempre com pessoas). Abrir a tarefa não põe ninguém a trabalhar. | `TaskThread::agentOf()`, `open()`, `InstructionComposer::task()` |
| **O resultado volta à conversa**: quando a pessoa marca a tarefa como feita, a última mensagem (e os ficheiros, por número) chegam à conversa de onde veio o pedido e o agente avisa quem pediu. Pode ler esses ficheiros e guardá-los no repositório (`files.read`, `knowledge.file` aceitam os das tarefas que deu a partir da conversa). | `TaskThread::reportToParent()`, `readableTaskIds()` |
| **A memória dessa tarefa é de quem a recebe**: no fio da tarefa, o agente lê e grava a memória da pessoa a quem a deu, nunca as conversas de quem a pediu (no teste, a pesquisa de conversas no fio da Naira trazia a conversa do Edy). | `Task::memoryOwner()`, scope `memoryOf` |
| **A página da tarefa mostra o agente que acompanha** («Escreva a Chief of Staff…», «Sobre Chief of Staff»). | `TaskController::show()` |
| **O agente conhece as pessoas**: o prompt lista as pessoas activas com email, função e departamento (até 60), e diz que pedir algo a um colega da plataforma é dar-lhe a tarefa; um texto para copiar só serve para pessoas de fora. | `InstructionComposer::team()`, `people()` |

`tasks.create` e `tasks.ask_human` já estavam ligadas a todos os agentes (`CapabilityRegistry::ALWAYS_ON`); o que travava era a regra da área (a Naira não tem departamento) e as instruções. Testes: `tests/Feature/Tasks/ColleaguesTest.php`.

## Agenda (16.10.2026)

Pergunta do Barrote: o Agente de Finanças disse «agendei um seguimento para 19/10» sobre a TVCabo, mas não havia onde o ver, e a mesma TVCabo já era a RET-5 da Naira, com prazo a 19/10. Ele propôs ligar ao Google Calendar e ter um calendário interno para pessoas e agentes. Viu a proposta (Agenda primeiro, um link de subscrição em vez de OAuth, Google nos dois sentidos depois) e disse «avança».

| Decisão | Onde |
|---|---|
| **Uma página Agenda** (menu, abaixo de Tarefas), mês e semana, feita do que já existe: seguimentos (`follow_ups`), prazos das tarefas (`due_at`) e as próximas execuções das rotinas. Nada é guardado duas vezes. Uma rotina que corra mais de 62 vezes no período aparece uma vez por dia. | `App\Agenda\Agenda`, `AgendaController`, `Pages/Agenda/Index.tsx` |
| **Quem vê o quê**: «O meu» é o que é para a pessoa e o dos agentes que lhe reportam (seguimentos, prazos, rotinas de que é a pessoa indicada). «Toda a empresa» só com «Ler todo o trabalho» e segue a visibilidade das tarefas (`Task::visibleTo`, agora usado também pela lista de Tarefas). **O lembrete pessoal de outra pessoa nunca aparece a ninguém**, nem a admins; um seguimento de uma tarefa só a quem vê a tarefa; o link para o email só a quem o pode ler. | `Agenda::followUps()` |
| **Lembretes próprios**: qualquer pessoa cria, marca como feito, adia e apaga os seus. Um seguimento que um agente guarda para si fecha-o a chefia do agente ou um admin, mas não se apaga. Adiar volta a pô-lo por avisar. | `AgendaController` |
| **Um seguimento pertence à tarefa** (`follow_ups.task_id`): a indicada (`task_id`), a que o agente está a fazer, ou a tarefa aberta sobre o email (de preferência a de uma pessoa). O aviso abre a tarefa. | migração `2026_10_16_120001`, `ScheduleFollowUp`, `NotifyFollowUps` |
| **Sem duplicados**: se já há uma tarefa aberta sobre o mesmo email (ou a mesma tarefa) com prazo nesse dia, ou um seguimento aberto para o mesmo nesse dia, o agente não agenda outro e recebe quem já acompanha («RET-5 «Pagar a TVCabo» (Naira) já acompanha isto, com prazo 19/10/2026»). Um lembrete noutro dia (dois dias antes, por exemplo) fica, ligado à tarefa. | `ScheduleFollowUp::alreadyTracked()` |
| **Numa conversa, o seguimento é da pessoa da conversa**, não da chefia do agente: as conversas são privadas. | `ScheduleFollowUp` |
| **Ver no Google ou no Outlook com um link privado (.ics)**, só de leitura: seguimentos e prazos de «O meu» (sem rotinas), de 60 dias atrás a um ano à frente, com título, quem trata, nota do seguimento e o link de volta; nunca o conteúdo de um email ou de uma conversa. Guarda-se só o hash do link, por isso ele aparece uma vez; gerar outro desliga o anterior, e pode desligar-se. Fica no registo de auditoria. | `IcsFeed`, rota `calendar/{token}.ics`, `users.calendar_token` |
| **Google Calendar nos dois sentidos fica para depois**: por pessoa, em Definições › Integrações, com o consentimento dela, como o email; um agente nunca escreve na agenda de alguém sem essa pessoa. As credenciais OAuth do Google ficam fora do repositório. | — |

| **Filtros** (pedido do Barrote, «faltam filtros»): por tipo (as etiquetas Seguimento, Prazo de tarefa e Rotina ligam e desligam), por quem trata (pessoas primeiro, depois agentes, com quantos têm no período) e por estado (por fazer, em atraso, feitos). Ficam no endereço (`tipo`, `pessoa`, `estado`) e mantêm-se ao mudar de mês ou de vista. A lista de quem trata vem do período inteiro, filtrado ou não. | `AgendaController::filter()`, `people()` |

Testes: `tests/Feature/AgendaTest.php`.

## Google Calendar por pessoa (27.10.2026)

Pedido do Barrote: «cada utilizador pode conectar com o seu Google Calendar, para sincronizar as actividades». Segue o que a Agenda deixara para depois: por pessoa, com o consentimento dela, e um agente nunca escreve na agenda de ninguém.

| Decisão | Onde |
|---|---|
| **Ligar na Agenda**: «Ligar o Google Calendar» leva ao consentimento do Google; depois o botão «Google Calendar» mostra a conta, a última sincronização, «Sincronizar agora» e «Desligar». Sem cliente OAuth no `.env` a opção não aparece. | `GoogleCalendarController`, `Pages/Agenda/Index.tsx` (`GoogleDialog`) |
| **Permissões mínimas**: ler eventos (`calendar.events.readonly`) e gerir só os calendários que a plataforma cria (`calendar.app.created`), mais o email da conta. Se a pessoa desmarcar uma das duas do calendário, a ligação é recusada e o acesso devolvido. | `GoogleCalendar::SCOPES` |
| **Um só endereço de regresso**, no domínio central (`/oauth/google/callback`), para todos os tenants: o `state` é aleatório, guardado 10 minutos em cache com o tenant e a pessoa, e só serve uma vez. O Google não tem de conhecer cada subdomínio. | `bootstrap/app.php`, `GoogleCalendar::redirectUri()` |
| **Do Google para a Agenda**: os eventos do calendário principal, de 60 dias atrás a um ano à frente, lidos por incrementos (`syncToken`; se o Google o esquecer, lê-se tudo de novo). Aparecem com a etiqueta «Google Calendar» só a essa pessoa, nem em «Toda a empresa» nem aos agentes, e não contam como «por fazer». | `CalendarSync::pull()`, `Agenda::google()` |
| **Da plataforma para o Google**: os seguimentos e prazos de «O meu» (o mesmo que o link .ics, sem rotinas) vão para um calendário «Agenda · <empresa>» que a plataforma cria na conta da pessoa; o calendário principal nunca é alterado. Só se reenvia o que mudou (hash); o que deixa de estar na Agenda sai do Google. Se a pessoa apagar esse calendário, é criado de novo. Os eventos do Google nunca voltam para lá nem para o .ics. | `CalendarSync::push()` |
| **De 5 em 5 minutos** (`calendar:sync`), quando a pessoa liga, e ao abrir a Agenda se a última vez foi há mais de 2 minutos. Acesso retirado no Google: fica a mensagem «Volte a ligar o Google Calendar». | `SyncCalendars`, `SyncCalendar`, `AgendaController::google()` |
| **Desligar** apaga o calendário da plataforma na conta, devolve o acesso ao Google e apaga a ligação e os eventos guardados. Ligar e desligar ficam na auditoria. Os tokens ficam encriptados. | `CalendarSync::disconnect()`, tabelas `calendar_connections`, `calendar_events` |
| **Credenciais fora do repositório**: um cliente OAuth «Aplicação Web» do Google Cloud, com a Google Calendar API activa, em `GOOGLE_CALENDAR_CLIENT_ID`/`_SECRET` (e `GOOGLE_CALENDAR_REDIRECT_URI` se o endereço não for o do domínio central). | `config/services.php`, `.env.example` |

Testes: `tests/Feature/GoogleCalendarTest.php`.

## Mensagens entre agentes (16.10.2026)

Pedido do Barrote, com quatro capturas do Grok: os agentes coordenam-se sozinhos. Ele pergunta ao Chief of Staff «O que há para hoje?», responde «Caça leads agora», e o Chief of Staff passa o pedido ao Gestor Comercial («Messaged Gestor Comercial»). Na conversa dele com o Gestor Comercial aparece «Message from Chief of Staff», o Gestor Comercial fala-lhe ali enquanto trabalha e, no fim, manda o resultado a ele e ao Chief of Staff («Messaged Chief of Staff»), que o transmite. Construído de ponta a ponta com as escolhas por omissão abaixo.

| Decisão | Onde |
|---|---|
| **`agents.message`, dada a todos os agentes**: passa um pedido da pessoa da conversa a um colega agente. É uma mensagem, não uma tarefa: o colega trata já. Para trabalho longo, com prazo e acompanhamento, continua `tasks.create`. | `MessageAgent`, `CapabilityRegistry::ALWAYS_ON` |
| **O colega trabalha na conversa dessa pessoa com ele** (a conversa única pessoa–agente), onde lhe fala; a pessoa vê-o «a trabalhar» lá e na barra lateral. **Só recebe o pedido**, nunca a conversa de onde veio (as conversas são privadas). | `Handoffs::send()`, `forModel()` |
| **A resposta volta sozinha**: quando a execução do colega nessa conversa termina, a resposta final segue para o agente que passou o pedido, que acorda e diz à pessoa o que importa. Se falhar, segue «Não consegui terminar» com o erro. Uma execução que pára sem resposta deixa o pedido aberto. O colega não precisa (nem pode) mandar mensagem de volta. | `Handoffs::settle()`, chamado em `TaskThread::recordReply()` |
| **As duas conversas mostram a passagem numa linha**, como no Grok: «Mensagem enviada a [avatar] Gestor Comercial · a trabalhar / respondeu / não terminou» e «Mensagem de [avatar] Chief of Staff». Aberta, mostra o pedido ou a resposta e leva à outra conversa, se quem vê a pode abrir. Contam como por ler na barra lateral. | `TaskMessageKind::Handoff`, `agent_handoffs`, `Chat.tsx` (`HandoffRow`) |
| **Quem pode passar a quem**: as regras do organigrama de sempre (para baixo, ou à chefia directa; fora do organigrama, a qualquer um). Entre áreas passa-se pelo Chief of Staff (decisão 12 do realinhamento). E só a um agente com quem a pessoa pode conversar, porque é com ela que ele vai falar. | `Handoffs::send()` |
| **Sem pingue-pongue**: no máximo 3 agentes por pedido, 3 pedidos abertos por agente e conversa, e as linhas da própria passagem nunca acordam ninguém. | `Handoffs::MAX_DEPTH`, `MAX_OPEN`, `TaskThread::pickUpWaitingMessages()` |
| **Nada sai para fora por causa de uma passagem**: o colega é lembrado de que não contacta ninguém nem envia emails sem ordem da pessoa, e o agente que passa diz-lho. As aprovações e o tecto absoluto (pagamentos, contratos, contratações) não mudam. | `Handoffs::forModel()`, `MessageAgent`, `AutonomyGate` (inalterado) |
| **Quem trabalha num pedido passado não finge passar o resto**: se uma parte é de um colega que não está abaixo dele, diz isso na resposta e quem lhe passou o pedido encaminha. Visto no teste real: a Triagem escreveu «encaminhado ao Gestor de Clientes» quando o organigrama a tinha travado. | `Handoffs::send()` |
| **O agente que passou o pedido vê o que foi mesmo passado**: com a resposta do colega chega uma linha da plataforma, tirada dos registos (os pedidos que ele passou e as tarefas que deu nessa execução), ou «não passou trabalho a ninguém». Se a resposta diz que uma parte é de outro colega, ou diz que encaminhou algo que os registos não confirmam, é ele que passa e diz à pessoa a quem; nunca diz que algo foi encaminhado se não foi. O Barrote viu no teste o Chief of Staff repetir «encaminhado ao Gestor de Clientes» sem nada ter passado. | `Handoffs::passedOn()`, `forModel()` |
| **Os ecrãs servem o build** (`public/build`): depois de mudar o frontend corre-se `npm run build` no checkout principal. A coluna `task_messages.kind` é um enum no MariaDB (os testes em SQLite não o apanham): um tipo novo precisa de migração. | `2026_10_16_150002` |

Teste real na Rethink (16.10, 14:36): o Barrote escreve «Caça leads agora» ao Chief of Staff, que passa o pedido ao Agente de Triagem (o tenant não tem um «Gestor Comercial»); a Triagem lê os emails #81 e #87 na conversa dela com o Barrote, agenda os seguimentos, e a resposta volta ao Chief of Staff, que a resume. Nenhum email saiu.

Testes: `tests/Feature/Tasks/HandoffsTest.php`.

## Ver o que o agente fez (16.10.2026)

O Barrote, na RET-7: o Gestor de Clientes diz que preparou um rascunho de email, agendou um seguimento e registou a lead, mas só o PDF tinha botão; «deveria ter».

| Decisão | Onde |
|---|---|
| **Por baixo da resposta, um botão por coisa que o agente fez nesse turno**, tirado do resultado da ferramenta e não do texto dele: «Ver rascunho» (`email.draft_reply`, abre o rascunho na caixa), «Abrir relatório #N» (`reports.draft`), «Ver na Agenda» no dia do seguimento (`followups.schedule`, só se ficou agendado), «Abrir RET-N» (`tasks.create`, `escalate.urgent`), «Abrir a conversa com …» (`agents.message`) e «Abrir no Conhecimento» (`knowledge.save`, `knowledge.file`). Os documentos gerados já aparecem como anexo. | `TaskController::resultLink()`, `Chat.tsx` (`ResultLinks`) |
| **Só caminhos da própria consola** (`/…`) devolvidos pela ferramenta; a página que abrem verifica quem a pode ver. O que não deixa nada para abrir (avisos, leituras) não tem botão. | `TaskController::resultLink()` |

Testes: `tests/Feature/Tasks/TaskThreadTest.php` («gives what the agent made…»).

## Quem escreveu o rascunho (16.10.2026)

O Barrote, no email «Pedido de cotação» da Rethink: dois cartões «Rascunho do agente» por baixo do mesmo email, sem dizer qual agente escreveu cada um.

| Decisão | Onde |
|---|---|
| **Cada rascunho de agente mostra o nome e a cara do agente** («Rascunho de Gestor de Clientes»), no cartão por baixo do email e no cabeçalho do «Rever e enviar». O autor vem da execução que criou o rascunho (`agent_run_id` → agente), não do texto. Um rascunho que uma pessoa já editou (`written_by_user_id`) deixa de ser do agente e fica só «Rascunho». | `InboxController::conversation()` (`agent`), `email.ts` (`draftTitle`), `Email/Index.tsx`, `EmailComposer.tsx` |

Testes: `tests/Feature/Email/EmailClientTest.php` («names the agent that wrote each draft…»).

## Pesquisa na internet (16.10.2026)

O Barrote pediu «Busca por concursos» ao Gestor de Clientes da Rethink: o agente só tinha os registos, a caixa geral@ e a base de conhecimento, e respondeu que não havia anúncios na caixa. O agente dele no Grok, com a mesma pergunta, encontrou na web um concurso da Associação Ungagodoli (sovagas.co.mz) e um da CFM prorrogado. Os agentes passam a poder pesquisar e ler a internet.

| Decisão | Onde |
|---|---|
| **`web.search`, «Pesquisar na internet»**: uma chamada curta a um modelo com a pesquisa do próprio provedor (Google Search no Gemini, já configurado: nenhum segredo novo). Devolve o que encontrou e a lista das fontes, embrulhado em `<resultados_web_nao_confiaveis>`, com a ordem de dizer que veio da internet e pôr os endereços. Ler uma página continua a ser `web.read_page`, que já existia. | `SearchWeb`, `App\Ai\Web\WebSearcher` |
| **As fontes são os endereços reais**: o Gemini cita por links de redirecção da Google, que expiram; a plataforma segue cada um um salto (HEAD, sem seguir) e guarda o destino, se for público. Repetidos saem. Se a resolução falhar, fica o link da Google. | `App\Ai\Web\WebSources` |
| **Só leitura**: pesquisar e ler não contactam ninguém (`isMutating` falso). Os agentes continuam a não enviar nada para fora sem uma pessoa pedir; o prompt di-lo. | `SearchWeb`, `InstructionComposer::internet()` |
| **Ligadas a todos os agentes, desligáveis por agente**: `web.search` e `web.read_page` ficam ligadas uma vez em cada agente que existe (na sincronização seguinte), vêm marcadas no formulário de um agente novo e com o assistente, e entram nos agentes instalados de modelos. Um administrador desliga-as num agente como qualquer outra capacidade e a sincronização não as volta a ligar (`capabilities.granted_to_agents_at` marca que a concessão já foi feita). | `CapabilityRegistry::ON_BY_DEFAULT`, `CapabilityCatalog::grantOnByDefault()`, `AgentEditor::options()`, `TemplateInstaller`, `AgentDrafting` |
| **O agente sabe quando ir à internet**: com `web.search` ligada, o prompt tem uma secção «Internet»: o que está fora da empresa (concursos, sites e contactos, notícias, preços) procura-se também na web; para concursos, portais e jornais de Moçambique e a página do anúncio aberta com `web.read_page`; na resposta separa o que é da web do que é da empresa, com o endereço de cada coisa. | `InstructionComposer::internet()` |
| **Custo no orçamento de IA**: cada pesquisa é um `AiCharge` (`web.search`) com os tokens e uma taxa por pesquisa (`agents.web_search.fee_usd`, 0,014 USD: a Google cobra cerca de 14 USD por 1000 pesquisas). Sem chave do provedor ou sem orçamento, a ferramenta diz que não está disponível. Provedor e modelo em `agents.web_search` (por omissão Gemini e o modelo por omissão dele). | `config/agents.php`, `BudgetGuard::charge()` |

Teste real na Rethink (16.10, 18:09): «Busca por concursos» ao Gestor de Clientes. O agente fez quatro pesquisas (software e TI, websites, equipamento, manifestações de interesse) e trouxe, cada um com a sua página: Ministério das Finanças/ASFPM (TI, biblioteca virtual e cloud, 27/10 09:15, diarioeconomico.co.mz), ChildFund (gestão documental, 29/10, concursos.co.mz), MTGAS/INAS (consultoria de transformação digital, 27/10 15:30, PDF em bcl.org.mz), EMME (equipamento, 26/10 10:00, b2b.co.mz) e CFM (storage e processamento de dados, 27/10 23:59, diarioeconomico.co.mz), separados do que veio dos registos (certificado UFSA, propostas anteriores). Custo: 0,22 USD nas quatro pesquisas mais 0,05 USD da execução. Na primeira tentativa fez uma só pesquisa e um resultado levava o link de outro: daí a regra de duas a quatro pesquisas e o endereço da própria fonte em cada resultado. Os concursos que o Grok achou (Ungagodoli em sovagas.co.mz, CFM gestão documental) não apareceram nesta pesquisa.

Testes: `tests/Feature/Agents/WebSearchTest.php`.

## Falar com um agente sobre um email (16.10.2026)

O Barrote, no email aberto: «poder encaminhar para um agente ou conversar acerca».

| Decisão | Onde |
|---|---|
| **«Falar com agente» na barra do email aberto**: escolhe-se o agente (os que a pessoa pode usar), escreve-se o que se quer (ou nada) e o email entra na conversa única da pessoa com esse agente, que abre. Não é reencaminhar por SMTP: o email não sai da empresa. | `InboxController::talk()`, `TalkToAgent.tsx` |
| **A mensagem leva o email** (`task_messages.email_message_id`); na conversa aparece como cartão que abre o email. O agente recebe-o como texto não confiável, com o número para as ferramentas de email, no turno e no histórico. | `TaskThread::sharedEmail()`, `Chat.tsx` |
| **Partilhar dá acesso só a esse email**: `EmailMessage::readableBy()` passa a incluir os emails partilhados numa conversa do agente, por isso pode ler, responder em rascunho ou criar tarefa a partir dele; o resto da caixa pessoal continua fechado. Fica registado (`email.shared_with_agent`). | `EmailMessage::readableBy()` |

Testes: `tests/Feature/Email/EmailClientTest.php` («takes the open email to a conversation…»).

## Perguntar, nunca inventar (16.10.2026)

Na RET-10 (Rethink, run 386) o Gestor de Clientes pôs 25 000 MZN de instalação e formação e 3 500 MZN/mês de licença e suporte no relatório #5, no PDF #17 e no rascunho #94 como «estimativas de mercado», porque não havia preçário de POS na base de conhecimento nem no ERP, e só depois pediu os preços. Na RET-6 (run 374) tinha feito o mesmo (12 500 MZN de setup, 7 500 MZN e 5% de comissão). Regra do Barrote: se o agente não tem a informação, pergunta; as pessoas dão-lha; nunca inventa.

| Decisão | Onde |
|---|---|
| **Regra para todos os agentes**: preços, taxas, comissões, descontos, condições, prazos, datas e contactos só saem de uma fonte (instruções, memória da empresa, base de conhecimento, ERP, emails, mensagens das pessoas, página da internet com endereço). Se falta um, pergunta logo com `questions.ask`, antes de criar relatórios, documentos, rascunhos ou registos; nada de valores provisórios, marcadores ou «valores de mercado». | `InstructionComposer` (regras da plataforma) |
| **A plataforma recusa o que traz valores sem fonte**: antes de uma ferramenta que altera dados ou produz para pessoas (`reports.draft` incluído; `questions.ask` e `tasks.ask_human` não) correr ou ir para aprovação, cada montante (com moeda, ou no formato 25 000,00), percentagem, email e telefone dos argumentos tem de aparecer nas fontes da execução: o prompt (instruções, tarefa, memória), a entrada, o que as ferramentas devolveram nesta execução e nas anteriores da mesma tarefa, as perguntas e respostas e as mensagens das pessoas na conversa. As respostas do próprio agente não contam. Recusada, a chamada volta ao agente com os valores em falta e a ordem de perguntar; fica no traço como erro e na auditoria como `denied`. | `App\Ai\Runs\FactCheck`, `GatedTool::handle()` |
| **Perguntar em aberto**: no primeiro teste o agente perguntou, mas com opções de preço inventadas («Mensalidade SaaS 5.000 MZN», «Licença Única 45.000 MZN», «50% / 50%»). `questions.ask` e `tasks.ask_human` passam pela mesma verificação: valores sem fonte nas perguntas ou opções são recusados, com a ordem de perguntar com opções neutras («Indico o valor», «Ainda não sei») e deixar a pessoa escrever o valor. | `FactCheck::blocked()`, `InstructionComposer` |
| **Contas sobre dados contam como encontradas**: totais de duas a quatro parcelas, parcela × quantidade escrita no texto, IVA e descontos sobre parcelas, e o total de registos de uma lista do ERP (facturas em atraso: soma de parte dos `total`/`balance`). A taxa só conta se a fonte a escrever como taxa (um 50 solto não é 50%); o IVA de 16% é conhecido (`agents.known_rates`). Destinatários (`to`, `cc`, `bcc`) ficam com o gate, que já mostra de onde veio cada endereço. | `FactCheck::derived()`, `FactCheck::totalOf()`, `config/agents.php` |
| **Na resposta, só uma nota**: montantes e contactos sem fonte na resposta final não a bloqueiam (podem ser contas do agente), mas ganham uma «Nota da plataforma» com os valores que não se encontraram. | `FactCheck::annotate()`, `AgentRunner::run()` |
| **A resposta da pessoa fica para a próxima vez**: quando uma pessoa dá um preço ou condição que serve para outros casos, o agente propõe-no à memória da empresa (`company.propose_fact`, à espera de aprovação) e diz-lho. | `InstructionComposer` |

Verificado sobre os traços reais: nas últimas 92 chamadas que produzem algo (MICOMOC e Rethink), a verificação recusaria só as da run 386 (RET-10) e da run 374 (RET-6); as cobranças da MICOMOC com totais de facturas passam. Limite conhecido: um valor inventado que coincide com um número de uma fonte (o 25.000 de um serviço de email numa proposta antiga) passa.

Testes: `tests/Feature/Agents/FactCheckTest.php`.

## Tarefas pelo título (16.10.2026)

O Barrote, sobre uma resposta do Chief of Staff («Tarefa criada: RET-13 — …», «Atribuída a: Gestor de Clientes (client_manager)», botão «Abrir RET-13»): as referências têm de dizer alguma coisa; cada tarefa tem título e descrição, e o título comunica melhor do que o código.

| Decisão | Onde |
|---|---|
| **Título e descrição obrigatórios** em cada tarefa criada por um agente (já eram); o título é curto e diz o que é e a quem ou ao quê respeita, sem códigos (exemplo na própria ferramenta: «Seguimento da proposta à Fundação AZUL»). | `CreateTask::schema()` |
| **Os agentes falam das tarefas pelo título e dos colegas pelo nome**: regra da plataforma para todos; códigos (RET-13) e chaves (client_manager) são só para as ferramentas. Na tarefa em curso, o código vem entre parênteses, como referência. `tasks.create` e `escalate.urgent` devolvem `title` e `ref` (já não `task` com o código), e os textos das ferramentas (`tasks.update_status`, `tasks.ask_human`, `followups.schedule`) falam pelo título. | `InstructionComposer`, ferramentas em `App\Ai\Capabilities\Local` |
| **A chave interna nunca chega à conversa**: na resposta do agente, «Gestor de Clientes (client_manager)» fica «Gestor de Clientes» e `client_manager` entre crases passa ao nome. | `TaskThread::withoutAgentKeys()` |
| **Botões, avisos e notas pelo título**: «Abrir «título»» depois de criar ou escalar uma tarefa; notificações «Nova tarefa: título», «X concluiu «título»», ««título» pronta para rever»; notas na conversa («deu a tarefa «título» a …»), rotinas, fluxos e percurso. | `TaskController::resultLink()`, `TaskThread`, `RoutineRunner`, `WorkflowEngine`, `TaskJourney`, `Journey.tsx` |
| **O código fica como texto secundário**, pequeno e cinzento, para pesquisa e unicidade: depois do título no cabeçalho da tarefa, na lista de tarefas, e como dica (title) nas fichas do percurso, da memória e dos passos de fluxo. A migalha e o separador do browser mostram o título. | `Tasks/Show.tsx`, `Tasks/Index.tsx` |

Testes: `tests/Feature/Tasks/TaskTitlesTest.php`.

## Raciocínio na conversa (16.10.2026)

Pedido do Barrote: o «A pensar…» deve mostrar o raciocínio, e ser minimalista como no Claude (uma faísca que roda e texto com brilho, sem balão, já feito antes). O ecrã tinha um balão com três pontos, «🔧 a usar web_search» em monospace e «ver execução #392» por baixo.

| Decisão | Onde |
|---|---|
| **O Gemini envia um resumo do raciocínio** (`generation_config.thinking_summaries = auto`, Interactions API). Desliga-se com `AGENTS_THOUGHT_SUMMARIES=false`. Outros provedores não pedem nada (o Claude exigiria orçamento de pensamento e temperatura 1). Os tokens de pensamento já entram em `output_tokens` (a laravel/ai soma `total_thought_tokens`), por isso contam no orçamento e no custo. | `GenericAgent::providerOptions()`, `config/agents.php` |
| **Ao vivo**: enquanto pensa, o raciocínio aparece por baixo de «A pensar…», em texto pequeno e esbatido, umas cinco linhas que seguem o mais recente. Quando responde ou usa uma ferramenta, recolhe-se em «Pensou durante 12 s», que se abre para ler. | `AgentRunner::streamToThread()`, `TaskReplyStreaming`, `Chat.tsx` (`WorkingBubble`, `ThoughtToggle`) |
| **Fica guardado por passo**: cada bloco de raciocínio é um passo `reasoning` da execução, com o texto e a duração (do início do passo do modelo ao fim do resumo). Na conversa, a resposta final mostra «Pensou durante…» com a soma dos blocos; na execução aparece como «Raciocínio». | `RunRecorder`, `TaskController::thinking()` |
| **A ferramenta em português**: «A pesquisar na internet…» em vez de `web_search`. O nome da capacidade passa a frase: infinitivo vira «A pesquisar…», substantivo vira «A consultar contratos…». | `ToolActivity`, `GatedTool::label()` |
| **Menos ruído**: «ver execução #…» só aparece enquanto não há ferramentas (com ferramentas, está dentro da linha das ferramentas). | `Chat.tsx` |

Limite conhecido: o Gemini escreve os resumos em inglês, mesmo com instruções e pedido em português (testado com o modelo por omissão, `gemini-3.6-flash`). Traduzir exigiria outra chamada ao modelo por bloco.

Testes: `tests/Feature/Tasks/ThinkingTest.php`.

## Email proposto na conversa (16.10.2026)

O Barrote pediu ao Chief of Staff «mostra aqui a proposta do email a enviar»: o Chief of Staff pediu o email ao Gestor de Clientes e mostrou a minuta em tópicos («Destinatário: Dra. Natália Saide (Fundação AZUL)», «Assunto: Seguimento da Proposta RT-PRO-2026-AZUL-01…», o texto). Quando um agente propõe um email, a conversa deve mostrá-lo como email, como o Claude faz.

| Decisão | Onde |
|---|---|
| **`email.draft`, «Preparar email»**: um email novo (seguimento, primeiro contacto, envio de proposta) fica como rascunho no Email, para a pessoa rever e enviar; responder a um email recebido continua a ser `email.draft_reply`. Ligada a todos os agentes uma vez (como a internet), desligável por agente; risco N1, por isso corre sem aprovação até em N2 (não sai nada da empresa). | `DraftEmail`, `CapabilityRegistry::ON_BY_DEFAULT` |
| **Na caixa da pessoa**: o rascunho vai para a caixa da pessoa da conversa (a que envia, se tiver várias), e só sem ela para a caixa do próprio agente. Sem nenhuma, a ferramenta recusa e o agente diz à pessoa que ligue a caixa. Vai também para os Rascunhos do servidor. | `DraftEmail::mailboxFor()` |
| **Endereços só de uma fonte**: cada endereço em Para e Cc tem de aparecer nas fontes da execução (a mesma verificação de «Perguntar, nunca inventar»); o que não aparece fica de fora e a ferramenta di-lo ao agente. Sem endereço, o rascunho guarda a quem se escreve (`pending_recipient`, «Dra. Natália Saide») e o cartão mostra «Endereço em falta». | `DraftEmail::grounded()`, `FactCheck::ungrounded()`, `2026_10_16_180001` |
| **O cartão de email**: por baixo da resposta do turno que preparou o email, com a cara e o nome do agente («Rascunho de Gestor de Clientes»), Para, Cc, De, Assunto e o texto formatado, e «Rever e enviar» (abre o rascunho no compositor do Email), «Editar» (muda o próprio rascunho, ali mesmo) e «Copiar». É o mesmo rascunho da caixa: editado lá aparece editado aqui, enviado aparece «Enviado às …», descartado desaparece. O «Ver rascunho» desse rascunho deixa de aparecer como botão. | `TaskController::proposedEmails()`, `EmailCard.tsx`, `Chat.tsx`, `Email/Index.tsx` (`?rever=`) |
| **Enviar continua com a pessoa**: os botões de rever e editar só aparecem a quem pode escrever dessa caixa; o envio é o do Email (os agentes nunca enviam em nome de uma pessoa; `comms.send_email` com as regras de aprovação de sempre). | `EmailCard.tsx` (`can_edit`), `MailboxMailer` |
| **Também quando o email vem de um colega**: o turno acordado pela resposta de um colega mostra os rascunhos que o colega deixou nessa resposta. O agente que passou o pedido lê, na linha da plataforma, que rascunhos o colega deixou e que a pessoa os vê como cartão, com a ordem de não copiar o email para a resposta. | `TaskController::proposedEmails()`, `Handoffs::drafted()` |
| **Regra para todos os agentes**: quando propõem um email, preparam-no com `email.draft` (ou `email.draft_reply`) e dizem numa ou duas linhas o que prepararam, em vez de escrever o email no texto. | `InstructionComposer` (regras da plataforma) |

Testes: `tests/Feature/Email/ProposedEmailTest.php`.

## Um só rascunho por email (17.10.2026)

O Barrote, no email 91 da Rethink («Pedido de cotação» de software para uma loja de calçados): quatro cartões iguais «Rascunho de Gestor de Clientes» por baixo do mesmo email, com as iniciais «GC» num círculo. «Aqui deve ter símbolo do agente, e nota que temos muitos drafts para o mesmo email, não pode acontecer isso.» Os quatro vieram de quatro execuções do Gestor de Clientes, cada uma a criar um rascunho novo: a 377 deixou o rascunho e falhou (o Gemini caiu), a retoma 378 escreveu outro; o Chief of Staff abriu uma segunda tarefa para o mesmo email (RET-10, run 386) e a revisão com os preços do Barrote (run 390) escreveu um quarto, dizendo que tinha «actualizado» o rascunho.

| Decisão | Onde |
|---|---|
| **Um rascunho aberto por email**: quando um agente prepara outra vez a resposta ao mesmo email (retoma de uma execução falhada, outra tarefa, uma revisão, outro agente), reescreve o rascunho aberto dessa resposta na mesma caixa em vez de criar outro; o rascunho passa a ser da última execução (o cartão aparece por baixo dessa resposta) e a cópia nos Rascunhos do servidor é substituída (mesmo Message-ID). A ferramenta diz ao agente que actualizou o que já havia. | `AgentDrafts::replyTo()`, `DraftEmailReply` |
| **O mesmo no «Preparar email»**: um email novo para as mesmas pessoas (os mesmos endereços, ou o mesmo destinatário sem endereço) na mesma conversa reescreve o rascunho aberto; para outras pessoas é outro email. | `AgentDrafts::newEmail()`, `DraftEmail` |
| **O rascunho de uma pessoa nunca é reescrito**: se uma pessoa o escreveu (`written_by_user_id`) ou o alterou (`edited_by_person_at`, marcado quando muda Para, Cc, Bcc, Assunto ou texto; abrir e guardar sem mudar não conta), o agente não lhe toca nem cria outro e a ferramenta manda-o dizer à pessoa o que mudaria. | `AgentDrafts::isPersons()`, `MailClient::saveDraft()`, `2026_10_17_100001` |
| **Os repetidos que já havia são arquivados, não apagados**: `mail:archive-duplicate-drafts` (com `--dry-run`) fica com o mais recente de cada email (ou o da pessoa, se houver) e tira os outros rascunhos de agentes das vistas e dos Rascunhos do servidor; a linha fica com o texto, a execução e os ficheiros (`removed_at`). | `ArchiveDuplicateDrafts`, `AgentDrafts::duplicates()`, `archive()` |
| **O símbolo do agente no rascunho**: o cartão do rascunho por baixo do email, o cabeçalho do «Rever e enviar» e o cartão de email na conversa mostram a cara do agente com o robô dos Agentes no canto, para não se ler como uma pessoa; sem foto, a cara continua a ser as iniciais do agente. | `AgentMark` (`AgentAvatar.tsx`), `Email/Index.tsx`, `EmailComposer.tsx`, `EmailCard.tsx` |

Testes: `tests/Feature/Email/OneDraftPerEmailTest.php`, `tests/Feature/Email/ProposedEmailTest.php`.

## Produtos e serviços para cotações: Reactive no Conhecimento (17.10.2026)

Pergunta do Barrote: onde ficam os produtos e serviços que os agentes usam nas cotações, se na base de conhecimento, e, se sim, um conhecimento sobre a Reactive para testar. A recomendação de onde ficam (catálogo) espera pelo «avança» dele; o teste fez-se só com dados, sem código.

| Decisão | Onde |
|---|---|
| **Conhecimento da Reactive no tenant `rethink`**, publicado, em Geral › Produtos e serviços › Reactive (todos os agentes o lêem): «Reactive: serviços e o que incluem» e «Reactive: preços e condições nas fontes». Cada facto diz a fonte: o site rethink.co.mz (produtos/reactive, produtos/rethink-rsvp, faq), o ERP (artigos 265 e 266, facturas e cotações com o número) e as skills internas reactive-cashless-fecho e reactive-bilhetes-e-bio. | `knowledge_items` 28 e 29, `knowledge_folders` 15 e 16 |
| **Preço de catálogo só o do ERP; o resto é histórico**: o artigo de preços separa o artigo do ERP («Tickets by Reactive», 7% por bilhete) dos valores de facturas e cotações a clientes concretos (comissões de 5%, 6% e 7%; plataforma de eventos 40 000 a 70 000; check-in 12 000 por dia; pacotes de caixas do fecho), e acaba com o que nenhuma fonte diz e se pergunta ao CEO. Os preços dos exemplos das skills (bilhetes de 1000 MT, etc.) ficaram de fora. | artigo 29 |
| **A proposta inventada da RET-6 continua por rever**: «Proposta Comercial Reactive - Eddy Barrote» (item 19, `pending_review`) tem os 12 500 MZN de setup, 7 500 MZN e 5% que o agente estimou. Não é publicada nem usada como fonte. | — |

Sem código, sem testes novos.

## Vendas parecidas (17.10.2026)

Pedido do Barrote: há eventos para os quais se fazem propostas, e o agente deve conseguir ir buscar facturas parecidas para ter uma base. O Perfex só procura facturas e cotações pelo número ou pelo cliente, e as linhas de cada documento custam uma leitura; na Rethink nenhum agente tinha sequer leituras de facturas, cotações ou catálogo.

| Decisão | Onde |
|---|---|
| **Uma cópia das vendas do ERP, linha a linha**: facturas, cotações e artigos do catálogo, com cliente, data, estado, total, linhas (descrição, descrição longa, quantidade, preço, valor, impostos) e notas e condições do documento. A cópia relê só os documentos novos ou mudados (estado, total, data, cliente, número) e retira os que desapareceram; no máximo 400 leituras por vez. Só para ERP Perfex. | `erp_sales`, `App\Sales\SalesHistory::sync()`, migração `2026_10_17_120001` |
| **Copiada de hora a hora** (`erp:sync-sales`, na fila por tenant) ou já com `erp:sync-sales {tenant}`. Cada leitura passa pelo `ErpGateway` e fica na auditoria. | `Console/Commands/Erp/SyncErpSales`, `Jobs/SyncErpSales`, `routes/console.php` |
| **`sales.similar` («Vendas parecidas»)**: o agente diz o que se vendeu («cashless», «bilhetes», «check-in»), e recebe os artigos do catálogo e os documentos cujas linhas o mencionam (sem acentos nem maiúsculas, palavras genéricas como «serviço», «evento» e «proposta» não contam), primeiro os que têm mais palavras, depois os mais recentes; filtra por facturas ou cotações e por cliente. Sem cópia ainda, pede-a e diz para tentar depois. | `App\Ai\Capabilities\Local\SimilarSales`, `SalesHistory::search()` |
| **Histórico não é tabela**: a ferramenta e as instruções do Gestor de Clientes dizem que o catálogo é o preço de tabela e um documento é o que se fez com um cliente; o agente cita o número do documento e pergunta a quem decide os preços quando o histórico varia ou não cobre o pedido. O que a ferramenta devolve conta como fonte para a verificação de «Perguntar, nunca inventar». | `SimilarSales::description()`, `AgentTemplates::clientManager()` |
| **Quem a tem**: Gestor de Clientes e Chief of Staff (modelos); na Rethink dada também aos dois agentes existentes. | `AgentTemplates` |

Testes: `tests/Feature/Erp/SimilarSalesTest.php`.

## Caixa de email sem servidor (17.10.2026)

- **O problema.** A caixa ebarrote@rethink.co.mz foi ligada a 16.10 com utilizador e palavra-passe mas sem servidor IMAP nem SMTP (o campo mostrava `imap.hostinger.com` como exemplo, parecia preenchido). Sem servidor a caixa fica local: as pastas aparecem, mas nunca chega nenhum email e não dizia porquê.
- **Servidor em branco = o do mesmo domínio.** Ao guardar uma caixa pessoal com o servidor de entrada ou de saída em branco, usa-se o servidor, a porta e a segurança de outra caixa pessoal do tenant com o mesmo domínio (geral@ e ebarrote@ estão no mesmo Hostinger).
- **Sem servidor de entrada não se liga.** Se não houver nenhuma caixa do mesmo domínio para copiar, a caixa é recusada com «Indique o servidor de entrada (IMAP)». Uma edição que não toca no servidor continua a passar.
- **A caixa diz porquê.** Uma caixa pessoal sem servidor de entrada mostra, no Email e nas Definições, «Esta caixa não tem servidor de entrada (IMAP), por isso nenhum email chega». Guardá-la de novo nas Definições preenche o servidor.
- O exemplo no campo passou a `ex.: imap.empresa.co.mz`, para não se confundir com um valor.

## Emails por quem escreveu (17.10.2026)

O Barrote, numa resposta do Chief of Staff que começava «Análise do Email #143 e acções tomadas»: «Aqui analise o email de fulano de y, z com assunto x, z». O número do email é interno, como o código das tarefas.

| Decisão | Onde |
|---|---|
| **A regra para os agentes**: fala-se dos emails por quem os escreveu, a empresa e o assunto («o email de Carmélia Buque, da Olankha, a pedir cotação de credenciação»), nunca pelo número; os números ficam para as ferramentas. | `InstructionComposer` (Regras da plataforma) |
| **A plataforma corrige o que escapar**: numa resposta de um agente, «Email #143» passa a «email de Carmelia Buque («Solicitação de Cotação…»)», com o nome de quem escreveu (ou o endereço) e o assunto; um email enviado lê-se «email para …». Só para emails que o agente pode ler; os outros ficam com o número, para não revelar quem escreveu. | `EmailReferences::inText()`, `TaskThread::recordReply()` |
| **Títulos de tarefas sem o número**: «Elaborar cotação … (Email #143)» fica «Elaborar cotação …». | `EmailReferences::inTitle()`, `CreateTask` |

Testes: `tests/Feature/Tasks/EmailReferencesTest.php`.

## Rascunho só quando a pessoa escolhe (17.10.2026)

O Barrote, na caixa geral@rethink.co.mz da Rethink, com 19 rascunhos nos Rascunhos quase todos escritos por agentes e repetidos (três «Re: Pedido de cotação – sistema de bilhética», dois «Re: Pedido de uma cotação»): «Estes rascunhos devemos escolher guardar em rascunho.» O rascunho de um agente ia sozinho para os Rascunhos da caixa e do servidor; passa a ser a pessoa a escolher.

| Decisão | Onde |
|---|---|
| **O rascunho de um agente é uma proposta**: fica por baixo do email e no cartão da conversa («Rascunho de …»), mas não entra nos Rascunhos nem vai para o servidor de email. Os agentes deixam de pôr rascunhos no servidor. | `EmailMessage::proposals()` / `isProposal()`, `EmailFolder::messages()`, `MailClient::pushDraft()`, `DraftEmail`, `DraftEmailReply` |
| **A pessoa escolhe**: «Guardar em rascunho» (vai para os Rascunhos e para o servidor e passa a ser dela), «Rever e enviar» (abre no compositor; guardar ou mudar também o faz dela) ou «Descartar». Só quem escreve a partir da caixa guarda; fica no registo (`email.draft_kept`). | `InboxController::keepDraft()` (`POST inbox/drafts/{id}/keep`), `MailClient::keepDraft()`, `saveDraft()` (`kept_at`), `Email/Index.tsx`, `EmailCard.tsx` |
| **Um rascunho por email continua**: o agente só reescreve a sua proposta; um rascunho que uma pessoa guardou (`kept_at`), escreveu ou alterou nunca é reescrito. | `AgentDrafts::isPersons()`, `2026_10_17_140001` |
| **Os rascunhos de agentes que já estavam nos Rascunhos voltam a propostas**: `mail:return-agent-drafts` (com `--dry-run`) tira dos Rascunhos e do servidor os que nenhuma pessoa guardou nem alterou; a linha fica com o texto, a execução e os ficheiros, por baixo do email e na conversa. Os escritos ou alterados por pessoas ficam onde estão. Os rascunhos de agentes cuja execução já foi apagada (escritos aqui, sem pessoa, sem cópia de um email do servidor; 16 na geral@ da Rethink, de 8 a 12.10) não podem aparecer como proposta de um agente e são arquivados (`removed_at`), não apagados. | `ReturnAgentDrafts`, `AgentDrafts::filedProposals()`, `returnToProposal()`, `orphans()` |

Testes: `tests/Feature/Email/DraftWhenPersonChoosesTest.php`, `tests/Feature/Email/EmailClientTest.php`, `tests/Feature/Email/OneDraftPerEmailTest.php`.

## Linha temporal compacta (17.10.2026)

O Barrote, na página de uma execução com 16 passos de que só cabiam 9 no ecrã: «Deve ser mais minimalista para preservar o espaço.» A linha temporal (página da execução e painel lateral da conversa) passa a ter uma linha por acontecimento.

| Decisão | Onde |
|---|---|
| **Chamada e Resultado numa só linha**: ícone, nome da ferramenta, o essencial da resposta a cinzento quando é texto, duração e hora. O resultado junta-se à última chamada aberta à mesma ferramenta, como em `TaskController::toolCalls()`. Uma ferramenta que falhou fica com o ícone vermelho e o erro por inteiro por baixo. | `RunTimeline.tsx` (`entriesOf()`, `ToolItem`) |
| **Detalhes ao clicar na linha**, sem «Ver o que enviou» / «Ver o que recebeu»: «Enviou» e «Recebeu» lado a lado quando há largura, um por baixo do outro no painel lateral. Uma ferramenta clicada na conversa abre a linha, venha pela chamada ou pelo resultado. | `Row`, `useOpen()` |
| **Respostas em duas linhas**, raciocínio e aprovações numa; o clique mostra o texto todo. Os erros soltos ficam sempre por inteiro. | `StepItem` |

## PDFs com aspecto profissional (17.10.2026)

O Barrote, sobre o PDF da proposta à Olankha (credenciação, email 143): os PDFs gerados pelos agentes estavam «muito pobres». Capa quase vazia com o logótipo e o nome repetidos, rodapé em duas linhas por cima do número da página, DejaVu em vez da fonte da marca, valores centrados na tabela e subtotal/IVA/total como lista de pontos.

| Decisão | Onde |
|---|---|
| **A marca continua a vir de Definições › Marca** (cor, cor secundária, fonte, logótipo, rodapé, por empresa). O desenho da página fica no código; o agente escolhe só `documento`, `relatorio` ou `carta`. | `Brand`, `documents/pdf.blade.php` |
| **A fonte da marca vai embutida no PDF** quando os ficheiros estão em `resources/fonts` (`<Nome>-Regular.ttf` e `-Bold.ttf`); hoje a Space Grotesk (OFL). As outras fontes continuam em DejaVu da mesma família. | `Brand::pdfFontFiles()`, `resources/fonts/README.md` |
| **Cabeçalho e rodapé desenhados em cada página**: logótipo e título do documento em cima; linha do rodapé da marca (cortada com «…» para caber numa linha) e «Página x de y» em baixo. Não aparecem na capa do relatório nem na primeira página da carta. | `PdfRenderer::decorate()` |
| **Capa do relatório**: logótipo uma vez, nome da empresa, título, subtítulo e uma faixa na cor da marca com a data e o rodapé. | `pdf.blade.php` |
| **O conteúdo do agente ganha forma sem mudar uma palavra**: colunas só com valores alinhadas à direita (o alinhamento do markdown é ignorado), cabeçalho das tabelas na cor da marca; linhas «**Rótulo:** valor» (lista ou parágrafo) viram uma caixa rótulo/valor; quando todos os rótulos são subtotal/IVA/total/desconto, viram um bloco de totais à direita com a linha «Total…» na cor da marca; uma linha `---` antes de um título de secção sai. | `PdfContent::polish()` |
| **Documentos já gerados não mudam**: a nova versão da proposta à Olankha foi gerada à parte para comparar; o anexo do rascunho por enviar fica como está até o Barrote decidir trocar. | — |

Testes: `tests/Feature/Knowledge/DocumentGenerationTest.php` («lays out a proposal for PDF…», «embeds the brand font…»).
## Chief of Staff cria agentes e skills (17.10.2026)

O Barrote: na conversa, o Chief of Staff deve poder criar agentes novos e skills novas. Uma skill já existia (pacote de instruções ao estilo do Claude, `App\Models\Skill`, docs/CAPACIDADES.md); o que faltava era o Chief prepará-la, e o agente, a partir do que a pessoa diz.

| Decisão | Onde |
|---|---|
| **Pede-se na conversa com o Chief**. Ele vê o que há (áreas, agentes, capacidades e skills com as chaves) com `agents.catalog` e prepara um agente (nome, função, área, a quem responde, instruções, capacidades, skills, nível inicial) com `agents.propose`, ou uma skill (nome, quando se usa, instruções, que agentes a recebem ou todos) com `skills.propose`. Só o Chief tem as três. | `BuildingCatalog`, `ProposeAgent`, `ProposeSkill`, `CapabilityRegistry::CHIEF_OF_STAFF`, `InstructionComposer::chiefOfStaff()` |
| **Perguntar, nunca inventar**: uma proposta incompleta (falta a área, a quem responde, as instruções, que agentes recebem a skill) ou com nomes que não existem nem chega à aprovação; o Chief recebe o que falta e pergunta com `questions.ask`. Para isto as capacidades locais ganham `precheck()`, que o `GatedTool` corre antes do portão de autonomia. | `LocalCapability::precheck()`, `GatedTool::handle()`, `NewColleagues::agent()` / `skill()` |
| **Nada fica criado sem uma pessoa**: a proposta cai sempre no tecto absoluto e aparece na conversa como cartão («Agente novo para confirmar» / «Skill nova para confirmar») com «Criar agente/skill» e «Não criar…». Confirma quem a matriz deixa: criar agentes (`agents.manage`) ou gerir skills (`catalog.manage`), como o orçamento e a confiança, seja a quem for que o Chief responde. Não passa pela revalidação do Chief. | `Approval::DECIDED_BY_PERMISSION`, `ApprovalPolicy::decide()`, `Approval::decidableBy()`, `ApprovalService::reviewerFor()`, `TaskController::buildProposal()`, `BuildProposalCard.tsx` |
| **Ao confirmar** o agente é criado activo, com as capacidades de sempre (`ON_BY_DEFAULT`), em nome de quem confirmou, e aparece em Agentes e no Organigrama (pela área e a quem responde); a skill fica ligada e atribuída. A conversa recebe uma nota com a ligação. | `NewColleagues::createAgent()` / `createSkill()`, `AgentEditor::save()` |
| **Um agente novo começa baixo**: N1 por omissão, nunca acima de N1; a confiança sobe depois com o histórico (`agents.set_trust_level`). | `NewColleagues::MAX_START_LEVEL` |

Testes: `tests/Feature/Agents/ChiefBuildsTest.php`.

## Tipos de documento são skills (17.10.2026)

O Barrote: «o gerar documento deve obedecer um skill, eu posso querer criar um tipo de documento hoje, e outro amanhã. Sim sobre marca, sobre assets, até deveria pedir em run time se houver necessidade de adicionar assets.» Detalhes em `docs/CAPACIDADES.md`, «Tipos de documento».

| Decisão | Onde |
|---|---|
| **Um tipo de documento é uma skill**, não um modelo novo no código: as instruções dizem o conteúdo, umas linhas `chave: valor` entre `---` no topo dizem o modelo de página, o estilo (CSS de um ficheiro da skill, sem `@import` nem endereços), o logótipo obrigatório e as imagens obrigatórias. Sem o bloco, uma skill é só instruções, como antes. | `DocumentType::fromSkill()`, `config()` |
| **`documents.generate` recebe `skill`** e aplica-a por cima da Marca; o documento guarda `skill_id` (`2026_10_17_170001`) para a conversão de formato manter o tipo. | `GenerateDocument`, `DocumentController::convert()` |
| **As imagens vivem na Marca** (Imagens para documentos), por chave, além das imagens que uma skill traga: a assinatura e o carimbo são da empresa e servem a vários tipos. Uma imagem que não é `asset:` não entra no PDF (nada é buscado fora). | `BrandAssets`, `Brand::$assets`, `PdfContent::image()` |
| **O que falta pede-se em tempo de execução** com o cartão de perguntas, num tipo «ficheiro»: carregar a imagem, enviá-la na conversa, ou seguir sem ela (`without_assets`). O que se carrega fica na Marca. | `QuestionService::askForAssets()`, `fileAnswer()`, `QuestionCard.tsx` |
| **Word e PowerPoint** mostram o nome da imagem no lugar dela por agora; o PDF mostra-a. | `MarkdownBlocks::inline()` |

Testes: `tests/Feature/Knowledge/DocumentTypesTest.php`.

## Fechar uma tarefa (17.10.2026)

O Barrote quer poder fechar uma tarefa na página dela («Teste 2: cotação de software de gestão para loja de calçados» estava «À sua espera» sem forma visível de a terminar). O estado «Feita» já existia, mas só no menu «Estado» dos detalhes do painel lateral.

| Decisão | Onde |
|---|---|
| **Botão «Fechar tarefa»** no topo da página de uma tarefa (não nas conversas, que se reabrem sozinhas), para quem pode mudar a tarefa (`TaskPolicy::update`). Fecha como «Feita», com o mesmo efeito de a aceitar: avisa a tarefa-mãe, consolida a memória do agente e avança o fluxo. | `TaskController::close()`, `TaskThread::close()`, `Tasks/Show.tsx` |
| **Fica quem fechou e quando**: `closed_by_user_id` / `closed_by_agent_id` (`2026_10_17_190001`) e `completed_at`, em «Concluída» nos detalhes; vale para qualquer forma de fechar (botão, menu, aceitar, agente). Limpa-se ao reabrir. A linha temporal diz quem mudou o estado. | `TaskThread::setStatus()` |
| **Quando uma pessoa fecha, o trabalho do agente que esperava pára**: execuções em fila, à espera de resposta ou de aprovação ficam canceladas, com as perguntas canceladas e as aprovações expiradas, e uma nota na linha temporal. Uma execução já a correr acaba o turno; nada a volta a acordar. | `TaskThread::stopAgentWork()` |
| **Um agente não reabre** uma tarefa fechada por uma pessoa (`tasks.update_status` recusa). | `UpdateTaskStatus` |
| **«Reabrir» desfaz**: volta a «Em curso» (ou «Por fazer» sem ninguém atribuído) sem acordar o agente; escrever na tarefa continua a reabri-la e acorda-o. | `TaskController::reopen()`, `TaskThread::reopen()` |

Testes: `tests/Feature/Tasks/CloseTaskTest.php`.

## Importar skills (17.10.2026)

O Barrote quer carregar na plataforma as skills que já tem no Claude, no Codex e parecidos, e vê-o como um bom teste da plataforma. Detalhes em `docs/CAPACIDADES.md`, «Importar skills».

| Decisão | Onde |
|---|---|
| **Formatos do Claude e do Codex**: um `SKILL.md` com `name`/`description` no topo, ou a pasta em `.zip`/`.skill` com o `SKILL.md` e os ficheiros ao lado. Um `.zip` com várias pastas importa várias de uma vez. O topo lê-se como YAML simples (valores normais, entre aspas e `>`/`|`), sem dependência nova. | `SkillPackageReader` |
| **Nada da skill corre**: scripts (`.py`, `.sh`, `.js`…) ficam sempre de fora, e as instruções que os mandam correr ganham um aviso para serem revistas. Fontes e tipos que a plataforma não lê também ficam de fora. Caminhos que sobem pastas, ficheiros escondidos e `__MACOSX` são ignorados. | `SkillPackageReader::verdict()`, `normalise()` |
| **Ver antes de guardar**: o envio fica de lado, e a pessoa vê o que entra e o que fica de fora (e porquê) antes de criar. Uma chave que já existe ganha um número. | `SkillImports::stage()`, `Skills/Import.tsx` |
| **Sem agentes à partida**: a skill importada fica activa mas não atribuída (nem «Todos os agentes»); uma pessoa escolhe quem a usa. | `SkillImports::commit()` |
| **Escolher os agentes na página da skill**: o interruptor «Todos os agentes» e a lista dos agentes da empresa com caixas para marcar quem a usa, além da página de cada agente. Os marcados ficam guardados debaixo de «Todos os agentes», para quando se desliga. Quem gere skills pode fazê-lo; fica `skill.agents_updated` na auditoria. | `SkillController::updateAgents()`, `SkillEditor.tsx` |
| **Ficheiros guardam o caminho dentro da skill** (`assets/logo.png`), porque é assim que as instruções os referem; a chave de imagem de um tipo de documento usa só o nome (`logo`). As skills passam a aceitar CSS e imagens também no editor. | `SkillFiles::attach()`, `DocumentType::key()` |

Testes: `tests/Feature/Agents/SkillImportTest.php`. Experimentado com as 14 skills de `~/.claude/skills` e `~/.agents/skills` do Barrote (fora as do gstack).

## Contas sobre tabelas anexadas (17.10.2026)

O Barrote achou que o Agente de Finanças não usou os ficheiros na reconciliação BAZA de Setembro. Usou-os em parte: leu
todo o CSV dos cartões (259 linhas), mas o das recargas (11 407 linhas, 1,5 MB) só em janelas de 12 000 caracteres com
`files.read`, por isso nunca somou o M-Pesa (o PDF e o Excel diziam «Apurado na conta M-Pesa») e inventou o quadro por
assistente. A skill importada mandava correr um script Python que faz essas contas, e a plataforma não corre scripts.

| Decisão | Onde |
|---|---|
| **`files.analyze`**, de todos os agentes (como `files.read`): conta, filtra, agrupa (até 2 colunas, e/ou por dia) e soma um anexo CSV ou Excel da conversa, percorrendo o ficheiro todo. Sem argumentos, mostra as colunas e os valores que cada uma toma. Nada corre: o agente diz colunas, filtros e grupos. | `AnalyzeAttachment`, `CapabilityRegistry::ALWAYS_ON` |
| **Ler tabelas como as pessoas as escrevem**: separador e codificação do CSV detectados (`,` `;` tab, UTF-8 ou Windows-1252, `NULL` vazio); no Excel, a folha por nome ou número e o cabeçalho encontrado abaixo de títulos; números `1 000,50`, `1.000,50`, `1,000.50`, `(30)`; datas `30/09/2026 23:45`, `2026-09-30` e as do Excel. Nomes de colunas sem maiúsculas nem acentos. | `App\Attachments\TableReader` |
| **O agente é mandado para lá**: o anexo que é tabela diz «para contas usa files.analyze» no prompt, no aviso «continua» de `files.read` e na lista de anexos da conversa; ler por partes deixa de ser o caminho sugerido para tabelas. | `ModelInput::build()`, `ReadMessageAttachment`, `TaskThread` |
| **Skills feitas para correr scripts**: quando as instruções mostram comandos (`python …`, blocos `bash`, `scripts/…`), `skills.load` acrescenta que a plataforma não os corre, que as contas se fazem com `files.analyze` e os ficheiros com `documents.generate`, e que os números do script nunca se estimam. | `LoadSkill::RUNS_SCRIPTS` |

Testes: `tests/Feature/Tasks/TableAnalysisTest.php`. Com os ficheiros reais dá o mesmo que a soma à mão: 10 280 recargas
M-Pesa (592 500,00 MZN) e 1 127 POS (144 585,00 MZN) em Setembro.

## Correr código (17.10.2026)

> Substituído no mesmo dia por [«Correr código localmente»](#correr-código-localmente-17102026): o custo por chamada era demasiado.

O Barrote perguntou por um mini terminal em PHP para os agentes. Um terminal dentro da plataforma teria acesso à base de
dados de todas as empresas, às chaves e ao disco, e bastaria uma instrução escondida num email ou anexo; escolheu a
execução de código do próprio fornecedor de IA («faz o recomendado»).

| Decisão | Onde |
|---|---|
| **`code.run` («Correr código»)**: o agente diz o que calcular e que anexos da conversa usar; a plataforma faz uma chamada curta ao modelo com a execução de código do fornecedor (Python numa caixa isolada do Google, no Gemini). **Nada corre no nosso servidor.** Devolve a resposta, o código e o que ele imprimiu, para os números terem fonte (e passarem em «Perguntar, nunca inventar»). | `RunCode`, `App\Ai\Code\CodeRunner` |
| **Ligada por defeito** em todos os agentes (como a pesquisa na internet), e os administradores desligam-na por agente. Só lê: risco «Observar». | `CapabilityRegistry::ON_BY_DEFAULT` |
| **Custa tokens**: os ficheiros entram inteiros no modelo (o CSV de 1,5 MB das recargas BAZA gastou cerca de 1 milhão de tokens de entrada numa conta). Por isso há um limite por chamada (`AI_CODE_EXECUTION_MAX_MB`, 5 MB), cada chamada conta para o orçamento de IA (`ai_charges.purpose = code.run`) e fica `code.run` na auditoria. Para somar, contar ou agrupar uma tabela, `files.analyze` continua a ser o caminho, sem custo. | `config/agents.php` (`code_execution`), `BudgetGuard::charge()` |
| **Ficheiros como a caixa os aceita**: CSV e texto como estão, PDF como PDF, cada folha de um Excel como CSV, o resto pelo texto extraído. A caixa recebe a lista dos nomes pela ordem (`input_file_0`, …). | `CodeRunner::documents()` |
| **Skills com scripts**: o aviso de `skills.load` passa a indicar também `code.run` para o que `files.analyze` não faz. Os scripts importados continuam de fora: o agente escreve o código para o pedido. | `LoadSkill` |

Testes: `tests/Feature/Tasks/TableAnalysisTest.php`.

## Correr código localmente (17.10.2026)

O Barrote: «o custo é demasiado, devemos criar uma forma localmente». A execução no Gemini custava cerca de 1,58 USD por
chamada com o CSV das recargas BAZA, porque o ficheiro entrava inteiro no modelo. Agora o código corre neste servidor e
o modelo só vê o que o código imprime.

| Decisão | Onde |
|---|---|
| **`code.run` recebe o código, não um pedido**: o agente escreve o Python (vê antes as colunas com `files.analyze`), diz que anexos usar, e recebe o que o código imprimiu e os erros. Nenhuma chamada extra ao modelo: o custo é só o da vez do agente. A versão no Gemini (`CodeRunner`) saiu. | `RunCode` |
| **Caixa local fechada**: uma pasta nova por execução só com os anexos (pelo nome) e o `main.py`, apagada no fim; ambiente vazio (sem as variáveis do `.env`); sem rede; sem ler o projecto, o `.env`, a pasta pessoal nem escrever fora da pasta; sem correr outros programas. Limites de tempo (60 s), memória (1 GB), tamanho de ficheiro, saída (20 000 caracteres) e anexos (50 MB). Testado neste Mac: rede, `.env`, `/etc/passwd`, escrita em `/tmp` e `subprocess` ficam bloqueados. | `App\Ai\Code\Sandbox`, `config/agents.php` (`code_execution`) |
| **Duas formas de isolar**: no macOS, `sandbox-exec` com um perfil que nega tudo menos o Python e a pasta; em servidores, Docker sem rede, só de leitura, como `nobody`, com limites de memória, CPU e processos (imagem em `docker/sandbox`). Sem nenhuma das duas, `code.run` diz que não está instalada e o agente usa `files.analyze`. O caminho Docker ainda não foi experimentado (este Mac não tem Docker). | `Sandbox::driver()`, `AI_CODE_SANDBOX` |
| **`php artisan sandbox:install`** cria o venv em `storage/app/sandbox/venv` com pandas, numpy e openpyxl (só pacotes já compilados). | `InstallSandbox` |
| Cada execução fica na auditoria (`code.run`, com o código, os anexos e como terminou). Ficheiros que o código cria não se guardam: os ficheiros entregues fazem-se com `documents.generate`. | `RunCode::execute()` |

Testes: `tests/Feature/Tasks/TableAnalysisTest.php` (os que correm código precisam da caixa instalada; sem ela ficam saltados).

## Tema Apple Os (26.10.2026)

O Barrote pediu para trocar o tema "Claude +" (creme e laranja) pelo tema "Apple Os" do tweakcn:
`npx shadcn@latest add https://tweakcn.com/r/themes/cmtu0kiln000004l4dkbr3c0w`. Fundo cinza-azulado claro, azul como
cor principal, cantos de 0,625rem e a fonte Inter.

| Decisão | Onde |
|---|---|
| **Tokens do tema** aplicados pelo `shadcn add`; os nossos tokens de estado (`--status-*`) e o `.text-warning-strong` ficam como estavam. | `resources/css/app.css` |
| **Azul principal com contraste AA**: o azul do tema claro (`oklch(0.6225 …)`) dava 3,5:1 com texto branco; passou para `oklch(0.55 0.19 256)` (#006DDD, 4,7:1), também no anel de foco e na barra lateral. O vermelho de erro desceu para `oklch(0.577 …)` (4,6:1). O tema escuro fica como vem (texto escuro, 7:1 e 6:1). | `:root` em `app.css` |
| **Sombras corrigidas**: o tweakcn escreve `hsl(0, 0, 0 / 0.1)`, que o browser ignora; passou a `hsl(0 0% 0% / 0.1)`, e `--shadow-color` a `oklch(0 0 0)`. | `app.css` |
| **Fonte Jost** como principal (pedido do Barrote, no lugar da Inter que o tema traz), do `@fontsource-variable/jost`, para não depender de estar instalada no computador. A Outfit e a Inter saíram; os números e ids continuam em Geist Mono. | `package.json`, `app.css` |
| **Favicon e miniaturas em Definições › Aparência** nas cores novas. | `public/favicon.svg`, `Settings/Appearance.tsx` |
| **A Marca da empresa não muda**: as cores de Definições › Marca vão para os documentos e o logótipo para a entrada; não pintam a consola, por isso o tema e a Marca não se misturam. | `Brand`, `Settings/Brand.tsx` |

## Página de entrada com animação (26.10.2026)

O Barrote não gostou da entrada e pediu componentes em vez de imagens. Viu sete direcções em maqueta (A a G) e escolheu
a C (escura, formulário em vidro, consola ao lado), com duas mudanças: no lugar da consola, uma animação; e o fundo
com pequenos pontos. Em claro e em escuro.

| Decisão | Onde |
|---|---|
| **Sem imagens**: as ilustrações geradas (`public/images/auth/*.webp`, terracota do tema antigo) saíram. | — |
| **Formulário num cartão de vidro**, com o logótipo da empresa (Definições › Marca) dentro do cartão; a saudação muda com a hora de quem abre («Bom dia.», «Boa tarde.», «Boa noite.»). Recuperar acesso, nova palavra-passe e a entrada do super admin usam o mesmo layout. | `Layouts/AuthLayout.tsx`, `Pages/Auth/Login.tsx` |
| **Fundo de pontos** em toda a página (cor do texto a 20 %, esbatido nas margens) com dois brilhos suaves, azul e lilás. | `AuthLayout` › `Backdrop` |
| ~~**Animação dos agentes a trabalhar** ao lado do formulário (o Chief of Staff ao centro, pacotes de trabalho a correr pelas linhas).~~ Saiu no mesmo dia, a pedido do Barrote («remove os workflows do login, centraliza o form»): o formulário fica **ao centro da página**, com o título e a saudação por cima, sobre o fundo de pontos. | `Layouts/AuthLayout.tsx` |
| **«Esqueceu-se?» ao lado de «Manter sessão iniciada»**, em vez da caixa tracejada por baixo do botão; «Não tem conta?» e «Voltar a entrar» por baixo do cartão (`note`). | `SignInForm`, `AuthLayout` |
| Só tokens do tema (`primary`, `card`, `foreground`, `chart-5`…), por isso o claro e o escuro saem do mesmo código. | — |

## Tema Google (26.10.2026)

O Barrote pediu o tema "Google" do tweakcn no lugar do "Apple Os":
`npx shadcn@latest add https://tweakcn.com/r/themes/cmsoje5dh000104ju3xhz0ahj`. Fundo branco, azul Google como cor
principal, cinzentos neutros no escuro. Substitui as cores da secção «Tema Apple Os»; o resto dessa secção (sombras,
favicon, Marca da empresa) continua a valer.

| Decisão | Onde |
|---|---|
| **Fontes ficam a Jost e a Geist Mono**: o tema traz as fontes do sistema, mas a Jost foi pedida pelo Barrote como principal. | `app.css` |
| **Contraste AA no claro**: o azul principal desceu de L 0,5737 (4,5:1 com texto branco, no limite) para `oklch(0.56 …)` (#146FE3, 4,8:1), também no anel de foco e na barra lateral; o azul do texto numa linha seleccionada (`accent-foreground`) para `oklch(0.52 …)` (4,9:1 em vez de 3,9:1). | `:root` em `app.css` |
| **Bordas dos campos**: o `--input` do tema é quase branco sobre branco (1,1:1); passou à cor das bordas. | `:root` em `app.css` |
| **Contraste AA no escuro**: o texto branco sobre o azul dava 3,6:1; o azul ficou um pouco mais claro (`oklch(0.66 …)`) com texto escuro (5,1:1), e lê-se como texto num cartão (4,5:1). O azul do texto numa linha seleccionada subiu para `oklch(0.75 …)` (5,1:1). | `.dark` em `app.css` |
| **Brilhos da entrada só em azul**: o segundo brilho usava `chart-5`, que no escuro deste tema é branco. | `AuthLayout` › `Backdrop` |
| Favicon e miniaturas de Definições › Aparência nas cores novas. | `public/favicon.svg`, `Settings/Appearance.tsx` |


## Preparação da demo (26.10.2026)

Para a demo de 27.10 no tenant micomoc (MCP, triagem, passagem ao Gestor de Clientes e ao Agente de Procurement, Chief
of Staff a sugerir fluxos). Guião em `docs/demo/GUIAO.md`.

| Decisão | Onde |
|---|---|
| **Repetir quando o provedor falha**: um pedido aos provedores de IA (Gemini, Anthropic, OpenAI) que não teve resposta (ligação caída, 408, 429, 5xx) é enviado outra vez, até 3 vezes, com espera de 1,5 s a dobrar (ou o `Retry-After` do provedor, até 20 s). Só se repete o que não teve resposta, por isso nenhuma ferramenta corre duas vezes. Nos logs: 6 execuções falharam com «Could not connect to AI provider [gemini]» entre 16 e 20.10. | `ProviderRetry` (middleware global do `Http`), `agents.provider_retry`, `AI_PROVIDER_RETRIES` |
| **Sem agente da área, o Chief of Staff**: um tipo de email cujo agente não existe (ex.: uma empresa sem Gestor de Clientes) vai ao Chief of Staff em vez de ficar parado na triagem. | `EmailRouter::route()` |
| **Passagens que davam erro**: quando o colega respondia depois de dar tarefas (sem passar pedidos), o registo do que fez juntava textos a uma colecção Eloquent vazia («getKey() on string») e a conversa de origem falhava; 8 execuções da Rethink falharam assim desde 19.10. | `Handoffs::passedOn()` |
| **Chief of Staff sugere fluxos**: `workflows.overview` mostra os tipos de email dos últimos dias (quantos, quem trata, se têm fluxo) e os fluxos existentes; `workflows.propose` desenha o fluxo com o mesmo rascunho de «Descreva o fluxo» (`WorkflowDrafting`) e guarda-o **como rascunho**, com a ligação para o editor e o porquê na descrição. Não precisa de aprovação porque um rascunho não faz nada: só uma pessoa que gere agentes o activa no editor. Não sugere um segundo fluxo para um tipo que já tem um activo. | `WorkflowsOverview`, `ProposeWorkflow`, `CapabilityRegistry::CHIEF_OF_STAFF`, `InstructionComposer` |

## Gestor de ficheiros (27.10.2026)

Pedido do Barrote, em Ficheiros (`/documents`): «deve ser mesmo um gestor de documentos, com vista em grelha e lista, ordenação, pesquisa, pastas; documento gerado por IA, mostrar a IA; se foi gerado por humano, mostrar também». Fica na mesma página, para os ficheiros gerados (pelos agentes ou pelas pessoas); o repositório da empresa continua no Conhecimento, onde estão as regras de acesso por domínio.

| Decisão | Onde |
|---|---|
| **Pastas** de um só nível, da empresa, numa coluna à esquerda com quantos ficheiros cada uma tem (dos que a pessoa vê), mais «Todos» e «Sem pasta». Criar, mudar o nome e apagar pede «Criar documentos»; apagar uma pasta não apaga os ficheiros, que voltam para «Sem pasta». Quem vê um ficheiro continua a ser decidido pelo ficheiro, não pela pasta. | `document_folders`, `generated_documents.document_folder_id`, `DocumentController::storeFolder()` |
| **Mover**: arrastar um ficheiro para uma pasta da coluna, ou «Mover para» no menu do ficheiro (com «Nova pasta…» e «Tirar da pasta»). | `DocumentController::move()`, `Pages/Documents/Index.tsx` |
| **Grelha ou lista**, escolha guardada no navegador. **Pesquisa** pelo título e pelo nome do ficheiro enquanto se escreve; **filtros** por tipo e por quem fez (Todos, IA, Pessoas); **ordem**: mais recentes, mais antigos, nome, tamanho, tipo. Tudo no endereço (`q`, `format`, `autor`, `pasta`, `ordem`). | `DocumentController::index()` |
| **Quem fez**: um agente aparece com o seu avatar, o nome e a marca «IA»; uma pessoa com as iniciais, o nome e «Pessoa». | `DocumentAuthor` |

Testes: `tests/Feature/Knowledge/DocumentManagerTest.php`.

## Agente contínuo: o trabalho de fundo na conversa (27.10.2026)

O Barrote, na conversa com o Agente de Finanças, viu o cartão «A fazer agora, fora desta conversa» e o botão «Ver execução» levou-o à página de Execuções: «a ideia é termos agentes persistentes, nesse caso a experiência não fica boa». Escolheu as três partes da proposta: a conversa é a linha do tempo única do agente e Execuções fica para auditoria e custos.

| Decisão | Onde |
|---|---|
| **No cabeçalho**, em vez de «a trabalhar noutro sítio»: «A tratar: Cobrança de facturas em atraso · a usar Pesquisar emails», ao vivo; carregar abre o passo a passo no painel lateral. O cartão «A fazer agora, fora desta conversa» sai da conversa (fica na página do agente). | `Pages/Tasks/Show.tsx` |
| **No fio da conversa**, cada trabalho do agente noutro sítio desde que a conversa começou (no máximo uma semana, os últimos 30) entra onde começou, como uma linha recolhível: «Comecei: …» com o passo em que vai, ao vivo, e «Terminei / Não terminei: …» quando acaba; ao abrir, quem pediu, a origem, a resposta, «Acompanhar ao vivo» / «Ver passo a passo» (no painel lateral) e «Abrir a tarefa». Não é gravado como mensagem: o agente não o recebe como conversa. As outras conversas do agente não entram (são de quem está nelas, mesmo que um admin as possa ler); uma tarefa que a pessoa não pode abrir aparece sem título. | `AgentNow::background()`, prop `agent_background`, `BackgroundRow`, `ChatThread` |
| **O painel lateral abre também execuções do agente fora da conversa**, se a pessoa pode ver onde correram; antes só abria as da própria conversa. | `TaskController::panelRun()` |
| **Presença**: onde aparece a cara do agente (barra lateral, cabeçalho, chat, organigrama), um anel que respira enquanto ele trabalha, com «… está a trabalhar» ao passar o rato. Sem animação para quem pede menos movimento. | `AgentAvatar`, `.agent-working` |

Testes: `tests/Feature/Agents/AgentConsoleTest.php` («puts the agent's work elsewhere in its conversation…»).

## Citar na conversa (27.10.2026)

Pedido do Barrote: «seleccionar um texto e fazer quote no chat, com a possibilidade de adicionar um comentário naquele ponto».

| Decisão | Onde |
|---|---|
| **Seleccionar texto numa mensagem** (do agente ou de uma pessoa) mostra «Citar» por cima da selecção; «Citar» abre ali mesmo uma caixa para o comentário (Enter guarda, Esc cancela). As citações guardadas ficam à parte («3 citações guardadas · 2 com comentário») até a pessoa carregar em «Anexar à mensagem»; aí vão para cima da caixa de escrita, onde o comentário ainda se muda e cada uma se tira. Pedido do Barrote: comentar no ponto, e só depois anexar tudo. | `Components/tasks/Quotes.tsx` (`QuoteSelection`, `QuoteTray`), `data-quote-author` em `Chat.tsx` |
| **Chegam ao agente como markdown**, no início da mensagem: cada citação como bloco citado com o autor, o comentário por baixo, e depois o texto da mensagem. Na conversa aparecem como citações. Não muda nada no servidor. | `withQuotes()` |

## Início: os agentes a trabalhar (27.10.2026)

Pedido do Barrote: o Início «deve mostrar de uma forma intuitiva os agentes que estão a correr e se já têm trabalho concluído».

| Decisão | Onde |
|---|---|
| **Por baixo da caixa de escrita, as caras dos agentes** com quem a pessoa fala, com o anel de quem está a trabalhar e o nome curto (a cor de «a trabalhar» quando é o caso); carregar abre a conversa com ele. | `HomeAgents` |
| **«A trabalhar agora»**: «Agente de Finanças está a tratar de Cobrança de facturas em atraso», com o passo e quando começou, ao vivo. **«Concluído nas últimas 24 horas»**: até 8, «tratou de …» ou «não terminou …», quando, «Ver o resultado». Sem caixas, só linhas. As conversas não entram no concluído (a resposta está na conversa) e as de outra pessoa não entram de todo. | `AgentNow::overview()`, prop `agents_now` em `HomeController` |
| **Cada linha abre o trabalho na conversa da pessoa com esse agente**, com o passo a passo no painel lateral (ou na tarefa, se for de uma tarefa); nunca na página de Execuções, a menos que a pessoa ainda não tenha conversa com o agente. | `HomeAgents` (`href`) |

## Enviar a partir da conversa (27.10.2026)

Pedido do Barrote (tarefa 64 da Rethink): ao carregar em enviar no email da conversa, «deve mostrar uma animação e o estado mesmo no chat, não precisa sair».

| Decisão | Onde |
|---|---|
| **O cartão de email tem «Enviar»** (antes «Rever e enviar», que levava ao Email): pede confirmação (para quem, cópia, assunto, de que caixa; não se desfaz) e envia dali, pelo mesmo `inbox/{email}/send`, com `stay`, que volta à conversa em vez de ir para a caixa. Sem endereço, o botão fica desligado e diz para usar Editar. Continua a ser uma pessoa a enviar; o agente nunca envia. | `EmailCard` (`send`), `InboxController::send()` |
| **Enquanto vai**: uma barra corre no topo do cartão, o avião do botão levanta e o cabeçalho diz «A enviar…». **Enviado**: «Enviado às 10:42» aparece com uma pequena animação. **Falhou**: «Não foi enviado» no cabeçalho, o motivo no rodapé e o botão passa a «Tentar de novo». Sem animação para quem pede menos movimento. | `.email-sending`, `.email-plane`, `.email-sent` |

## Confirmação automática de recepção (27.10.2026)

Pedido do Barrote: o email da empresa responde primeiro a dizer que recebemos e que estamos a tratar do assunto. É a primeira coisa que sai sem uma pessoa carregar em enviar, por isso é estreita de propósito.

| Decisão | Onde |
|---|---|
| **Por caixa, desligada por omissão**: em Definições › Caixas de email, «Confirmar a recepção automaticamente», os tipos de email (por omissão pedido de cotação, pedido de cliente, lead e concurso) e o texto, com `{nome}`, `{assunto}` e `{empresa}`. Quem gere a caixa liga e desliga; fica na auditoria. | `mailboxes.auto_ack*`, `MailboxController`, `Pages/Mailboxes/Index.tsx` |
| **Texto fixo, nunca escrito por um modelo**: só diz que chegou e está a ser tratado; preços, prazos e propostas continuam a ir para uma pessoa rever. Sai em resposta ao email (na mesma conversa do cliente), da caixa da empresa, assinado com o nome da empresa. | `Acknowledgement::render()` |
| **Quando sai**: depois de a triagem classificar o email, numa fila à parte (uma falha do servidor de email não pára a triagem). Não sai: a endereços automáticos (no-reply, notifications, mailer-daemon…), a pessoas da empresa, a outros tipos de email, a email com mais de dois dias (por exemplo ao reler a caixa), duas vezes na mesma conversa, nem mais de uma vez por dia ao mesmo remetente. | `Acknowledgement::skipReason()`, `AcknowledgeEmail`, `ClassifyEmail` |
| **De uma caixa de pessoas partilhada (como geral@)** só sai isto, e só com a opção ligada; tudo o resto continua a precisar de um dono da caixa. | `MailboxMailer::send(acknowledgement:)` |

Testes: `tests/Feature/Email/AcknowledgementTest.php`.


## Ficheiros: selecção múltipla e acções em massa (27.10.2026)

- Em Ficheiros (`/documents`), grelha e lista têm uma caixa por ficheiro; a caixa do cabeçalho escolhe todos os da página (Ctrl/Cmd+A faz o mesmo), Shift+clique escolhe o intervalo e Esc larga a selecção. Enquanto há ficheiros escolhidos, um clique num ficheiro junta-o ou tira-o em vez de o abrir.
- Com ficheiros escolhidos, a linha do título passa a barra de acções: Mover para (pastas, Tirar da pasta), Descarregar (um ZIP com nomes repetidos numerados), Arquivar no conhecimento (domínio e pasta, os já arquivados ficam como estão) e Apagar (com confirmação). Arrastar um ficheiro escolhido para uma pasta leva todos os escolhidos.
- Rotas `documents/bulk/*`: cada acção só toca os ficheiros que a pessoa vê; apagar só apaga os dela (um gestor, todos) e a mensagem diz quantos ficaram. Os domínios do conhecimento só se carregam quando se abre «Arquivar» (`knowledge`, prop opcional).

## Definições: menu lateral que colapsa (27.10.2026)

- Em ecrã grande, o menu das Definições tem no topo um botão para colapsar e expandir. Colapsado, fica uma coluna de ícones (o nome aparece ao passar o rato) e a página ganha a largura. A escolha fica no browser (`settings.nav.collapsed`).

## Documentos: relatórios e ficheiros juntos (27.10.2026)

- O menu tinha «Documentos» (os relatórios em `/reports`) e «Ficheiros» (`/documents`). Passa a haver só **Documentos**, em `/documents`: os relatórios que os agentes preparam para rever entram na mesma lista que os ficheiros, com ícone próprio, a etiqueta «Relatório» e «Por rever» enquanto estão em rascunho.
- O filtro de tipo ganha «Relatório» (`format=relatorio`); a coluna das pastas ganha a vista **Por rever** (`pasta=rever`) com o número de relatórios à espera. «Pessoas» mostra só ficheiros, porque os relatórios são sempre de um agente.
- A lista é uma união das duas tabelas (`generated_documents` e `reports`), paginada em conjunto e ordenada pelos mesmos critérios; o tamanho de um relatório é o do texto. Não se converteu nem se apagou nada.
- Os relatórios vão para pastas (`reports.document_folder_id`) e entram nas acções em massa: mover, descarregar (no ZIP vão como `.md`), arquivar no conhecimento (como documento de texto, uma vez) e apagar (só gestores).
- Exportar um relatório guarda a ligação no ficheiro (`generated_documents.report_id`) e põe-no na pasta do relatório; a página do relatório lista os ficheiros exportados e a do ficheiro diz de que relatório veio.
- `/reports` redirecciona para `/documents?format=relatorio`; `/reports/{id}` continua a abrir o relatório (as notificações e os links dos agentes não mudam), com o menu em Documentos.

## Agenda: vista do mês legível (27.10.2026)

- As rotinas que correm todos os dias deixam de ocupar as linhas de cada dia. Ficam numa linha discreta no fundo do dia («↻ 3 rotinas»), que abre a lista. Com o filtro «Rotina», aparecem como antes.
- Os itens do mesmo tipo com o mesmo início de título (antes de « - », « — » ou « : ») ficam numa só linha com o número («Seguimento de cobrança · 12»), que abre a lista com a hora, o título e quem trata. O que está em atraso vem primeiro.
- Os títulos ocupam até duas linhas. O atraso mostra-se com o ponto e a hora a vermelho, em vez de um contorno. O número de hoje fica sempre legível dentro do círculo, e os fins de semana têm um fundo ligeiro.
- Mostram-se até três linhas por dia; o resto fica em «+N mais», que abre a lista desse dia em vez de mudar para a semana.

## Agenda: semana em grelha de horas (27.10.2026)

- A semana passa a ser uma grelha como a de um calendário: os dias em colunas, as horas à esquerda (das 8h às 18h, alargando se houver algo fora disso) e uma linha «Dia todo» no topo. Deixa de haver uma caixa por dia e um botão «Lembrete» em cada coluna; o «+» aparece ao passar pelo dia.
- Cada hora de cada dia lista os seus itens, agrupados como no mês (até duas linhas e «+N mais»). Uma hora cheia torna a linha mais alta em vez de tapar a seguinte, o que corrige o item de dia todo que passava para o dia ao lado.
- As rotinas ficam na linha «Dia todo» como «↻ 3 rotinas» (com o filtro «Rotina» vão para as suas horas). No telefone, a semana é uma lista de dias.

## Tarefas: gestor de trabalho (27.10.2026)

Pedido do Barrote: as tarefas passam a ser um gestor de trabalho, não só a fila do que os agentes fazem, e cada tarefa pode ser feita com agentes e com colegas. Esta é a parte 1. A repetição de tarefas e os resumos do Chief of Staff (do dia e da semana) ficam para a parte 2.

### Tarefas: criação rápida

- Em Tarefas há uma linha «Nova tarefa: «Ligar à Mozal sexta» e Enter». O dia é lido da frase (`App\Tasks\QuickDate`): hoje, amanhã, depois de amanhã, os dias da semana (o próximo), «dia 5» e 5/11. O prazo fica às 18h desse dia e o resto da frase é o título.
- Qualquer pessoa pode criar as suas próprias tarefas, sem responsável ou atribuídas a si. Dar uma tarefa a outra pessoa, ou a um agente a partir daqui, continua reservado às chefias (realinhamento L5). Os agentes entram nas tarefas de cada pessoa através do «Com quem?».

### Tarefas: colaborar com agentes e colegas

- Numa tarefa, o botão «Com quem?» convida um agente ou um colega com uma função: Preparar, Rascunhar, Acompanhar, Fazer ou Rever (`App\Enums\CollaboratorRole`). Pode juntar-se uma nota.
- A parte de cada um é uma subtarefa da tarefa (`task_collaborators.subtask_id`), com o título «Preparar: …» e, no topo, o que a função pede. Um agente começa logo e um colega é avisado. Quando a subtarefa fica feita, reporta na conversa da tarefa principal (`TaskThread::reportToParent`). As funções dos agentes dizem-lhes que nada sai em nome de ninguém sem aprovação.
- Um colega convidado passa a ver a tarefa principal (na política e no `visibleTo`), e ela entra nas «Minhas» dele. Retirar alguém cancela a subtarefa que ainda estiver aberta.
- No cabeçalho da tarefa aparecem as caras de quem colabora, e o diálogo mostra a função e o estado da parte de cada um (Convidado, A trabalhar, Pronto para rever, Feito).

### Tarefas: quadro e planear

- Em Tarefas, o botão «Lista · Quadro · Planear» muda a vista (`modo` no endereço) e mantém os filtros e a vista do lado (Minhas, Todas…).
- **Quadro:** colunas Por fazer, Em curso, Por rever e Feito (feito na última semana). Arrastar um cartão muda o estado.
- **Planear:** os sete dias da semana (`semana`, de segunda a domingo, com navegação), a coluna «Sem data» e, na semana actual, «Em atraso» com o que passou do prazo. Arrastar um cartão dá-lhe esse dia (ou tira-lhe a data), e o «+» de cada coluna cria uma tarefa já com esse dia.
- Os cartões mostram a prioridade, o prazo (a vermelho se passou), «a trabalhar», as caras dos colaboradores (com um ponto quando a parte está pronta ou feita) e o responsável. São no máximo 300 cartões.

## Agentes: configuração sem caixas (27.10.2026)

- As páginas de definição (agente, skill e agente do super admin) deixam de ter secções em caixas. Cada secção é separada da seguinte por espaço e uma linha fina. Em ecrãs muito largos, o título e a explicação ficam à esquerda e os campos à direita, como numa página de definições.
- As capacidades aparecem agrupadas por área (Trabalho e equipa, Email e comunicação, Conhecimento, Finanças, Clientes e vendas, Compras, Pessoas, ERP, Conectores). Cada grupo mostra quantas estão escolhidas e tem «Escolher todas» / «Tirar todas». Os grupos sem nenhuma escolhida começam fechados. Há procura (também pela descrição) e «Só as escolhidas».
- O nível de autonomia escolhe-se numa lista com os cinco níveis visíveis, em vez de um select.

## Agentes: escolher o modelo (27.10.2026)

- Na configuração do agente, o modelo escolhe-se de uma lista (`config('agents.models')`) e deixa de se escrever. A primeira opção é «Por omissão da plataforma» e as seguintes são os modelos cujo provedor tem chave, cada um com uma frase e o preço por milhão de tokens (de `agents.pricing`). Hoje são o Gemini 3.6 Flash e o Gemini 3.1 Flash-Lite.
- A validação só aceita modelos da lista, ou o modelo que o agente já tinha, para um agente antigo continuar a gravar. A página do agente mostra o modelo pelo nome.

## Sugestões geradas todos os dias (27.10.2026)

- As sugestões do ecrã de entrada de cada agente deixam de se escrever na configuração (a secção «Sugestões» saiu). Todos os dias às 6h (Maputo), `agents:suggest` pede ao modelo barato (`agents.suggestions_model`, Gemini 3.1 Flash-Lite) quatro pedidos curtos para cada agente activo.
- O modelo escreve a partir da função e das instruções do agente, das capacidades ligadas, das tarefas e dos pedidos das pessoas dos últimos 7 dias, da memória do agente e das sugestões da véspera, para variar. Só pode usar nomes e factos que estejam nesse contexto.
- As sugestões ficam no agente (`settings.daily_suggestions`) e valem até 3 dias. Sem elas, aparecem as que as capacidades sugerem. Cada geração é registada no orçamento de IA (`agents.suggestions`) e não corre sem chave nem sem orçamento.

## Agentes: configuração em separadores (27.10.2026)

- A configuração do agente passa a mostrar uma secção de cada vez, com separadores: Identidade, Personalidade e instruções, Autonomia, Modelo, Capacidades e Skills. O separador Modelo só tem a escolha do modelo; temperatura, máximo de tokens e de passos saíram do ecrã e ficam com os valores por omissão (os que já estavam gravados mantêm-se). Num ecrã largo ficam na coluna da esquerda; no telefone e no tablet, numa linha por cima. O endereço guarda o separador (`#modelo` abre «Modelo») e a barra «Guardar agente» continua fixa em baixo, para gravar tudo de uma vez.
- Depois de gravar, um separador com erros fica com um ponto vermelho, e o primeiro desses separadores abre sozinho.

## Emails sem número nas respostas dos agentes (27.10.2026)

- O Barrote viu «A triagem do email #354 foi efetuada com sucesso». A regra «Emails por quem escreveu» só se aplicava às respostas nas conversas. Passa a aplicar-se também à resposta final de qualquer execução (`AgentRunner`, junto com a remoção das chaves internas dos agentes) e, para as execuções antigas, quando a execução é mostrada (`Present::run`).
- Os prompts de email (triagem, caixa de uma pessoa, reenvio para triagem e «Voltar a triar») deixam de dizer «Email #354». Dizem «É o email de Celeste Nhantumbo com o assunto «…» (email_id 354, só para as ferramentas…)», para o modelo não repetir o número.
- O painel lateral das execuções deixa de mostrar o número da execução nas dicas.

## Agentes: escolher o provedor (27.10.2026)

- A lista de modelos (`config('agents.models')`) passa a incluir vários provedores: Google Gemini, Anthropic (API directa), OpenAI, OpenRouter, DeepSeek, xAI e Mistral (`config('agents.providers')`). Os ids dos modelos são os do laravel/ai.
- No separador Modelo escolhe-se primeiro o provedor (Por omissão da plataforma, ou um provedor com chave; os sem chave aparecem desligados, com o caminho para Definições › Provedores de IA) e depois o modelo desse provedor. Os outros aparecem em «Outros provedores, por ligar», com o nome da linha do .env que falta. As chaves são escritas pelo dono no servidor e nunca na aplicação.
- Ao gravar, o provedor do agente fica sempre o do modelo escolhido. Um modelo sem preço em `agents.pricing` mostra «preço por definir» e é cobrado ao preço de segurança (`fallback_pricing`), até alguém acrescentar o preço.

## Selects com pesquisa (27.10.2026)

- Todas as escolhas de uma lista na plataforma passam a abrir uma lista com um campo de procura no topo (`Components/ui/search-list.tsx`). A procura ignora acentos e maiúsculas e encontra também pelo nome do grupo («Agentes», «Colegas»).
- A troca foi feita nos dois componentes partilhados, com a mesma API: `NativeSelect` (com value, onChange e `<option>`/`<optgroup>`) e `Select` (a API do shadcn). As páginas que os usam não mudaram. O `NativeSelect` com `name` leva um campo escondido para os formulários normais.
- Funciona dentro de diálogos, porque a lista abre como popover modal.

## Chaves dos provedores de IA na plataforma (27.10.2026)

- Pedido do Barrote: guardar as chaves dos provedores de IA na plataforma, sem ir ao .env. Fica em Definições › Provedores de IA (`/settings/ai-providers`, só proprietários e administradores, com a permissão «Administrar a empresa»). Cada provedor da lista (`agents.providers`) mostra o estado: chave da empresa (••••últimos 4 caracteres, quem a guardou e quando), ligado pelo .env, ou sem chave. Tem «Colar chave», «Substituir chave» e «Retirar».
- As chaves ficam em `ai_provider_keys`, uma por empresa e provedor, cifradas com a APP_KEY (cast `encrypted`). Nunca são enviadas para uma página nem escritas no registo de auditoria, que regista só quem a pôs ou retirou e de que provedor.
- `App\Ai\ProviderKeys::apply()` corre sempre que muda a empresa corrente (`TenantManager::set`, `run` e `forget`), ou seja, em cada pedido, job e comando por empresa. Põe as chaves da empresa na configuração e devolve ao valor do .env as que foram postas para a empresa anterior. Um provedor cuja chave mudou é construído de novo no uso seguinte. Sem chave da empresa, vale a do .env.

## Catálogo de modelos e preços actualizados (27.10.2026)

- Pedido do Barrote: Haiku 5.5 no catálogo e os preços de todos os modelos actualizados. Os preços (dólares por milhão de tokens, entrada / saída) foram conferidos a 27.10.2026 nas páginas oficiais de cada provedor e estão em `agents.pricing`, que alimenta o orçamento (`BudgetGuard`) e o preço mostrado ao escolher o modelo.
- Anthropic passa à família 5.5: Opus 5.5 (4 / 20), Sonnet 5.5 (2 / 10) e Haiku 5.5 (0,10 / 0,50 até 100 mil tokens de pedido; acima disso 0,50 / 2,50, que o orçamento não distingue). A OpenRouter passa os mesmos preços. O Haiku 4.5 sai da lista, mas mantém o preço (1 / 5) para o agente que já o usa.
- Gemini 3.6 Flash e Gemini 4 Argon contam pelo preço que vigora depois da promoção actual (1,50 / 7,50 a partir de 1.1.2027; 4 / 20 a partir de 1.12.2026): o orçamento erra para o lado seguro.
- Ids corrigidos: `deepseek-flash` (o `deepseek-v4-flash` foi retirado) e `grok-4.20-0309-non-reasoning`.
- Sem preço oficial publicado: Mistral Small 4 fica «preço por definir» (o orçamento usa o preço de reserva). DeepSeek usa os preços de tabela citados por terceiros, sem a promoção do V4 Pro, até a página oficial os mostrar.

## Foto de perfil (27.10.2026)

- Pedido do Barrote: cada pessoa pode pôr a sua foto. Fica em A minha conta › Perfil, no bloco «Foto»: «Carregar foto» (ou «Mudar foto») abre o ficheiro e uma janela para o enquadrar num círculo (arrastar para mover, barra ou roda do rato para aproximar); «Retirar foto» volta às iniciais. Só a própria pessoa muda a sua foto.
- O browser envia o quadrado enquadrado com 512 px; o servidor corta-o de novo ao quadrado e grava-o em JPEG de 512 px (`App\Support\UserAvatars`), o que também tira os metadados da câmara (local, aparelho). Aceita PNG, JPG e WebP até 8 MB. O ficheiro fica no disco privado (`users.avatar_path`) e é servido por `/people/{id}/avatar` só a quem é da mesma empresa.
- A foto aparece onde estavam as iniciais da pessoa: barra lateral, conversas das tarefas, quadro e lista de tarefas, colaboradores, percurso, organograma, pessoas, objectivos, projectos, conversas de um agente e autor dos documentos. O componente `PersonAvatar` procura-a pelo nome em `people_photos` (partilhado com todas as páginas, só as pessoas com foto), como o `AgentAvatar` faz com os agentes; sem foto, ficam as iniciais. Os remetentes de email continuam com iniciais.

## O Chief of Staff chega a todos os agentes (27.10.2026)

- Visto na Rethink: o Chief of Staff não conseguiu avisar o Agente de Finanças de que o relatório BAZA foi descartado, porque as Finanças respondem a uma pessoa (Naira) e não a ele. O organigrama travou-o com «não está abaixo de ti», que nem faz sentido para o Chief of Staff.
- `OrgChart::canDelegate()` deixa o Chief of Staff (`OrgChart::isChiefOfStaff()`, pela chave ou pelo modelo `chief_of_staff`) passar pedidos (`agents.message`) e dar tarefas (`tasks.create`) a qualquer agente, esteja onde estiver no organigrama. É por ele que passa o trabalho entre áreas (decisão 12 do realinhamento). Os outros agentes seguem as regras de sempre. As instruções do Chief of Staff dizem-lho.
- Regra nova para todos os agentes: dizer só o que ficou feito. Se uma ferramenta falhou ou uma parte ficou por fazer, a resposta não começa por «Feito» ou «Descartado»: começa pelo que fez de facto e diz logo o que ficou por fazer, porquê e quem o pode fazer. Registar uma decisão na memória não é executá-la. Na execução vista, o agente respondeu «Descartado.» quando só tinha registado a decisão.
- Painel da execução: o nome de uma ferramenta longo encolhe com reticências em vez de passar por cima da hora; a duração, o custo e os tokens, o «Pedido» e a «Resposta final» ficam sem caixas nem bordas.

## Modelo de cada execução (27.10.2026)

- Pedido do Barrote: ver em cada execução o modelo usado. Na página do agente, a lista de execuções mostra o modelo a seguir à origem (por exemplo «Manual · Gemini 3.6 Flash»). O painel da execução mostra «Modelo: …» por baixo do título, e a página inteira mostra-o com o nome do provedor. Ao passar o rato aparece o provedor e o id exacto.
- O nome vem do catálogo (`agents.models`), por `Present::modelLabels()`. Um modelo que já saiu do catálogo aparece pelo id, para nunca esconder o que correu de facto.

## Regras de aprovação (27.10.2026)

Ideia vinda do OpenDuet. O Barrote aprovou todos os pontos da proposta.

- **Os botões:** cada aprovação tem três botões: «Permitir uma vez», «Permitir sempre» e «Recusar». «Permitir sempre» aprova e deixa uma regra para que aquele agente faça aquela acção sem perguntar. Só o vê quem configura agentes (permissão `agents.manage`). Não aparece no tecto absoluto (pagamentos, emails para fora, contratos) nem nas acções que uma permissão decide (orçamento, confiança, agentes e skills novos).
- **As regras:** ficam em `approval_rules`, por empresa, capacidade e agente (sem agente quer dizer todos os agentes). Há três efeitos: «Permitir sempre», «Perguntar sempre» e «Recusar sempre». Estão por cima dos níveis N0 a N4, sem os substituir.
- **A ordem no porteiro (`AutonomyGate`):**
  - Primeiro a regra que recusa. O agente recebe «Recusado» e ninguém é chamado.
  - Depois o tecto absoluto, que nenhuma regra levanta.
  - Depois «Permitir sempre» e «Perguntar sempre».
  - Sem regra, vale o nível como antes.
  - A regra do agente vale antes da regra para todos.
- **Que regra pediu:** cada aprovação guarda a regra que a pediu (`rule_id`, `rule_label`), e o cartão diz «Pedida pela regra …». A auditoria regista a regra que decidiu (`rule_id`) nas acções executadas, recusadas e enviadas para aprovação. Também regista quem criou ou retirou cada regra.
- **A página:** em Definições › Regras de aprovação vêem-se as regras, muda-se o efeito, retiram-se e criam-se à mão.
- **Os nomes:** «Aprovar» e «Rejeitar» passam a «Permitir uma vez» e «Recusar» em todos os sítios onde se decide.

## Resumo da manhã (27.10.2026)

Ideia vinda do OpenDuet.

- **Onde e quando:** na primeira vez que a pessoa abre o Início em cada dia, o Chief of Staff já lá deixou uma mensagem por cima da caixa de escrita. Fica até ela a fechar. Fechar guarda o dia em `users.morning_summary_seen_on`, por isso não volta noutro aparelho. No dia seguinte há outro.
- **O que diz:**
  - Precisa de si: as aprovações à espera, as perguntas dos agentes, as tarefas paradas à espera da pessoa, os emails importantes ou urgentes dos últimos três dias ainda sem resposta na conversa, e as tarefas dela fora do prazo. No máximo seis linhas.
  - Pode esperar: o que está «pronto para rever».
  - Hoje: os eventos da agenda e os prazos do dia.
  - O que não consegui verificar: as caixas de correio com erro, a agenda Google que não sincronizou, o ERP que não respondeu, e os agentes sem chave do modelo.
- **Sem modelo de IA:** `App\Home\MorningSummary` monta tudo a partir do que a plataforma sabe. Não custa nada e não inventa. Cada linha tem a ligação para onde se decide.
- **«Começar»:** uma linha de email tem «Começar», que entrega o email ao agente que trata aquele tipo de email (`EmailRouter`) na conversa da pessoa com ele, pedindo que prepare o que for preciso sem enviar nada.
- Enquanto o resumo está aberto, a linha «À sua espera» do Início fica escondida, para não repetir.
- **Correcção de 28.10:** um email sai de «Precisa de si» quando já está com um agente: depois de «Começar», depois de partilhado numa conversa, ou quando já há uma tarefa feita a partir dele. Os títulos de tarefas deixam de mostrar «(Email #143)», no resumo e em toda a plataforma (`Present::task` usa `EmailReferences::inTitle`).

## Iniciativas dos agentes (27.10.2026)

Ideia vinda do OpenDuet. É o «batimento proactivo» proposto antes, e o Barrote aprovou-o com os outros pontos.

- **O que é:** duas vezes por dia, nos dias úteis às 08:00 e às 14:00 de Maputo, cada agente activo com uma pessoa responsável propõe-lhe trabalho que pode fazer sozinho. O comando é `agents:initiatives` e o serviço é `App\Ai\Initiatives\OfferWork`.
- **O modelo:** escreve-as o modelo mais barato (`agents.suggestions_model`, Gemini 3.1 Flash-Lite), e o custo entra no orçamento (`agents.heartbeat`).
- **De onde vêm:** de evidências que a plataforma lhe dá numa lista com referências:
  - os emails das últimas 48 horas da área do agente (pelas Regras de email e fluxos, ou da caixa do agente) que ainda não têm tarefa nem conversa;
  - as tarefas do agente que estão bloqueadas.
  - As tarefas só paradas ficam com o `agents:heartbeat` de hora a hora, que já acorda o agente para elas.
- **Sem evidência não há iniciativa:** cada iniciativa tem de citar uma referência da lista. Uma referência inventada é descartada, e a mesma evidência não é proposta duas vezes na mesma semana. São no máximo 3 de cada vez, e nenhuma quando nada vale a pena. Uma iniciativa não decidida expira ao fim de 3 dias.
- **Onde aparece:** no Início (por baixo do resumo da manhã) e na conversa da pessoa com esse agente, por cima da caixa de escrita. Mostra «Posso tratar disto: …», porquê, as evidências com ligação, e dois botões:
  - «Começar» abre a tarefa para o agente, em nome da pessoa e com o email de origem. Põe-no a trabalhar com «Prepara tudo, mas nada sai para fora sem eu ver», e o agente responde «Vou tratar disso.».
  - «Esconder» descarta a iniciativa.
  - Só a pessoa a quem foi proposta decide.
- **O nome:** o batimento de hora a hora (`agents:heartbeat`) continua igual. Para não se confundir com ele, este chama-se «iniciativas».

## Tarefas pelo que precisam de si (27.10.2026)

Ideia vinda do OpenDuet.

- **O novo modo:** Tarefas tem um modo novo, «Foco», que passa a ser o modo com que a página abre. Lista, Quadro e Planear continuam a um clique (`?modo=lista`).
- **As secções:** o Foco mostra as tarefas da pessoa (as mesmas de «Minhas») pelo que precisam dela. Cada tarefa aparece uma só vez, na primeira secção que lhe serve:
  1. Precisa de si: o agente espera uma resposta, ou há uma aprovação dela. Logo a seguir vêm as sugestões dos agentes (as iniciativas), com «Começar» e «Esconder».
  2. Não conseguiu: a última execução falhou.
  3. A trabalhar.
  4. Pronto para rever.
  5. Precisa de informação: as tarefas bloqueadas.
  6. Resolvido esta semana.
- **O próximo passo:** cada tarefa mostra o próximo passo do agente:
  - a última coisa que o agente disse;
  - «À espera da sua decisão: «…»» quando há uma aprovação;
  - «A trabalhar agora»;
  - na secção «Não conseguiu», o motivo da falha da execução.
- **Agir na própria lista:**
  - Uma pergunta responde-se ali mesmo com «Responder», que publica a mensagem na tarefa e acorda o agente.
  - Uma falha tem «Tentar de novo» (`POST /tasks/{id}/retry`), que deixa uma nota na conversa e acorda o agente outra vez. Não o faz se ele já estiver a trabalhar.

## Verificação da configuração (27.10.2026)

Ideia vinda do OpenDuet.

- **A página:** fica em Definições › Estado da configuração (`/settings/health`), para proprietários e administradores. Mostra cada coisa de que os agentes dependem com um de três estados: «Pronta», «Não verificada» ou «Precisa de configuração». Quando falha, diz como resolver e tem a ligação para onde se resolve.
- **Os testes a sério:** «Verificar de novo» testa quatro coisas, e o resultado fica guardado um dia (por empresa) para que abrir a página não custe nada:
  - um pedido de uma palavra ao modelo de IA, com o modelo mais barato e cobrado ao orçamento como `platform.health`. Se o provedor responde sem crédito ou quota, a página diz isso;
  - a internet (`generate_204` da Google);
  - o login IMAP de cada caixa de correio;
  - o teste do ERP (`ErpGateway::test`).
- **O que se lê na hora:** quatro coisas são baratas e vêem-se sempre que se abre a página:
  - a fila (o Horizon está a correr, e quantos trabalhos esperam);
  - a execução de código;
  - a pesquisa na internet;
  - o Google Calendar (o cliente OAuth no .env e as agendas com erro).
- **Ligação ao resumo da manhã:** a linha «O que não consegui verificar» do resumo da manhã aponta para esta página.

## Dados sensíveis no que sai (27.10.2026)

Ideia vinda do OpenDuet.

- **O que se lê:** antes de sair, o porteiro (`AutonomyGate`) lê o que leva cada acção que manda algo para fora ou para alguém. A lista está em `autonomy.outgoing`: enviar email, publicar briefing e notificar pessoa.
- **O que se procura (`App\Ai\Autonomy\SensitiveData`):**
  - um número de cartão (13 a 19 algarismos que passam a verificação de Luhn);
  - uma palavra-passe escrita («palavra-passe:», «senha:», «password:» e semelhantes);
  - uma chave de acesso (Google, OpenAI, Anthropic, GitHub, Slack, AWS);
  - um número de BI (12 algarismos e uma letra);
  - dados de saúde (VIH/SIDA, diagnósticos, baixa ou atestado médico, gravidez, resultados de análises).
- **O que acontece:** se encontra alguma destas coisas, a acção fica à espera de uma pessoa, como no tecto absoluto. O motivo diz o que foi encontrado («Leva uma palavra-passe: uma pessoa vê antes de sair»). Nenhum nível e nenhuma regra «Permitir sempre» passam por cima.
- **O que não se procura:** o NIB e o NUIT, porque as facturas levam-nos de propósito.

## Canais por projecto (27.10.2026)

Ideia vinda do OpenDuet. A ideia é ter um canal por trabalho, por exemplo um por evento da Reactive.

- **Um projecto é um canal:** os projectos que já existem passam a ser os canais, sem criar uma coisa nova.
  - Cada projecto tem um ícone (8 à escolha) e uma cor (8 à escolha).
  - As tarefas do projecto mostram a cor como uma pinta ao lado do título, na Lista e no Foco.
  - A descrição passa a chamar-se «Para que serve», porque é o que o agente lê no canal.
- **«Canal»:** no projecto, abre a conversa do projecto (`POST /projects/{id}/channel`). Na primeira vez cria-a:
  - é uma tarefa «Canal: …» do projecto, do agente responsável (ou do Chief of Staff, se o responsável for uma pessoa);
  - o agente começa logo: apresenta-se em poucas linhas e sugere três coisas concretas para começar, sem criar tarefas nem enviar nada até lhe dizerem por onde começar.
  - Nas vezes seguintes abre o mesmo canal. Quem gere projectos é quem o abre pela primeira vez.

## Primeiro dia (27.10.2026)

Ideia vinda do OpenDuet.

- **Quando:** na primeira vez que uma pessoa abre a plataforma e ainda não falou com nenhum agente, o agente fala primeiro. A pessoa vai direta para a conversa com o Chief of Staff (ou, se não tiver acesso a ele, com o primeiro agente com quem pode falar), onde fica a nota «Primeiro dia de … na plataforma.». O agente começa logo a trabalhar.
- **O que o agente recebe:** tudo o que a plataforma sabe da pessoa:
  - cargo, papel e área;
  - remetente e assunto dos emails dela das últimas duas semanas;
  - a agenda desta semana e da próxima;
  - as tarefas com ela.
- **O que o agente faz:** apresenta-se numa frase e diz quem acha que a pessoa é, só pelo que viu. Propõe três formas de ajudar nessa semana. No fim pergunta «Acertei?» com as opções «Está certo» e «Há algo errado» (`questions.ask`).
- **Só uma vez:** acontece uma única vez por pessoa (`users.welcomed_at`). Quem já tinha entrado na plataforma antes desta mudança fica marcado como já recebido, e quem já fala com agentes não é recebido.

## O que sabem de mim (27.10.2026)

Ideia vinda do OpenDuet.

- **O que já existia:** a página com o que cada agente guarda da pessoa (Definições › Memória), onde se lia, corrigia e apagava, e onde se podia mandar esquecer tudo.
- **O nome novo:** a página passa a chamar-se «O que sabem de mim» («O que os agentes sabem de si»).
- **«Dizer aos agentes»:** é o que faltava. A pessoa escreve algo sobre si (sobre quê, o quê e em que secção) e escolhe a quem: a um agente ou a todos com quem pode falar.
  - Fica como uma entrada da memória dela com cada um desses agentes, escrita por ela, por isso conta como corrigida.
  - Nas secções sobre a pessoa (preferências e perfil) é pessoal. Nas outras secções é de trabalho, e a memória do agente é republicada (`memory.told` na auditoria).

## Galeria do que os agentes fizeram (27.10.2026)

Ideia vinda do OpenDuet. Como a proposta dizia, a página Documentos chega.

- **O que já existia:** Documentos já tinha a grelha, o filtro por tipo e o filtro «Quem fez» (IA ou pessoas), com os ficheiros e os relatórios juntos.
- **O que faltava:** ver o que um agente fez.
  - A página do agente tem agora «O que fez», que abre Documentos com `?agente=` e mostra os ficheiros e os relatórios desse agente.
  - Em Documentos aparece o filtro «Só …», que se tira com um clique.

## Falar em vez de escrever (27.10.2026)

Ideia vinda do OpenDuet.

- **O botão:** ao lado de «Gravar áudio», nas caixas de escrita do Início, da conversa com um agente e das tarefas, há «Ditar». A pessoa fala até 2 minutos e o texto aparece na caixa, para ler e corrigir antes de enviar.
- **O que o distingue de «Gravar áudio»:** «Gravar áudio» anexa a gravação. «Ditar» não anexa nada e a gravação não fica guardada.
- **Como transcreve:** com o modelo que já ouve áudio (Gemini, o mais barato: `agents.suggestions_model`), sem fornecedor novo, por `POST /dictation`. O custo entra no orçamento (`dictation`). Tira as hesitações sem mudar o sentido.
- **Quando não há modelo:** sem chave ou sem orçamento, o botão diz que não está disponível.

## Detalhes vindos do OpenDuet: fila, respostas rápidas e avaliação da triagem (27.10.2026)

- **Mensagens na fila:** já não havia respostas cruzadas, porque o que a pessoa escreve enquanto o agente trabalha espera e é respondido a seguir (`pickUpWaitingMessages`). Faltava dizê-lo. Na conversa, uma mensagem escrita depois de o agente começar a vez dele mostra «Na fila: responde a seguir».
- **Respostas rápidas:** os agentes passam a perguntar sempre com `questions.ask`, em vez de deixar perguntas soltas no texto. Cada pergunta leva as respostas mais prováveis como botões, e a pessoa pode sempre escrever outra. Para um valor, um preço ou um contacto a opção continua aberta («Indico o valor»), nunca um valor sugerido.
- **Avaliação da triagem (`php artisan triage:eval {empresa}`):**
  - Usa 20 emails etiquetados em `database/eval/triage.json` (categoria e prioridade): pedidos de cotação, cotações e facturas de fornecedores, extracto, candidaturas, concurso, interno, newsletters, phishing, uma oportunidade e pedidos de clientes.
  - Passa-os ao modelo do Agente de Triagem com as instruções dele, sem ferramentas, por isso nada é escrito, enviado nem criado.
  - Mostra os falhados e a percentagem de acerto. Falha abaixo de `--min` (por omissão 80% na categoria).
  - Custa uns cêntimos (`triage.eval` no orçamento). Corre-se antes e depois de mudar as instruções ou o modelo da triagem.

## Início: o dia na barra da direita (28.10.2026)

- Pedido do Barrote: num ecrã largo (1280 px ou mais), o resumo da manhã, as iniciativas dos agentes e «A trabalhar agora / Concluído nas últimas 24 horas» passam para uma barra à direita no Início.
- A barra ocupa um quarto da largura (pelo menos 18rem), a pedido do Barrote. Uma aprovação aberta continua com a largura de sempre. Ao centro ficam a saudação, a caixa de escrita, as sugestões e as caras dos agentes.
- Quando se abre uma aprovação, ela ocupa essa barra, como antes, e ao fechá-la volta o dia.
- Num ecrã estreito fica tudo por baixo da caixa, como estava (`HomeAgents` com `part`).

## Modelos de cada provedor (28.10.2026)

- **Pedido do Barrote:** escolher o modelo numa lista e não em linhas soltas, e não ficar só com os modelos do catálogo («existe o 3.8 também»).
- **A lista:** no separador Modelo do agente, o segundo passo é agora uma lista com pesquisa. Cada modelo tem o preço ao lado (US$ por milhão de tokens, entrada e saída), e por baixo aparece a descrição do modelo escolhido.
- **De onde vêm os modelos:**
  - Primeiro vêm os do catálogo (`agents.models`), com nome e descrição.
  - Depois vêm os que o provedor oferece hoje, pedidos ao próprio provedor com a chave da empresa (`App\Ai\ProviderModels`): Google, Anthropic, OpenAI, OpenRouter, DeepSeek, xAI e Mistral.
  - Ficam de fora os que não escrevem texto (imagem, voz, embeddings, tempo real…).
  - A lista guarda-se um dia por empresa e provedor. Uma falha não se guarda: com o provedor em baixo, ficam só os do catálogo.
  - Um modelo fora da tabela de preços aparece como «preço por definir», e o orçamento conta-o pelo preço de reserva.
- **Gemini no catálogo:** entram o Gemini 3.8 Flash (1,50 / 7,50, o preço do 3.6), o 3.5 Flash-Lite (0,30 / 2,50) e o 3.1 Pro em pré-visualização (2 / 12), com os preços da página oficial.
- **Nos testes:** a descoberta desliga-se (`AGENTS_DISCOVER_MODELS=false`).

## Barra do dia em cartões discretos (28.10.2026)

Na barra da direita da página inicial cada linha (resumo do dia, sugestões dos agentes, o que estão a fazer e o que fizeram) é um cartão leve: fundo `bg-muted/50`, sem borda, cantos de 8 px, o título a abrir o item e o motivo em letra pequena. Os títulos das secções passam a pequenos e em maiúsculas com a contagem ao lado, e o cabeçalho do resumo fica compacto (cara do Chief of Staff, «Resumo de hoje», fechar). Só a barra da direita muda; a lista ao centro em ecrã estreito mantém as linhas simples.

## Respostas lidas saem das notificações (28.10.2026)

Ao abrir uma conversa ou tarefa, as notificações de «Conversas» sobre ela (respostas de agentes e pessoas, «quer falar consigo») apagam-se do centro dessa pessoa: já as leu no sítio certo, e guardá-las só ocupava espaço. As restantes sobre a mesma tarefa (à sua espera, aprovações, prazos) ficam, mas marcadas como lidas. Encontram-se pelo `task_id` da notificação ou, nas antigas, pelo link `/tasks/{id}` (`App\Support\ReadInChat`, chamado em `TaskController::show`).

A lista ficou mais compacta: cada notificação tem o título e uma só linha por baixo com quem, o tipo (escondido quando já se filtra por tipo) e o texto; ícone mais pequeno, menos espaço vertical, e a barra de selecção deixou de ser um cartão.

## Notificações em cartões (28.10.2026)

A pedido do Barrote, cada notificação passa a ser um cartão independente (borda fina, cantos de 8 px, 8 px entre cartões) em vez de linhas de uma tabela. As por ler têm a borda e o fundo levemente na cor primária. A paginação, que só existia no fundo, aparece também em cima, na barra de selecção, quando há mais de uma página e nada está seleccionado.

## Director de Recursos Humanos (19.11.2026)

Pedido do Barrote: «A Rethink ERP já tem módulo de assiduidade e de recursos humanos, precisamos de criar um agente que é o director de Recursos Humanos da empresa. O ERP já devolve tudo, documentos PDF, slips. Este agente apenas tem de ver as datas, actualizar, verificar quem faltou etc. e reportar.»

**Correcção à secção «ERP real» (07.10.2026):** o Perfex já tem RH. O Rethink ERP expõe 61 ferramentas MCP de RH, Assiduidade e Payroll: `hr_*` (38), `att_*` (15) e `pay_*` (8, só leitura), descritas em `modules/rethink_hr/MCP_TOOLS.md` do ERP. Criar, calcular, aprovar ou pagar folhas, mexer em contratos e salários, marcar presenças por outros e processos disciplinares ficam nos ecrãs do ERP, de propósito. Vagas, candidaturas e integração continuam sem ferramenta.

| Decisão | Onde |
|---|---|
| O modelo `hr` passa a **Director de Recursos Humanos** (não há um sétimo agente): a chave, o papel (`AgentDirectory::forRole('hr')`), o encaminhamento das candidaturas pela triagem, o departamento (Direcção de RH), o nível N2 e a caixa `rh@` ficam. O ERP faz o trabalho e os documentos; o Director lê, vigia as datas, verifica a assiduidade, propõe actualizações às fichas e reporta. Nunca refaz o que o ERP faz. | `AgentTemplates::hr()` |
| Os modelos continuam a falar o contrato. O contrato ganha 8 ferramentas (`hr.attendance_today`, `hr.attendance_daily`, `hr.upcoming_dates`, `hr.get_employee`, `hr.people_indicators`, `hr.list_payroll_runs`, `hr.get_payslip`, `hr.update_employee`) e cada ferramenta de RH do contrato passa a ter as do Perfex que fazem o mesmo. `hr.list_employees` deixa de trazer salários. | `docs/ERP-MCP-CONTRACT.md` (4.2), `Mcp\FakeErp\Modules\Hr` e `Attendance`, `PerfexToolMap` |
| A folha de salários é processada no ERP; o Director só a lê e confere: a folha do mês contra a do mês anterior e contra a assiduidade, e assinala anomalias. `hr.prepare_payroll_draft` continua no contrato (servidor falso, testes antigos), mas as instruções do Director não dependem dele, e no Perfex não tem equivalente. | `AgentTemplates::hr()` |
| Recibos de vencimento, contratos, cartas, folhas de IRPS e declarações anuais vêm das ferramentas `*_pdf` do ERP, anexados à resposta como os outros ficheiros do ERP. O agente nunca gera um documento que imite um destes. O servidor falso não gera PDFs: pedir um dá um erro legível. | `InstructionComposer::erpGlossary()`, `ErpFiles` |
| A única escrita do Director é actualizar a ficha (`hr.update_employee`, no Perfex `hr_update_employee`), e fica sob o tecto absoluto: pode cessar um contrato. Também ficam sob o tecto decidir férias (`att_decide_leave_request`), decidir despesas (`hr_decide_expense_claim`) e marcar despesas pagas (`hr_mark_expense_claim_paid`). Pedidos de férias, cartas, despesas, objectivos, equipamento e avaliações decidem-se no ERP, por pessoas. | `config/autonomy.php` (`ceiling`) |
| **Dados de pessoal limitados no servidor.** O token do ERP actua como administrador, por isso as regras do ERP sobre quem vê o quê não protegem nada. Uma lista fechada de capacidades (no ERP real todas as `pay_*` e todas as `hr_*` menos `hr_org_chart`, `hr_reference_data` e `hr_leave_balances`; no contrato a ficha, os indicadores, as datas de RH, as folhas, os recibos, preparar a folha e actualizar a ficha) só corre a pedido de uma pessoa activa com **«Gerir pessoas e papéis»** (`people.manage`), ou numa **rotina do próprio agente cujo resultado vai para uma pessoa assim** (*corrigido a 20.11.2026: «ou para ninguém» saiu; uma rotina sem destinatário é recusada, ver «Agente confidencial»*). A pessoa é só **quem pediu a execução** (`requested_by_user_id`): com quem a tarefa está não conta, porque um email ou outro agente abre tarefas com qualquer pessoa. O pedido segue nos caminhos legítimos: a mensagem de uma pessoa numa conversa ou tarefa, a «Nova tarefa» de uma chefia, uma pergunta respondida e uma aprovação (a mesma execução continua), as mensagens que esperavam o agente livre, e os pedidos passados entre agentes (`agents.message`), nos dois sentidos (*corrigido a 20.11.2026: só quando quem pediu é a pessoa da conversa onde o colega trabalha; de outro modo o colega trabalha para ninguém*). Uma tarefa aberta por um email ou por outro agente, o batimento e os passos de um fluxo não têm pessoa e são recusados. A recusa traz uma mensagem que o agente passa e fica na auditoria. A assiduidade (`att_*`, `list_staff`, `list_departments`) fica aberta a quem pode falar com o agente; as instruções dizem-lhe que o motivo de uma ausência e tudo o que toque a saúde só vai para a chefia. | `App\Ai\Autonomy\PeopleData`, `GatedTool`, `Handoffs`, `TaskThread`, `config/autonomy.php` (`people_data`, `people_data_open`) |
| As datas de RH (`hr.upcoming_dates`, `hr_upcoming`) contam como dados de pessoal: trazem fins de contrato, validades de documentos de identificação, baixas e prazos disciplinares. Pela mesma razão `hr.list_employees` do contrato deixa de trazer o tipo e o fim do contrato, para continuar aberta. | `config/autonomy.php`, `Mcp\FakeErp\Modules\Hr` |
| Relatórios e notificações levam totais, número de pessoas e quem precisa de atenção com o tipo de situação, nunca salários individuais, números de BI, NUIT, INSS ou contas. A verificação do que sai (`autonomy.outgoing`) não muda: segura «baixa médica», «gravidez» e números de BI; por isso as instruções dizem «de licença» em vez do tipo de licença, e uma notificação de faltas normal não fica retida. | `AgentTemplates::hr()`, `SensitiveData` |
| Rotinas (hora de Maputo), todas a falar o contrato: **Datas de RH** (dias úteis, 07:00; só os itens a exactamente 30, 14, 7, 3, 1 ou 0 dias, para nada se repetir sem memória), **Assiduidade do dia** (dias úteis, 09:30; ontem e hoje; só notifica quando há algo), **Resumo semanal de assiduidade** (segunda, 08:00; documento `attendance`), **Assiduidade do mês** (dia 1, 08:00; mês anterior, se está fechado, o que ficou por decidir) e **Conferir a folha de salários** (dia 25, 09:00; documento `payroll_check`). Saem «Preparar folha de salários» e «Férias pendentes». Sem limites de horas extraordinárias próprios: só os que o ERP indica. | `AgentTemplates::hr()` |
| Um agente de modelo num tenant que sempre esteve no Perfex não tinha nenhuma leitura do ERP: as chaves do contrato nunca existiram como capacidades, e as leituras do Perfex só se davam a partir delas. Agora recebe as leituras do Perfex das chaves do seu modelo ao ser instalado e ao ser actualizado (`--refresh`). Na **sincronização** só se dão as leituras que o ERP começou a listar nessa sincronização (novas, ou que voltaram depois de desaparecer), tanto pelas chaves do modelo como pelas capacidades do contrato que o agente tinha: uma leitura que um administrador tirou ao agente (em Agentes › Editar) não volta na sincronização seguinte. Cada atribuição automática fica na auditoria (`agent.capabilities_granted_by_sync`). As escritas continuam a ser dadas por um administrador. | `CapabilityCatalog::grantPerfexReads()`, `grantPerfexReadsFor()`, `TemplateInstaller` |
| No prompt, o glossário do ERP distingue a ferramenta que o agente tem, a que o ERP tem mas o agente não, e a que o ERP tem mas o token ainda não lista (é preciso marcá-la em MCP Server › Definições do ERP e sincronizar). | `InstructionComposer::erpGlossary()` |
| **Actualizar um agente ao modelo**, explícito e auditado: `agents:install-templates {tenant} --refresh=hr` repõe nome, título, descrição, personalidade e instruções; acrescenta as capacidades do modelo sem tirar nenhuma; sincroniza as rotinas pelo nome (cria as novas, actualiza as do mesmo nome, desliga sem apagar as que o modelo deixou de ter). Cada rotina mantém o estado em que o administrador a deixou; as novas nascem desligadas se as do agente estão todas desligadas. Departamento, nível, modelo de IA, chefia e caixa ficam. A auditoria guarda os valores anteriores dos campos que mudaram (`previous`), para se poder repor o texto do administrador. Sem `--refresh` o comando faz o que fazia. *Acrescentado a 20.11.2026:* a auditoria guarda também o texto e o horário que cada rotina actualizada tinha (`routines_previous`); e a chefia muda para o dono quando o agente lê dados de pessoal e a chefia não tem «Gerir pessoas e papéis» (ver «Agente confidencial»). | `TemplateInstaller::refresh()`, `agents:install-templates` |
| **Rotinas que lêem dados de pessoal.** Uma rotina de um agente que tem alguma capacidade da lista corre com essas leituras permitidas, por isso criá-la, mudá-la ou «Correr agora» exige «Gerir pessoas e papéis», além de administrar a empresa (*a 20.11.2026: qualquer administrador pode pausá-la; só o interruptor muda*). Quando o agente está ocupado, o pedido da rotina fica na conversa e a execução que o apanha depois continua a ser da rotina (a mensagem guarda `agent_routine_id`). | `RoutineController`, `RoutineRunner`, `TaskThread::pickUpWaitingMessages()` |
| **Quem vê uma execução** (linha da lista, registo passo a passo, canal ao vivo, painel da conversa, página do agente, painel inicial): quem tem «Ler as conversas de todos», quem a pediu, e quem pode abrir a sua tarefa; uma execução sem tarefa e sem pessoa só os primeiros (*corrigido a 20.11.2026: as de um agente confidencial, e as das suas tarefas, só quem tem «Gerir pessoas e papéis»; ver «Agente confidencial»*). Onde o agente aparece a trabalhar noutro sítio (a conversa, a sua página, o início), uma execução que a pessoa não pode abrir mostra que ele trabalhou, sem pedido, resposta nem erro. Os resultados das capacidades da lista só os vê quem tem «Gerir pessoas e papéis» (também os administradores precisam dela); os outros lêem «Resultado com dados de pessoal…». O canal ao vivo nunca os leva: quem pode vê-los recarrega a página. | `AgentRunPolicy`, `AgentRun::visibleTo()`, `PeopleData::present()`, `AgentRunStepAdded`, `AgentNow` |
| Os resultados das capacidades da lista não ficam na auditoria (`erp.tool_call` guarda só o tamanho e as chaves), nem no cartão de uma aprovação que um administrador tenha posto numa leitura destas. Um PDF de pessoal que a plataforma não pôde anexar (uma execução sem conversa) perde a ligação de descarga antes de chegar ao registo: essa ligação funciona sem sessão até expirar. | `ErpGateway`, `ApprovalService`, `CapabilityExecutor` |
| **Agente confidencial** (20.11.2026, decisão do Barrote). Um agente que tem alguma capacidade da lista de dados de pessoal (`PeopleData::agentHolds`) **só existe para quem tem «Gerir pessoas e papéis»**, verificado de cada vez, e tudo o que produz fica entre essas pessoas. **Excepção deliberada à decisão 23 do realinhamento** («Administradores e o dono podem ler sempre as conversas»): quem lê as conversas de todos, todo o trabalho, todos os documentos ou decide todas as aprovações não lê as deste agente sem a permissão. O dono vê tudo, porque o papel de CEO tem sempre a permissão. A recusa por capacidade (acima) fica como segunda camada. Na prática: **falar com ele** e pedir-lhe trabalho só com a permissão (`AgentPolicy::run`, `requestWork`); a chefia e quem tem acesso têm de a ter, e o editor e os acessos recusam quem não a tem, em português; tirar-lhe as leituras de pessoal também a exige (o que já fez ficaria aberto). **Ver**: conversas, tarefas que tem ou que abriu, execuções (também as de outros agentes nessas tarefas), anexos, raciocínio, canais ao vivo, documentos, ficheiros gerados, briefings, lembretes, memória por pessoa, o que está a fazer agora (painel, início, página do agente) e as conversas da equipa na página do agente, só para quem tem a permissão. **Aprovações**: só as decide quem tem a permissão (política e `decidableBy`), nunca passam pelo Chief of Staff, e o canal de quem decide todas não as leva; o resumo de uma capacidade de dados de pessoal é só o nome, nunca os argumentos (vai para notificações, a visão geral e os alertas). **O que envia**: `notify.user`, o aviso de `reports.draft`, `followups.schedule`, uma tarefa para uma pessoa (`tasks.create`) e o aviso de uma rotina só a quem tem a permissão; a capacidade devolve um erro que o agente passa; os administradores sem ela sabem que a rotina correu, sem texto. Outro agente só lhe dá uma tarefa a partir da conversa de quem tem a permissão. **Rotinas**: o destinatário tem de ter a permissão; sem destinatário, recusada. **Entre agentes**: um pedido passado a ele ou por ele só quando a pessoa da conversa tem a permissão; a resposta dele volta como «respondeu na conversa com X», sem o texto. **Memória**: sem memória de trabalho para todas as conversas, sem artigo na base de conhecimento, sem propostas à memória da empresa; `knowledge.save` e `memory.remember_decision` são recusadas e `documents.generate` não arquiva na base. **Pesquisar conversas**: quem pergunta é só quem pediu a execução, e as conversas deste agente só aparecem a quem tem a permissão. **Instalar e actualizar o modelo**: se a chefia (a chefia do departamento, ou a que tinha) não tem a permissão, a chefia passa a ser o dono, e o comando di-lo; quem tinha acesso sem a permissão aparece na saída. | `PeopleData` (`closedTo`, `closesTask`, `hiddenAgentIds`, `recipientRefusal`), `AgentPolicy`, `TaskPolicy`, `Task::visibleTo()`, `AgentRunPolicy`, `AgentRun::visibleTo()`, `ApprovalPolicy`, `Approval::visibleTo()`/`decidableBy()`, `ApprovalService`, `AgentEditor`, `AgentController`, `RoutineController`, `RoutineRunner`, `Handoffs`, `TaskThread`, `MemoryConsolidator`, `SearchConversations`, `CreateTask`, `OrganisationOverview`, `IssueDetector`, `ReportController`, `DocumentController`, `BriefingController`, `Agenda`, `AgentNow`, `TemplateInstaller` |
| **Limites conhecidos do agente confidencial** (20.11.2026): uma leitura que um administrador tirou ao agente volta se o ERP deixar de a listar e voltar a listá-la; o ecrã de rotinas do super admin da plataforma é de confiança; os motivos de ausência pelas `att_*` estão protegidos só pelas instruções (a assiduidade fica aberta, por decisão); o cartão de aprovação nomeia o funcionário pelo id do ERP. Também ficam por fechar: a caixa `rh@` (os emails e rascunhos seguem as regras da caixa de correio), a auditoria (guarda os argumentos de cada chamada, para quem pode ler a auditoria), o texto de um pedido que o agente passa a um colega (fica na conversa do colega com a pessoa) e os fluxos com passos deste agente. | — |

Para o tenant `rethink` (o agente de RH existe, com as rotinas desligadas): marcar as ferramentas `hr_*`, `att_*` e `pay_*` no token da plataforma no ERP, depois `php artisan migrate`, `php artisan capabilities:sync rethink` e `php artisan agents:install-templates rethink --refresh=hr`. A saída diz se a chefia passou para o dono e quem deixa de falar com o Director por não ter «Gerir pessoas e papéis» (tirar-lhes o acesso no agente, ou dar-lhes a permissão). As rotinas novas ficam desligadas; ligam-se em Definições › Rotinas, por quem tem «Gerir pessoas e papéis», com destinatário que também a tenha.

Testes: `tests/Feature/Business/HrDirectorTest.php`, `tests/Feature/Business/PeopleDataTest.php`, `tests/Feature/Business/ConfidentialAgentTest.php`, `tests/Feature/Erp/PerfexErpTest.php`, `tests/Feature/Erp/FakeErpServerTest.php`, `tests/Feature/Business/AreasTest.php`, `tests/Feature/Agents/AgentConsoleTest.php`.

## Seed do tenant Rethink (21.11.2026)

Pedido do Barrote: a configuração da Rethink num seed, no `main`. A base de desenvolvimento (`micomoc_agents`) foi recriada vazia a 20.11.2026 e o tenant `rethink`, montado à mão a 15–17.10.2026, perdeu-se com ela. O seed refaz o que essas sessões gravaram (os scripts e os textos estão nas conversas «Empresa de teste Rethink» e «Produtos e serviços para cotações»).

| Decisão | Onde |
|---|---|
| **`RethinkSeeder`**, chamado no fim do `DatabaseSeeder` (`php artisan db:seed` refaz a MICOMOC e a Rethink) ou sozinho (`--class=RethinkSeeder`). Pode correr outra vez: só acrescenta o que falta. | `database/seeders/RethinkSeeder.php` |
| **O que refaz**: tenant `rethink` com a marca (#0052CC, Space Grotesk, rodapé, logótipo da skill rethink-docs), sem `mail_domain`; Edy Barrote (dono, CEO), Kleyton Macatange e Naira Sitoe (membros, palavra-passe aleatória, sem convite); só a Direcção-Geral; os seis agentes dos modelos a reportar ao CEO, cinco com as descrições e sugestões da Rethink; o de RH fica com o texto do Director de Recursos Humanos (20.11.2026) e as rotinas desligadas; os 22 factos aprovados da Memória da empresa; os dois artigos da Reactive em Geral › Produtos e serviços › Reactive. | `RethinkSeeder`, `database/seeders/rethink/` |
| **Só leitura e sem email para fora**, como em Outubro: as ferramentas do ERP que escrevem e `comms.send_email` ficam desligadas a cada execução. | `RethinkSeeder::readOnly()` |
| **Segredos fora do repositório** (o repositório é público): a ligação ao ERP Perfex só se faz com `RETHINK_ERP_URL` e `RETHINK_ERP_TOKEN`; sem eles o tenant fica sem ERP e o seed avisa. A palavra-passe do dono vem de `RETHINK_OWNER_PASSWORD`; sem ela é `password` em local e aleatória noutros ambientes. A caixa geral@ liga-se em Definições, com a palavra-passe IMAP; correr o seed depois dá-lhe o dono e os leitores (Triagem processa o novo, Chief of Staff lê). | `config/services.php` (`rethink`), `.env.example` |
| **Não volta**: o histórico da caixa geral@ e os três PDFs da empresa que vieram por email (propostas ao MTC e ao FDC, «Documentos da empresa»). Os PDFs têm documentos pessoais e não entram num repositório público; carregam-se no repositório de ficheiros. | — |

Testes: `tests/Feature/Tenancy/RethinkSeederTest.php`.

## Instalação em produção (23.11.2026)

Pedido do Barrote: o kit para instalar a plataforma num servidor esta semana, para a entrega à MICOMOC (questão 6 da secção 21). Guia completo em [DEPLOY.md](DEPLOY.md); scripts em `deploy/`.

| Decisão | Onde |
|---|---|
| **Um VPS Ubuntu 24.04 LTS por ambiente** (produção e staging, a mesma receita). Para ~50 pessoas: 4 vCPU, 16 GB e 200 GB NVMe recomendados (mínimo 8 GB e 100 GB); staging com 2 vCPU e 8 GB. | `DEPLOY.md` (1, 15) |
| **PHP 8.4** (PPA `ondrej/php`), não o 8.3 do Ubuntu: o `composer.lock` tem `symfony/string` 8.1, que exige PHP 8.4.1, e o `composer install` falharia no 8.3 (no CI também; o CI passa a 8.4). | `deploy/provision.sh`, `.github/workflows/ci.yml` |
| **MariaDB 11.8 LTS** (`deb.mariadb.org`), com `DB_CONNECTION=mariadb` como no Mac: tem o tipo `VECTOR` para quando a pesquisa por vectores passar para a base de dados (14.10.2026). `MARIADB_FROM_UBUNTU=1` instala o 10.11 do Ubuntu, que também serve hoje. | `deploy/mariadb/60-micomoc.cnf` |
| **Redis** para filas, cache e sessões, sem despejo e com AOF, para as filas não se perderem. **`REDIS_QUEUE_RETRY_AFTER` passa a 930 s** por omissão (era 90): um trabalho a meio (agentes até 300 s, `SyncErpSales` 900 s) era entregue a um segundo processo e a execução repetia-se. | `config/queue.php`, `deploy/redis/micomoc.conf` |
| **Supervisor** (não systemd) para o Horizon, o Reverb e o `schedule:work`, num grupo `micomoc`. O Horizon espera até 960 s ao parar; o agendador pára sem matar os `schedule:run` a meio, para não deixar bloqueios de `withoutOverlapping` por 24 h. O Horizon ganha o ambiente `staging` (10 processos), sem o qual não arrancaria nenhum. | `deploy/supervisor/`, `config/horizon.php` |
| **Releases em pastas e troca atómica** (`current`): dependências, build e caches feitos antes da troca, por isso uma falha não mexe no que está no ar. Manutenção só durante as migrações, e só quando as há, sempre com uma cópia da base de dados antes. `rollback.sh` volta ao código de uma release que esteve no ar; a base de dados repõe-se à parte. | `deploy/deploy.sh`, `deploy/rollback.sh` |
| **Reverb atrás do Nginx em `ws.<central>`**: o browser liga-se por `wss://…:443` (`VITE_REVERB_*`, entram no build), o Laravel publica directo em `127.0.0.1:8080`. O slug `ws` fica reservado. | `deploy/nginx/micomoc.conf`, `config/tenancy.php` |
| **DNS: registo wildcard `*.<central>`. Certificado: um nome por empresa (HTTP-01) na entrega**, porque o DNS fica no Hostinger e não fica nenhum token de DNS no servidor; uma empresa nova é `tls.sh http <slug>`. Wildcard por DNS-01 (Cloudflare) quando houver mais empresas. | `deploy/tls.sh`, `DEPLOY.md` (2) |
| **Cópias todas as noites (01:00 UTC)**: `mariadb-dump --single-transaction` comprimido e verificado; `storage/app` e o `.env` (a `APP_KEY`) numa pasta por noite com hard links para o que não mudou; 14 dias no servidor, 30 para as de antes de cada deploy; fora do servidor por `rclone` (remote crypt). Passos de reposição e ensaio mensal escritos; o primeiro ensaio faz parte da entrega. | `deploy/backup.sh`, `deploy/restore.md` |
| **`code.run` com Docker**: imagem construída em cada deploy e provada por `sandbox-smoke.sh` (nunca tinha sido experimentada). O utilizador da aplicação fica no grupo `docker`, o que equivale a root na máquina: risco aceite com o servidor dedicado, Docker *rootless* fica para depois. Sem Docker, `AI_CODE_SANDBOX=off` e os agentes usam `files.analyze`. | `deploy/sandbox-smoke.sh`, `DEPLOY.md` (8) |
| **Endurecimento na aplicação**: `/up` verifica a base de dados e o Redis (503 a dizer o que falhou); `TRUSTED_PROXIES` para Cloudflare ou balanceador; links em `https` e cookie de sessão seguro por omissão quando `APP_URL` é https. | `UptimeController`, `config/trustedproxy.php`, `AppServiceProvider::configureUrls()`, `config/session.php` |
| **Entrada em produção da MICOMOC**: pessoas reais com papéis da lista da MICOMOC, agentes instalados em rascunho e todos em N2 nas primeiras duas semanas, tecto absoluto sem «Permitir sempre», orçamento de IA acordado (sugestão para arrancar: 150 USD/mês a organização, 40 por agente, 1 por execução), caixas e ERP testados, cópias ensaiadas, demo gravada e registo de aceitação assinado. | `DEPLOY.md` (18) |

Testes: `tests/Feature/ProductionHardeningTest.php`. Os scripts passam `bash -n` e `shellcheck`; o `deploy.sh`, o `rollback.sh` e o `backup.sh` foram ensaiados no Mac com as ferramentas do servidor simuladas. Fica por provar num servidor real o que está em `DEPLOY.md`, secção 19.
