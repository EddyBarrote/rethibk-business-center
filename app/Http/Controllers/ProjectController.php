<?php

namespace App\Http\Controllers;

use App\Enums\GoalStatus;
use App\Enums\Permission;
use App\Enums\ProjectStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects between goals and tasks (docs/DECISOES.md, realinhamento L12).
 * Everyone sees them; managers create and edit them, like goals.
 */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $projects = Project::query()->with(['goal:id,title', 'leadUser:id,name', 'leadAgent:id,name'])
            ->orderByRaw("case status when 'active' then 0 when 'planned' then 1 when 'done' then 2 else 3 end")
            ->orderBy('name')
            ->get();
        $counts = Task::query()->where('kind', TaskKind::Task)->whereNotNull('project_id')
            ->selectRaw('project_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as done', [TaskStatus::Done->value])
            ->groupBy('project_id')->get()->keyBy('project_id');

        return Inertia::render('Projects/Index', [
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
                'goal' => $project->goal ? ['id' => $project->goal->id, 'title' => $project->goal->title] : null,
                'lead' => $project->leadAgent->name ?? $project->leadUser->name ?? null,
                'lead_is_agent' => $project->lead_agent_id !== null,
                'lead_user_id' => $project->lead_user_id,
                'lead_agent_id' => $project->lead_agent_id,
                'target_date' => $project->target_date?->toDateString(),
                'tasks_total' => (int) ($counts[$project->id]->total ?? 0),
                'tasks_done' => (int) ($counts[$project->id]->done ?? 0),
            ]),
            'goals' => Goal::query()->whereIn('status', [GoalStatus::Planned, GoalStatus::Active])->orderBy('title')->get(['id', 'title']),
            'agents' => Agent::query()->orderBy('name')->get(['id', 'name']),
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => collect(ProjectStatus::cases())->map(fn (ProjectStatus $s) => ['value' => $s->value, 'label' => $s->label()]),
            'can_manage' => $user->hasPermission(Permission::ManageProjects),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::ManageProjects), 403);

        $project = Project::query()->create($this->validated($request));
        AuditLog::record($user, 'project.created', ['project_id' => $project->id], subject: $project);

        return back()->with('success', 'Projecto criado.');
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::ManageProjects), 403);

        $project->fill($this->validated($request))->save();
        AuditLog::record($user, 'project.updated', ['project_id' => $project->id, 'status' => $project->status->value], subject: $project);

        return back()->with('success', 'Projecto actualizado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'goal_id' => ['nullable', 'integer', TenantRule::exists('goals')],
            'lead_user_id' => ['nullable', 'integer', TenantRule::exists('users')],
            'lead_agent_id' => ['nullable', 'integer', TenantRule::exists('agents')],
            'target_date' => ['nullable', 'date'],
        ]);
    }
}
