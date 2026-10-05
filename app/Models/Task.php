<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The unit of work, and a conversation thread at the same time (Paperclip's
 * issue plus Hermes-style assistant chat): people and agents write in it, the
 * assigned agent answers with the whole history, and a direct action makes it
 * execute now.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $number
 * @property TaskKind $kind
 * @property string|null $chat_key
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property int|null $assignee_agent_id
 * @property int|null $user_id
 * @property int|null $created_by_user_id
 * @property int|null $created_by_agent_id
 * @property int|null $goal_id
 * @property int|null $parent_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property Carbon|null $due_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_activity_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'number', 'kind', 'chat_key', 'title', 'description', 'status', 'priority', 'assignee_agent_id', 'user_id', 'created_by_user_id',
    'created_by_agent_id', 'goal_id', 'parent_id', 'source_type', 'source_id', 'due_at', 'started_at', 'completed_at', 'last_activity_at',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToTenant, HasFactory;

    /** Mirrors the column defaults, so a fresh model has them before a refresh. */
    protected $attributes = ['kind' => 'task', 'status' => 'todo', 'priority' => 'normal'];

    protected static function booted(): void
    {
        // Numbers are per tenant (MIC-12), like Paperclip's issue identifiers.
        static::creating(function (Task $task): void {
            $task->number ??= (int) static::query()->where('tenant_id', $task->tenant_id)->lockForUpdate()->max('number') + 1;
            $task->last_activity_at ??= now();
        });
    }

    public function identifier(): string
    {
        $prefix = Str::upper(Str::substr(Str::slug((string) $this->tenant?->slug, ''), 0, 3)) ?: 'T';

        return "{$prefix}-{$this->number}";
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', TaskStatus::open());
    }

    /**
     * Tasks a person is part of: asked by them, about them, or assigned to an agent they answer for.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function involving(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('created_by_user_id', $user->id));
    }

    /**
     * What is on a person's desk: tasks with them or created by them, and
     * tasks whose agent has an action waiting for their decision.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function needing(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('user_id', $user->id)
            ->orWhere('created_by_user_id', $user->id)
            ->orWhereHas('runs.approvals', fn (Builder $approvals) => $approvals->pending()->visibleTo($user)));
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function assigneeAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assignee_agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function createdByAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'created_by_agent_id');
    }

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * What the task came from, such as the email the triage handed over.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    /**
     * @return HasMany<TaskMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TaskMessage::class)->orderBy('id');
    }

    /**
     * @return HasMany<AgentRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /**
     * How many tasks up the delegation chain go, to stop agents delegating forever.
     */
    public function depth(): int
    {
        $depth = 0;
        $task = $this;

        while ($task->parent_id !== null && $depth < 10) {
            $task = $task->parent;

            if ($task === null) {
                break;
            }

            $depth++;
        }

        return $depth;
    }

    protected function casts(): array
    {
        return [
            'kind' => TaskKind::class,
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }
}
