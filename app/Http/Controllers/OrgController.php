<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Tasks\OrgChart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The org chart (Paperclip's org page), with people and agents side by side
 * (docs/DECISOES.md, realinhamento L3): who reports to whom, and what each
 * one has on their plate.
 */
class OrgController extends Controller
{
    public function index(Request $request, OrgChart $chart): Response
    {
        $user = $this->user($request);
        $agents = Agent::query()->where('status', '!=', AgentStatus::Draft)->with(['reportsTo:id,name', 'department:id,name'])->orderBy('name')->get();
        $people = User::query()->where('is_active', true)->with('department:id,name')->orderBy('name')->get();

        $openByAgent = Task::query()->open()->whereNotNull('assignee_agent_id')->selectRaw('assignee_agent_id, count(*) as total')->groupBy('assignee_agent_id')->pluck('total', 'assignee_agent_id');
        $openByUser = Task::query()->open()->whereNotNull('assignee_user_id')->selectRaw('assignee_user_id, count(*) as total')->groupBy('assignee_user_id')->pluck('total', 'assignee_user_id');
        $waiting = Task::query()->where('status', TaskStatus::WaitingHuman)->selectRaw('assignee_agent_id, count(*) as total')->groupBy('assignee_agent_id')->pluck('total', 'assignee_agent_id');
        $running = AgentRun::query()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->pluck('agent_id')->flip();

        $members = $agents->map(fn (Agent $agent) => [
            'key' => OrgChart::key($agent),
            'type' => 'agent',
            'id' => $agent->id,
            'name' => $agent->name,
            'avatar_url' => $agent->avatarUrl(),
            'title' => $agent->title,
            'status' => $agent->status->value,
            'status_label' => $agent->status->label(),
            'autonomy_level' => $agent->autonomy_level->value,
            'department' => $agent->department?->name,
            'responsible' => $agent->reportsTo?->name,
            'manager' => $chart->managerOf($agent),
            'open_tasks' => (int) ($openByAgent[$agent->id] ?? 0),
            'waiting_tasks' => (int) ($waiting[$agent->id] ?? 0),
            'running' => isset($running[$agent->id]),
        ])->concat($people->map(fn (User $person) => [
            'key' => OrgChart::key($person),
            'type' => 'user',
            'id' => $person->id,
            'name' => $person->name,
            'avatar_url' => null,
            'title' => $person->job_title ?? $person->role->label(),
            'status' => 'active',
            'status_label' => 'Activo',
            'autonomy_level' => null,
            'department' => $person->department?->name,
            'responsible' => null,
            'manager' => $chart->managerOf($person),
            'open_tasks' => (int) ($openByUser[$person->id] ?? 0),
            'waiting_tasks' => 0,
            'running' => false,
        ]));

        return Inertia::render('Org/Index', [
            'members' => $members->values(),
            'can_manage' => $user->hasPermission(Permission::ManageOrg),
        ]);
    }

    public function update(Request $request, OrgChart $chart): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::ManageOrg), 403);

        $data = $request->validate([
            'member' => ['required', 'string', 'regex:/^(agent|user):\d+$/'],
            'manager' => ['nullable', 'string', 'regex:/^(agent|user):\d+$/'],
        ]);

        $member = $this->find($data['member']) ?? abort(404);
        $manager = $data['manager'] ?? null;

        if ($manager !== null && $this->find($manager) === null) {
            throw ValidationException::withMessages(['manager' => 'Essa chefia não existe.']);
        }

        if ($manager === $data['member'] || $chart->loops($member, $manager)) {
            throw ValidationException::withMessages(['manager' => 'Isso criava um ciclo no organigrama.']);
        }

        $chart->assign($member, $manager);
        AuditLog::record($user, 'org.changed', ['member' => $data['member'], 'manager' => $manager], subject: $member);

        return back()->with('success', 'Organigrama actualizado.');
    }

    private function find(string $key): Agent|User|null
    {
        [$type, $id] = explode(':', $key, 2);

        return $type === 'agent' ? Agent::query()->find((int) $id) : User::query()->find((int) $id);
    }
}
