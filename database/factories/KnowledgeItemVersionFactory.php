<?php

namespace Database\Factories;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeItemVersion>
 */
class KnowledgeItemVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'knowledge_item_id' => KnowledgeItem::factory(),
            'version' => 1,
            'disk' => 'local',
            'path' => 'knowledge/'.fake()->uuid().'.pdf',
            'filename' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'created_by_type' => 'system',
            'created_by_id' => null,
        ];
    }
}
