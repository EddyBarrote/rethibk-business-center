<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\ApprovalStatus;
use App\Enums\AutonomyLevel;
use App\Enums\ExecutionStatus;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An action the autonomy gate held back for a human decision (section 12).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_run_id
 * @property int $agent_id
 * @property int|null $skill_id
 * @property string $action_type
 * @property string $action_summary
 * @property array<string, mixed>|null $payload
 * @property AutonomyLevel $required_level
 * @property AutonomyLevel $agent_level
 * @property string|null $ceiling_reason
 * @property ApprovalStatus $status
 * @property int|null $assigned_to_user_id
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $expires_at
 * @property ExecutionStatus $execution_status
 * @property array<string, mixed>|null $execution_result
 * @property Carbon|null $executed_at
 * @property Carbon $created_at
 */
#[Fillable([
    'agent_run_id', 'agent_id', 'skill_id', 'action_type', 'action_summary', 'payload', 'required_level', 'agent_level',
    'ceiling_reason', 'status', 'assigned_to_user_id', 'decided_by_user_id', 'decided_at', 'decision_note', 'expires_at',
    'execution_status', 'execution_result', 'executed_at',
])]
class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use BelongsToTenant, HasFactory;

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending);
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * Approvals the person may see: all of them for owners and admins,
     * otherwise those of agents they answer for (docs/DECISOES.md).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->canManageTenant()) {
            return;
        }

        $query->whereHas('agent', fn (Builder $agents) => $agents
            ->where('reports_to_user_id', $user->id)
            ->orWhereHas('assignees', fn (Builder $assignees) => $assignees->whereKey($user->id)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'required_level' => AutonomyLevel::class,
            'agent_level' => AutonomyLevel::class,
            'status' => ApprovalStatus::class,
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'execution_status' => ExecutionStatus::class,
            'execution_result' => 'array',
            'executed_at' => 'datetime',
        ];
    }
}
