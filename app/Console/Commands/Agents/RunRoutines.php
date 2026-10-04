<?php

namespace App\Console\Commands\Agents;

use App\Ai\Runs\AgentRunner;
use App\Enums\TriggerType;
use App\Models\AgentRoutine;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agents:run-routines')]
#[Description('Despacha as rotinas de agentes que estão na hora (agendado a cada minuto)')]
class RunRoutines extends Command
{
    public function handle(TenantManager $tenants, AgentRunner $runner): int
    {
        $now = now();
        $dispatched = 0;

        $tenants->eachActive(function () use ($now, $runner, &$dispatched): void {
            AgentRoutine::query()
                ->where('is_active', true)
                ->whereHas('agent', fn ($agents) => $agents->active())
                ->with('agent')
                ->each(function (AgentRoutine $routine) use ($now, $runner, &$dispatched): void {
                    if (! $routine->isDue($now)) {
                        return;
                    }

                    $routine->forceFill(['last_run_at' => $now])->save();
                    $runner->dispatch($routine->agent, $routine->prompt, TriggerType::Schedule, source: $routine);
                    $dispatched++;
                });
        });

        $this->components->info("{$dispatched} rotina(s) despachada(s).");

        return self::SUCCESS;
    }
}
