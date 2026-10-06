<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Runs\AgentDirectory;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * An agent raises something urgent to the Chief of Staff on its own: money at
 * stake, legal risk, a critical deadline (docs/DECISOES.md, realinhamento,
 * decisão 24b). Everything else waits for the CEO to ask.
 */
final class EscalateUrgent extends LocalCapability
{
    public function __construct(private readonly TaskThread $threads, private readonly AgentDirectory $agents) {}

    public function key(): string
    {
        return 'escalate.urgent';
    }

    public function name(): string
    {
        return 'Avisar o Chief of Staff (urgente)';
    }

    public function description(): string
    {
        return 'Só para o que é urgente: muito dinheiro em jogo, risco legal, um prazo crítico a falhar. Leva ao Chief of Staff o que se passa, porquê é urgente e a ligação. O resto não se escala: o CEO pergunta ao Chief of Staff quando quiser.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Uma linha.')->required(),
            'why_urgent' => $schema->string()->description('Porquê não pode esperar.')->required(),
            'summary' => $schema->string()->description('O que se passa, com os factos e os nomes.')->required(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'title' => 'required|string|max:200',
            'why_urgent' => 'required|string|max:1000',
            'summary' => 'required|string|max:4000',
        ])->validate();

        $chief = $this->agents->forRole('chief_of_staff');

        if ($chief === null || $chief->id === $context->agent->id) {
            return CapabilityResult::error('não há um Chief of Staff activo a quem escalar.');
        }

        $from = $context->run->task;
        $task = $this->threads->open([
            'kind' => TaskKind::Task,
            'title' => Str::limit("Urgente: {$data['title']}", 200, '…'),
            'description' => "{$context->agent->name} escala por ser urgente: {$data['why_urgent']}\n\n{$data['summary']}"
                .($from !== null ? "\n\nOrigem: {$from->identifier()} «{$from->title}» (/tasks/{$from->id})." : ''),
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Urgent,
            'assignee_agent_id' => $chief->id,
            'user_id' => $chief->reports_to_user_id,
        ], $context->agent);

        if ($from !== null) {
            $this->threads->note($from, "{$context->agent->name} escalou ao Chief of Staff ({$task->identifier()}).", $context->run);
        }

        return CapabilityResult::data(['task' => $task->identifier(), 'link' => "/tasks/{$task->id}"]);
    }
}
