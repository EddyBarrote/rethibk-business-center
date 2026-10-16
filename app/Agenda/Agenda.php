<?php

namespace App\Agenda;

use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What is on a calendar (docs/DECISOES.md, "Agenda"): reminders (follow-ups
 * of people and agents), task deadlines and the next runs of routines. Built
 * from what exists; nothing here is stored twice.
 *
 * "Mine" is what is for the person and for the agents that report to them;
 * "everyone" (with "Ler todo o trabalho") adds the rest of the company's
 * work, but never another person's own reminder: that is theirs alone.
 */
final class Agenda
{
    /** A routine that runs more often than this in the range is shown once a day. */
    private const MAX_RUNS_PER_ROUTINE = 62;

    /**
     * @return list<array<string, mixed>>
     */
    public function events(User $user, Carbon $from, Carbon $to, bool $everyone = false, bool $routines = true): array
    {
        $everyone = $everyone && self::canSeeEveryone($user);
        $agents = Agent::query()->where('reports_to_user_id', $user->id)->pluck('id')->all();

        $events = [
            ...$this->followUps($user, $agents, $from, $to, $everyone),
            ...$this->tasks($user, $agents, $from, $to, $everyone),
            ...($routines ? $this->routines($user, $agents, $from, $to, $everyone) : []),
        ];

        usort($events, fn (array $a, array $b) => [$a['at'], $a['all_day'] ? 0 : 1] <=> [$b['at'], $b['all_day'] ? 0 : 1]);

        return $events;
    }

    public static function canSeeEveryone(User $user): bool
    {
        return $user->hasPermission(Permission::ReadAllWork);
    }

    /**
     * Who may mark a reminder done or move it: the person it is for, the
     * boss of the agent that keeps it for itself, or an admin.
     */
    public static function canEdit(User $user, FollowUp $followUp): bool
    {
        if ($followUp->user_id !== null) {
            return $followUp->user_id === $user->id;
        }

        return $followUp->agent?->reports_to_user_id === $user->id || $user->canManageTenant();
    }

    /**
     * @param  list<int>  $agents
     * @return list<array<string, mixed>>
     */
    private function followUps(User $user, array $agents, Carbon $from, Carbon $to, bool $everyone): array
    {
        $timezone = $this->timezone();

        return FollowUp::query()
            ->whereBetween('due_at', [$from, $to])
            ->where(fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('user_id')->whereIn('agent_id', $agents))
                ->when($everyone, fn (Builder $q) => $q->orWhereNull('user_id')->orWhereNotNull('task_id')))
            ->with(['user', 'agent', 'task.assigneeAgent'])
            ->orderBy('due_at')
            ->limit(500)
            ->get()
            // A reminder about a task follows the task: who cannot see it does not see the reminder.
            ->filter(fn (FollowUp $f) => $f->user_id === $user->id || $f->task === null || $user->can('view', $f->task))
            ->map(function (FollowUp $f) use ($user, $timezone): array {
                $email = $f->subject_type === (new EmailMessage)->getMorphClass() && $f->subject_id !== null
                    && EmailMessage::query()->visibleTo($user)->whereKey($f->subject_id)->exists();

                return [
                    'key' => "follow_up-{$f->id}",
                    'kind' => 'follow_up',
                    'title' => $f->title,
                    'note' => $f->note,
                    'at' => $f->due_at->toIso8601String(),
                    'all_day' => $this->allDay($f->due_at, $timezone),
                    'done' => $f->done_at !== null,
                    'overdue' => $f->done_at === null && $f->due_at->isPast(),
                    'who' => $f->user !== null
                        ? ['kind' => 'user', 'id' => $f->user->id, 'name' => $f->user->name]
                        : ($f->agent !== null ? ['kind' => 'agent', 'id' => $f->agent->id, 'name' => $f->agent->name] : null),
                    'by' => $f->agent?->name,
                    'context' => $f->task?->identifier(),
                    'link' => match (true) {
                        $f->task !== null => "/tasks/{$f->task->id}",
                        $email => "/inbox/{$f->subject_id}",
                        default => null,
                    },
                    'follow_up_id' => $f->id,
                    'can_edit' => self::canEdit($user, $f),
                    'can_delete' => $f->user_id === $user->id && $f->agent_id === null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $agents
     * @return list<array<string, mixed>>
     */
    private function tasks(User $user, array $agents, Carbon $from, Carbon $to, bool $everyone): array
    {
        $timezone = $this->timezone();

        return Task::query()
            ->visibleTo($user)
            ->where('kind', TaskKind::Task)
            ->where('status', '!=', TaskStatus::Cancelled)
            ->whereBetween('due_at', [$from, $to])
            ->when(! $everyone, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('assignee_user_id', $user->id)
                ->orWhere('user_id', $user->id)
                ->orWhere('created_by_user_id', $user->id)
                ->orWhereIn('assignee_agent_id', $agents)))
            ->with(['assigneeUser', 'assigneeAgent', 'tenant'])
            ->orderBy('due_at')
            ->limit(500)
            ->get()
            ->map(fn (Task $task): array => [
                'key' => "task-{$task->id}",
                'kind' => 'task',
                'title' => $task->title,
                'note' => null,
                'at' => $task->due_at?->toIso8601String(),
                'all_day' => $task->due_at !== null && $this->allDay($task->due_at, $timezone),
                'done' => $task->status->isClosed(),
                'overdue' => ! $task->status->isClosed() && $task->due_at?->isPast() === true,
                'who' => match (true) {
                    $task->assigneeUser !== null => ['kind' => 'user', 'id' => $task->assigneeUser->id, 'name' => $task->assigneeUser->name],
                    $task->assigneeAgent !== null => ['kind' => 'agent', 'id' => $task->assigneeAgent->id, 'name' => $task->assigneeAgent->name],
                    default => null,
                },
                'by' => null,
                'context' => $task->identifier(),
                'link' => "/tasks/{$task->id}",
                'follow_up_id' => null,
                'can_edit' => false,
                'can_delete' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $agents
     * @return list<array<string, mixed>>
     */
    private function routines(User $user, array $agents, Carbon $from, Carbon $to, bool $everyone): array
    {
        $events = [];
        $admin = $user->canManageTenant();

        $routines = AgentRoutine::query()
            ->where('is_active', true)
            ->when(! ($everyone && $admin), fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('owner_user_id', $user->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('owner_user_id')->whereIn('agent_id', $agents))))
            ->with(['agent', 'workflow.agent'])
            ->get();

        foreach ($routines as $routine) {
            $runs = array_filter($routine->nextRuns(self::MAX_RUNS_PER_ROUTINE + 1, $from->copy()->subSecond()), fn (Carbon $at) => $at->lte($to));
            $agent = $routine->agent ?? $routine->workflow?->agent;

            // An hourly routine would fill the month: once a day is enough to see it is there.
            if (count($runs) > self::MAX_RUNS_PER_ROUTINE) {
                $runs = collect($runs)->unique(fn (Carbon $at) => $at->copy()->setTimezone($this->timezone())->toDateString())->all();
            }

            foreach ($runs as $at) {
                $events[] = [
                    'key' => "routine-{$routine->id}-{$at->timestamp}",
                    'kind' => 'routine',
                    'title' => $routine->name,
                    'note' => $routine->prompt !== null ? Str::limit($routine->prompt, 300) : null,
                    'at' => $at->toIso8601String(),
                    'all_day' => false,
                    'done' => false,
                    'overdue' => false,
                    'who' => $agent !== null ? ['kind' => 'agent', 'id' => $agent->id, 'name' => $agent->name] : null,
                    'by' => null,
                    'context' => $routine->scheduleLabel(),
                    'link' => $admin ? '/settings/routines' : null,
                    'follow_up_id' => null,
                    'can_edit' => false,
                    'can_delete' => false,
                ];
            }
        }

        return $events;
    }

    /**
     * A time at midnight (or the end of the day) in Maputo is a date without
     * an hour; so is midnight UTC, how a task keeps a due date given as a day.
     */
    private function allDay(Carbon $at, string $timezone): bool
    {
        $local = $at->copy()->setTimezone($timezone)->format('H:i');

        return in_array($local, ['00:00', '23:59'], true) || $at->copy()->utc()->format('H:i') === '00:00';
    }

    private function timezone(): string
    {
        return (string) config('agents.schedule_timezone');
    }
}
