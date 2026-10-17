<?php

namespace Database\Factories;

use App\Models\ErpSale;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErpSale>
 */
class ErpSaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'kind' => ErpSale::INVOICE,
            'erp_id' => (string) fake()->unique()->numberBetween(1, 99999),
            'number' => 'INV-000001',
            'customer' => 'Picasso Eventos SU LDA',
            'date' => '2026-09-30',
            'status' => 'paid',
            'currency' => 'MZN',
            'total' => '1160.00',
            'lines' => [['description' => 'Serviço Cashless', 'long_description' => null, 'qty' => 1.0, 'rate' => 1000.0, 'amount' => 1000.0]],
            'search_text' => 'servico cashless',
            'signature' => 'x',
        ];
    }
}
