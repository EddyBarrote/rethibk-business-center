<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AuditLog;
use App\Models\Tenant;
use Cron\CronExpression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Routine actions: a prompt the agent runs on a cron schedule, read in
 * config('agents.schedule_timezone').
 */
class AgentRoutineController extends AdminController
{
    public function store(Request $request, Tenant $tenant, Agent $agent): RedirectResponse
    {
        $routine = $agent->routines()->create($this->validated($request));

        AuditLog::record($this->admin($request), 'agent.routine_created', ['routine_id' => $routine->id, 'schedule' => $routine->schedule], subject: $agent);

        return back()->with('success', 'Rotina criada.');
    }

    public function update(Request $request, Tenant $tenant, Agent $agent, AgentRoutine $routine): RedirectResponse
    {
        abort_unless($routine->agent_id === $agent->id, 404);

        $routine->update($this->validated($request));

        AuditLog::record($this->admin($request), 'agent.routine_updated', ['routine_id' => $routine->id, 'schedule' => $routine->schedule, 'is_active' => $routine->is_active], subject: $agent);

        return back()->with('success', 'Rotina guardada.');
    }

    public function destroy(Request $request, Tenant $tenant, Agent $agent, AgentRoutine $routine): RedirectResponse
    {
        abort_unless($routine->agent_id === $agent->id, 404);

        $routine->delete();

        AuditLog::record($this->admin($request), 'agent.routine_deleted', ['routine_id' => $routine->id], subject: $agent);

        return back()->with('success', 'Rotina apagada.');
    }

    /**
     * @return array{name: string, prompt: string, schedule: string, is_active: bool}
     */
    private function validated(Request $request): array
    {
        /** @var array{name: string, prompt: string, schedule: string, is_active?: bool} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:10000'],
            'schedule' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) {
                if (! CronExpression::isValidExpression((string) $value)) {
                    $fail('Expressão cron inválida (ex.: "0 7 * * 1-5" para dias úteis às 07:00).');
                }
            }],
            'is_active' => ['boolean'],
        ]);

        return [...$data, 'is_active' => (bool) ($data['is_active'] ?? true)];
    }
}
