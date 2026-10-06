<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStepStatus;
use App\Models\WorkflowStep;
use App\Workflows\WorkflowEngine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * How an agent ends the step of a flow the platform gave it (docs/DECISOES.md,
 * "Fluxos de trabalho"): with a summary, the answer of a condition or the
 * items of a loop, or "blocked" when it cannot, so the step goes to a person.
 * Given to every agent; it only touches the agent's own open step.
 */
final class CompleteWorkflowStep extends LocalCapability
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function key(): string
    {
        return 'workflow.complete_step';
    }

    public function name(): string
    {
        return 'Concluir passo do fluxo';
    }

    public function description(): string
    {
        return 'Conclui o passo de fluxo que a plataforma te deu nesta tarefa: resumo do que fizeste, resposta "yes"/"no" a uma condição, ou os itens de um ciclo. Usa outcome "blocked" se não o conseguires fazer, e ele passa a uma pessoa.';
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
            'outcome' => $schema->string()->enum(['done', 'blocked'])->required(),
            'summary' => $schema->string()->description('O que fizeste, a razão da resposta, ou porque não conseguiste.')->required(),
            'answer' => $schema->string()->enum(['yes', 'no'])->description('Só nas condições e nas perguntas de um ciclo.'),
            'items' => $schema->array()->items($schema->string())->description('Só quando o passo pede os itens de um ciclo.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'outcome' => 'required|in:done,blocked',
            'summary' => 'required|string|max:4000',
            'answer' => 'nullable|in:yes,no',
            'items' => 'nullable|array|max:50',
            'items.*' => 'string|max:500',
        ])->validate();

        $taskId = $context->run->task_id;
        $step = $taskId === null ? null : WorkflowStep::query()
            ->where('status', WorkflowStepStatus::Active)
            ->where('agent_id', $context->agent->id)
            ->whereHas('run', fn ($q) => $q->where('task_id', $taskId)->whereIn('status', [WorkflowRunStatus::Running, WorkflowRunStatus::Waiting]))
            ->latest('id')
            ->first();

        if ($step === null) {
            return CapabilityResult::error('não tens nenhum passo de fluxo em curso nesta tarefa.');
        }

        if ($data['outcome'] === 'done' && in_array($step->kind, ['condition', 'until'], true) && ! isset($data['answer'])) {
            return CapabilityResult::error('este passo é uma pergunta: responde com answer "yes" ou "no".');
        }

        $next = $this->engine->completeStep($step, $data['outcome'], $data['summary'], $data['answer'] ?? null, array_values($data['items'] ?? []));

        if ($data['outcome'] === 'blocked') {
            return CapabilityResult::text('O passo passou a uma pessoa. Termina aqui a tua resposta.');
        }

        return CapabilityResult::text($next !== null
            ? "Passo concluído. Próximo passo, para ti:\n\n{$next}"
            : 'Passo concluído. O fluxo segue com outra pessoa ou agente, ou terminou; termina aqui a tua resposta.');
    }

    public function summarise(array $arguments): string
    {
        return 'Concluir passo do fluxo';
    }
}
