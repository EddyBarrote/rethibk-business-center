<?php

namespace App\Console\Commands\Agents;

use App\Ai\Initiatives\OfferWork;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Each active agent's offers (docs/DECISOES.md, "Iniciativas dos agentes"):
 * what it could take on by itself, offered to the person it answers to.
 */
#[Signature('agents:initiatives')]
#[Description('Iniciativas dos agentes: propõem trabalho a partir do que entrou e do que está bloqueado')]
class Initiatives extends Command
{
    public function handle(TenantManager $tenants, OfferWork $offers): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($offers, &$count): void {
            foreach (Agent::query()->where('status', AgentStatus::Active)->whereNotNull('reports_to_user_id')->get() as $agent) {
                try {
                    $count += $offers->for($agent);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        $this->components->info("{$count} iniciativa(s) nova(s).");

        return self::SUCCESS;
    }
}
