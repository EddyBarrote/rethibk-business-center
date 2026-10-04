<?php

namespace App\Http\Controllers;

use App\Enums\GoalStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Goal;
use App\Models\Task;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company goals: what the work is for. Everyone sees them; managers set them.
 */
class GoalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $goals = Goal::query()->with(['ownerAgent:id,name', 'ownerUser:id,name'])->orderByRaw("case status when 'active' then 0 when 'planned' then 1 when 'achieved' then 2 else 3 end")->orderBy('title')->get();
        $counts = Task::query()->whereNotNull('goal_id')->selectRaw('goal_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as done', [TaskStatus::Done->value])->groupBy('goal_id')->get()->keyBy('goal_id');

        return Inertia::render('Goals/Index', [
            'goals' => $goals->map(fn (Goal $goal) => [
                'id' => $goal->id,
                'title' => $goal->title,
                'description' => $goal->description,
                'status' => $goal->status->value,
                'status_label' => $goal->status->label(),
                'parent_id' => $goal->parent_id,
                'owner' => $goal->ownerAgent->name ?? $goal->ownerUser->name ?? null,
                'owner_agent_id' => $goal->owner_agent_id,
                'target_date' => $goal->target_date?->toDateString(),
                'tasks_total' => (int) ($counts[$goal->id]->total ?? 0),
                'tasks_done' => (int) ($counts[$goal->id]->done ?? 0),
            ]),
            'agents' => Agent::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => collect(GoalStatus::cases())->map(fn (GoalStatus $s) => ['value' => $s->value, 'label' => $s->label()]),
            'can_manage' => $user->isManager(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->isManager(), 403);

        $goal = Goal::query()->create([...$this->validated($request), 'owner_user_id' => $user->id]);
        AuditLog::record($user, 'goal.created', ['goal_id' => $goal->id], subject: $goal);

        return back()->with('success', 'Objectivo criado.');
    }

    public function update(Request $request, Goal $goal): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->isManager(), 403);

        $data = $this->validated($request, $goal);
        $goal->fill($data)->save();
        AuditLog::record($user, 'goal.updated', ['goal_id' => $goal->id, 'status' => $goal->status->value], subject: $goal);

        return back()->with('success', 'Objectivo actualizado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Goal $goal = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'status' => ['required', Rule::enum(GoalStatus::class)],
            'parent_id' => ['nullable', 'integer', TenantRule::exists('goals'), Rule::notIn(array_filter([$goal?->id]))],
            'owner_agent_id' => ['nullable', 'integer', TenantRule::exists('agents')],
            'target_date' => ['nullable', 'date'],
        ]);
    }
}
