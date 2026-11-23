<?php

namespace App\Models;

use App\Models\Concerns\IsSkillVersion;
use Database\Factories\PlatformSkillVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a global skill. Its author is a super admin.
 *
 * @property int $platform_skill_id
 * @property int|null $created_by_admin_id
 */
#[Fillable(['platform_skill_id', 'number', 'key', 'name', 'description', 'instructions', 'files', 'restored_from', 'created_by_admin_id', 'created_at'])]
class PlatformSkillVersion extends Model
{
    /** @use HasFactory<PlatformSkillVersionFactory> */
    use HasFactory, IsSkillVersion;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<PlatformSkill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(PlatformSkill::class, 'platform_skill_id');
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_admin_id');
    }
}
