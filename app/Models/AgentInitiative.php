<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\AgentInitiativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Work an agent offers to do on its own (docs/DECISOES.md, "Iniciativas dos
 * agentes"): «Começar» opens the task, «Esconder» drops it. Never without
 * the evidence it came from (an email, a stalled task).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_id
 * @property int $user_id
 * @property string $title
 * @property string $why
 * @property string $plan
 * @property list<array{ref: string, label: string, href: string}> $evidence
 * @property string $dedupe_key
 * @property string $status
 * @property int|null $task_id
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
#[Fillable(['agent_id', 'user_id', 'title', 'why', 'plan', 'evidence', 'dedupe_key', 'status', 'task_id', 'decided_at'])]
class AgentInitiative extends Model
{
    public const OPEN = 'open';

    public const STARTED = 'started';

    public const HIDDEN = 'hidden';

    public const EXPIRED = 'expired';

    /** An offer older than this is no longer offered. */
    public const DAYS_OPEN = 3;

    /** @use HasFactory<AgentInitiativeFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return ['evidence' => 'array', 'decided_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function offered(Builder $query): void
    {
        $query->where('status', self::OPEN)->where('created_at', '>=', now()->subDays(self::DAYS_OPEN));
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
}
