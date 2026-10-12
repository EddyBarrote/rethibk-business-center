<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Memory\MemoryConsolidator;
use App\Models\AgentMemory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Reads one entry of the agent's memory in full (docs/DECISOES.md, "Memória
 * em entradas", 12.10.2026). The prompt carries only the index; this opens an
 * entry of the memory with the person of the run, or of the work with no person.
 * Given to every agent.
 */
final class ReadMemory extends LocalCapability
{
    public function __construct(private readonly MemoryConsolidator $memory) {}

    public function key(): string
    {
        return 'memory.read';
    }

    public function name(): string
    {
        return 'Ler entrada da memória';
    }

    public function description(): string
    {
        return 'Lê inteira uma entrada da tua memória, pelo número (#) ou pelo título que aparece no índice «A tua memória».';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entry' => $schema->string()->required()->description('O número da entrada (sem #) ou o título.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, ['entry' => 'required|max:160'])->validate();
        $entry = $this->memory->entry($context->agent, $context->run->task?->user, ltrim(trim((string) $data['entry']), '#'));

        if (! $entry instanceof AgentMemory) {
            return CapabilityResult::error('não há essa entrada na tua memória com esta pessoa.');
        }

        return new CapabilityResult(true, "[{$entry->section->label()}] {$entry->title}".($entry->description ? " — {$entry->description}" : '')
            ." (actualizada {$entry->updated_at->format('d.m.Y')})\n\n{$entry->content}", ['id' => $entry->id]);
    }
}
