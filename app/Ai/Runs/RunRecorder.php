<?php

namespace App\Ai\Runs;

use App\Enums\StepType;
use App\Events\AgentRunStepAdded;
use App\Models\AgentRun;
use App\Models\AgentRunStep;

/**
 * Writes the run timeline (agent_run_steps) and pushes each step live.
 */
final class RunRecorder
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function step(AgentRun $run, StepType $type, ?array $payload = null, ?string $toolName = null, ?int $durationMs = null): AgentRunStep
    {
        $step = AgentRunStep::query()->create([
            'agent_run_id' => $run->id,
            'seq' => (int) AgentRunStep::query()->where('agent_run_id', $run->id)->max('seq') + 1,
            'type' => $type,
            'tool_name' => $toolName,
            'payload' => $payload,
            'duration_ms' => $durationMs,
        ]);

        AgentRunStepAdded::live($step);

        return $step;
    }
}
