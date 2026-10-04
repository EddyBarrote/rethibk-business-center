<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Database\Seeder;

/**
 * Local development data only: the MICOMOC tenant with the direcções that the
 * agents report to (section 6.3 of docs/SPEC.md) and one owner.
 */
class DatabaseSeeder extends Seeder
{
    public function run(TenantManager $tenants): void
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => 'micomoc'], ['name' => 'MICOMOC', 'settings' => []]);

        $tenants->run($tenant, function () {
            $general = Department::query()->firstOrCreate(['slug' => 'direccao-geral'], ['name' => 'Direcção-Geral']);

            foreach ([
                'direccao-comercial' => 'Direcção Comercial',
                'direccao-financeira' => 'Direcção Financeira',
                'direccao-de-operacoes' => 'Direcção de Operações',
                'direccao-de-rh' => 'Direcção de RH',
            ] as $slug => $name) {
                Department::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'parent_id' => $general->id]);
            }

            User::query()->firstOrCreate(['email' => 'owner@micomoc.test'], [
                'name' => 'Proprietário (dev)',
                'password' => 'password',
                'role' => Role::Owner,
                'department_id' => $general->id,
            ]);
        });
    }
}
