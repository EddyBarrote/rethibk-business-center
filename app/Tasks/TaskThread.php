<?php

namespace App\Tasks;

use App\Ai\Runs\AgentRunner;
use App\Enums\ActorType;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Events\TaskUpdated;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;

/**
 * Everything that happens in a task's thread (docs/DECISOES.md, 04.10.2026).
 *
 * A task is a conversation with its assigned agent, Hermes-style: a person or
 * another agent writes, the agent answers with the whole thread as history and
 * may use its tools on the way. A direct action asks it to execute now. Agents
 * can open a thread with a person and wait for the answer, and delegate work to
 * other agents; when delegated work is done, the delegating agent is woken.
 */
final class TaskThread
{
    /** Delegation stops here, so agents cannot hand work around forever. */
    public const MAX_DEPTH = 3;

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * Create a task or a chat, with its opening message, and put its agent to work.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(array $attributes, User|Agent $author, ?string $message = null, bool $start = true): Task
    {
        $task = DB::transaction(fn () => Task::query()->create([
            ...$attributes,
            'created_by_user_id' => $author instanceof User ? $author->id : null,
            'created_by_agent_id' => $author instanceof Agent ? $author->id : null,
        ]));

        AuditLog::record($author, 'task.created', ['task_id' => $task->id, 'kind' => $task->kind->value, 'assignee_agent_id' => $task->assignee_agent_id], subject: $task);

        $agentOpensWithPerson = $author instanceof Agent && $task->user_id !== null && $task->assignee_agent_id === $author->id;

        if (filled($message)) {
            $this->post($task, $author, (string) $message, TaskMessageKind::Message, $start, notify: ! $agentOpensWithPerson);
        } elseif ($start && $task->kind === TaskKind::Task) {
            $this->wake($task, $author instanceof User ? $author : null, $task, $author instanceof Agent ? TriggerType::Agent : TriggerType::Manual);
        }

        if ($agentOpensWithPerson) {
            $this->notifyUser($task, "{$author->name} quer falar consigo", Str::limit((string) ($message ?? $task->title), 300));
        }

        return $task;
    }

    /**
     * Add a message to the thread. A message from anyone but the assigned agent
     * wakes that agent; a message from the agent reaches the person.
     */
    public function post(Task $task, User|Agent $author, string $body, TaskMessageKind $kind = TaskMessageKind::Message, bool $wake = true, bool $notify = true): TaskMessage
    {
        $message = $task->messages()->create([
            'author_type' => $author instanceof User ? ActorType::User : ActorType::Agent,
            'author_user_id' => $author instanceof User ? $author->id : null,
            'author_agent_id' => $author instanceof Agent ? $author->id : null,
            'kind' => $kind,
            'body' => $body,
        ]);

        $fromAssignee = $author instanceof Agent && $author->id === $task->assignee_agent_id;

        $task->forceFill([
            'last_activity_at' => now(),
            // A person answering a waiting agent hands the turn back to it.
            'status' => ! $fromAssignee && $task->status === TaskStatus::WaitingHuman ? TaskStatus::InProgress : $task->status,
        ])->save();

        if ($fromAssignee) {
            if ($notify) {
                $this->notifyUser($task, "{$author->name} respondeu", Str::limit($body, 300));
            }
        } elseif ($wake) {
            $this->wake($task, $author instanceof User ? $author : null, $message, $author instanceof Agent ? TriggerType::Agent : TriggerType::Manual);
        }

        TaskUpdated::live($task);

        return $message;
    }

    /**
     * A platform note in the thread (status changes, delegations).
     */
    public function note(Task $task, string $body, ?AgentRun $run = null, TaskMessageKind $kind = TaskMessageKind::Event): TaskMessage
    {
        $message = $task->messages()->create([
            'author_type' => ActorType::System,
            'kind' => $kind,
            'body' => $body,
            'agent_run_id' => $run?->id,
        ]);

        $task->forceFill(['last_activity_at' => now()])->save();

        return $message;
    }

    public function setStatus(Task $task, TaskStatus $status, User|Agent|null $by = null, ?string $note = null): Task
    {
        if ($task->status === $status) {
            return $task;
        }

        $previous = $task->status;
        $task->forceFill([
            'status' => $status,
            'started_at' => $task->started_at ?? ($status === TaskStatus::InProgress ? now() : null),
            'completed_at' => $status->isClosed() ? now() : null,
            'last_activity_at' => now(),
        ])->save();

        $who = $by === null ? 'A plataforma' : $by->name;
        $this->note($task, trim("{$who} mudou o estado de «{$previous->label()}» para «{$status->label()}». ".($note ?? '')));

        if ($by !== null) {
            AuditLog::record($by, 'task.status', ['task_id' => $task->id, 'from' => $previous->value, 'to' => $status->value], subject: $task);
        }

        if ($status === TaskStatus::Done) {
            $this->reportToParent($task, $by);
        }

        if ($status === TaskStatus::WaitingHuman) {
            $this->notifyUser($task, ($by->name ?? 'Um agente').' está à sua espera', $note ?? $task->title, urgent: true);
        }

        TaskUpdated::live($task);

        return $task;
    }

    /**
     * Put the assigned agent to work on the thread. One run at a time per task:
     * while one is active, new messages wait and are picked up when it ends.
     */
    public function wake(Task $task, ?User $requestedBy, TaskMessage|Task $source, TriggerType $trigger = TriggerType::Manual): ?AgentRun
    {
        $agent = $task->assigneeAgent;

        if ($agent === null || $task->status->isClosed() && $task->kind === TaskKind::Task) {
            return null;
        }

        if (! $agent->isActive()) {
            $this->note($task, "{$agent->name} está {$agent->status->label()} e não pode responder agora.");

            return null;
        }

        if ($this->activeRun($task) !== null) {
            return null;
        }

        if ($task->status === TaskStatus::Todo) {
            $task->forceFill(['status' => TaskStatus::InProgress, 'started_at' => now()])->save();
        }

        return $this->runner()->dispatch($agent, $this->input($task, $source), $trigger, $requestedBy, $source, $task->id);
    }

    /**
     * Called by AgentRunner when a run that belongs to a task ends: the agent's
     * answer goes into the thread, and messages that arrived meanwhile wake it again.
     */
    public function recordReply(AgentRun $run): void
    {
        $task = $run->task;

        if ($task === null) {
            return;
        }

        $text = trim((string) ($run->output['text'] ?? ''));

        if ($run->status === RunStatus::Failed) {
            $this->note($task, 'A execução falhou: '.Str::limit((string) $run->error, 300), $run);
        } elseif ($text !== '') {
            $task->messages()->create([
                'author_type' => ActorType::Agent,
                'author_agent_id' => $run->agent_id,
                'kind' => TaskMessageKind::Message,
                'body' => $text,
                'agent_run_id' => $run->id,
            ]);
            $task->forceFill(['last_activity_at' => now()])->save();

            // The person who asked from the console is watching; others get a notification.
            if ($run->trigger_type !== TriggerType::Manual) {
                $this->notifyUser($task, "{$run->agent->name} respondeu", Str::limit($text, 300));
            }
        }

        if ($run->status === RunStatus::AwaitingApproval) {
            $this->note($task, 'Uma acção ficou à espera de aprovação.', $run);
        }

        TaskUpdated::live($task);

        $this->pickUpWaitingMessages($task, $run);
    }

    /**
     * The task thread before the message that triggered this run, as model
     * messages. Platform notes go in as context the agent can read.
     *
     * @return list<Message>
     */
    public function history(AgentRun $run): array
    {
        if ($run->task_id === null) {
            return [];
        }

        $before = $run->trigger_source_type === (new TaskMessage)->getMorphClass() ? (int) $run->trigger_source_id : PHP_INT_MAX;

        return TaskMessage::query()
            ->where('task_id', $run->task_id)
            ->where('id', '<', $before)
            ->with(['authorUser:id,name', 'authorAgent:id,name'])
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->map(fn (TaskMessage $message) => $message->author_type === ActorType::Agent && $message->author_agent_id === $run->agent_id
                ? new AssistantMessage($message->body)
                : new UserMessage($this->speaker($message).$message->body))
            ->values()
            ->all();
    }

    /**
     * What the agent is asked in this turn.
     */
    private function input(Task $task, TaskMessage|Task $source): string
    {
        if ($source instanceof Task) {
            return "Foi-te atribuída a tarefa {$task->identifier()}: {$task->title}\n\n".($task->description ?: 'Sem descrição.')
                ."\n\nTrabalha nela com as tuas ferramentas. Quando terminares, marca-a como feita (tasks.update_status) e resume o resultado.";
        }

        if ($source->kind === TaskMessageKind::Action) {
            return $this->speaker($source)."Acção directa: {$source->body}\n\nExecuta agora com as tuas ferramentas e reporta o resultado em poucas linhas.";
        }

        return $this->speaker($source).$source->body;
    }

    private function speaker(TaskMessage $message): string
    {
        return match ($message->author_type) {
            ActorType::User => '['.($message->authorUser->name ?? 'Pessoa').'] ',
            ActorType::Agent => '[Agente '.($message->authorAgent->name ?? '').'] ',
            default => '[Nota da plataforma] ',
        };
    }

    private function activeRun(Task $task): ?AgentRun
    {
        return $task->runs()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
    }

    /**
     * Coalesce like Paperclip's wakeups: if people wrote while the agent was
     * busy, answer the latest of those messages (the rest are in the history).
     */
    private function pickUpWaitingMessages(Task $task, AgentRun $run): void
    {
        $seen = $run->trigger_source_type === (new TaskMessage)->getMorphClass() ? (int) $run->trigger_source_id : 0;

        $waiting = $task->messages()
            ->where('id', '>', $seen)
            ->whereIn('kind', [TaskMessageKind::Message, TaskMessageKind::Action, TaskMessageKind::Report])
            ->where(fn ($q) => $q->whereIn('author_type', [ActorType::User, ActorType::System])
                ->orWhere(fn ($q) => $q->where('author_type', ActorType::Agent)->where('author_agent_id', '!=', $run->agent_id)))
            ->reorder('id', 'desc')
            ->first();

        if ($waiting instanceof TaskMessage && $waiting->created_at >= ($run->started_at ?? $run->created_at)) {
            $this->wake($task->refresh(), $waiting->authorUser, $waiting, $waiting->author_type === ActorType::Agent ? TriggerType::Agent : TriggerType::Manual);
        }
    }

    /**
     * Delegated work finished: tell the parent thread and wake its agent.
     */
    private function reportToParent(Task $task, User|Agent|null $by): void
    {
        $parent = $task->parent;

        if ($parent === null) {
            return;
        }

        $last = $task->messages()->where('author_type', ActorType::Agent)->reorder('id', 'desc')->first();
        $summary = "A tarefa delegada {$task->identifier()} «{$task->title}» foi concluída".($by ? " por {$by->name}" : '').'.'
            .($last ? "\n\nÚltima resposta:\n".Str::limit($last->body, 1500) : '');

        $note = $this->note($parent, $summary, kind: TaskMessageKind::Report);

        if ($parent->assignee_agent_id !== null && ! ($by instanceof Agent && $by->id === $parent->assignee_agent_id)) {
            $this->wake($parent, null, $note, TriggerType::Agent);
        }
    }

    private function notifyUser(Task $task, string $title, string $body, bool $urgent = false): void
    {
        $user = $task->user ?? $task->createdByUser;

        if ($user === null) {
            return;
        }

        $this->notifier->notify($user, $title, $body, "/tasks/{$task->id}", $task->assigneeAgent?->name, $urgent ? 'warning' : 'info');
    }

    private function runner(): AgentRunner
    {
        return app(AgentRunner::class);
    }
}
