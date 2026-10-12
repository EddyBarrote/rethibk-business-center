<?php

namespace App\Console\Commands\Agents;

use App\Ai\Runs\StaleRuns;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Closes the runs that lost their worker (docs/DECISOES.md, "Agente ocupado noutro sítio").
 */
#[Signature('agents:close-stale-runs')]
#[Description('Fecha como falhadas as execuções presas em fila ou a correr sem processo')]
class CloseStaleRuns extends Command
{
    public function handle(TenantManager $tenants, StaleRuns $stale): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($stale, &$count): void {
            $count += $stale->sweep();
        });

        $this->components->info("{$count} execução(ões) presa(s) fechada(s).");

        return self::SUCCESS;
    }
}
