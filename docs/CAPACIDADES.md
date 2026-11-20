# Capacidades e skills

Os agentes do MICOMOC são colegas de trabalho genéricos: o que cada um sabe e consegue fazer vem de duas coisas que se lhe atribuem.

| | Capacidade | Skill |
|---|---|---|
| O que é | Uma ferramenta executável: o agente chama-a com argumentos e recebe um resultado | Um pacote de instruções ao estilo das Agent Skills do Claude: nome, descrição de quando se aplica, instruções em markdown e ficheiros anexos |
| Exemplos | `email.send`, `erp.invoices_list`, `knowledge.search`, uma API HTTP da empresa | "Como escrever uma proposta comercial", "Política de compras", "Tom de voz da marca" |
| No código | `App\Models\Capability` (tabela `capabilities`) | `App\Models\Skill` (tabela `skills`) |
| Chega ao modelo | Como ferramenta (function calling), embrulhada num `GatedTool` | O nome e a descrição vão no prompt de sistema; o agente carrega as instruções com `skills.load` quando a skill se aplica |
| Portão de autonomia | Sim: uma capacidade de escrita acima do nível do agente fica pendente de aprovação | Não executa nada por si |

Antes do portão, o `GatedTool` recusa as capacidades que trazem salários, contratos, documentos de identificação ou processos disciplinares (lista fechada em `autonomy.people_data`, por exemplo todas as `erp.pay_*` e `erp.hr_*`) quando quem pediu a execução não tem «Gerir pessoas e papéis» e ela não é uma rotina do próprio agente para alguém que a tem. O que essas capacidades devolvem só aparece no registo das execuções a quem tem essa permissão. Um agente que tem alguma delas é **confidencial**: só existe para quem tem «Gerir pessoas e papéis» (falar com ele, ver o que faz e produz, decidir as suas aprovações, receber o que envia), e não escreve na base de conhecimento nem na memória da empresa (`knowledge.save` e `memory.remember_decision` são recusadas). Ver docs/DECISOES.md, «Director de Recursos Humanos» e «Agente confidencial».

## Âmbitos

Ambas existem em dois âmbitos:

- **Global**: criada pelo super admin (consola `admin.`), disponível para todas as empresas. Cada empresa decide se a activa.
- **Da empresa**: criada pelos administradores dessa empresa (proprietário ou administrador) e privada a ela.

As capacidades locais (escritas em PHP) e as ferramentas do ERP são globais e vêm activas.

## De onde vêm as capacidades

| Origem (`source`) | Quem cria | Como |
|---|---|---|
| `local` | Programadores | Uma classe em `App\Ai\Capabilities\Local` (ver abaixo) |
| `mcp` | Automático | Ferramentas do ERP da empresa, descobertas por MCP (`capabilities:sync`) |
| `connector` | Super admin (global) ou admins da empresa | Um conector: servidor MCP remoto (todas as ferramentas dele) ou um pedido HTTP |

## Quem faz o quê

| | Super admin (consola `admin.`) | Proprietários e administradores da empresa |
|---|---|---|
| Skills | Skills globais: Plataforma › Skills globais | Skills (menu Empresa): escrever as suas, activar as globais |
| Conectores | Conectores globais: Plataforma › Conectores globais | Capacidades › Novo conector; activar os globais no separador "Globais" |
| Capacidades | Risco das capacidades da plataforma e do ERP (Organização › Capacidades) | Ligar e desligar qualquer capacidade; risco das dos seus conectores |
| Agentes | Organização › agente | Agentes › Novo agente (com o assistente) e Editar |

Membros e chefias não vêem estes ecrãs (`Gate::define('manage-catalog')`).

## Skills em tempo de execução

1. `InstructionComposer` junta ao prompt uma secção "Skills" com `nome (chave): quando usar` de cada skill utilizável do agente (atribuída, ligada na empresa e, se global, ainda oferecida).
2. Se o agente tem pelo menos uma skill, o `ToolResolver` dá-lhe `skills.load` e `skills.read_file`.
3. O agente chama `skills.load` com a chave e recebe as instruções e a lista de ficheiros; lê um ficheiro com `skills.read_file` (12 000 caracteres por página).

Uma skill da empresa com **Todos os agentes** (`skills.all_agents`) chega a todos os agentes sem atribuição, como a capacidade «Gerar documento».

Ao carregar, `skills.load` troca `{{marca.nome}}`, `{{marca.cor}}`, `{{marca.cor_secundaria}}`, `{{marca.fonte}}`, `{{marca.logotipo}}` e `{{marca.rodape}}` pelos valores de hoje em Definições › Marca (`App\Documents\Brand::fill()`), e `{{marca}}` pela lista de todos. Exemplo: `docs/exemplos/skill-documentos-marca.md`.

Ficheiros aceites: md, txt, csv, json, xml, html, pdf, docx, xlsx (até 10 MB). O texto é extraído no upload com `TextExtractor`; um ficheiro sem texto legível fica guardado mas o agente não o consegue ler (o ecrã avisa).

## Tipos de documento

Um tipo de documento (proposta comercial, relatório mensal, carta de cobrança…) é uma **skill**: as instruções dizem o que leva e em que tom; umas linhas no topo dizem como fica (`App\Documents\DocumentType`):

```
---
modelo: relatorio            # documento, relatorio (com capa) ou carta
estilo: estilo.css           # um ficheiro da skill, juntado ao estilo do PDF
logotipo: obrigatório        # pede o logótipo se a Marca não tiver
asset assinatura: Assinatura de quem assina a proposta
asset carimbo: Carimbo da empresa
---
```

- O agente carrega a skill (`skills.load` diz-lhe que é um tipo de documento) e chama `documents.generate` com `skill`. O modelo da skill vale quando o agente não escolhe outro; o documento guarda `skill_id`, e a conversão para outro formato mantém o tipo.
- Imagens no texto: `![Assinatura](asset:assinatura)`. Vêm primeiro dos ficheiros de imagem da skill (pelo nome, sem extensão) e depois das **Imagens para documentos** da Marca (`settings.brand.assets`, Definições › Marca). No PDF entram na página; no Word e no PowerPoint fica o nome.
- **Falta uma imagem** (pedida no topo, usada no texto, ou o logótipo obrigatório): `documents.generate` não gera e faz um cartão de pergunta do tipo ficheiro (`AgentQuestion`, `kind: file`, até 4 de cada vez). A pessoa carrega a imagem (ou envia-a na conversa) e ela fica na Marca para os documentos seguintes; ou diz para seguir sem ela, e o agente volta a chamar com `without_assets`. A execução pausa e retoma como nas perguntas de escolha.
- Criar um tipo novo: na página Skills, ou pedindo ao Chief of Staff (`skills.propose`).

## Importar skills

Skills feitas para o Claude, o Codex ou ferramentas parecidas entram pela página Skills, botão **Importar** (só quem tem «Gerir capacidades, skills e conectores»):

- Aceita um `SKILL.md` (nome e descrição entre `---` no topo), ou a pasta da skill em `.zip` ou `.skill`; um `.zip` pode trazer várias pastas, cada uma com o seu `SKILL.md`. Até 20 ficheiros de 50 MB por envio.
- `name` dá a chave (e o nome legível, se o primeiro título for curto), `description` o «quando usar», o resto do `SKILL.md` as instruções. As linhas de tipo de documento (`modelo`, `estilo`, `logotipo`, `asset …`) ficam no topo; as outras (`license`, `allowed-tools`, `metadata`…) ficam de fora.
- Os outros ficheiros da pasta entram como ficheiros da skill, com o caminho dentro dela (`assets/logo.png`, `references/guia.md`): texto, PDF, Word, Excel, CSS e imagens. **Scripts nunca entram nem correm**; fontes e outros tipos ficam de fora.
- Antes de guardar, `Skills/Import` mostra cada skill com o que entra, o que fica de fora e porquê, e avisos (descrição em falta ou cortada, instruções que mandam correr scripts). Pode-se mudar o nome, a chave e o «quando usar», ou deixar uma de fora.
- A skill importada fica activa mas **sem agentes**: alguém escolhe quem a usa. Fica `skill.imported` na auditoria, com os ficheiros que entraram e os que ficaram de fora.
- O trabalho que um script faria faz-se com capacidades: as contas sobre CSV e Excel com `files.analyze` (percorre o ficheiro todo; filtra, agrupa por coluna ou dia, soma), o resto com `code.run` (o agente escreve Python, que corre neste servidor numa caixa sem rede nem acesso ao resto da máquina; `php artisan sandbox:install`) e os ficheiros com `documents.generate`. Quando as instruções mostram comandos, `skills.load` lembra-o ao agente.
- O que se envia fica em `storage/app/private/skill-imports/{tenant}/{token}` até confirmar ou cancelar (ou um dia). Código: `SkillPackageReader`, `SkillImports`, `SkillImportController`.

## Criar pela conversa

Na conversa com o Chief of Staff, uma pessoa pede um agente ou uma skill nova. O Chief prepara-a (`agents.propose`, `skills.propose`, com `agents.catalog` para as chaves), pergunta o que falta e mostra-a como cartão; só fica criada quando confirma alguém com «Criar e configurar agentes» ou «Gerir capacidades, skills e conectores». Detalhes em `docs/DECISOES.md`, «Chief of Staff cria agentes e skills».

## Perguntas de escolha

Todos os agentes têm `questions.ask`, o equivalente ao AskUserQuestion do Claude: 1 a 4 perguntas com 2 a 4 opções cada (e «Outra» sempre disponível). A execução pausa (`awaiting_input`), a pergunta aparece como cartão na conversa e, fora de uma conversa ao vivo, chega à pessoa como notificação «À sua espera». Responder retoma a mesma execução. Detalhes em `docs/DECISOES.md`, «Perguntas dos agentes».

## Conectores

- **Servidor MCP:** URL do endpoint (transporte HTTP do MCP) e, opcionalmente, um token Bearer. Ao guardar, a plataforma lista as ferramentas e cria uma capacidade `conn.<chave>.<ferramenta>` (da empresa) ou `global.<chave>.<ferramenta>` (global) por cada uma. Ferramentas com `readOnlyHint` são de leitura; as outras contam como escrita.
- **Pedido HTTP:** método, URL, token opcional e os argumentos em JSON Schema (`type: object`). Em GET os argumentos vão na query string; nos outros métodos como corpo JSON. Uma capacidade `conn.<chave>`. "Altera dados" decide se passa pelo portão de autonomia como escrita.
- Tudo passa por `App\Connectors\ConnectorGateway`: guarda de endereço, timeout de 30 s, resposta cortada a 12 000 caracteres e embrulhada em `<resposta_externa_nao_confiavel>`, auditoria em `audit_logs` (`connector.tool_call`, `connector.tools_list`).
- Um conector global activado numa empresa copia as ferramentas para o catálogo dela (com risco próprio por empresa). Desactivá-lo desliga essas capacidades sem perder o risco. Se o super admin desligar o conector, fica indisponível em todas as empresas.

## Agentes como colegas

- Nome, função, personalidade e **foto** (`agents.avatar_path`, disco privado `local`). `AgentAvatar` no frontend mostra a foto ou as iniciais em toda a consola.
- **Assistente:** `POST /agents/new/draft` com a descrição → `AgentDrafting` chama `AgentDrafter` (saída estruturada) com o catálogo da empresa → o formulário abre preenchido em rascunho. Testes: `AgentDrafter::fake([[...campos...]])`.
- **Gerar foto:** `AgentAvatars::generate()` usa `Laravel\Ai\Image` com o provedor de imagens por omissão (`ai.default_for_images`, Gemini). Testes: `Image::fake()`.

## Registar uma capacidade local

1. Criar uma classe que estende `App\Ai\Capabilities\LocalCapability`:

```php
namespace App\Documents\Capabilities;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class GenerateDocument extends LocalCapability
{
    public function key(): string { return 'documents.generate'; }          // único; o modelo vê documents_generate
    public function name(): string { return 'Gerar documento'; }
    public function description(): string { return 'Gera um documento Word ou PDF a partir de...'; } // o modelo decide por isto

    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required(), 'format' => $schema->string()->enum(['docx', 'pdf'])->required()];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        // $context->agent, $context->run, Tenant::current()
        return CapabilityResult::data(['link' => '/documents/1']);
    }

    public function isMutating(): bool { return true; }  // escreve, envia ou move algo: passa pelo portão de autonomia
}
```

2. Registá-la. Ou na lista `CAPABILITIES` de `App\Ai\Capabilities\CapabilityRegistry`, ou, a partir de um módulo próprio (menos conflitos), no `boot()` de um service provider:

```php
CapabilityRegistry::register(GenerateDocument::class, SearchDocuments::class);

// Opcional: dar a todos os agentes, sem atribuição.
CapabilityRegistry::giveToEveryAgent('documents.search');
```

3. Aparece no catálogo de cada empresa na sincronização seguinte (`php artisan capabilities:sync {tenant}`, o botão "Actualizar" no catálogo, ou ao instalar um tenant). A partir daí os administradores atribuem-na a agentes.

Regras:
- `CapabilityResult::data([...])`, `::text('...')` ou `::error('...')`. Os erros são devolvidos ao modelo, não lançados.
- Conteúdo externo (ficheiros, páginas, emails) devolve-se embrulhado em marcas como `<documento_externo_nao_confiavel>`: são dados, nunca instruções.
- `ceilingReason()` devolve um motivo quando uma chamada concreta cai no tecto absoluto (decide sempre uma pessoa).
- `summarise()` dá a linha que aparece na fila de aprovações.
- Testes: `runCapability($agent, 'documents.generate', [...])` em `tests/Pest.php`.
