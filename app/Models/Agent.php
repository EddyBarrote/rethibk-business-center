<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\AgentStatus;
use App\Enums\AutonomyLevel;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A generic agent (section 5.2, adapted in docs/DECISOES.md): everything that
 * makes it a "Finance agent" or a "Triage agent" is configuration.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $title
 * @property string|null $description
 * @property string|null $personality
 * @property string|null $instructions
 * @property int|null $department_id
 * @property int|null $reports_to_user_id
 * @property AgentStatus $status
 * @property string|null $suspended_reason
 * @property AutonomyLevel $autonomy_level
 * @property string|null $provider
 * @property string|null $model
 * @property float|null $temperature
 * @property int|null $max_tokens
 * @property int|null $max_steps
 * @property array<string, mixed>|null $settings
 * @property int|null $created_by_admin_id
 * @property Carbon $created_at
 */
#[Fillable([
    'key', 'name', 'title', 'description', 'personality', 'instructions', 'department_id', 'reports_to_user_id',
    'status', 'suspended_reason', 'autonomy_level', 'provider', 'model', 'temperature', 'max_tokens', 'max_steps', 'settings',
])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use BelongsToTenant, HasFactory;

    public function isActive(): bool
    {
        return $this->status === AgentStatus::Active;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', AgentStatus::Active);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to_user_id');
    }

    /**
     * @return BelongsToMany<User, $this, AgentAssignment>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agent_assignments')
            ->using(AgentAssignment::class)
            ->withPivot(['id', 'tenant_id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Skill, $this, AgentSkill>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)
            ->using(AgentSkill::class)
            ->withPivot(['id', 'tenant_id', 'enabled', 'config'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<AgentRoutine, $this>
     */
    public function routines(): HasMany
    {
        return $this->hasMany(AgentRoutine::class);
    }

    /**
     * @return HasMany<AgentRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'autonomy_level' => AutonomyLevel::class,
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'max_steps' => 'integer',
            'settings' => 'array',
        ];
    }
}
