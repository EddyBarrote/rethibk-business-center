<?php

namespace App\Http\Controllers;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Runs\AgentRunner;
use App\Enums\AgentStatus;
use App\Enums\TriggerType;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Skill;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's view of its agents (section 11.2). The definition belongs to
 * the super admin; here people run, suspend, reactivate and assign them.
 */
class AgentController extends Controller
{
    public function index(BudgetGuard $budget): Response
    {
        Gate::authorize('viewAny', Agent::class);

        $agents = Agent::query()->with(['department:id,name', 'reportsTo:id,name'])->where('status', '!=', AgentStatus::Draft)->orderBy('name')->get();
        $pending = Approval::query()->pending()->selectRaw('agent_id, count(*) as total')->groupBy('agent_id')->pluck('total', 'agent_id');
        $lastRuns = AgentRun::query()->selectRaw('agent_id, max(created_at) as last')->groupBy('agent_id')->pluck('last', 'agent_id');

        return Inertia::render('Agents/Index', [
            'agents' => $agents->map(fn (Agent $agent) => [
                ...Present::agent($agent),
                'pending_approvals' => (int) ($pending[$agent->id] ?? 0),
                'last_run_at' => isset($lastRuns[$agent->id]) ? now()->parse($lastRuns[$agent->id])->toIso8601String() : null,
                'spent_usd' => round($budget->agentSpent($agent), 4),
            ]),
        ]);
    }

    public function show(Request $request, Agent $agent): Response
    {
        Gate::authorize('view', $agent);
        $user = $this->user($request);

        return Inertia::render('Agents/Show', [
            'agent' => [
                ...Present::agent($agent),
                'personality' => $agent->personality,
                'provider' => $agent->provider ?: config('ai.default'),
                'model' => $agent->model ?: (config('agents.model') ?: 'por omissão'),
                'assignees' => $agent->assignees()->orderBy('name')->get(['users.id', 'users.name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]),
            ],
            'skills' => $agent->skills()->wherePivot('enabled', true)->orderBy('key')->get()->map(fn (Skill $skill) => [
                'key' => $skill->key,
                'name' => $skill->name,
                'is_mutating' => $skill->is_mutating,
                'risk' => $skill->risk->value,
                'ceiling' => config('autonomy.ceiling.'.$skill->key) !== null,
            ]),
            'routines' => $agent->routines()->orderBy('name')->get()->map(fn (AgentRoutine $routine) => [
                'id' => $routine->id,
                'name' => $routine->name,
                'schedule' => $routine->schedule,
                'is_active' => $routine->is_active,
                'last_run_at' => $routine->last_run_at?->toIso8601String(),
            ]),
            'runs' => $agent->runs()->with(['agent:id,name', 'requestedBy:id,name'])->latest('id')->limit(25)->get()->map(fn (AgentRun $run) => Present::run($run)),
            'users' => $user->can('manage', $agent) ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'can' => [
                'run' => $user->can('run', $agent),
                'manage' => $user->can('manage', $agent),
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

    public function updateAssignees(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('manage', $agent);

        $data = $request->validate([
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', TenantRule::exists('users')],
        ]);

        $agent->assignees()->sync($data['user_ids'] ?? []);

        AuditLog::record($this->user($request), 'agent.assignees_updated', ['user_ids' => $data['user_ids'] ?? []], subject: $agent);

        return back()->with('success', 'Afectações guardadas.');
    }
}
