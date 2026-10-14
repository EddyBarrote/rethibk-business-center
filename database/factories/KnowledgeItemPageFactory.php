<?php

namespace Database\Factories;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemPage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeItemPage>
 */
class KnowledgeItemPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'knowledge_item_id' => KnowledgeItem::factory(),
            'page' => 1,
            'text' => fake()->paragraph(),
            'source' => KnowledgeItemPage::FROM_TEXT,
        ];
    }
}
