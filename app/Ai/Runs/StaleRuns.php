<?php

namespace App\Ai\Runs;

use App\Enums\RunStatus;
use App\Enums\StepType;
use App\Events\AgentRunFinished;
use App\Models\AgentRun;
use App\Tasks\TaskThread;

/**
 * Closes runs that lost their worker, so an agent never shows "a trabalhar"
 * for work that is no longer happening (docs/DECISOES.md, "Agente ocupado
 * noutro sítio"). The job's failed() hook closes its run straight away; the
 * sweep catches the rest (a worker killed without a failed() call).
 */
class StaleRuns
{
    public function __construct(
        private readonly RunRecorder $recorder,
        private readonly QuestionService $questions,
        private readonly TaskThread $threads,
    ) {}

    /**
     * Closes the current tenant's stale runs and returns how many.
     */
    public function sweep(): int
    {
        $stale = AgentRun::query()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->whereNotIn('id', AgentRun::query()->working()->select('id'))
            ->get();

        foreach ($stale as $run) {
            $this->close($run);
        }

        return $stale->count();
    }

    /**
     * Marks a queued or running run as failed, with a note in its conversation.
     */
    public function close(AgentRun $run, ?string $reason = null): void
    {
        $run->refresh()->load(['agent', 'task', 'triggerSource']);

        if (! in_array($run->status, [RunStatus::Queued, RunStatus::Running], true)) {
            return;
        }

        $message = $reason ?? ($run->status === RunStatus::Queued
            ? 'A execução ficou na fila sem nunca começar.'
            : 'A execução parou a meio sem terminar (o processo foi interrompido).');

        $this->recorder->step($run, StepType::Error, ['message' => $message]);
        $run->forceFill(['status' => RunStatus::Failed, 'error' => $message, 'finished_at' => now()])->save();
        $this->questions->cancelFor($run);

        if ($run->task_id !== null) {
            $this->threads->recordReply($run);
        }

        AgentRunFinished::live($run);
    }
}
