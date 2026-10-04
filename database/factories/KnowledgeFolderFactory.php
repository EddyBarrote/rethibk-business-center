<?php

namespace Database\Factories;

use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeFolder>
 */
class KnowledgeFolderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'knowledge_domain_id' => KnowledgeDomain::factory(),
            'parent_id' => null,
            'name' => ucfirst(fake()->word()),
        ];
    }
}
