<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Enums\PartyType;
use App\Models\Contract;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'party_type' => PartyType::Client,
            'party_ref' => 'ACC-0002',
            'party_name' => 'Hotel Baía Azul, SA',
            'title' => 'Contrato de manutenção',
            'value' => 1200000,
            'currency' => 'MZN',
            'starts_at' => today()->subYear(),
            'ends_at' => today()->addMonths(3),
            'notice_days' => 60,
            'status' => ContractStatus::Active,
        ];
    }
}
