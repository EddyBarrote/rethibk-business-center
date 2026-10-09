<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\EmailCategory;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTrigger;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A flow: what happens to a kind of email after triage, or what starts on
 * the schedules of its routines, drawn as blocks (docs/DECISOES.md, "Fluxos
 * de trabalho" and "Rotinas e fluxos agendados"). The graph is the same for the
 * canvas and the list; App\Workflows\WorkflowGraph reads it and
 * App\Workflows\WorkflowEngine walks it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property WorkflowTrigger $trigger
 * @property EmailCategory|null $email_category
 * @property int|null $agent_id
 * @property int|null $fallback_user_id
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
 * @property WorkflowStatus $status
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['name', 'description', 'trigger', 'email_category', 'agent_id', 'fallback_user_id', 'graph', 'status', 'created_by_user_id', 'updated_by_user_id'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = ['status' => 'draft', 'trigger' => 'email', 'graph' => '{"nodes":[],"edges":[]}'];

    /**
     * The active flow for a kind of email, if there is one.
     */
    public static function activeFor(EmailCategory $category): ?self
    {
        return static::query()->where('status', WorkflowStatus::Active)->where('trigger', WorkflowTrigger::Email)->where('email_category', $category)->latest('id')->first();
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
    public function fallbackUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fallback_user_id');
    }

    /**
     * @return HasMany<AgentRoutine, $this>
     */
    public function routines(): HasMany
    {
        return $this->hasMany(AgentRoutine::class);
    }

    /**
     * @return HasMany<WorkflowRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    protected function casts(): array
    {
        return [
            'email_category' => EmailCategory::class,
            'graph' => 'array',
            'status' => WorkflowStatus::class,
            'trigger' => WorkflowTrigger::class,
        ];
    }
}
