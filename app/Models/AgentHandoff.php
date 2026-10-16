<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\AgentHandoffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An agent passing a person's request to a colleague (agents.message), like
 * Grok's "Messaged …": the colleague works on it in that person's own
 * conversation with it, and its answer goes back to the person and to the
 * agent that passed it (docs/DECISOES.md, "Mensagens entre agentes").
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $from_agent_id
 * @property int $to_agent_id
 * @property int $from_task_id
 * @property int $to_task_id
 * @property int|null $from_run_id
 * @property int|null $answer_run_id
 * @property string $brief
 * @property string|null $answer
 * @property string $status
 * @property int $depth
 * @property Carbon|null $answered_at
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'from_agent_id', 'to_agent_id', 'from_task_id', 'to_task_id', 'from_run_id', 'answer_run_id', 'brief', 'answer', 'status', 'depth', 'answered_at'])]
class AgentHandoff extends Model
{
    /** @use HasFactory<AgentHandoffFactory> */
    use BelongsToTenant, HasFactory;

    public const WORKING = 'working';

    public const ANSWERED = 'answered';

    public const FAILED = 'failed';

    public function isOpen(): bool
    {
        return $this->status === self::WORKING;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function fromAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'from_agent_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function toAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'to_agent_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function fromTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'from_task_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function toTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'to_task_id');
    }

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'answered_at' => 'datetime',
        ];
    }
}
