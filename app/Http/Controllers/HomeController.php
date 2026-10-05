<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "A minha caixa", the landing page (docs/DECISOES.md, realinhamento L2):
 * what needs me, my work, and my colleagues, people and agents side by side.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $this->user($request);
        $with = ['assigneeAgent:id,name', 'user:id,name', 'createdByAgent:id,name', 'createdByUser:id,name', 'goal:id,title', 'tenant:id,slug'];

        $waiting = Task::query()->with($with)
            ->where('status', TaskStatus::WaitingHuman)
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity_at')
            ->limit(20)
            ->get();

        $review = Task::query()->with($with)
            ->where('kind', TaskKind::Task)
            ->where('status', TaskStatus::InReview)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('created_by_user_id', $user->id))
            ->orderByDesc('last_activity_at')
            ->limit(20)
            ->get();

        $approvals = Approval::query()->with(['agent', 'run:id,task_id'])->visibleTo($user)->pending()->latest('id')->limit(20)->get();

        $shown = $waiting->pluck('id')->merge($review->pluck('id'));
        $work = Task::query()->with($with)
            ->where('kind', TaskKind::Task)
            ->open()
            ->needing($user)
            ->whereNotIn('id', $shown)
            ->orderByDesc('last_activity_at')
            ->limit(20)
            ->get();

        return Inertia::render('Home', [
            'waiting' => $waiting->map(fn (Task $task) => Present::task($task)),
            'review' => $review->map(fn (Task $task) => Present::task($task)),
            'approvals' => $approvals->map(fn (Approval $approval) => [
                ...Present::approval($approval, $user->can('decide', $approval)),
                'task_id' => $approval->run?->task_id,
            ]),
            'work' => $work->map(fn (Task $task) => Present::task($task)),
            'colleagues' => [
                'people' => $this->people($user),
                'agents' => Agent::query()->where('status', AgentStatus::Active)->orderBy('name')->get()
                    ->filter(fn (Agent $agent) => $user->can('run', $agent))
                    ->map(fn (Agent $agent) => [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'title' => $agent->title,
                        'avatar_url' => $agent->avatarUrl(),
                    ])
                    ->values(),
            ],
        ]);
    }

    /**
     * People of my department; without one, everyone.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    private function people(User $user): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->when($user->department_id !== null, fn ($q) => $q->where('department_id', $user->department_id))
            ->orderBy('name')
            ->limit(30)
            ->get()
            ->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name, 'role' => $person->role->label()])
            ->all();
    }
}
