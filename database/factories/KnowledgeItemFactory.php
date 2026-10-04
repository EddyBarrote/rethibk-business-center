<?php

namespace Database\Factories;

use App\Enums\KnowledgeType;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeItem>
 */
class KnowledgeItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'type' => KnowledgeType::Decision,
            'title' => 'Decisão '.fake()->words(3, true),
            'content' => fake()->paragraph(),
            'summary' => null,
            'is_external' => false,
            'department_id' => null,
            'visibility' => 'tenant',
            'created_by_type' => 'system',
            'created_by_id' => null,
            'embedding_status' => 'pending',
        ];
    }
}
