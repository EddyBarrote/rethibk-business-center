<?php

namespace Database\Factories;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'title' => 'Requisição de '.fake()->words(2, true),
            'items' => [['description' => 'Varão A500 Ø12 mm', 'quantity' => 100, 'unit' => 'barra']],
            'needed_by' => today()->addDays(10),
            'status' => PurchaseRequestStatus::Submitted,
        ];
    }
}
