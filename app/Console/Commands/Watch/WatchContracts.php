<?php

namespace App\Console\Commands\Watch;

use App\Console\Concerns\DispatchesRoles;
use App\Enums\ContractStatus;
use App\Enums\PartyType;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\User;
use App\Support\Notifier;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Contracts entering their notice period (E06 suppliers, E08 renewals):
 * the owner is told and the agent of the area prepares the next step.
 * Repeated every 30 days until someone acts.
 */
#[Signature('agents:watch-contracts')]
#[Description('Avisa dos contratos a terminar e pede ao agente da área que prepare a renovação (diário às 07:00)')]
class WatchContracts extends Command
{
    use DispatchesRoles;

    public function handle(TenantManager $tenants, Notifier $notifier): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($notifier, &$count): void {
            $contracts = Contract::query()->where('status', ContractStatus::Active)->whereNotNull('ends_at')
                ->where(fn ($q) => $q->whereNull('alerted_at')->orWhere('alerted_at', '<', now()->subDays(30)))
                ->with('owner')->get();

            foreach ($contracts as $contract) {
                if ($contract->ends_at === null || $contract->ends_at->gt(today()->addDays($contract->notice_days))) {
                    continue;
                }

                $contract->forceFill(['alerted_at' => now()])->save();
                $count++;

                $days = (int) today()->diffInDays($contract->ends_at, false);
                $when = $days >= 0 ? 'termina dentro de '.$days.($days === 1 ? ' dia' : ' dias') : 'já terminou há '.abs($days).(abs($days) === 1 ? ' dia' : ' dias');
                $person = $contract->owner ?? User::query()->where('role', Role::Owner)->where('is_active', true)->first();

                $person && $notifier->notify($person, "Contrato a terminar: {$contract->title}", "O contrato com {$contract->party_name} {$when} (".$contract->ends_at->format('d/m/Y').').'.($contract->auto_renews ? ' Renova automaticamente.' : ''), null, 'Vigilância de contratos', 'warning');

                [$role, $task] = $contract->party_type === PartyType::Client
                    ? ['client_manager', 'prepara a proposta de renovação (reports.draft, tipo proposal) com a ficha do cliente e um rascunho de email ao cliente']
                    : ['procurement', 'avalia o fornecedor (suppliers.scores), diz se vale a pena renovar ou consultar o mercado e prepara o pedido de proposta'];

                $this->dispatchRole($role, "O contrato #{$contract->id} «{$contract->title}» com {$contract->party_name} ({$contract->party_ref}) {$when}. Valor: {$contract->value} {$contract->currency}. Usa contracts.list e {$task}.", $contract);
            }
        });

        $this->components->info("{$count} contrato(s) assinalado(s).");

        return self::SUCCESS;
    }
}
