<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Support\ScheduleLabel;
use Cron\CronExpression;
use Database\Factories\AgentRoutineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A routine (docs/DECISOES.md, "Rotinas e fluxos agendados"): on a cron
 * schedule, an agent gets a request, or a flow starts. The result goes to
 * the person it is for (owner) and the admins always hear of it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property int|null $workflow_id
 * @property int|null $owner_user_id
 * @property string $name
 * @property string|null $prompt
 * @property string $schedule
 * @property bool $is_active
 * @property int|null $created_by_user_id
 * @property Carbon|null $last_run_at
 * @property int|null $last_run_id
 * @property int|null $last_task_id
 * @property Carbon $created_at
 */
#[Fillable(['agent_id', 'workflow_id', 'owner_user_id', 'name', 'prompt', 'schedule', 'is_active', 'created_by_user_id'])]
class AgentRoutine extends Model
{
    /** @use HasFactory<AgentRoutineFactory> */
    use BelongsToTenant, HasFactory;

    public function isDue(Carbon $now): bool
    {
        return $this->dueAt($now) !== null;
    }

    /**
     * The scheduled time this routine should run for now, if any: the latest
     * time on its schedule, when it has not run since. A time missed while the
     * machine slept is caught up once, within agents.routine_catch_up_hours.
     */
    public function dueAt(Carbon $now): ?Carbon
    {
        if (! $this->is_active || ! CronExpression::isValidExpression($this->schedule)) {
            return null;
        }

        $timezone = (string) config('agents.schedule_timezone');
        $latest = Carbon::instance((new CronExpression($this->schedule))->getPreviousRunDate($now->copy()->setTimezone($timezone), 0, true))->utc();
        $ran = $this->last_run_at !== null && $latest->lte($this->last_run_at);
        $before = $this->last_run_at === null && $latest->lt($this->created_at->copy()->startOfMinute());

        if ($ran || $before) {
            return null;
        }

        return $latest->gte($now->copy()->subHours((int) config('agents.routine_catch_up_hours', 12))) ? $latest : null;
    }

    /**
     * The next times on its schedule, in UTC.
     *
     * @return list<Carbon>
     */
    public function nextRuns(int $count = 3, ?Carbon $from = null): array
    {
        if (! CronExpression::isValidExpression($this->schedule)) {
            return [];
        }

        $timezone = (string) config('agents.schedule_timezone');
        $local = ($from ?? now())->copy()->setTimezone($timezone);

        return array_map(
            fn (\DateTime $date) => Carbon::instance($date)->utc(),
            (new CronExpression($this->schedule))->getMultipleRunDates($count, $local),
        );
    }

    public function scheduleLabel(): string
    {
        return ScheduleLabel::for($this->schedule);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'last_run_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function lastTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'last_task_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }
}
