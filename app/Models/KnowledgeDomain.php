<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\KnowledgeDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An information domain of the knowledge base (Finanças, RH, Clientes...).
 * Access follows departments: null means everyone in the tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $color
 * @property list<int>|null $department_ids
 * @property int $position
 */
#[Fillable(['name', 'slug', 'description', 'color', 'department_ids', 'position'])]
class KnowledgeDomain extends Model
{
    /** @use HasFactory<KnowledgeDomainFactory> */
    use BelongsToTenant, HasFactory;

    public function isRestricted(): bool
    {
        return $this->department_ids !== null;
    }

    /**
     * @return HasMany<KnowledgeFolder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(KnowledgeFolder::class);
    }

    /**
     * @return HasMany<KnowledgeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class);
    }

    protected function casts(): array
    {
        return [
            'department_ids' => 'array',
        ];
    }
}
