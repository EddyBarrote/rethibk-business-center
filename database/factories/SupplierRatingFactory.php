<?php

namespace Database\Factories;

use App\Models\SupplierRating;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierRating>
 */
class SupplierRatingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'supplier_ref' => 'SUP-0001',
            'supplier_name' => 'Ferragens Matola, Lda',
            'on_time' => 4,
            'quality' => 4,
            'price' => 3,
            'rated_by_type' => 'user',
        ];
    }
}
