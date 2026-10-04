<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\ActorType;
use App\Enums\TaskMessageKind;
use Database\Factories\TaskMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a task's thread: a person's or an agent's message, a direct
 * action, or a platform event.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $task_id
 * @property ActorType $author_type
 * @property int|null $author_user_id
 * @property int|null $author_agent_id
 * @property TaskMessageKind $kind
 * @property string $body
 * @property int|null $agent_run_id
 * @property Carbon $created_at
 */
#[Fillable(['task_id', 'author_type', 'author_user_id', 'author_agent_id', 'kind', 'body', 'agent_run_id'])]
class TaskMessage extends Model
{
    /** @use HasFactory<TaskMessageFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function authorAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'author_agent_id');
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function authorName(): string
    {
        return match ($this->author_type) {
            ActorType::Agent => $this->authorAgent->name ?? 'Agente',
            ActorType::User => $this->authorUser->name ?? 'Pessoa',
            default => 'Plataforma',
        };
    }

    protected function casts(): array
    {
        return [
            'author_type' => ActorType::class,
            'kind' => TaskMessageKind::class,
        ];
    }
}
