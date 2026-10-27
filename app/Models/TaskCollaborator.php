<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\CollaboratorRole;
use Database\Factories\TaskCollaboratorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent or a colleague working on someone's task, with a role; their part
 * is a sub-task (docs/DECISOES.md, "Tarefas: colaborar com agentes e colegas").
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $task_id
 * @property int|null $agent_id
 * @property int|null $user_id
 * @property CollaboratorRole $role
 * @property int|null $subtask_id
 * @property int|null $invited_by_user_id
 */
#[Fillable(['task_id', 'agent_id', 'user_id', 'role', 'subtask_id', 'invited_by_user_id'])]
class TaskCollaborator extends Model
{
    /** @use HasFactory<TaskCollaboratorFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'subtask_id');
    }

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['role' => CollaboratorRole::class];
    }
}
