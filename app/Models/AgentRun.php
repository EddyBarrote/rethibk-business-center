<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Enums\TriggerType;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One execution of an agent (section 5.3).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_id
 * @property int|null $task_id
 * @property int|null $agent_routine_id
 * @property TriggerType $trigger_type
 * @property int|null $requested_by_user_id
 * @property RunStatus $status
 * @property string $input
 * @property array<string, mixed>|null $output
 * @property string|null $provider
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float $cost_usd
 * @property int|null $duration_ms
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
#[Fillable([
    'agent_id', 'task_id', 'conversation_id', 'trigger_type', 'trigger_source_type', 'trigger_source_id', 'requested_by_user_id', 'status', 'input', 'output',
    'provider', 'model', 'input_tokens', 'output_tokens', 'cost_usd', 'duration_ms', 'error', 'started_at', 'finished_at',
])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * What started the run: a routine, an email, another run.
     *
     * @return MorphTo<Model, $this>
     */
    public function triggerSource(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<AgentRunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(AgentRunStep::class)->orderBy('seq');
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * AgentRunPolicy::view as a query.
     *
     * @param  Builder<AgentRun>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->hasPermission(Permission::ReadAllConversations)) {
            return;
        }

        $query->where(fn (Builder $q) => $q->where('requested_by_user_id', $user->id)
            ->orWhereIn('task_id', Task::query()->visibleTo($user)->select('id')));
    }

    /**
     * Queued or running, and still alive: a run its worker lost (killed at the
     * job timeout, a crash) stops counting as working at once, before
     * StaleRuns closes it (docs/DECISOES.md, "Agente ocupado noutro sítio").
     *
     * @param  Builder<AgentRun>  $query
     */
    #[Scope]
    protected function working(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('status', RunStatus::Queued)->where('updated_at', '>=', now()->subMinutes((int) config('agents.stale_queued_minutes'))))
            ->orWhere(fn (Builder $q) => $q->where('status', RunStatus::Running)->where('updated_at', '>=', now()->subMinutes((int) config('agents.stale_running_minutes')))));
    }

    protected function casts(): array
    {
        return [
            'trigger_type' => TriggerType::class,
            'status' => RunStatus::class,
            'output' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'float',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
