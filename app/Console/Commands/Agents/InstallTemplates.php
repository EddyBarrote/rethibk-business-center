<?php

namespace App\Console\Commands\Agents;

use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Console\Concerns\InteractsWithTenant;
use App\Enums\AgentStatus;
use App\Workflows\WorkflowTemplates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agents:install-templates {tenant : Slug do tenant} {--only=* : Só estes modelos (triage, chief_of_staff, finance, procurement, hr, client_manager)} {--draft : Cria os agentes em rascunho, sem correr} {--refresh=* : Actualiza o agente que já existe destes modelos (nome, descrição, personalidade, instruções, capacidades em falta, rotinas); só estes modelos}')]
#[Description('Cria no tenant os seis agentes da secção 6.3 a partir dos modelos e os fluxos de exemplo (o que já existe fica como está, salvo com --refresh)')]
class InstallTemplates extends Command
{
    use InteractsWithTenant;

    public function handle(TemplateInstaller $installer, WorkflowTemplates $workflows): int
    {
        $refresh = (array) $this->option('refresh');

        if ($refresh !== []) {
            return $this->refresh($installer, $refresh);
        }

        return $this->asTenant(function () use ($installer, $workflows): int {
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

            // The example flows (docs/DECISOES.md, "Fluxos de trabalho"), for the agents that now exist.
            foreach ($workflows->install() as $workflow) {
                $this->info("Fluxo criado: {$workflow->name}.");
            }

            return self::SUCCESS;
        });
    }

    /**
     * Brings existing agents up to their template (docs/DECISOES.md, Director
     * de Recursos Humanos). Touches nothing else: no agents or flows created.
     *
     * @param  list<string>  $keys
     */
    private function refresh(TemplateInstaller $installer, array $keys): int
    {
        $unknown = array_diff($keys, array_keys(AgentTemplates::all()));

        if ($unknown !== []) {
            $this->components->error('Modelo desconhecido: '.implode(', ', $unknown).'.');

            return self::FAILURE;
        }

        return $this->asTenant(function () use ($installer, $keys): int {
            $failed = false;

            foreach ($keys as $key) {
                $result = $installer->refresh(AgentTemplates::all()[$key]);

                if ($result === null) {
                    $this->components->warn("Não há nenhum agente do modelo {$key} neste tenant: instale-o primeiro (sem --refresh).");
                    $failed = true;

                    continue;
                }

                $this->components->info("{$result['agent']->name} actualizado a partir do modelo {$key}.");
                $this->table(['O quê', 'Itens'], [
                    ['Capacidades acrescentadas', $result['capabilities_added'] === [] ? '—' : implode(', ', $result['capabilities_added'])],
                    ['Rotinas criadas', $result['routines_created'] === [] ? '—' : implode(', ', $result['routines_created'])],
                    ['Rotinas actualizadas', $result['routines_updated'] === [] ? '—' : implode(', ', $result['routines_updated'])],
                    ['Rotinas desligadas', $result['routines_deactivated'] === [] ? '—' : implode(', ', $result['routines_deactivated'])],
                ]);
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        });
    }
}
