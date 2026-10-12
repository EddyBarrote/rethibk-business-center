<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Memory\CompanyMemory;
use App\Enums\AutonomyLevel;
use App\Enums\CompanySection;
use App\Enums\KnowledgeType;
use App\Models\CompanyFact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Record a decision in organisational memory. Section 13.2 reserves it for
 * the Chief of Staff: the super admin gives it only to that agent. The
 * decision goes straight into the company memory's "Decisões" (12.10.2026):
 * the autonomy gate already asked a person, and the direction decided it.
 */
final class RememberDecision extends LocalCapability
{
    public function __construct(private readonly KnowledgeBase $knowledge) {}

    public function key(): string
    {
        return 'memory.remember_decision';
    }

    public function name(): string
    {
        return 'Registar decisão';
    }

    public function description(): string
    {
        return 'Regista uma decisão da direcção na memória da organização.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::ExecuteWithApproval;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Título curto da decisão.')->required(),
            'content' => $schema->string()->description('O que foi decidido, por quem, e porquê.')->required(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $arguments = Validator::make($arguments, ['title' => 'required|string|max:255', 'content' => 'required|string'])->validate();

        $item = $this->knowledge->remember(KnowledgeType::Decision, $arguments['title'], $arguments['content'], $context->agent, [
            'source_type' => $context->run->getMorphClass(),
            'source_id' => $context->run->id,
        ]);

        $fact = CompanyFact::query()->create([
            'section' => CompanySection::Decisions,
            'content' => Str::limit($item->title.': '.trim($item->content), CompanyMemory::MAX_FACT, '…'),
            'status' => CompanyFact::PUBLISHED,
            'proposed_by_agent_id' => $context->agent->id,
            'task_id' => $context->run->task_id,
            'knowledge_item_id' => $item->id,
            'reviewed_by_user_id' => $context->approval?->decided_by_user_id,
            'reviewed_at' => now(),
        ]);

        return CapabilityResult::data(['knowledge_item_id' => $item->id, 'company_fact_id' => $fact->id, 'title' => $item->title]);
    }

    public function summarise(array $arguments): string
    {
        return 'Registar decisão: '.($arguments['title'] ?? '');
    }
}
