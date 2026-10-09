<?php

namespace App\Console\Commands\Agents;

use App\Ai\Runs\RoutineRunner;
use App\Models\AgentRoutine;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Routines that are due run once (docs/DECISOES.md, "Rotinas e fluxos
 * agendados"); one missed while the scheduler was down runs when it is back.
 */
#[Signature('agents:run-routines')]
#[Description('Despacha as rotinas de agentes e fluxos que estão na hora (agendado a cada minuto)')]
class RunRoutines extends Command
{
    public function handle(TenantManager $tenants, RoutineRunner $routines): int
    {
        $now = now();
        $dispatched = 0;

        $tenants->eachActive(function () use ($now, $routines, &$dispatched): void {
            AgentRoutine::query()
                ->where('is_active', true)
                ->with(['agent', 'workflow.agent', 'owner', 'createdBy'])
                ->each(function (AgentRoutine $routine) use ($now, $routines, &$dispatched): void {
                    if ($routine->dueAt($now) === null) {
                        return;
                    }

                    if ($routines->run($routine, $now)['ok']) {
                        $dispatched++;
                    }
                });
        });

        $this->components->info("{$dispatched} rotina(s) despachada(s).");

        return self::SUCCESS;
    }
}
