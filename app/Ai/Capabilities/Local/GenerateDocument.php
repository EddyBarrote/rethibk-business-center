<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Autonomy\PeopleData;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\AgentKnowledgeWriter;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeLocator;
use App\Ai\Runs\QuestionService;
use App\Ai\Skills\AgentSkills;
use App\Documents\Brand;
use App\Documents\DocumentFormat;
use App\Documents\DocumentGenerator;
use App\Documents\DocumentSpec;
use App\Documents\DocumentTemplate;
use App\Documents\DocumentType;
use App\Enums\AutonomyLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Produces a Word document, a PowerPoint presentation, an Excel workbook or
 * a PDF from Markdown, with the organisation's branding. The file stays in
 * the console (Ficheiros); it never leaves the platform by itself.
 */
final class GenerateDocument extends LocalCapability
{
    public function __construct(
        private readonly DocumentGenerator $generator,
        private readonly KnowledgeAccess $access,
        private readonly KnowledgeLocator $locator,
        private readonly AgentKnowledgeWriter $writer,
        private readonly AgentSkills $skills,
        private readonly QuestionService $questions,
    ) {}

    public function key(): string
    {
        return 'documents.generate';
    }

    public function name(): string
    {
        return 'Gerar documento';
    }

    public function description(): string
    {
        return 'Gera um ficheiro com a marca da organização a partir de markdown: docx (Word), pptx (apresentação: um diapositivo por título ## ou por linha ---, com listas e tabelas curtas), xlsx (Excel: cada tabela markdown vira uma folha com o nome do título acima; números em formato 1 200,50) ou pdf. '
            .'Quando uma das tuas skills é um tipo de documento (proposta, relatório, carta…), carrega-a com skills.load, segue as instruções dela e passa a chave em skill: o ficheiro fica com o modelo, o estilo e as imagens desse tipo. '
            .'Imagens (assinatura, carimbo, logótipos) entram no markdown como ![Assinatura](asset:assinatura). Se faltar alguma, a plataforma pede-a à pessoa e a execução pausa até responder; depois chama de novo com os mesmos argumentos. '
            .'Devolve a ligação para pré-visualizar e descarregar; numa conversa ou tarefa, o ficheiro vai também anexado à tua resposta. Pode também arquivá-lo na base de conhecimento.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Observe;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'format' => $schema->string()->enum(array_column(DocumentFormat::cases(), 'value'))->required(),
            'title' => $schema->string()->description('Título do documento (também o nome do ficheiro).')->required(),
            'content' => $schema->string()->description('O conteúdo em markdown.')->required(),
            'template' => $schema->string()->enum(array_column(DocumentTemplate::cases(), 'value'))->description('Word e PDF: documento (por omissão), relatorio (com capa) ou carta. Com skill, por omissão o modelo da skill.'),
            'skill' => $schema->string()->description('A chave da skill do tipo de documento que segues (ex.: proposta-comercial).'),
            'without_assets' => $schema->array()->items($schema->string())->description('Chaves de imagens que a pessoa disse para dispensar; o documento sai sem elas.'),
            'subtitle' => $schema->string()->description('Subtítulo opcional (ex.: departamento ou período).'),
            'file_in_knowledge' => $schema->boolean()->description('Arquivar também na base de conhecimento.'),
            'domain' => $schema->string()->description('Domínio onde arquivar (com file_in_knowledge).'),
            'folder' => $schema->string()->description('Pasta onde arquivar (com file_in_knowledge).'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'format' => 'required|in:'.implode(',', array_column(DocumentFormat::cases(), 'value')),
            'title' => 'required|string|max:200',
            'content' => 'required|string|max:200000',
            'template' => 'nullable|in:'.implode(',', array_column(DocumentTemplate::cases(), 'value')),
            'skill' => 'nullable|string|max:120',
            'without_assets' => 'nullable|array|max:20',
            'without_assets.*' => 'string|max:120',
            'subtitle' => 'nullable|string|max:200',
            'file_in_knowledge' => 'nullable|boolean',
            'domain' => 'nullable|string|max:120',
            'folder' => 'nullable|string|max:255',
        ])->validate();

        $type = null;

        if (filled($data['skill'] ?? null)) {
            $skill = $this->skills->find($context->agent, (string) $data['skill']);

            if ($skill === null) {
                return CapabilityResult::error('não tens nenhuma skill com essa chave. Vê a lista das tuas skills nas instruções, ou gera sem skill.');
            }

            $type = DocumentType::fromSkill($skill);
            $missing = $type->missing($data['content'], Brand::current(), $data['without_assets'] ?? []);

            if ($missing !== []) {
                $asked = $this->questions->askForAssets($missing, $data['title'], $context);

                if (is_string($asked)) {
                    return CapabilityResult::error('faltam imagens ('.implode(', ', array_column($missing, 'key')).') e '.$asked);
                }

                return CapabilityResult::text('Faltam imagens para este documento: '.implode(', ', array_map(fn (array $m) => "{$m['label']} (asset:{$m['key']})", $missing)).'. '
                    .'Pedi-as a '.($asked->askedUser->name ?? 'a pessoa').' num cartão na conversa; a execução fica em pausa e continua quando responder. '
                    .'Termina já a tua resposta com uma frase curta; não chames outras ferramentas.');
            }
        }

        $template = DocumentTemplate::tryFrom((string) ($data['template'] ?? '')) ?? $type->template ?? DocumentTemplate::Document;
        $spec = new DocumentSpec($data['title'], $data['content'], $template, $data['subtitle'] ?? null, type: $type);

        try {
            $document = $this->generator->generate(DocumentFormat::from($data['format']), $spec, $context->agent, $context->run);
        } catch (Throwable $e) {
            report($e);

            return CapabilityResult::error('Não foi possível gerar o ficheiro: '.$e->getMessage());
        }

        $result = [
            'document_id' => $document->id,
            'filename' => $document->filename,
            'link' => "/documents/{$document->id}",
            'download' => "/documents/{$document->id}/download",
        ];

        // A confidential agent's files stay out of the knowledge base, which people without the permission read (PeopleData).
        if (($data['file_in_knowledge'] ?? false) && PeopleData::agentHolds($context->agent)) {
            return CapabilityResult::data([...$result, 'knowledge' => 'Não arquivado: o teu trabalho tem dados de pessoal e não vai para a base de conhecimento.']);
        }

        if ($data['file_in_knowledge'] ?? false) {
            $this->access->ensureDefaults();
            $domain = $this->locator->domain($data['domain'] ?? 'Geral') ?? $this->locator->domain('geral');

            if ($domain === null || ! $this->access->canOpen($context->agent, $domain)) {
                return CapabilityResult::data([...$result, 'knowledge' => 'Não arquivado: domínio não encontrado ou sem acesso.']);
            }

            $folder = filled($data['folder'] ?? null) ? $this->locator->folder($domain, $data['folder'], create: true) : null;
            $item = $this->generator->fileInKnowledge($document, $context->agent, [
                'knowledge_domain_id' => $domain->id,
                'knowledge_folder_id' => $folder?->id,
                'status' => AgentKnowledgeWriter::statusFor($context->agent),
            ]);
            $this->writer->announce($context->agent, $item, $domain);
            $result['knowledge_item_id'] = $item->id;
            $result['knowledge_status'] = $item->isPublished() ? 'publicado' : 'à espera de revisão por uma pessoa';
        }

        return CapabilityResult::data($result);
    }

    public function summarise(array $arguments): string
    {
        return 'Gerar '.strtoupper((string) ($arguments['format'] ?? '')).': '.($arguments['title'] ?? '');
    }
}
