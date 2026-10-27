<?php

namespace App\Console\Commands\Agents;

use App\Ai\Suggestions\DailySuggestions;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * New suggestions for every active agent's entry screen, once a day
 * (docs/DECISOES.md, "Sugestões geradas todos os dias").
 */
#[Signature('agents:suggest')]
#[Description('Escreve as sugestões do dia do ecrã de entrada de cada agente')]
class Suggest extends Command
{
    public function handle(TenantManager $tenants, DailySuggestions $suggestions): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($suggestions, &$count): void {
            foreach (Agent::query()->where('status', AgentStatus::Active)->get() as $agent) {
                try {
                    if ($suggestions->generate($agent) !== null) {
                        $count++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        $this->components->info("Sugestões novas para {$count} agente(s).");

        return self::SUCCESS;
    }
}
