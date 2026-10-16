<?php

namespace App\Ai\Agents;

use App\Models\Agent as AgentRecord;
use App\Models\AgentRun;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use Laravel\Ai\ToolChoice;

/**
 * The one agent class (docs/DECISOES.md): personality, instructions, model,
 * limits and tools all come from the agents row. Only AgentRunner builds it.
 */
final class GenericAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasTools
{
    use Promptable;

    public const LAST_STEP = 'Chegaste ao limite de passos desta execução: já não podes usar ferramentas. Responde agora à pessoa com o que fizeste, o que encontraste e o que ficou por fazer, para ela te pedir que continues.';

    /**
     * @param  list<Tool>  $tools
     * @param  list<Message>  $history  The task thread before this turn, when the run belongs to a task.
     */
    public function __construct(
        public readonly AgentRecord $record,
        public readonly AgentRun $run,
        private readonly string $instructions,
        private readonly array $tools,
        private readonly array $history = [],
    ) {}

    /**
     * @return list<Message>
     */
    public function messages(): iterable
    {
        return $this->history;
    }

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
        return $this->record->max_steps ?: (int) config('agents.max_steps', 15);
    }

    /**
     * On the last step the model may not call tools, so a run that reaches the
     * step limit still ends with an answer instead of a dropped tool call.
     *
     * @return list<\Closure>
     */
    public function middleware(): array
    {
        return [
            fn (PendingStep $step, \Closure $next) => $step->isFinalStep && ! $step->isFirstStep()
                ? $next($step->withToolChoice(ToolChoice::none)->withInstructions(trim($step->instructions."\n\n".self::LAST_STEP)))
                : $next($step),
        ];
    }

    /**
     * Gemini sends a summary of its reasoning, shown in the chat while it thinks
     * (docs/DECISOES.md, "Raciocínio na conversa").
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = $provider instanceof Lab ? $provider->value : $provider;

        return $provider === 'gemini' && config('agents.thought_summaries')
            ? ['thinking_summaries' => 'auto']
            : [];
    }

    public function timeout(): int
    {
        return (int) config('agents.timeout', 120);
    }
}
