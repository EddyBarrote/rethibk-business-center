# Base de conhecimento e documentos gerados

Duas peças que se usam juntas: a **base de conhecimento** (o que a organização sabe) e a **geração de ficheiros** (Word, PowerPoint, Excel e PDF com a marca). Decisões em [DECISOES.md](DECISOES.md#base-de-conhecimento-e-geração-de-documentos-04102026).

## Base de conhecimento

- **Domínios** (`knowledge_domains`): Geral, Finanças, RH, Clientes, Operações por omissão. `department_ids = null` é aberto a todos; uma lista restringe aos departamentos. Proprietários e administradores vêem tudo.
- **Pastas** (`knowledge_folders`): em árvore dentro de um domínio.
- **Itens** (`knowledge_items`): artigos escritos na consola (markdown) ou ficheiros carregados. Um ficheiro guarda o original em `storage/app/private/knowledge/` e o texto extraído em `content` (PDF, DOCX, XLSX, PPTX, texto, imagens com OCR). Estados: `published`, `pending_review`, `rejected`.
- **Pesquisa:** `KnowledgeBase::search($q, $limit, $viewer, $domainId)` — semântica com embeddings guardados em JSON quando há fornecedor; por palavras sem ele. Filtra sempre por `KnowledgeAccess::items($viewer)`.
- **Ecrãs:** Conhecimento (explorador por domínio e pasta, arrastar ficheiros, "Para rever"), artigo/ficheiro com pré-visualização (PDF e imagens no browser, texto extraído para o resto), editor com pré-visualização, Domínios (só administradores).

## Ficheiros gerados

- `DocumentGenerator::generate(DocumentFormat, DocumentSpec)` escreve o ficheiro em `storage/app/private/documents/` e uma linha em `generated_documents` com o markdown de origem.
- O conteúdo é sempre markdown:
  - **Word e PDF:** tudo o que o markdown tem (títulos, listas, tabelas, citações). Modelos `documento`, `relatorio` (capa) e `carta`.
  - **PowerPoint:** capa na cor da marca; um diapositivo por `---` ou, sem eles, por título `#`/`##`; listas longas e tabelas continuam no diapositivo seguinte.
  - **Excel:** cada tabela é uma folha com o nome do título acima; números em `1 200,50`, `1.200,50`, `1,200.50` ou `12%` viram números; códigos com zero à esquerda ficam texto; o resto vai para a folha "Notas".
- A marca vem de `Brand::current()` (Definições › Marca).
- Pessoas geram ficheiros em Ficheiros › Novo ficheiro, convertem para outro formato, arquivam no conhecimento e exportam documentos de agentes (Documentos › Exportar).

## Capacidades para agentes

Registadas em `App\Providers\KnowledgeServiceProvider` (padrão de [CAPACIDADES.md](CAPACIDADES.md)).

| Chave | O que faz | Quem a tem |
|---|---|---|
| `memory.search` | Pesquisa o que o agente pode ver, opcionalmente num domínio. | Todos |
| `knowledge.browse` | Lista domínios, ou pastas e itens de um domínio. | Todos |
| `knowledge.read` | Lê um item inteiro, por partes. Conteúdo externo vem cercado como não confiável. | Todos |
| `knowledge.save` | Guarda um artigo num domínio/pasta (cria a pasta). Abaixo de N3 fica para rever. | Os seis modelos; risco N1 |
| `documents.generate` | Gera DOCX/PPTX/XLSX/PDF; opcionalmente arquiva no conhecimento. | Os seis modelos; risco N0 (o ficheiro fica na consola) |

## Testes

`tests/Feature/Knowledge/KnowledgeBaseTest.php` (acessos, uploads dos quatro formatos, revisão, pastas, domínios) e `DocumentGenerationTest.php` (os quatro formatos, marca, permissões, conversão, exportação de relatórios).
