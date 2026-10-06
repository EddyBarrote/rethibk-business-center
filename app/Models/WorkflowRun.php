<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\WorkflowRunStatus;
use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One email going through a flow. It keeps the graph as it was when it
 * started, the loops in progress (state) and the block it is on.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $workflow_id
 * @property int|null $task_id
 * @property int|null $email_message_id
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
 * @property array<string, mixed>|null $state
 * @property WorkflowRunStatus $status
 * @property string|null $current_node_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
#[Fillable(['workflow_id', 'task_id', 'email_message_id', 'graph', 'state', 'status', 'current_node_id', 'started_at', 'finished_at'])]
class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = ['status' => 'running'];

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    /**
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class);
    }

    protected function casts(): array
    {
        return [
            'graph' => 'array',
            'state' => 'array',
            'status' => WorkflowRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
