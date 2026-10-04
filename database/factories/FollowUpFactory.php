<?php

namespace Database\Factories;

use App\Models\FollowUp;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'title' => 'Voltar a contactar o cliente',
            'note' => null,
            'due_at' => now()->addDay(),
        ];
    }
}
