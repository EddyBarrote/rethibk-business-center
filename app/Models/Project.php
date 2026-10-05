<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A body of work serving a goal and holding tasks: goal → project → tasks,
 * as in Paperclip (docs/DECISOES.md, realinhamento L12).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $goal_id
 * @property string $name
 * @property string|null $description
 * @property ProjectStatus $status
 * @property int|null $lead_user_id
 * @property int|null $lead_agent_id
 * @property Carbon|null $target_date
 * @property Carbon $created_at
 */
#[Fillable(['goal_id', 'name', 'description', 'status', 'lead_user_id', 'lead_agent_id', 'target_date'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = ['status' => 'active'];

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function leadUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function leadAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'lead_agent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'target_date' => 'date',
        ];
    }
}
