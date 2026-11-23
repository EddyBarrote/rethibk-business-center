<?php

namespace App\Ai\Runs;

use App\Enums\RunStatus;
use App\Models\AgentRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Observability per agent (docs/DECISOES.md, "Latência, taxa de erro e
 * versões das skills"): over the runs created in a period, how many there
 * were, how many failed, the error rate, the median and p90 duration, and
 * what they cost.
 *
 * - Error rate: failed ÷ finished (completed + failed). Runs still queued,
 *   running or waiting for a person have no outcome yet; a cancelled run
 *   was stopped by a person. Null when nothing finished.
 * - Duration: the time the agent was working (`duration_ms`, measured by
 *   AgentRunner, without the time it waited for an approval or an answer);
 *   for a finished run without it (closed by StaleRuns), finished_at −
 *   started_at. Only completed and failed runs count.
 *
 * Counts and cost are one aggregate query that SQLite (tests) and MariaDB
 * (production) both run. Percentiles are worked out in PHP, interpolated
 * like PERCENTILE_CONT, over each agent's latest SAMPLE finished runs.
 */
final class RunMetrics
{
    /** Finished runs per agent the percentiles are worked out from, the most recent. */
    public const SAMPLE = 5000;

    /**
     * @param  list<int>|null  $agentIds  only these agents; null for every agent
     * @return Collection<int, array{agent_id: int, runs: int, completed: int, failed: int, error_rate: float|null, p50_ms: int|null, p90_ms: int|null, measured: int, spent: float}> keyed by agent id
     */
    public function perAgent(CarbonInterface $from, ?CarbonInterface $to = null, ?array $agentIds = null): Collection
    {
        return $this->inPeriod(AgentRun::query(), $from, $to)
            ->when($agentIds !== null, fn (Builder $query) => $query->whereIn('agent_id', $agentIds))
            ->selectRaw(
                'agent_id, count(*) as runs, sum(case when status = ? then 1 else 0 end) as completed, sum(case when status = ? then 1 else 0 end) as failed, coalesce(sum(cost_usd), 0) as spent',
                [RunStatus::Completed->value, RunStatus::Failed->value],
            )
            ->groupBy('agent_id')
            ->get()
            ->mapWithKeys(fn (AgentRun $row) => [(int) $row->getAttribute('agent_id') => $this->row($row, $from, $to)]);
    }

    /**
     * @return array{agent_id: int, runs: int, completed: int, failed: int, error_rate: float|null, p50_ms: int|null, p90_ms: int|null, measured: int, spent: float}
     */
    private function row(AgentRun $row, CarbonInterface $from, ?CarbonInterface $to): array
    {
        $agentId = (int) $row->getAttribute('agent_id');
        $completed = (int) $row->getAttribute('completed');
        $failed = (int) $row->getAttribute('failed');
        $durations = $this->durations($agentId, $from, $to);

        return [
            'agent_id' => $agentId,
            'runs' => (int) $row->getAttribute('runs'),
            'completed' => $completed,
            'failed' => $failed,
            'error_rate' => $completed + $failed > 0 ? round($failed / ($completed + $failed), 4) : null,
            'p50_ms' => self::percentile($durations, 0.5),
            'p90_ms' => self::percentile($durations, 0.9),
            'measured' => count($durations),
            'spent' => round((float) $row->getAttribute('spent'), 4),
        ];
    }

    /**
     * One agent's numbers; zeros when it did not run in the period.
     *
     * @return array{agent_id: int, runs: int, completed: int, failed: int, error_rate: float|null, p50_ms: int|null, p90_ms: int|null, measured: int, spent: float}
     */
    public function forAgent(int $agentId, CarbonInterface $from, ?CarbonInterface $to = null): array
    {
        return $this->perAgent($from, $to, [$agentId])->get($agentId)
            ?? ['agent_id' => $agentId, 'runs' => 0, 'completed' => 0, 'failed' => 0, 'error_rate' => null, 'p50_ms' => null, 'p90_ms' => null, 'measured' => 0, 'spent' => 0.0];
    }

    /**
     * The value below which this share of the sorted list falls, interpolated
     * between the two nearest values, in whole ms (the median of 100, 200,
     * 300 and 400 ms is 250 ms).
     *
     * @param  list<int>  $sorted  ascending
     */
    public static function percentile(array $sorted, float $share): ?int
    {
        if ($sorted === []) {
            return null;
        }

        $rank = $share * (count($sorted) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        return (int) round($sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($rank - $low));
    }

    /**
     * How long each of the agent's finished runs took, in ms, ascending.
     *
     * @return list<int>
     */
    private function durations(int $agentId, CarbonInterface $from, ?CarbonInterface $to): array
    {
        $durations = $this->inPeriod(AgentRun::query(), $from, $to)
            ->where('agent_id', $agentId)
            ->whereIn('status', [RunStatus::Completed, RunStatus::Failed])
            ->latest('id')
            ->limit(self::SAMPLE)
            ->get(['id', 'duration_ms', 'started_at', 'finished_at'])
            ->map(fn (AgentRun $run) => $run->duration_ms ?? ($run->started_at !== null && $run->finished_at !== null
                ? (int) round($run->started_at->diffInMilliseconds($run->finished_at, true))
                : null))
            ->filter(fn (?int $ms) => $ms !== null)
            ->sort()
            ->values()
            ->all();

        /** @var list<int> $durations */
        return $durations;
    }

    /**
     * @param  Builder<AgentRun>  $query
     * @return Builder<AgentRun>
     */
    private function inPeriod(Builder $query, CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $query->where('created_at', '>=', $from)->when($to !== null, fn (Builder $query) => $query->where('created_at', '<', $to));
    }
}
