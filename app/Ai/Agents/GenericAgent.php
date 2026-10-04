<?php

namespace App\Ai\Agents;

use App\Models\Agent as AgentRecord;
use App\Models\AgentRun;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * The one agent class (docs/DECISOES.md): personality, instructions, model,
 * limits and tools all come from the agents row. Only AgentRunner builds it.
 */
final class GenericAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * @param  list<Tool>  $tools
     */
    public function __construct(
        public readonly AgentRecord $record,
        public readonly AgentRun $run,
        private readonly string $instructions,
        private readonly array $tools,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }

    /**
     * Null falls back to config('ai.default').
     */
    public function provider(): ?string
    {
        return $this->record->provider ?: null;
    }

    /**
     * Null falls back to the provider's default text model.
     */
    public function model(): ?string
    {
        return $this->record->model ?: config('agents.model');
    }

    public function temperature(): ?float
    {
        return $this->record->temperature;
    }

    public function maxTokens(): ?int
    {
        return $this->record->max_tokens;
    }

    public function maxSteps(): int
    {
        return $this->record->max_steps ?: (int) config('agents.max_steps', 8);
    }

    public function timeout(): int
    {
        return (int) config('agents.timeout', 120);
    }
}
