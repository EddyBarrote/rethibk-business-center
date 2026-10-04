<?php

namespace App\Console\Commands\Agents;

use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Console\Concerns\InteractsWithTenant;
use App\Enums\AgentStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agents:install-templates {tenant : Slug do tenant} {--only=* : Só estes modelos (triage, chief_of_staff, finance, procurement, hr, client_manager)} {--draft : Cria os agentes em rascunho, sem correr}')]
#[Description('Cria no tenant os seis agentes da secção 6.3 a partir dos modelos (os que já existem ficam como estão)')]
class InstallTemplates extends Command
{
    use InteractsWithTenant;

    public function handle(TemplateInstaller $installer): int
    {
        return $this->asTenant(function () use ($installer): int {
            $only = (array) $this->option('only');
            $status = $this->option('draft') ? AgentStatus::Draft : AgentStatus::Active;
            $rows = [];

            foreach (AgentTemplates::all() as $key => $template) {
                if ($only !== [] && ! in_array($key, $only, true)) {
                    continue;
                }

                $result = $installer->install($template, status: $status);
                $rows[] = [
                    $template->delivery,
                    $result['agent']->name,
                    $result['created'] ? 'criado' : 'já existia',
                    $result['mailbox'] ?? '—',
                    $result['missing_capabilities'] === [] ? '—' : implode(', ', $result['missing_capabilities']),
                ];
            }

            $this->table(['Entrega', 'Agente', 'Estado', 'Caixa', 'Capacidades em falta'], $rows);

            return self::SUCCESS;
        });
    }
}
