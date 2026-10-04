<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\BriefingType;
use Database\Factories\BriefingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A briefing written by the Chief of Staff (section 5.7, E04).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property BriefingType $type
 * @property int|null $for_user_id
 * @property string $title
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string $content
 * @property list<string>|null $highlights
 * @property list<array<string, mixed>>|null $decisions_pending
 * @property int|null $agent_run_id
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
#[Fillable(['agent_id', 'type', 'for_user_id', 'title', 'period_start', 'period_end', 'content', 'highlights', 'decisions_pending', 'agent_run_id', 'delivered_at', 'read_at'])]
class Briefing extends Model
{
    /** @use HasFactory<BriefingFactory> */
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
    public function forUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'for_user_id');
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    protected function casts(): array
    {
        return [
            'type' => BriefingType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'highlights' => 'array',
            'decisions_pending' => 'array',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
