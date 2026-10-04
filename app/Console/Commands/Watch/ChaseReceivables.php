<?php

namespace App\Console\Commands\Watch;

use App\Console\Concerns\DispatchesRoles;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agents:chase-receivables')]
#[Description('Pede ao agente de finanças que persiga as facturas em atraso (dias úteis às 09:00)')]
class ChaseReceivables extends Command
{
    use DispatchesRoles;

    public function handle(TenantManager $tenants): int
    {
        $count = 0;

        $tenants->eachActive(function () use (&$count): void {
            $run = $this->dispatchRole('finance', 'Persegue os recebimentos: lista as facturas em atraso com erp.invoices.list_receivables (overdue_only). Para cada cliente com atraso, vê se já lhe escreveste nos últimos 7 dias (email.search); se não, prepara um email cordial ao contacto financeiro com número, valor e dias de atraso (o envio espera aprovação) e agenda um seguimento a 7 dias. Termina com o total em atraso por cliente.');
            $count += $run !== null ? 1 : 0;
        });

        $this->components->info("{$count} pedido(s) ao agente de finanças.");

        return self::SUCCESS;
    }
}
