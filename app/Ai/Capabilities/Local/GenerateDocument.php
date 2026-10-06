<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\AgentKnowledgeWriter;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeLocator;
use App\Documents\DocumentFormat;
use App\Documents\DocumentGenerator;
use App\Documents\DocumentSpec;
use App\Documents\DocumentTemplate;
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
        return 'Gera um ficheiro com a marca da organização a partir de markdown: docx (Word), pptx (apresentação: um diapositivo por título ## ou por linha ---, com listas e tabelas curtas), xlsx (Excel: cada tabela markdown vira uma folha com o nome do título acima; números em formato 1 200,50) ou pdf. Devolve a ligação para pré-visualizar e descarregar. Pode também arquivá-lo na base de conhecimento.';
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
            'template' => $schema->string()->enum(array_column(DocumentTemplate::cases(), 'value'))->description('Word e PDF: documento (por omissão), relatorio (com capa) ou carta.'),
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
            'subtitle' => 'nullable|string|max:200',
            'file_in_knowledge' => 'nullable|boolean',
            'domain' => 'nullable|string|max:120',
            'folder' => 'nullable|string|max:255',
        ])->validate();

        $spec = new DocumentSpec($data['title'], $data['content'], DocumentTemplate::from($data['template'] ?? 'documento'), $data['subtitle'] ?? null);

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
