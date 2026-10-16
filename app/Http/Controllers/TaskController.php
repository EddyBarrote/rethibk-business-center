<?php

namespace App\Http\Controllers;

use App\Ai\Runs\AgentNow;
use App\Attachments\AttachmentLimits;
use App\Attachments\AttachmentStore;
use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\GoalStatus;
use App\Enums\Permission;
use App\Enums\ProjectStatus;
use App\Enums\StepType;
use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Approval;
use App\Models\Capability;
use App\Models\EmailMessage;
use App\Models\Goal;
use App\Models\MessageAttachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Tasks\Handoffs;
use App\Tasks\TaskJourney;
use App\Tasks\TaskThread;
use App\Tenancy\TenantRule;
use App\Workflows\WorkflowGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tasks and conversations with agents (docs/DECISOES.md, 04.10.2026): the
 * list, the thread with its composer, and the properties panel.
 */
class TaskController extends Controller
{
    /** How many per page: the first is the default. */
    private const PER_PAGE = [20, 50, 100];

    /** What needs a person first, closed last: the order of the status groups. */
    private const STATUS_ORDER = ['waiting_human', 'in_progress', 'in_review', 'blocked', 'todo', 'done', 'cancelled'];

    private const PRIORITY_ORDER = ['urgent', 'high', 'normal', 'low'];

    /**
     * The tasks page (docs/DECISOES.md, "Página de tarefas"): views with their
     * counts, filters that stay in the address, search, groups and pages.
     */
    public function index(Request $request): RedirectResponse|Response
    {
        Gate::authorize('viewAny', Task::class);
        $user = $this->user($request);

        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['mine', 'all', 'chats', 'waiting', 'closed'])],
            'estado' => ['nullable', Rule::enum(TaskStatus::class)],
            'prioridade' => ['nullable', Rule::enum(TaskPriority::class)],
            'responsavel' => ['nullable', 'string', 'regex:/^((agent|user):\d+|ninguem)$/'],
            'prazo' => ['nullable', Rule::in(['atrasadas', 'semana', 'sem-prazo'])],
            'agent' => ['nullable', 'integer'],
            'goal' => ['nullable', 'integer'],
            'project' => ['nullable', 'integer'],
            'person' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
            'ordenar' => ['nullable', Rule::in(['actividade', 'prioridade', 'prazo', 'criacao'])],
            'agrupar' => ['nullable', Rule::in(['estado', 'prioridade', 'responsavel', 'nenhum'])],
            'por_pagina' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
        ]);
        $view = $filters['view'] ?? 'mine';

        // Conversations are opened from the agents in the sidebar (docs/DECISOES.md, "Conversas a partir dos agentes").
        if ($view === 'chats') {
            return redirect()->route('home');
        }
        // Older links (an agent's or a person's page) still say ?agent= or ?person=.
        $assignee = $filters['responsavel'] ?? match (true) {
            isset($filters['agent']) => "agent:{$filters['agent']}",
            isset($filters['person']) => "user:{$filters['person']}",
            default => null,
        };
        $search = trim((string) ($filters['q'] ?? ''));
        $status = isset($filters['estado']) ? TaskStatus::from($filters['estado']) : null;
        $sort = $filters['ordenar'] ?? 'actividade';
        $group = $filters['agrupar'] ?? 'estado';
        $perPage = (int) ($filters['por_pagina'] ?? self::PER_PAGE[0]);

        // Everything but the view and the status: the counters beside them use it too.
        $narrow = fn (Builder $q) => $q
            ->tap($this->listed(...))
            ->when($assignee, fn (Builder $q) => $this->assignedTo($q, (string) $assignee))
            ->when($filters['prioridade'] ?? null, fn (Builder $q, $priority) => $q->where('priority', $priority))
            ->when($filters['prazo'] ?? null, fn (Builder $q, $due) => $this->due($q, $due))
            ->when($filters['goal'] ?? null, fn (Builder $q, $goal) => $q->where('goal_id', $goal))
            ->when($filters['project'] ?? null, fn (Builder $q, $project) => $q->where('project_id', $project))
            ->when($search !== '', fn (Builder $q) => $this->search($q, $search));
        $base = fn (string $in) => $this->visible(Task::query(), $user)->tap($narrow)->tap(fn (Builder $q) => $this->inView($q, $in, $user));

        $page = $base($view)
            ->with(Present::TASK_RELATIONS)
            ->withCount('messages')
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->tap(fn (Builder $q) => $this->sorted($q, $group, $sort))
            ->paginate($perPage)
            ->withQueryString();

        $running = AgentRun::query()->whereIn('task_id', $page->getCollection()->modelKeys())->working()->pluck('task_id')->flip();
        $byStatus = $base($view)->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $open = fn () => $this->visible(Task::query(), $user)->tap($this->listed(...))->open();

        return Inertia::render('Tasks/Index', [
            'tasks' => $page->through(fn (Task $task) => [...Present::task($task), 'working' => isset($running[$task->id])]),
            'filters' => [
                'view' => $view,
                'estado' => $status?->value,
                'prioridade' => $filters['prioridade'] ?? null,
                'responsavel' => $assignee,
                'prazo' => $filters['prazo'] ?? null,
                'goal' => $filters['goal'] ?? null,
                'project' => $filters['project'] ?? null,
                'q' => $search,
                'ordenar' => $sort,
                'agrupar' => $group,
                'por_pagina' => $perPage,
            ],
            'per_page_options' => self::PER_PAGE,
            // Counted with the same rule and filters as the list, so a view never promises rows it does not show.
            'counts' => collect(['mine', 'waiting', 'all', 'closed'])->mapWithKeys(fn (string $in) => [$in => $base($in)->count()]),
            'status_counts' => collect(self::STATUS_ORDER)->mapWithKeys(fn (string $s) => [$s => (int) ($byStatus[$s] ?? 0)]),
            // The two strips on top: answers owed and deadlines passed, whatever the filters.
            'waiting_total' => $open()->tap(fn (Builder $q) => $this->inView($q, 'waiting', $user))->count(),
            'overdue_total' => $open()->tap(fn (Builder $q) => $this->due($q, 'atrasadas'))->tap(fn (Builder $q) => $this->inView($q, 'mine', $user))->count(),
            ...$this->formOptions($user),
        ]);
    }

    /**
     * Change several at once from the list: their status or their priority.
     * Tasks the person cannot change are left as they are.
     */
    public function bulk(Request $request, TaskThread $threads): RedirectResponse
    {
        Gate::authorize('viewAny', Task::class);
        $user = $this->user($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'status' => ['nullable', 'required_without:priority', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', 'required_without:status', Rule::enum(TaskPriority::class)],
        ]);

        $changed = 0;
        foreach ($this->visible(Task::query(), $user)->with(['parent', 'assigneeAgent'])->whereKey($data['ids'])->get() as $task) {
            if (! $user->can('update', $task)) {
                continue;
            }

            if (isset($data['priority']) && $task->priority->value !== $data['priority']) {
                $task->forceFill(['priority' => $data['priority']])->save();
                $threads->note($task, "{$user->name} mudou a prioridade para «{$task->priority->label()}».");
            }

            if (isset($data['status'])) {
                $threads->setStatus($task, TaskStatus::from($data['status']), $user);
            }

            $changed++;
        }

        return back()->with('success', match ($changed) {
            0 => 'Nenhuma tarefa foi alterada.',
            1 => 'Uma tarefa actualizada.',
            default => "{$changed} tarefas actualizadas.",
        });
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function inView(Builder $query, string $view, User $user): void
    {
        match ($view) {
            'mine' => $query->open()->needing($user),
            'all' => $query->open()->where('kind', TaskKind::Task),
            'waiting' => $query->where('status', TaskStatus::WaitingHuman),
            'closed' => $query->whereIn('status', [TaskStatus::Done, TaskStatus::Cancelled]),
            default => null,
        };
    }

    /**
     * "agent:3", "user:7" or "ninguem" (nobody yet).
     *
     * @param  Builder<Task>  $query
     */
    private function assignedTo(Builder $query, string $assignee): void
    {
        if ($assignee === 'ninguem') {
            $query->whereNull('assignee_agent_id')->whereNull('assignee_user_id');

            return;
        }

        [$type, $id] = explode(':', $assignee);
        $query->where($type === 'agent' ? 'assignee_agent_id' : 'assignee_user_id', (int) $id);
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function due(Builder $query, string $due): void
    {
        match ($due) {
            'atrasadas' => $query->open()->where('due_at', '<', now()),
            'semana' => $query->open()->whereBetween('due_at', [now(), now()->addDays(7)]),
            'sem-prazo' => $query->whereNull('due_at'),
            default => null,
        };
    }

    /**
     * Every word must appear in the title, the description, or the name of
     * whoever has it; "MIC-12" or "12" also finds task number 12.
     *
     * @param  Builder<Task>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $words = preg_split('/\s+/u', str_replace(['%', '_', '\\'], ' ', $search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_slice($words, 0, 8) as $word) {
            $number = preg_match('/^(?:[a-z]{1,3}-)?(\d{1,9})$/i', $word, $match) === 1 ? (int) $match[1] : null;
            $like = "%{$word}%";

            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhereHas('assigneeAgent', fn (Builder $a) => $a->where('name', 'like', $like))
                ->orWhereHas('assigneeUser', fn (Builder $u) => $u->where('name', 'like', $like))
                ->when($number !== null, fn (Builder $q) => $q->orWhere('number', $number)));
        }
    }

    /**
     * The group comes first, so a group is never split across pages out of
     * order; inside it, the chosen order.
     *
     * @param  Builder<Task>  $query
     */
    private function sorted(Builder $query, string $group, string $sort): void
    {
        $ranked = fn (string $column, array $order) => $query->orderByRaw(
            "case {$column} ".implode(' ', array_fill(0, count($order), 'when ? then ?')).' else '.count($order).' end',
            collect($order)->flatMap(fn (string $value, int $rank) => [$value, $rank])->all(),
        );

        match ($group) {
            'estado' => $ranked('status', self::STATUS_ORDER),
            'prioridade' => $ranked('priority', self::PRIORITY_ORDER),
            'responsavel' => $query->orderByRaw('assignee_agent_id is null')->orderBy('assignee_agent_id')->orderByRaw('assignee_user_id is null')->orderBy('assignee_user_id'),
            default => null,
        };

        match ($sort) {
            'prioridade' => $ranked('priority', self::PRIORITY_ORDER)->orderByDesc('last_activity_at'),
            'prazo' => $query->orderByRaw('due_at is null')->orderBy('due_at'),
            'criacao' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('last_activity_at'),
        };

        $query->orderByDesc('id');
    }

    /**
     * A conversation nobody has written in yet is not work: it stays out of
     * the lists and their counters.
     *
     * @param  Builder<Task>  $query
     */
    private function listed(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNot('kind', TaskKind::Chat)->orWhereHas('messages'));
    }

    public function store(Request $request, TaskThread $threads): RedirectResponse
    {
        Gate::authorize('viewAny', Task::class);
        $user = $this->user($request);

        $data = $request->validate([
            'kind' => ['required', Rule::enum(TaskKind::class)],
            'title' => ['required_if:kind,task', 'nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:10000', 'required_if:kind,chat'],
            'assignee_agent_id' => ['nullable', 'required_if:kind,chat', 'integer', TenantRule::exists('agents')],
            'assignee_user_id' => ['nullable', 'prohibits:assignee_agent_id', 'integer', TenantRule::exists('users')],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'goal_id' => ['nullable', 'integer', TenantRule::exists('goals')],
            'project_id' => ['nullable', 'integer', TenantRule::exists('projects')],
            'due_at' => ['nullable', 'date'],
        ]);

        $agent = isset($data['assignee_agent_id']) ? Agent::query()->find($data['assignee_agent_id']) : null;
        $kind = TaskKind::from($data['kind']);

        if ($agent !== null) {
            // Talking needs the first access level, giving work the second (realinhamento L8).
            Gate::authorize($kind === TaskKind::Chat ? 'run' : 'requestWork', $agent);
        }

        $message = $data['message'] ?? null;

        // Work is asked for in the conversation with an agent; chefias may also
        // open a task directly, for instance for a person (realinhamento L5).
        abort_if($kind === TaskKind::Task && ! $user->isManager(), 403, 'Peça trabalho na conversa com o agente.');
        $person = isset($data['assignee_user_id']) ? User::query()->where('is_active', true)->find($data['assignee_user_id']) : null;

        // A chat is the one conversation with that agent, never a new one.
        if ($kind === TaskKind::Chat && $agent !== null) {
            $chat = $threads->conversation($user, $agent);
            $threads->post($chat, $user, (string) $message);

            return to_route('tasks.show', $chat);
        }

        $task = $threads->open([
            'kind' => $kind,
            'title' => $data['title'] ?: mb_strimwidth((string) $message, 0, 80, '…'),
            'description' => $kind === TaskKind::Task ? $message : null,
            'status' => $kind === TaskKind::Chat ? TaskStatus::InProgress : TaskStatus::Todo,
            'priority' => $data['priority'] ?? TaskPriority::Normal->value,
            'assignee_agent_id' => $agent?->id,
            'assignee_user_id' => $person?->id,
            'user_id' => $user->id,
            'goal_id' => $data['goal_id'] ?? $this->goalOf($data['project_id'] ?? null),
            'project_id' => $data['project_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
        ], $user, $kind === TaskKind::Chat ? $message : null);

        return to_route('tasks.show', $task);
    }

    /**
     * Open the person's one conversation with an agent (Grok-style). Opening
     * it creates nothing: until the first message the page is a blank
     * conversation, and the message (agents.chat) creates it.
     */
    public function conversation(Request $request, Agent $agent, TaskThread $threads): RedirectResponse|Response
    {
        Gate::authorize('run', $agent);
        $user = $this->user($request);

        if (Task::query()->where('chat_key', TaskThread::chatKey($user, $agent))->exists()) {
            return to_route('tasks.show', $threads->conversation($user, $agent));
        }

        return Inertia::render('Agents/Chat', [
            'agent' => ['id' => $agent->id, 'name' => $agent->name, 'title' => $agent->title, 'avatar_url' => $agent->avatarUrl()],
            'attachment_limits' => AttachmentLimits::current()->forScreen(),
        ]);
    }

    /**
     * Write to an agent from its page: the message goes into that one
     * conversation, as a message or as a direct action ("Executar").
     */
    public function chat(Request $request, Agent $agent, TaskThread $threads, AttachmentStore $store): RedirectResponse
    {
        Gate::authorize('run', $agent);
        $user = $this->user($request);
        $data = $request->validate([
            'message' => ['required_without:attachment_ids', 'nullable', 'string', 'max:10000'],
            'mode' => ['sometimes', Rule::in(['message', 'action'])],
            'attachment_ids' => ['array'],
            'attachment_ids.*' => ['integer'],
        ]);
        $mode = $data['mode'] ?? 'message';

        if ($mode === 'action') {
            Gate::authorize('requestWork', $agent);
        }

        $files = $store->claim($user, array_map('intval', $data['attachment_ids'] ?? []), null);
        $chat = $threads->conversation($user, $agent);
        $threads->post($chat, $user, (string) ($data['message'] ?? ''), TaskMessageKind::from($mode), attachments: $files);

        return to_route('tasks.show', $chat);
    }

    public function show(Request $request, Task $task): Response
    {
        Gate::authorize('view', $task);
        $user = $this->user($request);
        $task->load(['assigneeAgent', 'user:id,name', 'createdByAgent:id,name', 'createdByUser:id,name', 'goal:id,title', 'parent:id,number,title,tenant_id', 'tenant:id,slug']);

        $active = $task->runs()->working()->latest('id')->first();
        $elsewhere = $task->kind === TaskKind::Chat && $task->assigneeAgent !== null
            ? app(AgentNow::class)->for($task->assigneeAgent, $user, exceptTask: $task, withLast: false, withRecent: true)
            : ['active' => [], 'recent' => []];
        $mine = $task->chat_key !== null && $task->user_id === $user->id;
        $handoffs = app(Handoffs::class);

        if ($mine) {
            // Read up to now: the sidebar stops counting its answers as unread.
            $task->forceFill(['chat_read_at' => now()])->saveQuietly();
        }

        return Inertia::render('Tasks/Show', [
            'task' => [
                ...Present::task($task),
                'description' => $task->description,
                'due_at' => $task->due_at?->toIso8601String(),
                'started_at' => $task->started_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'parent' => $task->parent ? ['id' => $task->parent->id, 'ref' => $task->parent->identifier(), 'title' => $task->parent->title] : null,
                'source' => $this->source($task),
                // The agent who answers here: the assigned one, or the one that gave a person this task.
                'agent' => ($agent = app(TaskThread::class)->agentOf($task)) ? Present::agent($agent) : null,
            ],
            'messages' => $task->messages()->with(['authorUser:id,name', 'authorAgent:id,name', 'attachments' => fn ($q) => $q->ready(), 'question.answeredBy:id,name', 'handoff.fromAgent:id,name', 'handoff.toAgent:id,name', 'handoff.fromTask', 'handoff.toTask', 'email:id,subject,from_name,from_address'])->get()->map(fn (TaskMessage $m) => [
                // A message between agents, shown as a row (docs/DECISOES.md, "Mensagens entre agentes").
                'handoff' => $handoffs->present($m, $task, $user),
                'id' => $m->id,
                // The agent's questions, as a card on its message (docs/DECISOES.md, "Perguntas dos agentes").
                'question' => $m->question ? [
                    'id' => $m->question->id,
                    'status' => $m->question->status->value,
                    'questions' => $m->question->questions,
                    'answers' => $m->question->answers,
                    'answered_by' => $m->question->answeredBy?->name,
                    'answered_at' => $m->question->answered_at?->toIso8601String(),
                    'can_answer' => $m->question->isPending() && ($user->id === $m->question->asked_user_id || $user->can('reply', $task)),
                ] : null,
                'attachments' => $m->attachments->map(fn (MessageAttachment $a) => Present::attachment($a))->values(),
                // The email the person brought in from the inbox.
                'email' => $m->email ? ['id' => $m->email->id, 'subject' => $m->email->subject ?: '(sem assunto)', 'from' => $m->email->from_name ?: $m->email->from_address] : null,
                'author_type' => $m->author_type->value,
                'author' => $m->authorName(),
                'author_id' => $m->author_user_id ?? $m->author_agent_id,
                'kind' => $m->kind->value,
                'body' => $m->body,
                'run_id' => $m->agent_run_id,
                'created_at' => $m->created_at->toIso8601String(),
            ]),
            'children' => $task->children()->with(Present::TASK_RELATIONS)->orderBy('id')->get()->map(fn (Task $child) => Present::task($child)),
            'runs' => $task->runs()->with(['agent:id,name', 'requestedBy:id,name'])->latest('id')->limit(50)->get()->map(fn (AgentRun $run) => Present::run($run)),
            'working' => $active ? Present::run($active->load('agent:id,name')) : null,
            // The tools each turn called, shown under the agent's answer (docs/DECISOES.md, "Página de conversa").
            'tool_calls' => $this->toolCalls($task),
            // The run open in the side panel (?execucao=), so "Ver execução" never leaves the conversation.
            'panel_run' => fn () => $this->panelRun($request, $task, $user),
            'tool_names' => fn () => Capability::query()->pluck('name', 'key'),
            'approvals' => Approval::query()
                ->whereIn('agent_run_id', $task->runs()->select('id'))
                ->where('status', ApprovalStatus::Pending)
                ->orderBy('id')
                ->get()
                ->map(fn (Approval $approval) => [
                    'id' => $approval->id,
                    'summary' => $approval->action_summary,
                    // The screen writes the action in words from its type and arguments (resources/js/lib/approvals.ts).
                    'action_type' => $approval->action_type,
                    'payload' => $approval->payload,
                    'ceiling_reason' => $approval->ceiling_reason,
                    'can_decide' => $user->can('decide', $approval),
                ]),
            'workflow' => $this->workflow($task, $user),
            // The path of the case across agents and people, loaded when the "Percurso" tab opens.
            'has_journey' => $task->kind !== TaskKind::Chat && TaskJourney::worthShowing($task),
            'journey' => Inertia::optional(fn () => app(TaskJourney::class)->for($task, fn (Task $node) => $user->can('view', $node))),
            'attachment_limits' => AttachmentLimits::current()->forScreen(),
            // In a conversation, the side panel's "Tarefas": the person's work with this agent and its approvals.
            // In a conversation, what the agent is busy with elsewhere (a task, a routine, a flow), so the
            // sidebar's "a trabalhar" is explained here too (docs/DECISOES.md, "Agente ocupado noutro sítio").
            'agent_elsewhere' => $elsewhere['active'],
            // What it finished there in the last half hour, with the result, so the card does not just vanish.
            'agent_elsewhere_done' => $elsewhere['recent'],
            'agent_work' => fn () => $mine && $task->assigneeAgent !== null ? $this->agentWork($task->assigneeAgent, $user) : null,
            'can' => [
                'reply' => $user->can('reply', $task),
                'update' => $user->can('update', $task),
                'manage_agent' => $task->assigneeAgent !== null && $user->can('manage', $task->assigneeAgent),
            ],
            ...$this->formOptions($user),
        ]);
    }

    /**
     * The person's tasks with an agent (open first) and the agent's approvals they can see.
     *
     * @return array{tasks: list<array<string, mixed>>, approvals: list<array<string, mixed>>}
     */
    private function agentWork(Agent $agent, User $user): array
    {
        return [
            'tasks' => Task::query()
                ->withAgentFor($agent, $user)
                ->with(Present::TASK_RELATIONS)
                ->orderByRaw('case when status in (?, ?) then 1 else 0 end', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
                ->latest('last_activity_at')
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (Task $task) => Present::task($task))
                ->all(),
            'approvals' => Approval::query()
                ->with([...Present::APPROVAL_RELATIONS, 'run:id,task_id'])
                ->where('agent_id', $agent->id)
                ->visibleTo($user)
                ->pending()
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (Approval $approval) => [...Present::approval($approval, $user->can('decide', $approval)), 'task_id' => $approval->run?->task_id])
                ->all(),
        ];
    }

    /**
     * The flow this task follows, block by block, or the decision of a flow's
     * step this task asks of a person (docs/DECISOES.md, "Fluxos de trabalho").
     *
     * @return array<string, mixed>|null
     */
    private function workflow(Task $task, User $user): ?array
    {
        $run = WorkflowRun::query()->with('workflow:id,name')->where('task_id', $task->id)->latest('id')->first();
        $step = WorkflowStep::query()->with('run.workflow:id,name')->where('task_id', $task->id)->latest('id')->first();

        if ($run === null && $step === null) {
            return null;
        }

        $progress = null;

        if ($run !== null) {
            $graph = new WorkflowGraph($run->graph);
            $progress = [
                'workflow' => ['id' => $run->workflow_id, 'name' => $run->workflow->name ?? 'Fluxo'],
                'status' => $run->status->value,
                'status_label' => $run->status->label(),
                'steps' => $run->steps()->with('task:id,number,tenant_id,title')->orderBy('id')->get()->map(fn (WorkflowStep $row) => [
                    'id' => $row->id,
                    'label' => match ($row->kind) {
                        'list' => 'Listar: '.$graph->label($row->node_id),
                        'until' => 'Continuar? '.$graph->label($row->node_id),
                        'fallback' => 'Com uma pessoa: '.$graph->label($row->node_id),
                        default => $graph->label($row->node_id),
                    },
                    'kind' => $row->kind,
                    'status' => $row->status->value,
                    'status_label' => $row->status->label(),
                    'item' => $row->item,
                    'answer' => $row->answer,
                    'task' => $row->task ? ['id' => $row->task->id, 'ref' => $row->task->identifier()] : null,
                ])->values(),
            ];
        }

        $decision = null;

        if ($step !== null && $step->status->isOpen() && $task->assignee_user_id !== null) {
            $kind = $step->kind === 'fallback' ? ($step->output['blocked_kind'] ?? 'agent') : $step->kind;
            $decision = [
                'step_id' => $step->id,
                'workflow' => $step->run->workflow->name ?? 'Fluxo',
                'question' => (new WorkflowGraph($step->run->graph))->label($step->node_id),
                'options' => match (true) {
                    $step->kind === WorkflowGraph::APPROVAL => [['value' => 'approved', 'label' => 'Aprovar'], ['value' => 'rejected', 'label' => 'Rejeitar']],
                    in_array($kind, [WorkflowGraph::CONDITION, 'until'], true) => [['value' => 'yes', 'label' => 'Sim'], ['value' => 'no', 'label' => 'Não']],
                    default => [['value' => 'done', 'label' => 'Feito, continuar o fluxo']],
                },
                'can_decide' => $task->assignee_user_id === $user->id || $user->hasPermission(Permission::DecideAllApprovals),
            ];
        }

        return ['progress' => $progress, 'decision' => $decision];
    }

    public function message(Request $request, Task $task, TaskThread $threads, AttachmentStore $store): RedirectResponse
    {
        Gate::authorize('reply', $task);

        $data = $request->validate([
            'body' => ['required_without:attachment_ids', 'nullable', 'string', 'max:10000'],
            'mode' => ['required', Rule::in(['message', 'action'])],
            'attachment_ids' => ['array'],
            'attachment_ids.*' => ['integer'],
        ]);
        $files = $store->claim($this->user($request), array_map('intval', $data['attachment_ids'] ?? []), $task);

        if ($data['mode'] === 'action' && $task->assigneeAgent !== null) {
            Gate::authorize('requestWork', $task->assigneeAgent);
        }

        if ($task->status->isClosed()) {
            $threads->setStatus($task, TaskStatus::InProgress, $this->user($request), 'Reaberta com uma mensagem nova.');
        }

        $threads->post($task, $this->user($request), (string) ($data['body'] ?? ''), TaskMessageKind::from($data['mode']), attachments: $files);

        return back();
    }

    public function update(Request $request, Task $task, TaskThread $threads): RedirectResponse
    {
        Gate::authorize('update', $task);
        $user = $this->user($request);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'assignee_agent_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('agents')],
            'assignee_user_id' => ['sometimes', 'nullable', 'prohibits:assignee_agent_id', 'integer', TenantRule::exists('users')],
            'goal_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('goals')],
            'project_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('projects')],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($task->chat_key !== null) {
            // A conversation belongs to one person and one agent.
            unset($data['assignee_agent_id'], $data['assignee_user_id']);
        }

        if (array_key_exists('assignee_user_id', $data) && $data['assignee_user_id'] !== $task->assignee_user_id) {
            $person = $data['assignee_user_id'] ? User::query()->where('is_active', true)->findOrFail($data['assignee_user_id']) : null;
            abort_unless($user->isManager() || $person?->id === $user->id, 403);

            $threads->assignPerson($task, $person, $user);
        }

        if (array_key_exists('assignee_agent_id', $data) && $data['assignee_agent_id'] !== $task->assignee_agent_id) {
            $agent = $data['assignee_agent_id'] ? Agent::query()->findOrFail($data['assignee_agent_id']) : null;

            if ($agent !== null) {
                Gate::authorize('run', $agent);
            }

            $task->forceFill(['assignee_agent_id' => $agent?->id, 'assignee_user_id' => $agent !== null ? null : $task->assignee_user_id])->save();
            $threads->note($task, $agent ? "{$user->name} atribuiu a {$agent->name}." : "{$user->name} retirou o agente.");

            if ($agent !== null && $task->kind === TaskKind::Task && ! $task->status->isClosed()) {
                $threads->wake($task->refresh(), $user, $task);
            }
        }

        if (! empty($data['project_id']) && $task->goal_id === null && ! array_key_exists('goal_id', $data)) {
            // A task in a project serves that project's goal.
            $data['goal_id'] = $this->goalOf($data['project_id']);
        }

        $task->fill(collect($data)->only(['title', 'priority', 'goal_id', 'project_id', 'due_at'])->all())->save();

        if (isset($data['status'])) {
            $threads->setStatus($task, TaskStatus::from($data['status']), $user);
        }

        return back();
    }

    /**
     * Owners and admins see everything; others the threads they are part of
     * and the work of agents they answer for or work with (TaskPolicy::view).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function visible(Builder $query, User $user): Builder
    {
        return $query->visibleTo($user);
    }

    /**
     * Choices for the new-task dialog and the properties panel.
     *
     * @return array<string, mixed>
     */
    private function formOptions(User $user): array
    {
        return [
            'agents' => Agent::query()->where('status', AgentStatus::Active)->orderBy('name')->get()
                ->filter(fn (Agent $agent) => $user->can('run', $agent))
                ->map(fn (Agent $agent) => ['id' => $agent->id, 'name' => $agent->name, 'title' => $agent->title])
                ->values(),
            'goals' => Goal::query()->whereIn('status', [GoalStatus::Planned, GoalStatus::Active])->orderBy('title')->get(['id', 'title']),
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'projects' => Project::query()->whereIn('status', [ProjectStatus::Planned, ProjectStatus::Active])->orderBy('name')->get(['id', 'name', 'goal_id']),
            'statuses' => collect(TaskStatus::cases())->map(fn (TaskStatus $s) => ['value' => $s->value, 'label' => $s->label()]),
            'priorities' => collect(TaskPriority::cases())->map(fn (TaskPriority $p) => ['value' => $p->value, 'label' => $p->label()]),
        ];
    }

    private function goalOf(?int $projectId): ?int
    {
        return $projectId === null ? null : Project::query()->whereKey($projectId)->value('goal_id');
    }

    /**
     * Where the task came from, as a link the person can open.
     *
     * @return array{label: string, href: string}|null
     */
    private function source(Task $task): ?array
    {
        $source = $task->source;

        return match (true) {
            $source instanceof EmailMessage => ['label' => "Email: {$source->subject}", 'href' => "/inbox/{$source->id}"],
            default => null,
        };
    }

    /**
     * The tools each run of this task called, in order, paired with how they
     * ended: a call is "running" until its result, "approval" while a person
     * has to decide, then "ok", "error" or "rejected".
     *
     * @return array<int, list<array{step_id: int, key: string, name: string, status: string, duration_ms: int|null, link: array{label: string, href: string}|null}>>
     */
    private function toolCalls(Task $task): array
    {
        $runIds = $task->runs()->latest('id')->limit(50)->pluck('id');

        if ($runIds->isEmpty()) {
            return [];
        }

        $names = Capability::query()->pluck('name', 'key');
        $steps = AgentRunStep::query()
            ->whereIn('agent_run_id', $runIds)
            ->whereIn('type', [StepType::ToolCall, StepType::ToolResult, StepType::Error, StepType::Approval])
            ->whereNotNull('tool_name')
            ->orderBy('agent_run_id')
            ->orderBy('seq')
            ->get(['id', 'agent_run_id', 'seq', 'type', 'tool_name', 'payload', 'duration_ms']);

        $calls = [];
        $open = [];

        foreach ($steps as $step) {
            $run = $step->agent_run_id;
            $key = (string) $step->tool_name;

            if ($step->type === StepType::ToolCall) {
                $calls[$run][] = ['step_id' => $step->id, 'key' => $key, 'name' => $names[$key] ?? $key, 'status' => 'running', 'duration_ms' => null, 'link' => null];
                $open[$run][$key] = array_key_last($calls[$run]);

                continue;
            }

            $index = $open[$run][$key] ?? null;

            if ($index === null) {
                continue;
            }

            if ($step->type === StepType::Approval) {
                $calls[$run][$index]['status'] = ($step->payload['status'] ?? null) === 'rejected' ? 'rejected' : 'approval';

                continue;
            }

            $calls[$run][$index]['status'] = $step->type === StepType::ToolResult ? 'ok' : 'error';
            $calls[$run][$index]['duration_ms'] = $step->duration_ms;
            $calls[$run][$index]['link'] = $step->type === StepType::ToolResult ? $this->resultLink($key, $step->payload['content'] ?? null) : null;
            unset($open[$run][$key]);
        }

        return $calls;
    }

    /**
     * Where to see what a tool made, so "fiz um rascunho" comes with a button
     * to open it (docs/DECISOES.md, "Ver o que o agente fez"). Only paths of
     * this console that the tool itself returned; the page they open checks
     * who may see it. Generated files already show as attachments.
     *
     * @return array{label: string, href: string}|null
     */
    private function resultLink(string $key, mixed $content): ?array
    {
        $data = is_string($content) ? json_decode($content, true) : null;

        if (! is_array($data)) {
            return null;
        }

        [$label, $href] = match ($key) {
            'email.draft_reply' => ['Ver rascunho', isset($data['draft_id']) ? "/inbox/{$data['draft_id']}" : null],
            'reports.draft' => ['Abrir relatório'.(isset($data['report_id']) ? " #{$data['report_id']}" : ''), $data['link'] ?? null],
            'followups.schedule' => ($data['scheduled'] ?? false) === true
                ? ['Ver na Agenda', '/agenda'.(isset($data['due_at']) && is_string($data['due_at']) ? '?data='.substr($data['due_at'], 0, 10) : '')]
                : [null, null],
            'tasks.create', 'escalate.urgent' => ['Abrir '.($data['task'] ?? 'tarefa'), $data['link'] ?? null],
            'agents.message' => ['Abrir a conversa com '.($data['sent_to'] ?? 'o colega'), $data['conversation'] ?? null],
            'knowledge.save', 'knowledge.file' => ['Abrir no Conhecimento', $data['link'] ?? null],
            default => [null, null],
        };

        return is_string($label) && is_string($href) && preg_match('#^/[^/]#', $href) === 1 ? ['label' => $label, 'href' => $href] : null;
    }

    /**
     * One run of this task, with its timeline, for the side panel.
     *
     * @return array<string, mixed>|null
     */
    private function panelRun(Request $request, Task $task, User $user): ?array
    {
        $id = $request->integer('execucao');
        $run = $id > 0 ? $task->runs()->with(['agent', 'requestedBy'])->find($id) : null;

        if ($run === null || ! $user->can('view', $run->agent)) {
            return null;
        }

        return [
            'run' => Present::run($run),
            'steps' => $run->steps()->orderBy('seq')->get()->map(fn (AgentRunStep $step) => $step->toBroadcast()),
            'approvals' => $run->approvals()->with(Present::APPROVAL_RELATIONS)->get()
                ->map(fn (Approval $approval) => Present::approval($approval, $user->can('decide', $approval))),
        ];
    }
}
