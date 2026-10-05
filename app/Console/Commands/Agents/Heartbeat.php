<?php

namespace App\Console\Commands\Agents;

use App\Enums\AgentStatus;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\Task;
use App\Support\TenantSettings;
use App\Tasks\TaskThread;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Paperclip's heartbeat (docs/DECISOES.md, realinhamento L6): agents also
 * wake on their own to pick up work they own that has gone quiet. One task
 * per agent per beat, the one waiting longest; runs count against the budget
 * like any other.
 */
#[Signature('agents:heartbeat')]
#[Description('Acorda cada agente para a tarefa sua parada há mais tempo (por omissão, de hora a hora)')]
class Heartbeat extends Command
{
    public function handle(TenantManager $tenants, TaskThread $threads): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($threads, &$count): void {
            $minutes = TenantSettings::int('heartbeat_minutes');

            if ($minutes <= 0) {
                return;
            }

            foreach (Agent::query()->where('status', AgentStatus::Active)->get() as $agent) {
                $task = Task::query()
                    ->where('assignee_agent_id', $agent->id)
                    ->where('kind', TaskKind::Task)
                    ->whereIn('status', [TaskStatus::Todo, TaskStatus::InProgress])
                    ->where('last_activity_at', '<', now()->subMinutes($minutes))
                    ->whereDoesntHave('runs', fn ($runs) => $runs->whereIn('status', [RunStatus::Queued, RunStatus::Running, RunStatus::AwaitingApproval]))
                    ->oldest('last_activity_at')
                    ->first();

                if ($task !== null && $threads->nudge($task) !== null) {
                    $count++;
                }
            }
        });

        $this->components->info("{$count} agente(s) acordado(s).");

        return self::SUCCESS;
    }
}
