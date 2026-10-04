<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Skills\AgentSkills;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Number;

/**
 * Loads one of the agent's skills into the conversation, as Claude does with
 * Agent Skills: the prompt only lists names and descriptions, the agent reads
 * the instructions when the work calls for them. Given to agents with skills.
 */
final class LoadSkill extends LocalCapability
{
    public function __construct(private readonly AgentSkills $skills) {}

    public function key(): string
    {
        return 'skills.load';
    }

    public function name(): string
    {
        return 'Carregar skill';
    }

    public function description(): string
    {
        return 'Lê as instruções completas de uma das tuas skills (e a lista dos ficheiros dela). Usa antes de fazer o trabalho a que a skill se aplica.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['skill' => $schema->string()->description('A chave da skill, como aparece na lista das tuas skills.')->required()];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $skill = $this->skills->find($context->agent, (string) ($arguments['skill'] ?? ''));

        if ($skill === null) {
            return CapabilityResult::error('não tens nenhuma skill com essa chave. Vê a lista das tuas skills nas instruções.');
        }

        $files = collect($skill->attachments())
            ->map(fn (array $file) => "- {$file['filename']} (".Number::fileSize($file['size']).')'.($file['content'] === null ? ', sem texto legível' : ''))
            ->implode("\n");

        return CapabilityResult::text(
            "# Skill: {$skill->displayName()} ({$skill->key})\n\n".$skill->body()
            .($files !== '' ? "\n\n## Ficheiros desta skill\nLê-os com skills.read_file quando precisares:\n{$files}" : ''),
        );
    }
}
