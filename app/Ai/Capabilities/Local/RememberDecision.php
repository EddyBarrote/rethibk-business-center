<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\KnowledgeBase;
use App\Enums\AutonomyLevel;
use App\Enums\KnowledgeType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Record a decision in organisational memory. Section 13.2 reserves it for
 * the Chief of Staff: the super admin gives it only to that agent.
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

        return CapabilityResult::data(['knowledge_item_id' => $item->id, 'title' => $item->title]);
    }

    public function summarise(array $arguments): string
    {
        return 'Registar decisão: '.($arguments['title'] ?? '');
    }
}
