<?php

namespace App\Http\Controllers;

use App\Ai\Autonomy\PeopleData;
use App\Ai\Runs\RoutineRunner;
use App\Enums\AgentStatus;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkflowTrigger;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Tenancy\TenantRule;
use Cron\CronExpression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rotinas (docs/DECISOES.md, "Rotinas e fluxos agendados"): the admin says
 * what an agent does, or which flow starts, on a schedule, and who gets the
 * result. Only admins create and change them.
 */
class RoutineController extends Controller
{
    public function index(Request $request, RoutineRunner $runner): Response
    {
        $this->admin($request);

        $routines = AgentRoutine::query()
            ->with(['agent.reportsTo', 'workflow.agent.reportsTo', 'workflow.fallbackUser', 'owner', 'createdBy', 'lastRun', 'lastTask'])
            ->orderBy('name')
            ->get();
        $user = $this->user($request);
        // The text of a run only for whoever may open it (AgentRunPolicy).
        $readable = AgentRun::query()->visibleTo($user)->whereIn('agent_routine_id', $routines->pluck('id'))->where('created_at', '>=', now()->subDays(60))
            ->pluck('id')->merge(AgentRun::query()->visibleTo($user)->whereIn('id', $routines->pluck('last_run_id')->filter())->pluck('id'))->flip();
        $history = $this->history($routines, $readable);
        $since = now()->subDays(30);

        return Inertia::render('Routines/Index', [
            'routines' => $routines->map(fn (AgentRoutine $routine) => [
                'id' => $routine->id,
                'name' => $routine->name,
                'kind' => $routine->workflow_id !== null ? 'workflow' : 'agent',
                'agent' => $routine->agent ? ['id' => $routine->agent->id, 'name' => $routine->agent->name, 'active' => $routine->agent->isActive()] : null,
                'workflow' => $routine->workflow ? [
                    'id' => $routine->workflow->id,
                    'name' => $routine->workflow->name,
                    'active' => $routine->workflow->status->value === 'active',
                    'agent' => $routine->workflow->agent?->name,
                ] : null,
                'prompt' => $routine->prompt,
                'schedule' => $routine->schedule,
                'schedule_label' => $routine->scheduleLabel(),
                'owner_user_id' => $routine->owner_user_id,
                'recipient' => ($routine->workflow_id !== null
                    ? ($routine->owner ?? $routine->workflow->fallbackUser ?? $runner->recipient($routine, $routine->workflow?->agent))
                    : $runner->recipient($routine, $routine->agent))?->name,
                'is_active' => $routine->is_active,
                'created_by' => $routine->createdBy?->name,
                'last_run_at' => $routine->last_run_at?->toIso8601String(),
                'last_status' => $routine->lastRun?->status->value,
                'last_status_label' => $routine->lastRun?->status->label(),
                'last_url' => match (true) {
                    $routine->lastTask !== null => "/tasks/{$routine->lastTask->id}",
                    $routine->lastRun !== null => "/settings/runs/{$routine->lastRun->id}",
                    default => null,
                },
                'last_task' => $routine->lastTask?->kind->value === 'task' ? $routine->lastTask->identifier() : null,
                'last_output' => $routine->lastRun !== null && $readable->has($routine->lastRun->id) ? Str::limit(trim((string) ($routine->lastRun->output['text'] ?? '')), 1200) : null,
                'last_error' => $routine->lastRun?->error !== null && $readable->has($routine->lastRun->id) ? Str::limit((string) $routine->lastRun->error, 400) : null,
                'next_runs' => $routine->is_active ? array_map(fn (Carbon $at) => $at->toIso8601String(), $routine->nextRuns(3)) : [],
                'history' => $history[$routine->id] ?? [],
                'stats' => (function () use ($history, $routine, $since) {
                    $recent = collect($history[$routine->id] ?? [])->filter(fn (array $row) => $row['at'] !== null && Carbon::parse($row['at'])->gte($since));

                    return [
                        'total' => $recent->count(),
                        'ok' => $recent->where('tone', 'success')->count(),
                        'failed' => $recent->where('tone', 'danger')->count(),
                        'cost' => round((float) $recent->sum('cost'), 4),
                    ];
                })(),
            ])->values(),
            'agents' => Agent::query()->where('status', '!=', AgentStatus::Draft)->orderBy('name')->get(['id', 'name', 'title', 'status'])
                ->map(fn (Agent $agent) => ['id' => $agent->id, 'name' => $agent->name, 'title' => $agent->title, 'active' => $agent->isActive()])->values(),
            'workflows' => Workflow::query()->where('trigger', WorkflowTrigger::Schedule)->with('agent')->orderBy('name')->get()
                ->map(fn (Workflow $workflow) => [
                    'id' => $workflow->id,
                    'name' => $workflow->name,
                    'active' => $workflow->status->value === 'active',
                    'status_label' => $workflow->status->label(),
                    'agent' => $workflow->agent?->name,
                ])->values(),
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'timezone' => (string) config('agents.schedule_timezone'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->admin($request);
        $data = $this->validated($request);
        $this->peopleData($user, $data['agent_id'] !== null ? Agent::query()->find($data['agent_id']) : null);
        $routine = AgentRoutine::query()->create([...$data, 'created_by_user_id' => $user->id]);

        AuditLog::record($user, 'routine.created', ['routine_id' => $routine->id, 'schedule' => $routine->schedule, 'agent_id' => $routine->agent_id, 'workflow_id' => $routine->workflow_id], subject: $routine);

        return to_route('routines.index')->with('success', "Rotina criada: {$routine->scheduleLabel()}.");
    }

    public function update(Request $request, AgentRoutine $routine): RedirectResponse
    {
        $user = $this->admin($request);
        $data = $this->validated($request);
        $this->peopleData($user, $routine->agent, $data['agent_id'] !== null ? Agent::query()->find($data['agent_id']) : null);
        $routine->fill($data)->save();

        AuditLog::record($user, 'routine.updated', ['routine_id' => $routine->id, 'schedule' => $routine->schedule, 'is_active' => $routine->is_active], subject: $routine);

        return back()->with('success', $routine->is_active ? "Rotina guardada: {$routine->scheduleLabel()}." : 'Rotina guardada e pausada.');
    }

    public function destroy(Request $request, AgentRoutine $routine): RedirectResponse
    {
        $user = $this->admin($request);

        AuditLog::record($user, 'routine.deleted', ['routine_id' => $routine->id, 'name' => $routine->name], subject: $routine);
        $routine->delete();

        return back()->with('success', 'Rotina apagada.');
    }

    /**
     * "Correr agora": the same as when its time comes, to try it out.
     */
    public function run(Request $request, AgentRoutine $routine, RoutineRunner $runner): RedirectResponse
    {
        $user = $this->admin($request);
        $this->peopleData($user, $routine->agent);
        $result = $runner->run($routine);

        AuditLog::record($user, 'routine.run_now', ['routine_id' => $routine->id, 'ok' => $result['ok']], subject: $routine);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * What each routine did lately: the runs it started (a request to an agent)
     * or the tasks it opened (a flow), newest first.
     *
     * @param  Collection<int, AgentRoutine>  $routines
     * @param  Collection<int, int>  $readable  ids of the runs this person may open, as keys
     * @return array<int, list<array{kind: string, at: string|null, status: string, status_label: string, tone: string, summary: string, cost: float|null, duration_ms: int|null, url: string}>>
     */
    private function history(Collection $routines, Collection $readable): array
    {
        $ids = $routines->pluck('id');
        $history = [];

        AgentRun::query()->whereIn('agent_routine_id', $ids)->where('created_at', '>=', now()->subDays(60))->latest('id')->limit(500)->get()
            ->each(function (AgentRun $run) use (&$history, $readable) {
                $text = $readable->has($run->id) ? trim((string) ($run->output['text'] ?? '')) : '';
                $history[(int) $run->agent_routine_id][] = [
                    'kind' => 'run',
                    'at' => $run->created_at->toIso8601String(),
                    'status' => $run->status->value,
                    'status_label' => $run->status->label(),
                    'tone' => match ($run->status) {
                        RunStatus::Completed => 'success',
                        RunStatus::Failed => 'danger',
                        RunStatus::AwaitingApproval => 'warning',
                        RunStatus::Queued, RunStatus::Running => 'running',
                        default => 'idle',
                    },
                    'summary' => ! $readable->has($run->id) ? 'Resultado na conversa de quem o recebe.'
                        : Str::limit($run->status === RunStatus::Failed ? (string) $run->error : ($text !== '' ? $text : 'Sem texto: o agente usou só ferramentas.'), 160),
                    'cost' => $run->cost_usd,
                    'duration_ms' => $run->duration_ms,
                    'url' => $run->task_id !== null ? "/tasks/{$run->task_id}?execucao={$run->id}" : "/settings/runs/{$run->id}",
                ];
            });

        Task::query()->where('source_type', (new AgentRoutine)->getMorphClass())->whereIn('source_id', $ids)->latest('id')->limit(300)->get()
            ->each(function (Task $task) use (&$history) {
                $history[(int) $task->source_id][] = [
                    'kind' => 'task',
                    'at' => $task->created_at->toIso8601String(),
                    'status' => $task->status->value,
                    'status_label' => $task->status->label(),
                    'tone' => match (true) {
                        $task->status === TaskStatus::Done => 'success',
                        $task->status === TaskStatus::Cancelled => 'idle',
                        $task->status->isClosed() => 'idle',
                        $task->status === TaskStatus::Blocked => 'danger',
                        $task->status === TaskStatus::WaitingHuman, $task->status === TaskStatus::InReview => 'warning',
                        default => 'running',
                    },
                    'summary' => $task->title,
                    'cost' => null,
                    'duration_ms' => null,
                    'url' => "/tasks/{$task->id}",
                ];
            });

        return array_map(fn (array $rows) => array_slice($rows, 0, 15), $history);
    }

    /**
     * A routine of an agent that reads pay and personal data runs with those
     * reads allowed (PeopleData), so only someone who may manage people
     * creates, changes or runs it (docs/DECISOES.md, "Director de Recursos Humanos").
     */
    private function peopleData(User $user, ?Agent ...$agents): void
    {
        foreach ($agents as $agent) {
            if (PeopleData::agentHolds($agent) && ! PeopleData::mayRead($user)) {
                abort(403, "{$agent?->name} lê salários e dados de pessoal: só quem gere pessoas cria, muda ou corre as suas rotinas.");
            }
        }
    }

    private function admin(Request $request): User
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403, 'Só os admins criam e mudam rotinas.');

        return $user;
    }

    /**
     * @return array{name: string, agent_id: int|null, workflow_id: int|null, prompt: string|null, schedule: string, owner_user_id: int|null, is_active: bool}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['agent', 'workflow'])],
            'agent_id' => ['nullable', 'required_if:kind,agent', 'integer', TenantRule::exists('agents')],
            'workflow_id' => ['nullable', 'required_if:kind,workflow', 'integer', TenantRule::exists('workflows')],
            'prompt' => ['nullable', 'required_if:kind,agent', 'string', 'max:10000'],
            'schedule' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) {
                if (! CronExpression::isValidExpression((string) $value)) {
                    $fail('Horário inválido. Escolha uma das opções ou escreva-o em cron (ex.: "0 7 * * 1-5" para dias úteis às 07:00).');
                }
            }],
            'owner_user_id' => ['nullable', 'integer', TenantRule::exists('users')],
            'is_active' => ['boolean'],
        ], [
            'agent_id.required_if' => 'Escolha o agente.',
            'workflow_id.required_if' => 'Escolha o fluxo.',
            'prompt.required_if' => 'Diga o que o agente deve fazer.',
        ]);

        $owner = isset($data['owner_user_id']) ? User::query()->find($data['owner_user_id']) : null;

        if ($data['kind'] === 'agent') {
            $agent = Agent::query()->findOrFail($data['agent_id']);
            $reaches = $owner === null || Gate::forUser($owner)->allows('run', $agent) || (! $agent->isActive() && Gate::forUser($owner)->allows('view', $agent));

            if (! $reaches) {
                throw ValidationException::withMessages(['owner_user_id' => "{$owner->name} não tem acesso ao {$agent->name}: dê-lhe acesso ao agente ou escolha outra pessoa."]);
            }
        } else {
            $workflow = Workflow::query()->findOrFail($data['workflow_id']);

            if ($workflow->trigger !== WorkflowTrigger::Schedule) {
                throw ValidationException::withMessages(['workflow_id' => "«{$workflow->name}» começa com um email. Mude-o para «Por agenda» no editor do fluxo."]);
            }
        }

        return [
            'name' => $data['name'],
            'agent_id' => $data['kind'] === 'agent' ? (int) $data['agent_id'] : null,
            'workflow_id' => $data['kind'] === 'workflow' ? (int) $data['workflow_id'] : null,
            'prompt' => filled($data['prompt'] ?? null) ? trim((string) $data['prompt']) : null,
            'schedule' => trim(preg_replace('/\s+/', ' ', (string) $data['schedule']) ?? ''),
            'owner_user_id' => $owner?->id,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
