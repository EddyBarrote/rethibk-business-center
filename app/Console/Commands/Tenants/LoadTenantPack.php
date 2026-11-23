<?php

namespace App\Console\Commands\Tenants;

use App\Console\Concerns\InteractsWithTenant;
use App\Enums\AgentStatus;
use App\Models\Tenant;
use App\Tenancy\TenantPack;
use App\Tenancy\TenantPackException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sets up a company from its pack (docs/PACOTE-DA-EMPRESA.md), kept outside
 * the repository: run after tenant:create and again whenever the pack changes.
 */
#[Signature('tenant:load-pack {tenant : Slug do tenant} {path : Pasta do pacote (company.json, facts.json, knowledge/)} {--draft : Os agentes novos ficam em rascunho, sem correr}')]
#[Description('Configura uma empresa a partir do seu pacote: marca, departamentos, agentes, factos e conhecimento')]
class LoadTenantPack extends Command
{
    use InteractsWithTenant;

    public function handle(TenantPack $pack): int
    {
        return $this->asTenant(function (Tenant $tenant) use ($pack): int {
            try {
                $counts = $pack->load($tenant, (string) $this->argument('path'), $this->option('draft') ? AgentStatus::Draft : AgentStatus::Active);
            } catch (TenantPackException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->info("Pacote carregado em {$tenant->slug}.");
            $this->components->twoColumnDetail('Departamentos novos', (string) $counts['departments']);
            $this->components->twoColumnDetail('Departamentos vazios removidos', (string) $counts['departments_removed']);
            $this->components->twoColumnDetail('Agentes instalados / já existentes', $counts['agents_installed'].' / '.$counts['agents_updated']);
            $this->components->twoColumnDetail('Factos novos', (string) $counts['facts']);
            $this->components->twoColumnDetail('Artigos novos / actualizados', $counts['articles_created'].' / '.$counts['articles_updated']);

            return self::SUCCESS;
        });
    }
}
