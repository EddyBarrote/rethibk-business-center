<?php

namespace Database\Factories;

use App\Models\KnowledgeEmbedding;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeEmbedding>
 */
class KnowledgeEmbeddingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'knowledge_item_id' => KnowledgeItem::factory(),
            'chunk_index' => 0,
            'chunk_text' => fake()->sentence(),
            'embedding' => [0.1, 0.2, 0.3],
            'model' => 'fake',
            'dimensions' => 3,
        ];
    }
}
