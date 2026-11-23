<?php

namespace App\Http\Controllers;

use App\Access\AgentAccess;
use App\Ai\Autonomy\PeopleData;
use App\Ai\Budget\BudgetGuard;
use App\Ai\Memory\MemoryConsolidator;
use App\Ai\Runs\AgentNow;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\RunMetrics;
use App\Enums\AgentStatus;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentAssignment;
use App\Models\AgentMemory;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Skill;
use App\Models\Task;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's view of its agents (section 11.2): people run, suspend,
 * reactivate and assign them. Owners and admins edit their definition in
 * AgentDefinitionController.
 */
class AgentController extends Controller
{
    public function index(Request $request, BudgetGuard $budget): Response
    {
        Gate::authorize('viewAny', Agent::class);
        $user = $this->user($request);

        // Drafts are work in progress: only the people who can edit them see them.
        $agents = Agent::query()->with(['department:id,name', 'reportsTo:id,name'])
            ->when(! $user->can('create', Agent::class), fn ($query) => $query->where('status', '!=', AgentStatus::Draft))
            ->orderBy('name')
            ->get();
        $pending = Approval::query()->pending()->selectRaw('agent_id, count(*) as total')->groupBy('agent_id')->pluck('total', 'agent_id');
        $lastRuns = AgentRun::query()->selectRaw('agent_id, max(created_at) as last')->groupBy('agent_id')->pluck('last', 'agent_id');

        return Inertia::render('Agents/Index', [
            'agents' => $agents->map(fn (Agent $agent) => [
                ...Present::agent($agent),
                'pending_approvals' => (int) ($pending[$agent->id] ?? 0),
                'last_run_at' => isset($lastRuns[$agent->id]) ? now()->parse($lastRuns[$agent->id])->toIso8601String() : null,
                'spent_usd' => $user->hasPermission(Permission::ViewCosts) ? round($budget->agentSpent($agent), 4) : null,
            ]),
            'can' => ['create' => $user->can('create', Agent::class)],
        ]);
    }

    public function show(Request $request, Agent $agent): Response
    {
        Gate::authorize('view', $agent);
        $user = $this->user($request);

        return Inertia::render('Agents/Show', [
            'tool_names' => Capability::query()->pluck('name', 'key'),
            'agent' => [
                ...Present::agent($agent),
                'personality' => $agent->personality,
                'provider' => $agent->provider ?: config('ai.default'),
                'model' => $agent->model ?: (config('agents.model') ?: 'por omissão'),
                // The model by its name (docs/DECISOES.md, "Agentes: escolher o modelo").
                'model_label' => collect((array) config('agents.models'))->firstWhere('model', $agent->model ?: config('agents.model') ?: collect((array) config('agents.models'))->first()['model'] ?? null)['label'] ?? ($agent->model ?: 'Por omissão'),
                'assignees' => AgentAssignment::query()->where('agent_id', $agent->id)->with('user:id,name')->get()
                    ->filter(fn (AgentAssignment $row) => $row->user !== null)
                    ->sortBy(fn (AgentAssignment $row) => $row->user->name)
                    ->map(fn (AgentAssignment $row) => ['id' => $row->user->id, 'name' => $row->user->name, 'level' => $row->role === AgentAccess::CHAT ? AgentAccess::CHAT : AgentAccess::WORK])
                    ->values(),
            ],
            'capabilities' => $agent->capabilities()->wherePivot('enabled', true)->orderBy('key')->get()->map(fn (Capability $capability) => [
                'key' => $capability->key,
                'name' => $capability->name,
                'is_mutating' => $capability->is_mutating,
                'risk' => $capability->risk->value,
                'ceiling' => config('autonomy.ceiling.'.$capability->key) !== null,
            ]),
            'skills' => $agent->skills()->with('platformSkill')->get()->map(fn (Skill $skill) => [
                'id' => $skill->id,
                'key' => $skill->key,
                'name' => $skill->displayName(),
                'description' => $skill->displayDescription(),
                'scope' => $skill->ownership()->value,
                'is_available' => $skill->isUsable(),
            ])->sortBy('name')->values(),
            'routines' => $agent->routines()->orderBy('name')->get()->map(fn (AgentRoutine $routine) => [
                'id' => $routine->id,
                'name' => $routine->name,
                'schedule' => $routine->schedule,
                'schedule_label' => $routine->scheduleLabel(),
                'is_active' => $routine->is_active,
                'last_run_at' => $routine->last_run_at?->toIso8601String(),
            ]),
            // Only the runs this person may open (AgentRunPolicy).
            'runs' => $agent->runs()->visibleTo($user)->with(['agent:id,name', 'requestedBy:id,name'])->latest('id')->limit(25)->get()->map(fn (AgentRun $run) => Present::run($run)),
            // What the agent is doing right now, at the top of its page (docs/DECISOES.md, "Agente: o que está a fazer").
            'now' => fn () => app(AgentNow::class)->for($agent, $user),
            // Runs, failures, latency and cost over 30 days (docs/DECISOES.md, "Latência, taxa de erro e versões das skills").
            'metrics' => fn () => $this->metrics($agent, $user),
            // The memory itself lives in Definições › Memória dos agentes (12.10.2026).
            'memory_count' => $user->can('viewMemory', $agent) ? AgentMemory::query()->where('agent_id', $agent->id)->count() : null,
            // The tasks between this person and the agent, inside the agent's page (09.10.2026).
            'tasks' => fn () => $this->tasks($agent, $user),
            // Other people's conversations with it, read-only, for whoever may read them all (docs/DECISOES.md, "Conversas a partir dos agentes").
            // A confidential agent's, only for people who may manage people (PeopleData).
            'conversations' => fn () => $user->hasPermission(Permission::ReadAllConversations) && ! PeopleData::closedTo($agent, $user) ? $this->conversations($agent, $user) : null,
            // A confidential agent is given only to people who may manage people.
            'users' => $user->can('manageAccess', $agent) ? $this->grantable($agent) : [],
            'can' => [
                'run' => $user->can('run', $agent),
                'request_work' => $user->can('requestWork', $agent),
                'manage' => $user->can('manage', $agent),
                'manage_access' => $user->can('manageAccess', $agent),
                'view_memory' => $user->can('viewMemory', $agent),
            ],
        ]);
    }

    /**
     * The last 30 days in numbers, for whoever sees AI costs or configures
     * agents; the cost only for the first. A confidential agent's, only for
     * people who may manage people (PeopleData).
     *
     * @return array<string, mixed>|null
     */
    private function metrics(Agent $agent, User $user): ?array
    {
        $costs = $user->hasPermission(Permission::ViewCosts);

        if (PeopleData::closedTo($agent, $user) || ! ($costs || $user->can('manage', $agent))) {
            return null;
        }

        $row = app(RunMetrics::class)->forAgent($agent->id, now()->subDays(30));

        return [
            'days' => 30,
            'runs' => $row['runs'],
            'failed' => $row['failed'],
            'finished' => $row['completed'] + $row['failed'],
            'error_rate' => $row['error_rate'],
            'p50_ms' => $row['p50_ms'],
            'p90_ms' => $row['p90_ms'],
            'spent' => $costs ? $row['spent'] : null,
        ];
    }

    public function run(Request $request, Agent $agent, AgentRunner $runner): RedirectResponse
    {
        Gate::authorize('run', $agent);

        $data = $request->validate(['input' => ['required', 'string', 'max:10000']]);
        $run = $runner->dispatch($agent, $data['input'], TriggerType::Manual, $this->user($request));

        return to_route('runs.show', $run);
    }

    public function updateStatus(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('manage', $agent);

        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $active = $data['status'] === 'active';
        $agent->forceFill([
            'status' => $active ? AgentStatus::Active : AgentStatus::Suspended,
            'suspended_reason' => $active ? null : ($data['reason'] ?? 'Suspenso manualmente.'),
        ])->save();

        AuditLog::record($this->user($request), $active ? 'agent.reactivated' : 'agent.suspended', ['reason' => $agent->suspended_reason], subject: $agent);

        return back()->with('success', $active ? 'Agente reactivado.' : 'Agente suspenso.');
    }

    /**
     * Who talks to the agent and at which level (realinhamento L8).
     */
    public function updateAssignees(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('manageAccess', $agent);

        $data = $request->validate([
            'access' => ['present', 'array'],
            'access.*.user_id' => ['required', 'integer', TenantRule::exists('users')],
            'access.*.level' => ['required', Rule::in(AgentAccess::LEVELS)],
        ]);

        $rows = [];
        foreach ($data['access'] as $row) {
            $rows[(int) $row['user_id']] = ['role' => $row['level']];
        }

        // A confidential agent exists only for people who may manage people (PeopleData).
        if (PeopleData::agentHolds($agent) && ($without = User::query()->whereKey(array_keys($rows))->get()->reject(fn (User $person) => PeopleData::mayRead($person)))->isNotEmpty()) {
            throw ValidationException::withMessages(['access' => PeopleData::accessRefusal($agent, $without->pluck('name')->all())]);
        }

        $agent->assignees()->sync($rows);

        AuditLog::record($this->user($request), 'agent.access_updated', ['access' => $data['access']], subject: $agent);

        return back()->with('success', 'Acessos guardados.');
    }

    /**
     * Correct an entry the agent remembers (realinhamento, decisão 18; entries since 12.10.2026).
     */
    public function updateMemory(Request $request, Agent $agent, AgentMemory $memory, MemoryConsolidator $consolidator): RedirectResponse
    {
        Gate::authorize('viewMemory', $agent);
        abort_unless($memory->agent_id === $agent->id, 404);

        $data = $request->validate([
            ...AgentMemory::rules(),
            'kind' => ['required', Rule::in([AgentMemory::WORK, AgentMemory::PERSONAL])],
        ]);

        $memory->fill([...$data, 'edited_by_user_id' => $this->user($request)->id])->save();
        AuditLog::record($this->user($request), 'agent.memory_updated', ['memory_id' => $memory->id], subject: $agent);
        $consolidator->publish($agent);

        return back()->with('success', 'Memória corrigida.');
    }

    public function destroyMemory(Request $request, Agent $agent, AgentMemory $memory, MemoryConsolidator $consolidator): RedirectResponse
    {
        Gate::authorize('viewMemory', $agent);
        abort_unless($memory->agent_id === $agent->id, 404);

        AuditLog::record($this->user($request), 'agent.memory_deleted', ['memory_id' => $memory->id, 'title' => $memory->title, 'content' => $memory->content], subject: $agent);
        $memory->delete();
        $consolidator->publish($agent);

        return back()->with('success', 'Esquecido.');
    }

    /**
     * Who may be given access to the agent: every active person, or for a
     * confidential agent only those who may manage people (PeopleData).
     *
     * @return list<array{id: int, name: string}>
     */
    private function grantable(Agent $agent): array
    {
        $people = User::query()->where('is_active', true)->orderBy('name')->get();

        if (PeopleData::agentHolds($agent)) {
            $people = $people->filter(fn (User $person) => PeopleData::mayRead($person));
        }

        return $people->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name])->values()->all();
    }

    /**
     * The person's tasks with the agent: assigned to it or delegated by it,
     * and asked by, assigned to or opened for this person. Open ones first.
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(Agent $agent, User $user): array
    {
        return Task::query()
            ->withAgentFor($agent, $user)
            ->visibleTo($user)
            ->with(Present::TASK_RELATIONS)
            ->orderByRaw('case when status in (?, ?) then 1 else 0 end', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->latest('last_activity_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Task $task) => Present::task($task))
            ->all();
    }

    /**
     * The other people's conversations with this agent, the most recent first.
     *
     * @return list<array{id: int, person: string|null, messages: int, status: string, status_label: string, last_activity_at: string|null}>
     */
    private function conversations(Agent $agent, User $user): array
    {
        return Task::query()
            ->whereNotNull('chat_key')
            ->where('assignee_agent_id', $agent->id)
            ->where('user_id', '!=', $user->id)
            ->whereHas('messages')
            ->with('user:id,name')
            ->withCount('messages')
            ->latest('last_activity_at')
            ->limit(100)
            ->get()
            ->map(fn (Task $chat) => [
                'id' => $chat->id,
                'person' => $chat->user?->name,
                'messages' => (int) $chat->messages_count,
                'status' => $chat->status->value,
                'status_label' => $chat->status->label(),
                'last_activity_at' => $chat->last_activity_at?->toIso8601String(),
            ])
            ->all();
    }
}
