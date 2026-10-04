<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\KnowledgeFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder inside a domain; folders nest.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $knowledge_domain_id
 * @property int|null $parent_id
 * @property string $name
 */
#[Fillable(['knowledge_domain_id', 'parent_id', 'name'])]
class KnowledgeFolder extends Model
{
    /** @use HasFactory<KnowledgeFolderFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<KnowledgeDomain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDomain::class, 'knowledge_domain_id');
    }

    /**
     * @return BelongsTo<KnowledgeFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFolder::class, 'parent_id');
    }

    /**
     * @return HasMany<KnowledgeFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(KnowledgeFolder::class, 'parent_id');
    }

    /**
     * @return HasMany<KnowledgeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class);
    }

    /**
     * "Contratos / 2026", walking up the parents.
     */
    public function path(): string
    {
        return implode(' / ', array_column($this->trail(), 'name'));
    }

    /**
     * This folder and its parents, root first, one query per level.
     *
     * @return list<array{id: int, name: string}>
     */
    public function trail(): array
    {
        $trail = [['id' => $this->id, 'name' => $this->name]];
        $parentId = $this->parent_id;

        for ($depth = 0; $parentId !== null && $depth < 10; $depth++) {
            $parent = self::query()->find($parentId, ['id', 'name', 'parent_id']);

            if ($parent === null) {
                break;
            }

            array_unshift($trail, ['id' => $parent->id, 'name' => $parent->name]);
            $parentId = $parent->parent_id;
        }

        return $trail;
    }
}
