<?php

namespace App\Ai\Skills\Local;

use App\Ai\Knowledge\AgentKnowledgeWriter;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Knowledge\KnowledgeLocator;
use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\AutonomyLevel;
use App\Enums\KnowledgeType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * An agent writes new knowledge: a procedure it learned, a client fact, a
 * pattern it noticed. Marked as written by the agent; below N3 it waits for
 * a person's review before other agents see it.
 */
final class SaveKnowledge extends LocalSkill
{
    private const TYPES = ['article', 'pattern', 'entity_note', 'meeting_brief', 'decision'];

    public function __construct(
        private readonly KnowledgeBase $knowledge,
        private readonly KnowledgeAccess $access,
        private readonly KnowledgeLocator $locator,
        private readonly AgentKnowledgeWriter $writer,
    ) {}

    public function key(): string
    {
        return 'knowledge.save';
    }

    public function name(): string
    {
        return 'Guardar na base de conhecimento';
    }

    public function description(): string
    {
        return 'Guarda informação nova na base de conhecimento, num domínio e pasta: um procedimento, um facto sobre um cliente ou fornecedor, um padrão observado, o resumo de uma reunião. Escreva em markdown, com contexto suficiente para outra pessoa perceber. Não guarde dados pessoais sensíveis fora do domínio de RH.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Suggest;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Título curto e específico.')->required(),
            'content' => $schema->string()->description('O conteúdo, em markdown.')->required(),
            'domain' => $schema->string()->description('Domínio (use knowledge.browse para ver os que existem). Por omissão "Geral".'),
            'folder' => $schema->string()->description('Pasta dentro do domínio, ex.: "Clientes/Mozal". É criada se não existir.'),
            'type' => $schema->string()->enum(self::TYPES)->description('article (por omissão), pattern, entity_note, meeting_brief ou decision.'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:200000',
            'domain' => 'nullable|string|max:120',
            'folder' => 'nullable|string|max:255',
            'type' => 'nullable|in:'.implode(',', self::TYPES),
        ])->validate();

        $this->access->ensureDefaults();
        $agent = $context->agent;
        $domain = $this->locator->domain($data['domain'] ?? 'Geral') ?? $this->locator->domain('geral');

        if ($domain === null || ! $this->access->canOpen($agent, $domain)) {
            return SkillResult::error('Domínio não encontrado ou sem acesso. Veja os domínios com knowledge.browse.');
        }

        $folder = filled($data['folder'] ?? null) ? $this->locator->folder($domain, $data['folder'], create: true) : null;

        $item = $this->knowledge->remember(KnowledgeType::from($data['type'] ?? 'article'), $data['title'], $data['content'], $agent, [
            'knowledge_domain_id' => $domain->id,
            'knowledge_folder_id' => $folder?->id,
            'status' => AgentKnowledgeWriter::statusFor($agent),
            'source_type' => $context->run->getMorphClass(),
            'source_id' => $context->run->id,
        ]);

        $this->writer->announce($agent, $item, $domain);

        return SkillResult::data([
            'knowledge_item_id' => $item->id,
            'link' => "/knowledge/{$item->id}",
            'domain' => $domain->name,
            'folder' => $folder?->path(),
            'status' => $item->isPublished() ? 'publicado' : 'à espera de revisão por uma pessoa',
        ]);
    }

    public function summarise(array $arguments): string
    {
        return 'Guardar na base de conhecimento: '.($arguments['title'] ?? '');
    }
}
