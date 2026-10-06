<?php

namespace App\Console\Commands\Watch;

use App\Console\Concerns\DispatchesRoles;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agents:daily-briefing {--weekly : O briefing semanal, em vez do diário}')]
#[Description('Pede ao Chief of Staff o briefing diário (dias úteis às 06:30) ou semanal (segundas às 07:00)')]
class DailyBriefing extends Command
{
    use DispatchesRoles;

    public function handle(TenantManager $tenants): int
    {
        $weekly = (bool) $this->option('weekly');
        $count = 0;

        $tenants->eachActive(function () use ($weekly, &$count): void {
            $prompt = $weekly
                ? 'Prepara o briefing semanal (tipo weekly) da última semana: platform.overview com hours 168, platform.detect_issues, contas a receber em atraso, margens e pedidos de clientes fora do SLA. Publica-o com briefings.publish.'
                : 'Prepara o briefing diário (tipo daily) de hoje: platform.overview com hours 24, platform.detect_issues, contas a receber em atraso e pedidos de clientes fora do SLA. Começa pelo que precisa de decisão hoje. Publica-o com briefings.publish.';

            if ($this->dispatchRole('chief_of_staff', $prompt) !== null) {
                $count++;
            }
        });

        $this->components->info("{$count} briefing(s) pedido(s).");

        return self::SUCCESS;
    }
}
