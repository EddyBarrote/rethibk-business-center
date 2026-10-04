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
