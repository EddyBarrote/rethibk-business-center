<?php

namespace Database\Seeders;

use App\Ai\Capabilities\CapabilityCatalog;
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
        PlatformAdmin::query()->firstOrCreate(['email' => 'admin@rethink.test'], ['name' => 'Equipa Rethink', 'password' => 'password']);

        $tenant = Tenant::query()->firstOrCreate(['slug' => 'micomoc'], ['name' => 'MICOMOC', 'settings' => [
            'mail_domain' => 'agentes.micomoc.test',
            'email_retention_days' => 365,
            'tender_sources' => [],
        ]]);

        $tenants->run($tenant, function () {
            $general = Department::query()->firstOrCreate(['slug' => 'direccao-geral'], ['name' => 'Direcção-Geral']);

            $owner = User::query()->firstOrCreate(['email' => 'owner@micomoc.test'], [
                'name' => 'Carlos Tembe',
                'job_title' => 'CEO',
                'password' => 'password',
                'role' => Role::Owner,
                'department_id' => $general->id,
            ]);

            // Plausible people instead of "(dev)" labels, so screenshots and demos read like the company.
            foreach ([
                'direccao-comercial' => ['Direcção Comercial', 'comercial@micomoc.test', 'Ana Sitoe', 'Directora Comercial'],
                'direccao-financeira' => ['Direcção Financeira', 'financas@micomoc.test', 'Jorge Cossa', 'Director Financeiro'],
                'direccao-de-operacoes' => ['Direcção de Operações', 'operacoes@micomoc.test', 'Rui Macuácua', 'Director de Operações'],
                'direccao-de-rh' => ['Direcção de RH', 'rh@micomoc.test', 'Marta Nhantumbo', 'Directora de RH'],
            ] as $slug => [$name, $email, $person, $title]) {
                $department = Department::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'parent_id' => $general->id]);
                // The directors report to the CEO in the org chart (realinhamento L3).
                User::query()->firstOrCreate(['email' => $email], ['name' => $person, 'job_title' => $title, 'password' => 'password', 'role' => Role::Manager, 'department_id' => $department->id, 'reports_to_user_id' => $owner->id]);
            }

            User::query()->firstOrCreate(['email' => 'tecnico@micomoc.test'], [
                'name' => 'Paulo Muianga',
                'job_title' => 'Técnico de obra',
                'password' => 'password',
                'role' => Role::Member,
                'department_id' => Department::query()->where('slug', 'direccao-de-operacoes')->value('id'),
                'reports_to_user_id' => User::query()->where('email', 'operacoes@micomoc.test')->value('id'),
            ]);

            // Until the ERP team ships its MCP server, the fake one stands in (section 8.2).
            // One connection per tenant: re-seeding keeps an existing one, whatever it is called.
            if (! ErpConnection::query()->exists()) {
                ErpConnection::query()->create(['name' => 'Rethink ERP (demonstração)', 'transport' => ErpTransport::Local]);
            }

            $catalog = app(CapabilityCatalog::class);
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
