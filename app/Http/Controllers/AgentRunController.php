<?php

namespace App\Http\Controllers;

use App\Enums\TaskKind;
use App\Enums\TriggerType;
use App\Http\Presenters\Present;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Approval;
use App\Models\Capability;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Run history and the live timeline of one run (section 11.2).
 */
class AgentRunController extends Controller
{
    /**
     * Execuções. Work and conversations are kept apart (third visual review):
     * by default the list is the agents' work (routines, tasks, email), and a
     * person's chat turns appear grouped under their conversation.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'origin' => ['nullable', 'in:rotina,tarefa,email,conversa'],
        ]);
        $origin = $filters['origin'] ?? null;
        $status = $filters['status'] ?? null;
        $chat = fn (Builder $task) => $task->where('kind', TaskKind::Chat);

        $runs = AgentRun::query()
            ->when($status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($origin === null, fn (Builder $query) => $query->whereDoesntHave('task', $chat))
            ->when($origin === 'rotina', fn (Builder $query) => $query->where('trigger_type', TriggerType::Schedule)->whereNull('task_id'))
            ->when($origin === 'tarefa', fn (Builder $query) => $query->whereHas('task', fn (Builder $task) => $task->where('kind', TaskKind::Task)))
            ->when($origin === 'email', fn (Builder $query) => $query->where('trigger_type', TriggerType::Email))
            ->when($origin === 'conversa', fn (Builder $query) => $query->whereHas('task', $chat));

        if ($origin === 'conversa') {
            $groups = $runs
                ->selectRaw('task_id, count(*) as turns, max(id) as last_id, sum(cost_usd) as cost')
                ->groupBy('task_id')
                ->orderByDesc('last_id')
                ->paginate(30)
                ->withQueryString();
            $tasks = Task::query()->with(['assigneeAgent:id,name', 'user:id,name'])->whereIn('id', $groups->pluck('task_id'))->get()->keyBy('id');
            $last = AgentRun::query()->whereIn('id', $groups->pluck('last_id'))->get(['id', 'status', 'created_at'])->keyBy('id');

            return Inertia::render('Runs/Index', [
                'runs' => null,
                'conversations' => $groups->through(fn (AgentRun $group) => [
                    'task_id' => $group->task_id,
                    'agent' => $tasks->get($group->task_id)?->assigneeAgent?->name,
                    'person' => $tasks->get($group->task_id)?->user?->name,
                    'turns' => (int) $group->getAttribute('turns'),
                    'cost_usd' => (float) $group->getAttribute('cost'),
                    'last_status' => $last->get($group->getAttribute('last_id'))?->status->value,
                    'last_status_label' => $last->get($group->getAttribute('last_id'))?->status->label(),
                    'last_at' => $last->get($group->getAttribute('last_id'))?->created_at->toIso8601String(),
                ]),
                'filters' => ['status' => $status, 'origin' => $origin],
            ]);
        }

        $runs = $runs->with(['agent:id,name', 'requestedBy:id,name'])->latest('id')->paginate(30)->withQueryString();

        return Inertia::render('Runs/Index', [
            'runs' => $runs->through(fn (AgentRun $run) => Present::run($run)),
            'conversations' => null,
            'filters' => ['status' => $status, 'origin' => $origin],
            // Requests written for agents name tools by key; the list shows their names.
            'tool_names' => Capability::query()->pluck('name', 'key'),
        ]);
    }

    public function show(Request $request, AgentRun $run): Response
    {
        Gate::authorize('view', $run->agent);
        $user = $this->user($request);

        return Inertia::render('Runs/Show', [
            'run' => Present::run($run->load(['agent', 'requestedBy'])),
            'steps' => $run->steps()->orderBy('seq')->get()->map(fn (AgentRunStep $step) => $step->toBroadcast()),
            'approvals' => $run->approvals()->with(Present::APPROVAL_RELATIONS)->get()
                ->map(fn (Approval $approval) => Present::approval($approval, $user->can('decide', $approval))),
            // The timeline names each tool as people read it in Capacidades; the key stays in a tooltip.
            'tool_names' => Capability::query()->pluck('name', 'key'),
        ]);
    }
}
