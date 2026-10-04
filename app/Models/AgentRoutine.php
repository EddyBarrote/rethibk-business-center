<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Cron\CronExpression;
use Database\Factories\AgentRoutineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A routine action: a prompt the agent runs on a cron schedule.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_id
 * @property string $name
 * @property string $prompt
 * @property string $schedule
 * @property bool $is_active
 * @property Carbon|null $last_run_at
 */
#[Fillable(['agent_id', 'name', 'prompt', 'schedule', 'is_active'])]
class AgentRoutine extends Model
{
    /** @use HasFactory<AgentRoutineFactory> */
    use BelongsToTenant, HasFactory;

    public function isDue(Carbon $now): bool
    {
        $local = $now->copy()->setTimezone((string) config('agents.schedule_timezone'));

        return $this->is_active
            && CronExpression::isValidExpression($this->schedule)
            && (new CronExpression($this->schedule))->isDue($local)
            && ! $this->last_run_at?->isSameMinute($now);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }
}
