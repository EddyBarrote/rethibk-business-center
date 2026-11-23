<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Models\Concerns\IsSkillVersion;
use Database\Factories\SkillVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a company's own skill, kept when it is created, edited,
 * gets or loses a file, or is restored. Its author is a person.
 *
 * @property int $tenant_id
 * @property int $skill_id
 * @property int|null $created_by_user_id
 */
#[Fillable(['skill_id', 'number', 'key', 'name', 'description', 'instructions', 'files', 'restored_from', 'created_by_user_id', 'created_at'])]
class SkillVersion extends Model
{
    /** @use HasFactory<SkillVersionFactory> */
    use BelongsToTenant, HasFactory, IsSkillVersion;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
