<?php

namespace App\Http\Presenters;

use App\Ai\Autonomy\ApprovalRules;
use App\Email\EmailReferences;
use App\Enums\TaskKind;
use App\Models\Agent;
use App\Models\AgentInitiative;
use App\Models\AgentMemory;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Contract;
use App\Models\EmailMessage;
use App\Models\MessageAttachment;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * Shapes shared by several tenant console screens.
 */
final class Present
{
    /**
     * @return array<string, mixed>
     */
    public static function agent(Agent $agent): array
    {
        return [
            'id' => $agent->id,
            'key' => $agent->key,
            'name' => $agent->name,
            'avatar_url' => $agent->avatarUrl(),
            'title' => $agent->title,
            'description' => $agent->description,
            'status' => $agent->status->value,
            'status_label' => $agent->status->label(),
            'suspended_reason' => $agent->suspended_reason,
            'autonomy_level' => $agent->autonomy_level->value,
            'department' => $agent->department?->name,
            'reports_to' => $agent->reportsTo?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'agent' => ['id' => $run->agent_id, 'name' => $run->agent->name],
            'trigger' => $run->trigger_type->value,
            'trigger_label' => $run->trigger_type->label(),
            'title' => self::runTitle($run),
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'input' => $run->input,
            // Older runs said «email #354»: named the human way here too.
            'output' => isset($run->output['text']) ? app(EmailReferences::class)->inText((string) $run->output['text']) : null,
            'requested_by' => $run->requestedBy?->name,
            'provider' => $run->provider,
            'model' => $run->model,
            ...self::modelLabels($run->provider, $run->model),
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'cost_usd' => $run->cost_usd,
            'duration_ms' => $run->duration_ms,
            'error' => $run->error,
            'created_at' => $run->created_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * The model a run used, the human way: its name in the catalog
     * (config('agents.models')) and the provider's, the raw id when the
     * catalog no longer lists it.
     *
     * @return array{model_label: string|null, provider_label: string|null}
     */
    public static function modelLabels(?string $provider, ?string $model): array
    {
        $entry = collect((array) config('agents.models'))->first(fn (array $m) => $m['model'] === $model && ($provider === null || $m['provider'] === $provider));

        return [
            'model_label' => $model === null ? null : ($entry['label'] ?? $model),
            'provider_label' => $provider === null ? null : (config("agents.providers.{$provider}.label") ?? $provider),
        ];
    }

    /** Scheduled checks that start a run without a routine, by the start of their prompt. */
    private const SCHEDULED = [
        'Prepara o briefing semanal' => 'Briefing semanal',
        'Prepara o briefing diário' => 'Briefing diário',
        'Persegue os recebimentos' => 'Cobrança de facturas em atraso',
        'Encontrei concursos novos' => 'Concursos novos nas fontes',
        'O pedido do cliente no email' => 'Pedido de cliente fora do prazo',
    ];

    /**
     * What a run is about, in the words of the screen it came from: the
     * routine's name, the task, the email, the contract. The prompt itself is
     * written for the agent and names tools by key. Null for a conversation
     * turn, which the screen titles from the person's message.
     */
    private static function runTitle(AgentRun $run): ?string
    {
        $source = $run->trigger_source_type;
        $id = $run->trigger_source_id;

        if ($source === (new AgentRoutine)->getMorphClass() && $id !== null) {
            return $run->relationLoaded('triggerSource')
                ? $run->triggerSource?->getAttribute('name')
                : AgentRoutine::query()->whereKey($id)->value('name');
        }

        if ($source === (new EmailMessage)->getMorphClass() && $id !== null) {
            $subject = EmailMessage::query()->whereKey($id)->value('subject');

            return 'Triagem do email «'.($subject ?: 'sem assunto').'»';
        }

        if ($source === (new Contract)->getMorphClass() && $id !== null) {
            return 'Contrato a terminar: «'.Contract::query()->whereKey($id)->value('title').'»';
        }

        if ($run->task_id !== null && ($source === (new Task)->getMorphClass() || str_starts_with($run->input, '[Nota da plataforma]'))) {
            $task = Task::query()->whereKey($run->task_id)->first(['id', 'tenant_id', 'number', 'title', 'kind']);

            if ($task !== null && $task->kind === TaskKind::Task) {
                $ref = $task->title;

                return str_contains($run->input, 'Batimento') ? "Retomar {$ref}" : $ref;
            }
        }

        foreach (self::SCHEDULED as $start => $title) {
            if (str_starts_with($run->input, $start)) {
                return $title;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function approval(Approval $approval, bool $canDecide): array
    {
        return [
            'id' => $approval->id,
            'run_id' => $approval->agent_run_id,
            'task_id' => $approval->relationLoaded('run') ? $approval->run?->task_id : null,
            'agent' => ['id' => $approval->agent_id, 'name' => $approval->agent->name],
            'action_type' => $approval->action_type,
            'action_summary' => $approval->action_summary,
            'payload' => $approval->payload,
            'required_level' => $approval->required_level->value,
            'agent_level' => $approval->agent_level->value,
            'ceiling_reason' => $approval->ceiling_reason,
            // The standing rule that asked (docs/DECISOES.md, "Regras de aprovação"), and whether one may come out of it.
            'rule_label' => $approval->rule_label,
            'can_always' => ApprovalRules::canAllowAlways($approval),
            'status' => $approval->status->value,
            'status_label' => $approval->status->label(),
            'assigned_to' => $approval->assignedTo?->name,
            'decided_by' => $approval->decidedBy->name ?? $approval->decidedByAgent?->name,
            'review_stage' => $approval->review_stage,
            'review_agent' => $approval->reviewAgent?->name,
            'review_note' => $approval->review_note,
            'decided_at' => $approval->decided_at?->toIso8601String(),
            'decision_note' => $approval->decision_note,
            'execution_status' => $approval->execution_status->value,
            'execution_result' => $approval->execution_result,
            'executed_at' => $approval->executed_at?->toIso8601String(),
            'created_at' => $approval->created_at->toIso8601String(),
            'can_decide' => $canDecide && $approval->status->value === 'pending',
        ];
    }

    /**
     * What an agent offers to do (docs/DECISOES.md, "Iniciativas dos agentes").
     *
     * @return array<string, mixed>
     */
    public static function initiative(AgentInitiative $initiative): array
    {
        return [
            'id' => $initiative->id,
            'agent' => ['id' => $initiative->agent_id, 'name' => $initiative->agent->name, 'avatar_url' => $initiative->agent->avatarUrl()],
            'title' => $initiative->title,
            'why' => $initiative->why,
            'plan' => $initiative->plan,
            'evidence' => $initiative->evidence,
            'created_at' => $initiative->created_at->toIso8601String(),
        ];
    }

    /** Relations Present::task() reads; eager-load them with the tasks. */
    public const TASK_RELATIONS = ['assigneeAgent:id,name', 'assigneeUser:id,name', 'user:id,name', 'createdByAgent:id,name', 'createdByUser:id,name', 'goal:id,title', 'project:id,name', 'tenant:id,slug'];

    /** Relations Present::approval() reads. */
    public const APPROVAL_RELATIONS = ['agent', 'run:id,task_id', 'assignedTo:id,name', 'decidedBy:id,name', 'decidedByAgent:id,name', 'reviewAgent:id,name'];

    /**
     * An entry of an agent's memory (docs/DECISOES.md, "Memória em entradas").
     *
     * @return array<string, mixed>
     */
    public static function memory(AgentMemory $memory): array
    {
        return [
            'id' => $memory->id,
            'section' => $memory->section->value,
            'title' => $memory->title ?? Str::limit($memory->content, 60),
            'description' => $memory->description,
            'content' => $memory->content,
            'kind' => $memory->kind,
            'agent_id' => $memory->agent_id,
            'person' => $memory->relationLoaded('user') && $memory->user ? ['id' => $memory->user->id, 'name' => $memory->user->name] : null,
            'about' => $memory->relationLoaded('aboutUser') ? $memory->aboutUser?->name : null,
            'task' => $memory->relationLoaded('task') && $memory->task ? ['id' => $memory->task->id, 'ref' => $memory->task->identifier(), 'title' => $memory->task->title] : null,
            'edited' => $memory->edited_by_user_id !== null,
            'updated_at' => $memory->updated_at->toIso8601String(),
        ];
    }

    /**
     * A task or conversation as a row (Tarefas, A minha caixa).
     *
     * @return array<string, mixed>
     */
    public static function task(Task $task): array
    {
        return [
            'id' => $task->id,
            'ref' => $task->identifier(),
            'kind' => $task->kind->value,
            'is_conversation' => $task->chat_key !== null,
            // A conversation is always called after its agent, whatever its first message was ("Hello").
            'title' => $task->kind === TaskKind::Chat && $task->assigneeAgent !== null ? "Conversa com {$task->assigneeAgent->name}" : $task->title,
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'priority' => $task->priority->value,
            'priority_label' => $task->priority->label(),
            'assignee' => $task->assigneeAgent ? ['id' => $task->assigneeAgent->id, 'name' => $task->assigneeAgent->name] : null,
            'assignee_user' => $task->assigneeUser ? ['id' => $task->assigneeUser->id, 'name' => $task->assigneeUser->name] : null,
            'user' => $task->user?->name,
            'user_id' => $task->user_id,
            'created_by' => $task->createdByAgent->name ?? $task->createdByUser->name ?? null,
            'created_by_agent' => $task->created_by_agent_id !== null,
            'goal' => $task->goal ? ['id' => $task->goal->id, 'title' => $task->goal->title] : null,
            'project' => $task->project ? ['id' => $task->project->id, 'name' => $task->project->name] : null,
            'messages_count' => $task->messages_count ?? null,
            'due_at' => $task->due_at?->toIso8601String(),
            'last_activity_at' => $task->last_activity_at?->toIso8601String(),
            'created_at' => $task->created_at->toIso8601String(),
        ];
    }

    /**
     * A file in a conversation (docs/DECISOES.md, "Anexos nas conversas").
     *
     * @return array<string, mixed>
     */
    public static function attachment(MessageAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->filename,
            'kind' => $attachment->kind->value,
            'mime' => $attachment->mime_type,
            'size' => $attachment->size_bytes,
            'extension' => $attachment->extension(),
            'source' => $attachment->source,
            'url' => "/files/{$attachment->id}",
            'transcript' => $attachment->kind->value === 'audio' ? $attachment->extracted_text : null,
        ];
    }
}
