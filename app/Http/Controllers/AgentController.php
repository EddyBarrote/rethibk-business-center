<?php

namespace App\Http\Controllers;

use App\Access\AgentAccess;
use App\Ai\Budget\BudgetGuard;
use App\Ai\Memory\MemoryConsolidator;
use App\Ai\Runs\AgentRunner;
use App\Enums\AgentStatus;
use App\Enums\Permission;
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
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
                'is_active' => $routine->is_active,
                'last_run_at' => $routine->last_run_at?->toIso8601String(),
            ]),
            'runs' => $agent->runs()->with(['agent:id,name', 'requestedBy:id,name'])->latest('id')->limit(25)->get()->map(fn (AgentRun $run) => Present::run($run)),
            'memories' => $user->can('viewMemory', $agent) ? AgentMemory::query()->where('agent_id', $agent->id)->with(['aboutUser:id,name', 'task:id,number,title,tenant_id', 'task.tenant:id,slug'])->latest('id')->limit(300)->get()->map(fn (AgentMemory $memory) => [
                'id' => $memory->id,
                'content' => $memory->content,
                'kind' => $memory->kind,
                'about' => $memory->aboutUser?->name,
                'task' => $memory->task ? ['id' => $memory->task->id, 'ref' => $memory->task->identifier()] : null,
                'knowledge_item_id' => $memory->knowledge_item_id,
                'created_at' => $memory->created_at->toIso8601String(),
            ]) : null,
            'users' => $user->can('manageAccess', $agent) ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'can' => [
                'run' => $user->can('run', $agent),
                'manage' => $user->can('manage', $agent),
                'manage_access' => $user->can('manageAccess', $agent),
                'view_memory' => $user->can('viewMemory', $agent),
            ],
        ]);
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
        $agent->assignees()->sync($rows);

        AuditLog::record($this->user($request), 'agent.access_updated', ['access' => $data['access']], subject: $agent);

        return back()->with('success', 'Acessos guardados.');
    }

    /**
     * Correct a fact the agent remembers (realinhamento, decisão 18).
     */
    public function updateMemory(Request $request, Agent $agent, AgentMemory $memory, MemoryConsolidator $consolidator): RedirectResponse
    {
        Gate::authorize('viewMemory', $agent);
        abort_unless($memory->agent_id === $agent->id, 404);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:500'],
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

        AuditLog::record($this->user($request), 'agent.memory_deleted', ['memory_id' => $memory->id, 'content' => $memory->content], subject: $agent);
        $memory->delete();
        $consolidator->publish($agent);

        return back()->with('success', 'Esquecido.');
    }
}
