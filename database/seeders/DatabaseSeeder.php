<?php

namespace Database\Seeders;

use App\Ai\Skills\SkillCatalog;
use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Enums\ContractStatus;
use App\Enums\ErpTransport;
use App\Enums\PartyType;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Department;
use App\Models\ErpConnection;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Local development data only: the MICOMOC tenant with the direcções that the
 * agents report to (section 6.3 of docs/SPEC.md), a director for each, the
 * six agents installed from the templates and a few contracts to watch.
 */
class DatabaseSeeder extends Seeder
{
    public function run(TenantManager $tenants): void
    {
        PlatformAdmin::query()->firstOrCreate(['email' => 'admin@rethink.test'], ['name' => 'Super admin (dev)', 'password' => 'password']);

        $tenant = Tenant::query()->firstOrCreate(['slug' => 'micomoc'], ['name' => 'MICOMOC', 'settings' => [
            'mail_domain' => 'agentes.micomoc.test',
            'email_retention_days' => 365,
            'tender_sources' => [],
        ]]);

        $tenants->run($tenant, function () {
            $general = Department::query()->firstOrCreate(['slug' => 'direccao-geral'], ['name' => 'Direcção-Geral']);

            $owner = User::query()->firstOrCreate(['email' => 'owner@micomoc.test'], [
                'name' => 'Proprietário (dev)',
                'password' => 'password',
                'role' => Role::Owner,
                'department_id' => $general->id,
            ]);

            foreach ([
                'direccao-comercial' => ['Direcção Comercial', 'comercial@micomoc.test', 'Directora Comercial (dev)'],
                'direccao-financeira' => ['Direcção Financeira', 'financas@micomoc.test', 'Director Financeiro (dev)'],
                'direccao-de-operacoes' => ['Direcção de Operações', 'operacoes@micomoc.test', 'Director de Operações (dev)'],
                'direccao-de-rh' => ['Direcção de RH', 'rh@micomoc.test', 'Directora de RH (dev)'],
            ] as $slug => [$name, $email, $person]) {
                $department = Department::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'parent_id' => $general->id]);
                User::query()->firstOrCreate(['email' => $email], ['name' => $person, 'password' => 'password', 'role' => Role::Manager, 'department_id' => $department->id]);
            }

            User::query()->firstOrCreate(['email' => 'tecnico@micomoc.test'], [
                'name' => 'Técnico (dev)',
                'password' => 'password',
                'role' => Role::Member,
                'department_id' => Department::query()->where('slug', 'direccao-de-operacoes')->value('id'),
            ]);

            // Until the ERP team ships its MCP server, the fake one stands in (section 8.2).
            ErpConnection::query()->firstOrCreate(['name' => 'Rethink ERP (servidor falso)'], [
                'transport' => ErpTransport::Local,
            ]);

            $catalog = app(SkillCatalog::class);
            $catalog->syncLocal();

            try {
                $catalog->syncErp();
            } catch (Throwable $e) {
                $this->command->warn('Não foi possível ler as ferramentas do ERP: '.$e->getMessage());
            }

            foreach (AgentTemplates::all() as $template) {
                app(TemplateInstaller::class)->install($template, $owner);
            }

            Contract::query()->firstOrCreate(['reference' => 'CT-2025-014'], [
                'party_type' => PartyType::Client, 'party_ref' => 'ACC-0002', 'party_name' => 'Hotel Baía Azul, SA', 'party_domain' => 'baiaazul.co.mz',
                'title' => 'Manutenção preventiva de geradores e AVAC', 'value' => 1_450_000, 'currency' => 'MZN',
                'starts_at' => today()->subYear()->addDays(45), 'ends_at' => today()->addDays(45), 'notice_days' => 60,
                'auto_renews' => false, 'sla_response_hours' => 8, 'status' => ContractStatus::Active, 'owner_user_id' => $owner->id,
            ]);
            Contract::query()->firstOrCreate(['reference' => 'FN-2026-003'], [
                'party_type' => PartyType::Supplier, 'party_ref' => 'SUP-0001', 'party_name' => 'Ferragens Matola, Lda',
                'title' => 'Fornecimento de ferro e cimento', 'value' => 3_200_000, 'currency' => 'MZN',
                'starts_at' => today()->subMonths(9), 'ends_at' => today()->addMonths(3), 'notice_days' => 30,
                'auto_renews' => true, 'status' => ContractStatus::Active,
            ]);
        });
    }
}
