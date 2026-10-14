# Base de conhecimento e documentos gerados

Duas peças que se usam juntas: a **base de conhecimento** (o que a organização sabe) e a **geração de ficheiros** (Word, PowerPoint, Excel e PDF com a marca). Decisões em [DECISOES.md](DECISOES.md#base-de-conhecimento-e-geração-de-documentos-04102026).

## Base de conhecimento

- **Domínios** (`knowledge_domains`): Geral, Finanças, RH, Clientes, Operações por omissão. `department_ids = null` é aberto a todos; uma lista restringe aos departamentos. Proprietários e administradores vêem tudo.
- **Pastas** (`knowledge_folders`): em árvore dentro de um domínio.
- **Itens** (`knowledge_items`): artigos escritos na consola (markdown) ou ficheiros carregados. Um ficheiro guarda o original em `storage/app/private/knowledge/` e o texto em `content`, lido na fila por `FileReader` (`ReadKnowledgeFile`): PDF página a página (`knowledge_item_pages`, linhas `[Página N]`), as páginas digitalizadas e as imagens sem texto pelo modelo (`PageReader`), DOCX, XLSX, PPTX e texto pelo `TextExtractor`. Versões anteriores em `knowledge_item_versions`. Estados: `published`, `pending_review`, `rejected`; leitura em `reading_status`.
- **Pesquisa:** `KnowledgeBase::search($q, $limit, $viewer, $domainId)` — híbrida: vectores (768 dimensões, float32 em `knowledge_embeddings.vector`, com a página de cada parte) e palavras (exactas primeiro, depois o índice de texto completo), juntos por reciprocal rank fusion. Sem fornecedor de embeddings, só palavras. Filtra sempre por `KnowledgeAccess::items($viewer)`. Detalhe em [DECISOES.md](DECISOES.md#repositório-de-ficheiros-da-empresa-14102026).
- **Ecrãs:** Conhecimento (explorador por domínio e pasta, arrastar ficheiros, "Para rever"), artigo/ficheiro com pré-visualização (PDF e imagens no browser, texto extraído para o resto), editor com pré-visualização, Domínios (só administradores).

## Memória da empresa

Factos que todos os agentes lêem sempre, em seis secções (`company_facts`), em Conhecimento › Memória da empresa. Os agentes propõem e uma pessoa aprova. Detalhe em [DECISOES.md](DECISOES.md#memória-da-empresa-12102026).

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
| `memory.read` | Lê inteira uma entrada da memória do agente com a pessoa da execução (o prompt só traz o índice). | Todos |
| `memory.search_chats` | Procura nas conversas e tarefas anteriores da pessoa com este agente, se a pessoa o permitir. | Todos |
| `knowledge.browse` | Lista domínios, ou pastas e itens de um domínio. | Todos |
| `knowledge.read` | Lê um item inteiro, por partes, ou só umas páginas de um PDF (`pages`). Conteúdo externo vem cercado como não confiável. | Todos |
| `knowledge.view` | Pergunta sobre as páginas originais de um PDF ou imagem; o modelo vê-as e responde (custo no orçamento do agente). | Todos |
| `knowledge.file` | Guarda no repositório um anexo da conversa ou do email em que o agente está. Abaixo de N3 fica para rever. | Todos; risco N1 |
| `knowledge.save` | Guarda um artigo num domínio/pasta (cria a pasta). Abaixo de N3 fica para rever. | Os seis modelos; risco N1 |
| `company.propose_fact` | Propõe um facto à memória da empresa; uma pessoa aprova. | Todos |
| `documents.generate` | Gera DOCX/PPTX/XLSX/PDF; opcionalmente arquiva no conhecimento. | Os seis modelos; risco N0 (o ficheiro fica na consola) |

## Testes

`tests/Feature/Knowledge/KnowledgeBaseTest.php` (acessos, uploads dos quatro formatos, revisão, pastas, domínios), `FileRepositoryTest.php` (repositório de ficheiros) e `DocumentGenerationTest.php` (os quatro formatos, marca, permissões, conversão, exportação de relatórios).
