<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\AgentAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * An agent assigned to a person (section 5.2).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_id
 * @property int $user_id
 * @property string|null $role
 */
#[Fillable(['agent_id', 'user_id', 'role'])]
class AgentAssignment extends Pivot
{
    /** @use HasFactory<AgentAssignmentFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'agent_assignments';

    public $incrementing = true;

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
}
