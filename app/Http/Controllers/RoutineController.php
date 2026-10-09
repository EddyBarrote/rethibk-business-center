<?php

namespace App\Http\Controllers;

use App\Ai\Runs\RoutineRunner;
use App\Enums\AgentStatus;
use App\Enums\WorkflowTrigger;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workflow;
use App\Tenancy\TenantRule;
use Cron\CronExpression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
            ->with(['agent.reportsTo', 'workflow.agent', 'workflow.fallbackUser', 'owner', 'createdBy', 'lastRun', 'lastTask'])
            ->orderBy('name')
            ->get();

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
                    $routine->lastRun !== null => "/runs/{$routine->lastRun->id}",
                    default => null,
                },
                'last_task' => $routine->lastTask?->kind->value === 'task' ? $routine->lastTask->identifier() : null,
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
        $routine = AgentRoutine::query()->create([...$this->validated($request), 'created_by_user_id' => $user->id]);

        AuditLog::record($user, 'routine.created', ['routine_id' => $routine->id, 'schedule' => $routine->schedule, 'agent_id' => $routine->agent_id, 'workflow_id' => $routine->workflow_id], subject: $routine);

        return to_route('routines.index')->with('success', "Rotina criada: {$routine->scheduleLabel()}.");
    }

    public function update(Request $request, AgentRoutine $routine): RedirectResponse
    {
        $user = $this->admin($request);
        $routine->fill($this->validated($request))->save();

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
        $result = $runner->run($routine);

        AuditLog::record($user, 'routine.run_now', ['routine_id' => $routine->id, 'ok' => $result['ok']], subject: $routine);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
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
