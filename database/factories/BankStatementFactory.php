<?php

namespace Database\Factories;

use App\Models\BankStatement;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankStatement>
 */
class BankStatementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'account_name' => 'Conta à ordem MZN',
            'bank' => 'BCI',
            'currency' => 'MZN',
            'source' => 'upload',
        ];
    }
}
