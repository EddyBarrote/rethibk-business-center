# Pacote da empresa

Um pacote configura uma empresa de uma vez: marca e logótipo, departamentos, onde fica cada agente dos modelos e o que diz de si, os factos da Memória da empresa e os artigos da base de conhecimento. Faz-se a partir dos documentos que a empresa entrega (perfil, catálogos, papel timbrado).

**O pacote nunca entra no repositório.** Tem dados do cliente e o repositório não é o sítio deles. Guarda-se numa pasta privada e copia-se para o servidor quando é preciso. O da MICOMOC está em `business-center/micomoc-docs/pack/`, ao lado dos documentos de onde saiu.

```bash
php artisan tenant:load-pack micomoc /caminho/do/pacote            # agentes novos activos
php artisan tenant:load-pack micomoc /caminho/do/pacote --draft    # agentes novos em rascunho (produção)
```

Corre depois do `tenant:create`, e outra vez sempre que o pacote muda:

- **Antes de escrever, verifica tudo.** Com um erro (uma cor mal escrita, uma letra que a marca não tem, um agente num departamento que não está na lista), diz o que corrigir e não escreve nada.
- **Pode correr-se de novo.** O mesmo pacote outra vez não muda nada. Um pacote editado actualiza o que nomeia e deixa o resto da empresa como está. Fica `tenant.pack_loaded` na auditoria, com o que mudou.

## Ficheiros

```
pacote/
├── company.json        obrigatório
├── facts.json          opcional
├── logo.png            opcional (PNG ou JPG; o nome vem de company.json)
└── knowledge/*.md      opcional, um artigo por ficheiro
```

### `company.json`

```json
{
  "source": "De onde vem o pacote (vai para a auditoria)",
  "brand": {
    "color": "#8F1933",
    "accent_color": "#D69E29",
    "font": "Montserrat",
    "footer": "Rodapé dos documentos",
    "logo": "logo.png"
  },
  "owner_department": "Direcção-Geral",
  "departments": [
    { "name": "Direcção-Geral" },
    { "name": "Procurement", "parent": "Direcção-Geral" }
  ],
  "remove_empty_departments": true,
  "agents": {
    "procurement": {
      "department": "Procurement",
      "description": "O que o agente faz, como aparece nas listas",
      "suggestions": ["Perguntas de exemplo na conversa"]
    }
  }
}
```

| Campo | O que faz |
|---|---|
| `brand` | Junta-se à Marca (Definições › Marca). `color` e `accent_color` em `#RRGGBB`; `font` é uma das letras de `App\Documents\Brand::FONTS`. O logótipo vai para `brand/{tenant}-pack-logo.png`, e um logótipo anterior é apagado. |
| `departments` | Cada um é encontrado pelo nome ou pelo endereço (slug), e criado se não existir. `parent` é outro departamento da lista. |
| `owner_department` | O departamento do proprietário, se ele ainda não tiver um. |
| `remove_empty_departments` | Apaga os departamentos que não estão na lista e que ninguém usa (sem pessoas, agentes nem subdepartamentos), como os que os modelos criam: Direcção Comercial, Financeira, etc. |
| `agents` | Chaves dos modelos: `triage`, `chief_of_staff`, `finance`, `procurement`, `hr`, `client_manager`. Instala os que faltam (com `--draft`, em rascunho); um que já existe mantém o estado. `department` muda o departamento e a chefia passa a ser a chefia desse departamento ou, sem ela, o proprietário. Um agente que lê salários e dados de pessoal só responde a quem os pode ver. `description` e `suggestions` substituem os do modelo; sem eles, ficam os do modelo. |

### `facts.json`

```json
[
  { "section": "about", "content": "O facto, com a fonte entre parênteses." },
  { "section": "rules", "content": "Uma regra que a empresa ainda tem de confirmar.", "status": "pending" }
]
```

- **`section`:** `about`, `clients`, `rules`, `pricing`, `people` ou `decisions`.
- **`status`:**
  - `published` (o valor por omissão) entra na Memória da empresa já revisto pelo proprietário.
  - `pending` fica à espera de revisão em Memória da empresa. Usa-se para o que vem de inferência, ou para dados que os documentos contradizem.
- **Repetidos:** um facto com o mesmo texto não se repete.

### `knowledge/*.md`

```markdown
---
title: Catálogo MICOMOC: protecção auditiva
summary: Uma frase: o que o artigo cobre e quando o agente o deve ler.
folder: Produtos e serviços/Protecção auditiva
domain: geral
---
# O texto do artigo em Markdown
```

- **Unicidade:** um artigo por título.
- **Carregamentos seguintes:** um texto que mudou substitui o que lá está e volta a ser indexado. As pastas (`A/B`) criam-se na área `domain`, que é `geral` quando falta.
- **Visibilidade:** os artigos ficam publicados e visíveis para toda a empresa.

## O que o pacote não faz

- **Pessoas:** as pessoas reais entram em Definições › Utilizadores, com papel e chefia; o pacote não cria contas.
- **ERP, caixas de email e chaves:** ligam-se em Definições, com as credenciais, que nunca vão no pacote.
- **Autonomia, regras de aprovação e limites de valor:** ficam com a lista de entrada em produção (`docs/DEPLOY.md`, secção 18).
