<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\SkillFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to one of the tenant's own skills.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $skill_id
 * @property string $filename
 * @property string $path
 * @property string|null $mime
 * @property int $size
 * @property string|null $content
 */
#[Fillable(['skill_id', 'filename', 'path', 'mime', 'size', 'content'])]
class SkillFile extends Model
{
    /** @use HasFactory<SkillFileFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
