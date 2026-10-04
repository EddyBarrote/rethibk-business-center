<?php

namespace App\Models;

use Database\Factories\PlatformSkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A skill the super admin offers to every tenant (docs/CAPACIDADES.md). A
 * tenant activates it with a Skill row that points here, so edits reach every
 * tenant at once.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property string $instructions
 * @property bool $is_active
 */
#[Fillable(['key', 'name', 'description', 'instructions', 'is_active'])]
class PlatformSkill extends Model
{
    /** @use HasFactory<PlatformSkillFactory> */
    use HasFactory;

    /**
     * @return HasMany<PlatformSkillFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(PlatformSkillFile::class)->orderBy('filename');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
