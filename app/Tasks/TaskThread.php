<?php

namespace App\Tasks;

use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\QuestionService;
use App\Attachments\AttachmentStore;
use App\Attachments\ModelInput;
use App\Attachments\StoredFile;
use App\Enums\ActorType;
use App\Enums\ApprovalStatus;
use App\Enums\NotificationCategory;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Events\TaskUpdated;
use App\Jobs\ConsolidateAgentMemory;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\GeneratedDocument;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use App\Support\Notifier;
use App\Support\TenantSettings;
use App\Workflows\WorkflowEngine;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
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

    /** How many of the thread's latest messages the agent reads as history. */
    public const HISTORY_MESSAGES = 40;

    public function __construct(private readonly Notifier $notifier) {}

    public static function chatKey(User $user, Agent $agent): string
    {
        return "{$user->id}:{$agent->id}";
    }

    /**
     * The one persistent conversation between a person and an agent (like
     * Grok): created on first use, reopened if it was closed, never duplicated.
     */
    public function conversation(User $user, Agent $agent): Task
    {
        $key = self::chatKey($user, $agent);
        $chat = Task::query()->where('chat_key', $key)->first();

        if ($chat === null) {
            try {
                $chat = Task::query()->create([
                    'kind' => TaskKind::Chat,
                    'chat_key' => $key,
                    'title' => "Conversa com {$agent->name}",
                    'status' => TaskStatus::InProgress,
                    'assignee_agent_id' => $agent->id,
                    'user_id' => $user->id,
                    'created_by_user_id' => $user->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                $chat = Task::query()->where('chat_key', $key)->firstOrFail();
            }
        }

        if ($chat->status->isClosed()) {
            $chat->forceFill(['status' => TaskStatus::InProgress, 'completed_at' => null])->save();
        }

        return $chat;
    }

    /**
     * Create a task or a chat, with its opening message, and put its agent to work.
     *
     * @param  array<string, mixed>  $attributes
     * @param  iterable<MessageAttachment>  $attachments
     */
    public function open(array $attributes, User|Agent $author, ?string $message = null, bool $start = true, iterable $attachments = []): Task
    {
        $task = DB::transaction(fn () => Task::query()->create([
            ...$attributes,
            'created_by_user_id' => $author instanceof User ? $author->id : null,
            'created_by_agent_id' => $author instanceof Agent ? $author->id : null,
        ]));

        AuditLog::record($author, 'task.created', ['task_id' => $task->id, 'kind' => $task->kind->value, 'assignee_agent_id' => $task->assignee_agent_id], subject: $task);

        $agentOpensWithPerson = $author instanceof Agent && $task->user_id !== null && $task->assignee_agent_id === $author->id;

        if (filled($message) || collect($attachments)->isNotEmpty()) {
            $this->post($task, $author, (string) $message, TaskMessageKind::Message, $start, notify: ! $agentOpensWithPerson, attachments: $attachments);
        } elseif ($start && $task->kind === TaskKind::Task && $task->assignee_agent_id !== null) {
            $this->wake($task, $author instanceof User ? $author : null, $task, $author instanceof Agent ? TriggerType::Agent : TriggerType::Manual);
        }

        if ($agentOpensWithPerson) {
            $this->notifyUser($task, "{$author->name} quer falar consigo", Str::limit((string) ($message ?? $task->title), 300), category: NotificationCategory::Conversation);
        }

        if ($task->assignee_user_id !== null && $task->assignee_user_id !== ($author instanceof User ? $author->id : null)) {
            $this->notifyAssignee($task, $author);
        }

        return $task;
    }

    /**
     * Add a message to the thread. A message from anyone but the assigned agent
     * wakes that agent; a message from the agent reaches the person. Files go
     * with it (docs/DECISOES.md, "Anexos nas conversas").
     *
     * @param  iterable<MessageAttachment>  $attachments
     */
    public function post(Task $task, User|Agent $author, string $body, TaskMessageKind $kind = TaskMessageKind::Message, bool $wake = true, bool $notify = true, iterable $attachments = []): TaskMessage
    {
        $message = $task->messages()->create([
            'author_type' => $author instanceof User ? ActorType::User : ActorType::Agent,
            'author_user_id' => $author instanceof User ? $author->id : null,
            'author_agent_id' => $author instanceof Agent ? $author->id : null,
            'kind' => $kind,
            'body' => $body,
        ]);

        $attachments = collect($attachments);

        if ($attachments->isNotEmpty()) {
            app(AttachmentStore::class)->attachToMessage($attachments, $message);
            AuditLog::record($author, 'task.attachments', ['task_id' => $task->id, 'message_id' => $message->id, 'files' => $attachments->map(fn (MessageAttachment $a) => ['id' => $a->id, 'name' => $a->filename, 'kind' => $a->kind->value, 'bytes' => $a->size_bytes])->values()->all()], subject: $task);
        }

        $fromAssignee = $author instanceof Agent && $author->id === $task->assignee_agent_id;

        // Writing while the agent waits on its questions answers them, and the paused run carries on.
        if ($wake && $author instanceof User && $kind !== TaskMessageKind::Event && app(QuestionService::class)->answerWithMessage($task, $message, $author)) {
            TaskUpdated::live($task);

            return $message;
        }

        // Answering a waiting agent, or a person sending back work in review,
        // hands the turn back to the agent.
        $handsBack = ! $fromAssignee && ($task->status === TaskStatus::WaitingHuman
            || ($task->status === TaskStatus::InReview && $author instanceof User && $task->assignee_agent_id !== null && $task->kind === TaskKind::Task));

        $task->forceFill([
            'last_activity_at' => now(),
            'status' => $handsBack ? TaskStatus::InProgress : $task->status,
        ])->save();

        if ($fromAssignee) {
            if ($notify) {
                $this->notifyUser($task, "{$author->name} respondeu", Str::limit($body !== '' ? $body : 'Enviou ficheiros.', 300), category: NotificationCategory::Conversation);
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

        // Delivered work becomes the agent's memory (realinhamento L7).
        if (in_array($status, [TaskStatus::InReview, TaskStatus::Done], true) && $task->kind === TaskKind::Task && $task->assignee_agent_id !== null) {
            ConsolidateAgentMemory::dispatch($task->tenant_id, $task->id);
        }

        if ($status === TaskStatus::InReview && $by instanceof Agent && $task->kind === TaskKind::Task) {
            $this->notifyUser($task, "{$task->identifier()} pronta para rever", $note ?? $task->title, urgent: true, category: NotificationCategory::Waiting);
        }

        if ($status === TaskStatus::Done && $by instanceof Agent && $task->parent_id === null && $task->kind === TaskKind::Task) {
            $this->notifyUser($task, "{$by->name} concluiu {$task->identifier()}", $note ?? $task->title);
        }

        if ($status === TaskStatus::WaitingHuman) {
            $this->notifyUser($task, ($by->name ?? 'Um agente').' está à sua espera', $note ?? $task->title, urgent: true, category: NotificationCategory::Waiting);
        }

        if ($status->isClosed()) {
            app(WorkflowEngine::class)->taskClosed($task);
        }

        TaskUpdated::live($task);

        return $task;
    }

    /**
     * Put the assigned agent to work on the thread. One run at a time per task:
     * while one is active, new messages wait and are picked up when it ends.
     *
     * @param  (Closure(AgentRun): void)|null  $created  called with the run before it is queued
     */
    public function wake(Task $task, ?User $requestedBy, TaskMessage|Task $source, TriggerType $trigger = TriggerType::Manual, ?Closure $created = null): ?AgentRun
    {
        $agent = $this->agentOf($task);

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

        return $this->runner()->dispatch($agent, $this->input($task, $source), $trigger, $requestedBy, $source, $task->id, $created);
    }

    /**
     * The agent who answers in the thread: the one it is assigned to or, when an
     * agent gave the task to a person, that agent, who stays to help
     * (docs/DECISOES.md, "Passar trabalho a um colega").
     */
    public function agentOf(Task $task): ?Agent
    {
        if ($task->assigneeAgent !== null) {
            return $task->assigneeAgent;
        }

        // Loaded whole: pages load the creator with only a few columns.
        return $task->kind === TaskKind::Task && $task->assignee_user_id !== null && $task->created_by_agent_id !== null
            ? Agent::query()->find($task->created_by_agent_id)
            : null;
    }

    /**
     * The threads whose files the agent of this run may read: its own, the
     * tasks delegated from it and, in a conversation, the tasks it gave people
     * from it, whose results come back here.
     *
     * @return list<int>
     */
    public function readableTaskIds(AgentRun $run): array
    {
        $task = $run->task;

        if ($task === null) {
            return [];
        }

        $ids = Task::query()->where('parent_id', $task->id)->pluck('id');

        if ($task->kind === TaskKind::Chat && $task->user_id !== null) {
            $ids = $ids->merge(Task::query()->where('kind', TaskKind::Task)->whereNull('parent_id')
                ->where('created_by_agent_id', $run->agent_id)->where('user_id', $task->user_id)
                ->whereNotNull('assignee_user_id')->pluck('id'));
        }

        return [$task->id, ...$ids->map(fn ($id) => (int) $id)->all()];
    }

    /**
     * The heartbeat: a platform note in the thread wakes its agent to pick
     * the work back up.
     */
    public function nudge(Task $task): ?AgentRun
    {
        $note = $this->note($task, 'Batimento: esta tarefa está parada. Retoma-a, ou marca-a como bloqueada e diz porquê.');

        return $this->wake($task, null, $note, TriggerType::Schedule);
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
        $files = $this->agentFiles($run);

        if ($run->status === RunStatus::Failed) {
            $note = $this->note($task, 'A execução falhou: '.Str::limit((string) $run->error, 300), $run);
            app(AttachmentStore::class)->attachToMessage($files, $note);
        } elseif ($text !== '' || $files->isNotEmpty() || $run->status === RunStatus::AwaitingInput) {
            $reply = $task->messages()->create([
                'author_type' => ActorType::Agent,
                'author_agent_id' => $run->agent_id,
                'kind' => TaskMessageKind::Message,
                'body' => $text,
                'agent_run_id' => $run->id,
            ]);
            app(AttachmentStore::class)->attachToMessage($files, $reply);
            $task->forceFill(['last_activity_at' => now()])->save();
            $this->consolidateIfDue($task);

            // The agent's questions show as a card on this reply (docs/DECISOES.md, "Perguntas dos agentes").
            AgentQuestion::query()->where('agent_run_id', $run->id)->where('task_id', $task->id)->whereNull('task_message_id')->update(['task_message_id' => $reply->id]);

            // The person who asked from the console is watching; others get a notification
            // (for questions, QuestionService already sent one).
            if ($run->trigger_type !== TriggerType::Manual && $run->status !== RunStatus::AwaitingInput) {
                $this->notifyUser($task, "{$run->agent->name} respondeu", Str::limit($text !== '' ? $text : 'Enviou ficheiros.', 300), category: NotificationCategory::Conversation, runId: $run->id);
            }
        }

        // The model stopped without a word (the step limit, or an empty answer): say so instead of going quiet.
        if ($run->status === RunStatus::Completed && $text === '' && $files->isEmpty()) {
            $this->note($task, "{$run->agent->name} terminou sem escrever resposta, provavelmente por ter chegado ao limite de passos. Diga «continua» para ele seguir.", $run);
        }

        if ($run->status === RunStatus::AwaitingApproval) {
            $pending = Approval::query()->where('agent_run_id', $run->id)->where('status', ApprovalStatus::Pending)->pluck('action_summary');
            $this->note($task, $pending->isEmpty()
                ? 'Uma acção ficou à espera de aprovação.'
                : 'À espera de aprovação: '.$pending->map(fn (string $summary) => Str::limit($summary, 160))->join('; ').'.', $run);
        }

        // A flow's step the agent left unfinished goes back to it, or to a person (docs/DECISOES.md, "Fluxos de trabalho").
        app(WorkflowEngine::class)->runEnded($run);

        TaskUpdated::live($task);

        // Paused on questions, the run itself carries on once they are answered.
        if ($run->status !== RunStatus::AwaitingInput) {
            $this->pickUpWaitingMessages($task, $run);
        }
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

        $messages = $this->window($run, ['authorUser:id,name', 'authorAgent:id,name', 'attachments' => fn ($q) => $q->ready(), 'question.answeredBy:id,name']);

        // The latest files of the conversation go again as files; older ones are named.
        $recent = $messages->flatMap(fn (TaskMessage $message) => $message->author_type === ActorType::User ? $message->attachments : [])
            ->sortByDesc('id')->take((int) config('attachments.history_files', 4))->pluck('id')->all();
        $input = app(ModelInput::class);
        $provider = $run->agent->provider ?? null;

        return $messages->map(function (TaskMessage $message) use ($run, $recent, $input, $provider) {
            if ($message->author_type === ActorType::Agent && $message->author_agent_id === $run->agent_id) {
                $sent = $message->attachments->map(fn (MessageAttachment $a) => "{$a->reference()}: {$a->filename}")->implode('; ');
                $asked = $message->question !== null
                    ? "\n\n[Perguntaste com questions.ask".($message->question->answeredBy ? "; {$message->question->answeredBy->name} respondeu" : '; ainda sem resposta').":]\n".app(QuestionService::class)->transcript($message->question)
                    : '';

                return new AssistantMessage(trim($message->body.($sent !== '' ? "\n\n[Enviaste: {$sent}]" : '').$asked));
            }

            $files = [];
            $named = [];

            foreach ($message->attachments as $attachment) {
                if (in_array($attachment->id, $recent, true) && $input->readsNatively($provider, $attachment->mime_type)) {
                    $files[] = $attachment;
                } else {
                    $named[] = "{$attachment->reference()}: {$attachment->filename} ({$attachment->kind->label()})";
                }
            }

            $built = $input->build($provider, $files, budgetBytes: (int) config('attachments.inline_max_mb', 18) * 1024 * 1024 / 2);
            $list = $named !== [] ? "\n\n[Anexos: ".implode('; ', $named).'. Lê-os com files.read se precisares.]' : '';

            return new UserMessage($this->speaker($message, dated: true).$message->body.$built['text'].$list, $built['files']);
        })->all();
    }

    /**
     * The messages the agent reads as history: the latest ones, up to
     * HISTORY_MESSAGES and to the tenant's character budget (`history_chars`),
     * so forty long replies neither cost a fortune nor overflow the model.
     * The newest message always goes in, cut to the budget if it alone is
     * longer (docs/DECISOES.md, "Falhas de contexto").
     *
     * @param  array<array-key, mixed>  $with
     * @return Collection<int, TaskMessage>
     */
    public function window(AgentRun $run, array $with = []): Collection
    {
        if ($run->task_id === null) {
            return collect();
        }

        $before = $run->trigger_source_type === (new TaskMessage)->getMorphClass() ? (int) $run->trigger_source_id : PHP_INT_MAX;

        return $this->latest($run->task_id, $before, $run->id, $with);
    }

    /**
     * @param  array<array-key, mixed>  $with
     * @return Collection<int, TaskMessage>
     */
    private function latest(int $taskId, int $before = PHP_INT_MAX, ?int $exceptRun = null, array $with = []): Collection
    {
        $budget = max(2000, TenantSettings::int('history_chars'));
        $used = 0;

        return TaskMessage::query()
            ->where('task_id', $taskId)
            ->where('id', '<', $before)
            // What this run already wrote (before pausing for questions) goes back in by AgentRunner.
            ->when($exceptRun !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('agent_run_id')->orWhere('agent_run_id', '!=', $exceptRun)))
            ->with($with)
            ->orderByDesc('id')
            ->limit(self::HISTORY_MESSAGES)
            ->get()
            ->takeWhile(function (TaskMessage $message) use (&$used, $budget) {
                $first = $used === 0;
                $used += max(1, mb_strlen($message->body));

                if ($first && $used > $budget) {
                    $message->body = Str::limit($message->body, $budget, ' […]');
                }

                return $first || $used <= $budget;
            })
            ->reverse()
            ->values();
    }

    /**
     * Whether the thread has older messages than the history shows, so the
     * agent needs the rolling summary of what came before.
     */
    public function trimmed(AgentRun $run): bool
    {
        $oldest = $this->window($run)->first();

        return $oldest !== null && TaskMessage::query()->where('task_id', $run->task_id)->where('id', '<', $oldest->id)->exists();
    }

    /**
     * The files of the message that woke this run (a person's message in the
     * thread, or the email being triaged), for the model.
     *
     * @return list<StoredFile>
     */
    public function attachmentsFor(AgentRun $run): array
    {
        return match ($run->trigger_source_type) {
            (new TaskMessage)->getMorphClass() => MessageAttachment::query()->ready()->where('task_message_id', $run->trigger_source_id)->orderBy('id')->get()->all(),
            (new EmailMessage)->getMorphClass() => EmailMessage::query()->find($run->trigger_source_id)?->attachments()->orderBy('id')->get()->all() ?? [],
            default => [],
        };
    }

    /**
     * What the agent sends with its reply: the files it attached with
     * files.attach and the documents it generated in this run.
     *
     * @return Collection<int, MessageAttachment>
     */
    private function agentFiles(AgentRun $run): Collection
    {
        $store = app(AttachmentStore::class);
        $attached = MessageAttachment::query()->where('agent_run_id', $run->id)->pluck('filename')->all();

        GeneratedDocument::query()->where('agent_run_id', $run->id)->orderBy('id')->get()
            ->reject(fn (GeneratedDocument $document) => in_array($document->filename, $attached, true))
            ->each(fn (GeneratedDocument $document) => $store->fromAgent($run, $document->filename, $document->disk, $document->path, 'generated', $document->source));

        return MessageAttachment::query()->where('agent_run_id', $run->id)->whereNull('task_message_id')->orderBy('id')->get();
    }

    /**
     * What the agent is asked in this turn.
     */
    private function input(Task $task, TaskMessage|Task $source): string
    {
        if ($source instanceof Task) {
            return "Foi-te atribuída a tarefa {$task->identifier()}: {$task->title}\n\n".($task->description ?: 'Sem descrição.')
                .$this->origin($task)
                ."\n\nTrabalha nela com as tuas ferramentas. Quando terminares, marca-a como feita (tasks.update_status) e resume o resultado.";
        }

        if ($source->kind === TaskMessageKind::Action) {
            return $this->speaker($source)."Acção directa: {$source->body}\n\nExecuta agora com as tuas ferramentas e reporta o resultado em poucas linhas.";
        }

        return $this->speaker($source).($source->body !== '' ? $source->body : '(enviou só anexos)');
    }

    /**
     * Where the task came from, in the agent's terms. The description is
     * written for people; the tool to open the origin goes only to the agent.
     */
    private function origin(Task $task): string
    {
        return match ($task->source_id === null ? null : $task->source_type) {
            (new EmailMessage)->getMorphClass() => "\n\nOrigem: o email #{$task->source_id}. Lê-o com email.read (email_id {$task->source_id}) e trata-o dentro das tuas competências.",
            (new Approval)->getMorphClass() => "\n\nOrigem: a aprovação #{$task->source_id}. Acção: ".Approval::query()->whereKey($task->source_id)->value('action_summary')
                .".\nRevê-a com approvals.review: aprova se está certa e cabe no teu nível, devolve se está errada, ou passa às pessoas (escalate) se não tens a certeza ou não cabe no teu nível.",
            default => '',
        };
    }

    /**
     * Who wrote, and in the history also when, so the agent knows a message
     * from three days ago is not from a minute ago.
     */
    private function speaker(TaskMessage $message, bool $dated = false): string
    {
        $who = match ($message->author_type) {
            ActorType::User => $message->authorUser->name ?? 'Pessoa',
            ActorType::Agent => 'Agente '.($message->authorAgent->name ?? ''),
            default => 'Nota da plataforma',
        };

        return '['.$who.($dated ? ' · '.$message->created_at->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i') : '').'] ';
    }

    /**
     * Turn the thread into memory as soon as enough is new, or as soon as
     * messages not yet in memory leave the agent's history (docs/DECISOES.md,
     * "Contexto dos agentes" and "Falhas de contexto").
     */
    private function consolidateIfDue(Task $task): void
    {
        $every = TenantSettings::int('memory_every_messages');

        if ($every <= 0 || $task->assignee_agent_id === null) {
            return;
        }

        $pending = $task->messages()->where('id', '>', (int) $task->memory_message_id);

        // Enough new messages, or some already about to leave the history because they are long.
        if ((clone $pending)->count() >= $every || (clone $pending)->where('id', '<', (int) $this->latest($task->id)->first()?->id)->exists()) {
            ConsolidateAgentMemory::dispatch($task->tenant_id, $task->id);
        }
    }

    private function activeRun(Task $task): ?AgentRun
    {
        return $task->runs()->working()->latest('id')->first();
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

        // A flow's sub-task: the flow, not the parent's agent, picks up what comes next.
        if (app(WorkflowEngine::class)->ownsTask($task)) {
            return;
        }

        // Work an agent gave a person from a conversation goes back to that conversation.
        $parent ??= $task->kind === TaskKind::Task && $task->assignee_user_id !== null && $task->createdByAgent !== null && $task->user !== null
            ? $this->conversation($task->user, $task->createdByAgent)
            : null;

        if ($parent === null) {
            return;
        }

        $last = $task->messages()->whereIn('author_type', [ActorType::Agent, ActorType::User])->reorder('id', 'desc')->first();
        $files = $last?->attachments()->get()->map(fn (MessageAttachment $a) => "{$a->reference()} {$a->filename}")->all() ?? [];
        $summary = "A tarefa delegada {$task->identifier()} «{$task->title}» foi concluída".($by ? " por {$by->name}" : '').'.'
            .($last ? "\n\nÚltima resposta:\n".Str::limit($last->body, 1500) : '')
            .($files !== [] ? "\n\nFicheiros nessa resposta: ".implode(', ', $files).'. Podes lê-los (files.read) ou guardá-los no repositório (knowledge.file) pelo número.' : '');

        $note = $this->note($parent, $summary, kind: TaskMessageKind::Report);

        if ($parent->assignee_agent_id !== null && ! ($by instanceof Agent && $by->id === $parent->assignee_agent_id)) {
            $this->wake($parent, null, $note, TriggerType::Agent);
        }
    }

    /**
     * Give the task to a person (or take it from one). A task has one owner,
     * so the agent lets go of it.
     */
    public function assignPerson(Task $task, ?User $person, User|Agent $by): Task
    {
        $task->forceFill([
            'assignee_user_id' => $person?->id,
            'assignee_agent_id' => $person !== null ? null : $task->assignee_agent_id,
        ])->save();

        $this->note($task, $person !== null ? "{$by->name} atribuiu a {$person->name}." : "{$by->name} retirou a pessoa responsável.");

        if ($person !== null && ! ($by instanceof User && $by->id === $person->id)) {
            $this->notifyAssignee($task, $by);
        }

        TaskUpdated::live($task);

        return $task;
    }

    private function notifyAssignee(Task $task, User|Agent $by): void
    {
        $person = $task->assigneeUser;

        if ($person !== null) {
            $this->notifier->notify($person, "Nova tarefa {$task->identifier()}: {$task->title}", Str::limit((string) ($task->description ?? $task->title), 300), "/tasks/{$task->id}", $by->name, $task->priority->value === 'urgent' ? 'warning' : 'info', NotificationCategory::Task, ['task_id' => $task->id]);
        }
    }

    private function notifyUser(Task $task, string $title, string $body, bool $urgent = false, NotificationCategory $category = NotificationCategory::Task, ?int $runId = null): void
    {
        $user = $task->user ?? $task->createdByUser;

        if ($user === null) {
            return;
        }

        $this->notifier->notify($user, $title, $body, "/tasks/{$task->id}", $task->assigneeAgent?->name, $urgent ? 'warning' : 'info', $category, ['task_id' => $task->id, 'run_id' => $runId]);
    }

    private function runner(): AgentRunner
    {
        return app(AgentRunner::class);
    }
}
