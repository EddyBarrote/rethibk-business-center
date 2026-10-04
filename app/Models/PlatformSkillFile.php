<?php

namespace App\Models;

use Database\Factories\PlatformSkillFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a global skill: a template, an example, a reference.
 * `content` holds its text, which is what the agent reads.
 *
 * @property int $id
 * @property int $platform_skill_id
 * @property string $filename
 * @property string $path
 * @property string|null $mime
 * @property int $size
 * @property string|null $content
 */
#[Fillable(['filename', 'path', 'mime', 'size', 'content'])]
class PlatformSkillFile extends Model
{
    /** @use HasFactory<PlatformSkillFileFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<PlatformSkill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(PlatformSkill::class, 'platform_skill_id');
    }
}
