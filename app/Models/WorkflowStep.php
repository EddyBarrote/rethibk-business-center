<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\WorkflowStepStatus;
use Database\Factories\WorkflowStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One block of a run, each time it is entered (a block inside a loop has one
 * step per item). Agent work happens in the run's task; another agent's work
 * and a person's work happen in a sub-task (task_id).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $workflow_run_id
 * @property string $node_id
 * @property string $kind
 * @property WorkflowStepStatus $status
 * @property int|null $agent_id
 * @property int|null $task_id
 * @property int $attempts
 * @property string|null $item
 * @property string|null $answer
 * @property array<string, mixed>|null $output
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['workflow_run_id', 'node_id', 'kind', 'status', 'agent_id', 'task_id', 'attempts', 'item', 'answer', 'output', 'started_at', 'finished_at'])]
class WorkflowStep extends Model
{
    /** @use HasFactory<WorkflowStepFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = ['status' => 'active', 'attempts' => 0];

    /**
     * @return BelongsTo<WorkflowRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected function casts(): array
    {
        return [
            'status' => WorkflowStepStatus::class,
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
