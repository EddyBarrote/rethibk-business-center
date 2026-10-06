<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\Scope;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A skill in the Claude sense (docs/CAPACIDADES.md): a name, a description
 * that says when it applies, markdown instructions and optional files. The
 * agent sees the name and description in its prompt and loads the rest with
 * skills.load when the work calls for it.
 *
 * A row either is the tenant's own skill or activates a global one
 * (platform_skill_id), whose content is read from the platform at use time.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $platform_skill_id
 * @property string $key
 * @property string|null $name
 * @property string|null $description
 * @property string|null $instructions
 * @property bool $is_enabled
 * @property int|null $created_by_user_id
 */
#[Fillable(['platform_skill_id', 'key', 'name', 'description', 'instructions', 'is_enabled'])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use BelongsToTenant, HasFactory;

    public function ownership(): Scope
    {
        return $this->platform_skill_id === null ? Scope::Tenant : Scope::Global;
    }

    public function displayName(): string
    {
        return (string) ($this->platformSkill->name ?? $this->name ?? $this->key);
    }

    public function displayDescription(): string
    {
        return (string) ($this->platformSkill->description ?? $this->description ?? '');
    }

    public function body(): string
    {
        return (string) ($this->platformSkill->instructions ?? $this->instructions ?? '');
    }

    /**
     * The files the agent can read, own or global.
     *
     * @return list<array{id: int, filename: string, mime: string|null, size: int, content: string|null}>
     */
    public function attachments(): array
    {
        $files = $this->platformSkill !== null ? $this->platformSkill->files : $this->files;

        return $files->map(fn (PlatformSkillFile|SkillFile $file) => [
            'id' => $file->id,
            'filename' => $file->filename,
            'mime' => $file->mime,
            'size' => $file->size,
            'content' => $file->content,
        ])->values()->all();
    }

    /**
     * Usable by agents: switched on here and, if global, still offered.
     */
    public function isUsable(): bool
    {
        return $this->is_enabled && ($this->platform_skill_id === null || ($this->platformSkill !== null && $this->platformSkill->is_active));
    }

    /**
     * @return BelongsTo<PlatformSkill, $this>
     */
    public function platformSkill(): BelongsTo
    {
        return $this->belongsTo(PlatformSkill::class);
    }

    /**
     * @return HasMany<SkillFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(SkillFile::class)->orderBy('filename');
    }

    /**
     * @return BelongsToMany<Agent, $this, AgentSkill>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class)->using(AgentSkill::class)->withPivot(['id', 'tenant_id'])->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}
