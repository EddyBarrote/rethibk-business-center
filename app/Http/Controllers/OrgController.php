<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Task;
use App\Tasks\OrgChart;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The agent org chart (Paperclip's org page): who reports to whom, and what
 * each agent has on its plate.
 */
class OrgController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $agents = Agent::query()->where('status', '!=', AgentStatus::Draft)->with(['reportsTo:id,name', 'department:id,name'])->orderBy('name')->get();
        $open = Task::query()->open()->whereNotNull('assignee_agent_id')->selectRaw('assignee_agent_id, count(*) as total')->groupBy('assignee_agent_id')->pluck('total', 'assignee_agent_id');
        $waiting = Task::query()->where('status', TaskStatus::WaitingHuman)->selectRaw('assignee_agent_id, count(*) as total')->groupBy('assignee_agent_id')->pluck('total', 'assignee_agent_id');
        $running = AgentRun::query()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->pluck('agent_id')->flip();

        return Inertia::render('Org/Index', [
            'agents' => $agents->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'title' => $agent->title,
                'status' => $agent->status->value,
                'status_label' => $agent->status->label(),
                'autonomy_level' => $agent->autonomy_level->value,
                'department' => $agent->department?->name,
                'reports_to_user' => $agent->reportsTo?->name,
                'reports_to_agent_id' => $agent->reports_to_agent_id,
                'open_tasks' => (int) ($open[$agent->id] ?? 0),
                'waiting_tasks' => (int) ($waiting[$agent->id] ?? 0),
                'running' => isset($running[$agent->id]),
            ]),
            'can_manage' => $user->canManageTenant(),
        ]);
    }

    public function update(Request $request, Agent $agent, OrgChart $chart): RedirectResponse
    {
        Gate::authorize('manage', $agent);

        $data = $request->validate(['reports_to_agent_id' => ['nullable', 'integer', TenantRule::exists('agents')]]);
        $manager = $data['reports_to_agent_id'] ?? null;

        if ($chart->createsCycle($agent, $manager)) {
            throw ValidationException::withMessages(['reports_to_agent_id' => 'Isso criava um ciclo no organigrama.']);
        }

        $agent->forceFill(['reports_to_agent_id' => $manager])->save();
        AuditLog::record($this->user($request), 'agent.org_changed', ['reports_to_agent_id' => $manager], subject: $agent);

        return back()->with('success', 'Organigrama actualizado.');
    }
}
