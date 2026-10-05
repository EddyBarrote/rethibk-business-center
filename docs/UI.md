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

## Imagens geradas

Geradas com a skill nano banana (Gemini), redimensionadas para 1400 px e convertidas para WebP (`cwebp -q 80`). Têm a marca de água invisível SynthID.

- `public/images/auth/login-hero.webp` (`-m pro -s 2K -a 3:4`): *An abstract editorial illustration for the sign-in screen of an AI agent business platform. Soft warm composition of floating rounded geometric shapes and thin connecting lines, like a calm network of nodes and cards orbiting a central glowing point, suggesting coordinated agents at work. Matte paper texture, gentle grain, warm light from the upper left. Palette: cream and ivory background, terracotta #C96442, muted clay, warm sand and a little charcoal. Plenty of breathing room, the lower third quieter. No text, no letters, no people, no logos, no watermark.*
- `public/images/auth/login-hero-dark.webp` (edição da anterior, `-i login-hero`): *Keep exactly the same composition, shapes, orbits and positions. Change only the lighting and palette to a night version: deep warm charcoal background (#2B2A27), the shapes in terracotta, clay and sand glowing softly, the thin orbit lines as faint warm light. Same paper grain. No text, no watermark.*
- Logótipo (conceito, não usado tal e qual; a versão final é o SVG em `Components/RethinkMark.tsx`): *A modern minimal app icon logo mark for an AI agents and workflow automation platform. Three small rounded nodes connected by one smooth flowing line, forming a simple workflow path, with a small four-pointed AI sparkle where the line turns. Flat geometric vector style, thick even strokes, rounded ends. White symbol on a solid terracotta #C96442 rounded square. Perfectly centred, simple enough to read at 24 pixels. No text, no letters, no gradients, no shadows, no mockup.*
