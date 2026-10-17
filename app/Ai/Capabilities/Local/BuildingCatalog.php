<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Models\Agent;
use App\Models\Capability;
use App\Models\Department;
use App\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;

/**
 * What the Chief of Staff picks from when it prepares a new agent or skill:
 * the areas, the agents, the capabilities and the skills of the company, by
 * key (docs/DECISOES.md, «Chief of Staff cria agentes e skills»).
 */
final class BuildingCatalog extends LocalCapability
{
    public function key(): string
    {
        return 'agents.catalog';
    }

    public function name(): string
    {
        return 'Ver o catálogo para agentes novos';
    }

    public function description(): string
    {
        return 'Lista as áreas, os agentes, as capacidades e as skills da empresa, com as chaves a usar em agents.propose e skills.propose. Filtra as capacidades por uma palavra (ex.: "contratos").';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Palavra para filtrar as capacidades e as skills.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $search = mb_strtolower(trim((string) ($arguments['search'] ?? '')));
        $matches = fn (string ...$texts) => $search === '' || Str::contains(mb_strtolower(implode(' ', $texts)), $search);

        $capabilities = Capability::query()
            ->whereNotIn('key', [...CapabilityRegistry::hidden(), ...CapabilityRegistry::alwaysOn(), ...CapabilityRegistry::CHIEF_OF_STAFF])
            ->where('is_available', true)
            ->where('is_enabled', true)
            ->orderBy('key')
            ->get(['key', 'name', 'description', 'is_mutating'])
            ->filter(fn (Capability $capability) => $matches($capability->key, (string) $capability->name, (string) $capability->description))
            ->map(fn (Capability $capability) => ['key' => $capability->key, 'name' => $capability->name, 'writes' => $capability->is_mutating, 'what' => Str::limit((string) $capability->description, 140)])
            ->values();

        return CapabilityResult::data([
            'areas' => Department::query()->orderBy('name')->pluck('name'),
            'agents' => Agent::query()->orderBy('name')->get(['key', 'name', 'title']),
            'capabilities' => $capabilities,
            'skills' => Skill::query()->with('platformSkill')->get()
                ->filter(fn (Skill $skill) => $skill->isUsable() && $matches($skill->key, $skill->displayName(), $skill->displayDescription()))
                ->map(fn (Skill $skill) => ['key' => $skill->key, 'name' => $skill->displayName(), 'when' => Str::limit($skill->displayDescription(), 140)])
                ->values(),
            'note' => 'Todos os agentes já têm, sem atribuir: '.implode(', ', CapabilityRegistry::alwaysOn()).'.',
        ]);
    }
}
