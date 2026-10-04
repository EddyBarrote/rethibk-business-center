<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\GoalStatus;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A company goal: every task can say which goal it serves, so anyone can see
 * why a piece of work exists (adapted from Paperclip).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $parent_id
 * @property string $title
 * @property string|null $description
 * @property GoalStatus $status
 * @property int|null $owner_agent_id
 * @property int|null $owner_user_id
 * @property Carbon|null $target_date
 * @property Carbon $created_at
 */
#[Fillable(['parent_id', 'title', 'description', 'status', 'owner_agent_id', 'owner_user_id', 'target_date'])]
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'parent_id');
    }

    /**
     * @return HasMany<Goal, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Goal::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function ownerAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'owner_agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => GoalStatus::class,
            'target_date' => 'date',
        ];
    }
}
