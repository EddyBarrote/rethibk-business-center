<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\GoalStatus;
use App\Enums\Permission;
use App\Enums\ProjectStatus;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\EmailMessage;
use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use App\Tasks\TaskThread;
use App\Tenancy\TenantRule;
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
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);
        $user = $this->user($request);

        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['mine', 'all', 'chats', 'waiting', 'closed'])],
            'agent' => ['nullable', 'integer'],
            'goal' => ['nullable', 'integer'],
            'project' => ['nullable', 'integer'],
            'person' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $view = $filters['view'] ?? 'mine';

        $query = $this->visible(Task::query(), $user)
            ->with(Present::TASK_RELATIONS)
            ->withCount('messages')
            // A conversation nobody has written in yet is not work: it stays out of the lists.
            ->where(fn (Builder $q) => $q->whereNot('kind', TaskKind::Chat)->orWhereHas('messages'))
            ->when($view === 'mine', fn (Builder $q) => $q->open()->needing($user))
            ->when($view === 'all', fn (Builder $q) => $q->open()->where('kind', TaskKind::Task))
            ->when($view === 'chats', fn (Builder $q) => $q->where('kind', TaskKind::Chat)->whereNot('status', TaskStatus::Cancelled))
            ->when($view === 'waiting', fn (Builder $q) => $q->where('status', TaskStatus::WaitingHuman))
            ->when($view === 'closed', fn (Builder $q) => $q->whereIn('status', [TaskStatus::Done, TaskStatus::Cancelled]))
            ->when($filters['agent'] ?? null, fn (Builder $q, $agent) => $q->where('assignee_agent_id', $agent))
            ->when($filters['goal'] ?? null, fn (Builder $q, $goal) => $q->where('goal_id', $goal))
            ->when($filters['project'] ?? null, fn (Builder $q, $project) => $q->where('project_id', $project))
            ->when($filters['person'] ?? null, fn (Builder $q, $person) => $q->where('assignee_user_id', $person))
            ->when($filters['q'] ?? null, fn (Builder $q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            ->orderByDesc('last_activity_at');

        $running = AgentRun::query()->whereNotNull('task_id')->whereIn('status', [RunStatus::Queued, RunStatus::Running])->pluck('task_id')->flip();

        return Inertia::render('Tasks/Index', [
            'tasks' => $query->limit(200)->get()->map(fn (Task $task) => [...Present::task($task), 'working' => isset($running[$task->id])]),
            'filters' => ['view' => $view, 'agent' => $filters['agent'] ?? null, 'goal' => $filters['goal'] ?? null, 'project' => $filters['project'] ?? null, 'q' => $filters['q'] ?? ''],
            'counts' => [
                'mine' => $this->visible(Task::query(), $user)->open()->needing($user)->count(),
                'waiting' => $this->visible(Task::query(), $user)->where('status', TaskStatus::WaitingHuman)->count(),
            ],
            ...$this->formOptions($user),
        ]);
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
        ]);
    }

    /**
     * Write to an agent from its page: the message goes into that one conversation.
     */
    public function chat(Request $request, Agent $agent, TaskThread $threads): RedirectResponse
    {
        Gate::authorize('run', $agent);
        $user = $this->user($request);
        $data = $request->validate(['message' => ['required', 'string', 'max:10000']]);

        $chat = $threads->conversation($user, $agent);
        $threads->post($chat, $user, $data['message']);

        return to_route('tasks.show', $chat);
    }

    public function show(Request $request, Task $task): Response
    {
        Gate::authorize('view', $task);
        $user = $this->user($request);
        $task->load(['assigneeAgent', 'user:id,name', 'createdByAgent:id,name', 'createdByUser:id,name', 'goal:id,title', 'parent:id,number,title,tenant_id', 'tenant:id,slug']);

        $active = $task->runs()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();

        return Inertia::render('Tasks/Show', [
            'task' => [
                ...Present::task($task),
                'description' => $task->description,
                'due_at' => $task->due_at?->toIso8601String(),
                'started_at' => $task->started_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'parent' => $task->parent ? ['id' => $task->parent->id, 'ref' => $task->parent->identifier(), 'title' => $task->parent->title] : null,
                'source' => $this->source($task),
                'agent' => $task->assigneeAgent ? Present::agent($task->assigneeAgent) : null,
            ],
            'messages' => $task->messages()->with(['authorUser:id,name', 'authorAgent:id,name'])->get()->map(fn (TaskMessage $m) => [
                'id' => $m->id,
                'author_type' => $m->author_type->value,
                'author' => $m->authorName(),
                'author_id' => $m->author_user_id ?? $m->author_agent_id,
                'kind' => $m->kind->value,
                'body' => $m->body,
                'run_id' => $m->agent_run_id,
                'created_at' => $m->created_at->toIso8601String(),
            ]),
            'children' => $task->children()->with(Present::TASK_RELATIONS)->orderBy('id')->get()->map(fn (Task $child) => Present::task($child)),
            'runs' => $task->runs()->with(['agent:id,name', 'requestedBy:id,name'])->latest('id')->limit(10)->get()->map(fn (AgentRun $run) => Present::run($run)),
            'working' => $active ? Present::run($active->load('agent:id,name')) : null,
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
            'can' => [
                'reply' => $user->can('reply', $task),
                'update' => $user->can('update', $task),
                'manage_agent' => $task->assigneeAgent !== null && $user->can('manage', $task->assigneeAgent),
            ],
            ...$this->formOptions($user),
        ]);
    }

    public function message(Request $request, Task $task, TaskThread $threads): RedirectResponse
    {
        Gate::authorize('reply', $task);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'mode' => ['required', Rule::in(['message', 'action'])],
        ]);

        if ($data['mode'] === 'action' && $task->assigneeAgent !== null) {
            Gate::authorize('requestWork', $task->assigneeAgent);
        }

        if ($task->status->isClosed()) {
            $threads->setStatus($task, TaskStatus::InProgress, $this->user($request), 'Reaberta com uma mensagem nova.');
        }

        $threads->post($task, $this->user($request), $data['body'], TaskMessageKind::from($data['mode']));

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
        $chats = fn (Builder $q) => $user->hasPermission(Permission::ReadAllConversations)
            ? $q
            : $q->where(fn (Builder $c) => $c->whereNull('chat_key')->orWhere('user_id', $user->id));

        if ($user->can('viewAll', Task::class)) {
            return $chats($query);
        }

        $agents = Agent::query()
            ->where(fn (Builder $q) => $q->where('reports_to_user_id', $user->id)->orWhereHas('assignees', fn (Builder $a) => $a->whereKey($user->id)))
            ->pluck('id');

        return $chats($query)->where(fn (Builder $q) => $q->where('user_id', $user->id)
            ->orWhere('created_by_user_id', $user->id)
            ->orWhere('assignee_user_id', $user->id)
            ->orWhereIn('assignee_agent_id', $agents));
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
}
