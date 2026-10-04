<?php

namespace App\Console\Commands\Watch;

use App\Ai\Runs\AgentDirectory;
use App\Console\Concerns\DispatchesRoles;
use App\Enums\TriggerType;
use App\Insights\SlaMonitor;
use App\Models\EmailMessage;
use App\Support\Notifier;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('agents:watch-sla')]
#[Description('Avisa dos pedidos de clientes sem resposta para lá do SLA (de hora a hora)')]
class WatchSla extends Command
{
    use DispatchesRoles;

    public function handle(TenantManager $tenants, SlaMonitor $sla, Notifier $notifier, AgentDirectory $agents): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($tenants, $sla, $notifier, $agents, &$count): void {
            foreach ($sla->breaches() as $breach) {
                if (! Cache::add("sla-breach:{$tenants->id()}:{$breach['email_id']}", true, now()->addDays(30))) {
                    continue;
                }

                $email = EmailMessage::query()->with('routedTo')->find($breach['email_id']);
                $person = $email->routedTo ?? $agents->forRole('client_manager')?->reportsTo;
                $count++;

                $person && $notifier->notify($person, 'Pedido de cliente fora do SLA', "«{$breach['subject']}» de {$breach['from']} espera há {$breach['hours_waiting']} h (SLA {$breach['sla_hours']} h).", "/inbox/{$breach['email_id']}", 'Vigilância de SLA', 'warning');

                $this->dispatchRole('client_manager', "O pedido do cliente no email #{$breach['email_id']} passou o SLA ({$breach['hours_waiting']} h de {$breach['sla_hours']} h). Lê-o, abre a ficha do cliente e prepara a resposta (rascunho para aprovação).", $email, TriggerType::Schedule);
            }
        });

        $this->components->info("{$count} pedido(s) fora do SLA.");

        return self::SUCCESS;
    }
}
