# Interface — regras de desenho

A interface segue o padrão do [Paperclip](https://github.com/paperclipai/paperclip) (licença MIT): uma consola de operador.
Cada ecrã responde, por esta ordem: **o que está a acontecer, precisa de mim, o que faço**. Densidade de informação, não de decoração.

## Tema e tokens

- Tema "Claude +" do tweakcn, aplicado com `npx shadcn@latest add https://tweakcn.com/r/themes/cmdght103000n04lh3e2ae93r`.
  Os tokens vivem só em `resources/css/app.css` (Tailwind v4, sem `tailwind.config.js`).
- Fontes: Outfit (texto) e Geist Mono (valores de máquina), via `@fontsource-variable`.
- Modo claro, escuro e sistema: `resources/js/lib/appearance.ts`; o primeiro paint é feito em `app.blade.php`.
- Componentes base são os oficiais do shadcn (`npx shadcn@latest add <nome>`), em `resources/js/Components/ui`.

## Regras

1. **Um componente por função.** Antes de criar um, ver `Components/Blocks.tsx` (`Section`, `ListPanel`, `EntityRow`,
   `MetricCard`, `Properties`/`Property`, `Monogram`) e `Components/Status.tsx`.
2. **Estados são sistemáticos.** Todo o estado usa `StatusBadge`/`StatusDot` com um de cinco tons
   (`running`, `success`, `warning`, `danger`, `idle`) e os mapeamentos `runTone`, `agentTone`, `approvalTone`.
   Nada de `bg-emerald-100` avulso.
3. **Hierarquia pela estrutura.** Títulos de bloco com `Section` (maiúsculas pequenas), listas em linhas (`ListPanel` +
   `EntityRow`) em vez de cartões grandes. Cada borda tem de se justificar.
4. **Valores de máquina parecem de máquina.** IDs, custos, tokens, durações e chaves em `font-mono`; datas em listas
   com `ago()` e a data exacta em `title`.
5. **Páginas de detalhe:** conteúdo à esquerda, `Properties` à direita (`lg:grid-cols-[1fr_20rem]`).
6. **Migalhas de pão** no topo: `AppLayout` deduz a secção; páginas de detalhe passam `breadcrumbs`.
7. **Palavras:** uma palavra por conceito, botões dizem a acção ("Aprovar", não "Submeter"), estados vazios dizem o que
   fazer primeiro.
8. Mensagens de sucesso/erro do servidor (`flash`) aparecem como toast; não repetir em banners.

## Padrões de ecrã (revisão de 05.10.2026)

Peças partilhadas, a usar antes de escrever outra:

| Precisa de | Use | Onde |
|---|---|---|
| Lista com muitas linhas vinda do servidor | `<Pagination page={…} noun={['item', 'itens']} />` com o paginador do Laravel | `Components/Pagination.tsx` |
| Lista que chega inteira ao browser (catálogos, até ~100) | `usePaged(rows, 20)` e `<PaginationBar {...paged.pager} />` | idem |
| Perguntar antes de apagar ou de algo sem volta | `<ConfirmDialog destructive … />`, nunca `window.confirm()` | `Components/Dialogs.tsx` |
| Criar ou editar algo com poucos campos | `<FormDialog>`: título, porquê, campos, Cancelar à esquerda e a acção à direita | idem |
| Escolher um ficheiro | `<FileInput>` (português, aceita arrastar), nunca `<Input type="file">` à vista | `Components/FileInput.tsx` |
| Escolha numa lista | `Select` do shadcn em formulários e diálogos; `NativeSelect` só em formulários longos (desenha a mesma seta nos dois temas) | `Components/ui` |
| Uma aprovação | `<ApprovalCard>`: quem pede, porquê precisa de uma pessoa, Aprovar num clique, Rejeitar com motivo num diálogo, detalhes num diálogo | `Components/ApprovalCard.tsx` |

Regras que saíram da revisão:

1. **Modal ou página.** Criar e editar com até ~6 campos é um `FormDialog` aberto a partir do botão do cabeçalho
   ("Novo domínio", "Novo departamento"). Formulários longos ou com várias secções (agente, skill, organização)
   continuam a ser uma página. Nunca um formulário de criação solto no fundo de uma lista.
2. **Filtros de estado são separadores** (`Tabs variant="line"`), como em Aprovações e Execuções; filtros secundários
   são botões `xs` (`secondary` quando activos), como a origem nas Capacidades. Não mostrar filtros vazios.
3. **Toda a lista diz quantos tem**, no pager ("1–20 de 78 capacidades"), também quando cabe numa página.
4. **Texto de máquina não vai cru para a pessoa:** campos extraídos e dados de aprovações aparecem como etiqueta e
   valor (`fieldLabel()` em `lib/format.ts`); JSON só dentro de "Ver dados".
5. **Markdown dos agentes** passa sempre por `<Markdown>`; tabelas largas deslizam dentro da coluna.
6. **No telemóvel** o título de uma linha nunca é espremido por insígnias: estados e níveis descem para debaixo do
   título ou escondem-se abaixo de `sm`.
7. Plurais escritos ("1 agente", "2 agentes"), nunca "agente(s)".

Segunda passagem (avaliação de 6/10, mesmo dia):

| Precisa de | Use | Onde |
|---|---|---|
| Dizer o que uma aprovação faz | `approvalTitle(action_type, payload, summary)`: uma frase por acção do ERP ("Registar despesa de 72 848,00 MZN · Segurança Total EPI"); os argumentos como factos com `approvalFacts()` | `lib/approvals.ts` |
| Aprovar várias de uma vez | Caixa de selecção nas linhas de `<ApprovalList>` e barra "Aprovar n" (`POST /approvals/approve`); as do tecto absoluto decidem-se uma a uma | `Pages/Approvals/Index.tsx` |
| Números, dinheiro, durações, datas | Só `lib/format.ts`: `mzn`, `usd` (2 casas) / `usdPrecise` (custo de uma execução), `number`, `duration` ("12,1 s", "2 min 05 s"), `date`/`dateTime`/`ago`, `plural()` | `lib/format.ts` |
| Pré-visualizar texto de um agente numa linha | `plainText()` (tira markdown) e `runTitle()` (tira "[Proprietário] [Nota da plataforma]") | idem |
| Ligações que os agentes escrevem | `<Markdown>` mostra "/approvals" como "Aprovações" e abre-a na consola; caminhos de áreas que já não existem ficam texto | `lib/paths.ts`, `Components/Markdown.tsx` |
| Nomes de colunas numa lista de linhas | `<ListHeader>` por cima de `<ListPanel>`, com as mesmas larguras das células da linha | `Components/Blocks.tsx` |
| Tabela no telemóvel | `className="table-stack"` no `<Table>` e `data-label` em cada célula: abaixo de 640 px cada linha é um cartão | `resources/css/app.css` |
| Separadores que não cabem | `scroll-fade overflow-x-auto` no `TabsList`, triggers `flex-none` | idem |
| Um PDF dentro da página | `<PdfPreview src title>`: sem leitor de PDF, mostra Abrir e Descarregar em vez de uma caixa cinzenta | `Components/PdfPreview.tsx` |

8. **Uma só largura de página** (`max-w-[90rem]` no `AppLayout` e no `AdminLayout`): o conteúdo começa sempre no mesmo
   sítio. Formulários e detalhes limitam-se por dentro quando precisam.
9. **Rodapé de formulário de página:** Cancelar `ghost` à esquerda, acção principal à direita. Nos diálogos, os dois à direita.
10. **Execução:** o pedido mostra as instruções e o email recebido como email, nunca a vedação
    `<email_externo_nao_confiavel>`; chamadas e resultados de ferramentas mostram o nome da capacidade e só abrem os
    dados com "Ver o que enviou" / "Ver o que recebeu".
11. **Linhas no telemóvel** deixam o título ir a duas linhas e a linha de baixo quebrar, em vez de cortar três nomes.
12. **Organigrama:** árvore de cima para baixo só de leitura; um grupo só de folhas fica em coluna debaixo da chefia;
    mudar quem reporta a quem faz-se num diálogo. No telemóvel é uma lista indentada.
13. **Acções secundárias de uma linha** (corrigir, mudar o tipo, esquecer) vão para um menu "…"; o que apaga pede confirmação.

Terceira passagem:

14. **Nenhuma chave de ferramenta à vista de quem não configura.** Títulos de execuções vêm do nome da rotina ou da
    tarefa; o resto passa por `withToolNames()` (`lib/format.ts`, com `tool_names` da página). As chaves ficam em
    "Detalhes técnicos", em dicas (`title`) e em Capacidades.
15. **Datas:** prazos sem hora mostram só o dia (`deadline()`); períodos e meses ISO dentro de texto passam por
    `period()` ("01/09/2026 a 30/09/2026", "outubro de 2026").
16. **Dinheiro** sempre na letra normal com `tabular-nums`, nunca em `font-mono`.
17. **Emails para aprovar** começam pelo assunto («Lembrete de Pagamento – Factura FT 2026/131 (Agro Zambeze, Lda)»);
    o destinatário vai para a linha de baixo (`approvalRecipient()`).
18. **Revisão do Chief of Staff:** a etiqueta diz que se pode decidir já (a decisão de uma pessoa ganha; a revisão
    deixa de contar) e usa a cor do tema.
19. **Listas que rolam dentro de um formulário** levam `relative`: as caixas de selecção do Radix criam um input
    invisível em `position: absolute` que, de outro modo, estica a página.
20. **Artigos:** `<MarkdownEditor>` com barra de formatação e "Como fica" ao lado (empilhado abaixo de 1280 px).
21. **Estados vazios** podem mostrar um `example` esbatido do que vai aparecer (Objectivos, Projectos).
22. **Organigrama:** cartões de 13 rem, ramos que se recolhem, grupos de mais de três folhas em duas colunas, em
    tamanho real por omissão; "Ajustar ao ecrã" nunca baixa os nomes de 12 px.

Polimento final:

23. **Cabeçalhos de tabela** sempre em minúsculas normais (`text-xs font-medium text-muted-foreground`), como o `ListHeader`.
24. **Texto âmbar** sobre fundo claro usa `text-warning-strong` (5,5:1 num cartão, 5,2:1 numa linha seleccionada); o âmbar puro fica para ícones e pontos.
25. **Tabelas de markdown** viram cartões no telemóvel, como as outras (o `<Markdown>` etiqueta cada célula); o código
    em linha só parte depois de `. @ / _ -`.
26. **Selecção em lote:** a barra "Aprovar n" fica presa no topo ao descer; no telemóvel aparece também em baixo,
    ao alcance do polegar, enquanto houver linhas seleccionadas.
27. **Pré-visualizações** dizem "(ver tabela)" em vez de juntar as células numa linha.

Definições:

28. **Páginas de definições** usam `<SettingsLayout>` em vez de `<AppLayout>`: o título "Definições" e a barra
    lateral agrupada aparecem a partir de 1280 px; entre 640 e 1280 px um menu "secção ▾" por cima da página troca
    de secção; no telemóvel a página de entrada (`/settings`) é a lista agrupada e cada secção volta com
    "‹ Definições". Uma secção nova entra em `lib/settings.ts`, com as permissões que o servidor verifica.
29. **Blocos de formulário** de definições usam `<SettingsBlock>`: o que é à esquerda, os campos num cartão à direita.

## Imagens geradas

Geradas com a skill nano banana (Gemini), redimensionadas para 1400 px e convertidas para WebP (`cwebp -q 80`). Têm a marca de água invisível SynthID.

- `public/images/auth/login-hero.webp` (`-m pro -s 2K -a 3:4`): *An abstract editorial illustration for the sign-in screen of an AI agent business platform. Soft warm composition of floating rounded geometric shapes and thin connecting lines, like a calm network of nodes and cards orbiting a central glowing point, suggesting coordinated agents at work. Matte paper texture, gentle grain, warm light from the upper left. Palette: cream and ivory background, terracotta #C96442, muted clay, warm sand and a little charcoal. Plenty of breathing room, the lower third quieter. No text, no letters, no people, no logos, no watermark.*
- `public/images/auth/login-hero-dark.webp` (edição da anterior, `-i login-hero`): *Keep exactly the same composition, shapes, orbits and positions. Change only the lighting and palette to a night version: deep warm charcoal background (#2B2A27), the shapes in terracotta, clay and sand glowing softly, the thin orbit lines as faint warm light. Same paper grain. No text, no watermark.*
- Logótipo (conceito, não usado tal e qual; a versão final é o SVG em `Components/RethinkMark.tsx`): *A modern minimal app icon logo mark for an AI agents and workflow automation platform. Three small rounded nodes connected by one smooth flowing line, forming a simple workflow path, with a small four-pointed AI sparkle where the line turns. Flat geometric vector style, thick even strokes, rounded ends. White symbol on a solid terracotta #C96442 rounded square. Perfectly centred, simple enough to read at 24 pixels. No text, no letters, no gradients, no shadows, no mockup.*
