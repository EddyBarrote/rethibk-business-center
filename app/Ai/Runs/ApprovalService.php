<?php

namespace App\Ai\Runs;

use App\Ai\Autonomy\GateDecision;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityExecutor;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Enums\ApprovalStatus;
use App\Enums\AuditResult;
use App\Enums\ExecutionStatus;
use App\Enums\RunStatus;
use App\Enums\StepType;
use App\Events\AgentRunFinished;
use App\Events\ApprovalDecided;
use App\Events\ApprovalRequested;
use App\Jobs\ExecuteApprovedAction;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The approval lifecycle (section 12): the gate requests, a human decides,
 * and an approved action runs exactly once.
 */
final class ApprovalService
{
    public function __construct(
        private readonly RunRecorder $recorder,
        private readonly CapabilityRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function request(Capability $capability, array $arguments, GateDecision $decision, CapabilityContext $context): Approval
    {
        $agent = $context->agent;

        $approval = Approval::query()->create([
            'agent_run_id' => $context->run->id,
            'agent_id' => $agent->id,
            'capability_id' => $capability->id,
            'action_type' => $capability->key,
            'action_summary' => Str::limit($this->summarise($capability, $arguments), 250),
            'payload' => $arguments,
            'required_level' => $decision->requiredLevel,
            'agent_level' => $agent->autonomy_level,
            'ceiling_reason' => $decision->ceilingReason,
            'status' => ApprovalStatus::Pending,
            'assigned_to_user_id' => $agent->reports_to_user_id,
        ]);

        AuditLog::record($agent, $capability->key, [
            'agent_run_id' => $context->run->id,
            'arguments' => $arguments,
            'approval_id' => $approval->id,
            'reason' => $decision->reason(),
        ], AuditResult::Denied, $approval);

        $this->recorder->step($context->run, StepType::Approval, [
            'approval_id' => $approval->id,
            'status' => 'pending',
            'summary' => $approval->action_summary,
            'reason' => $decision->reason(),
        ], $capability->key);

        $notify = array_values(array_unique(array_filter([
            $agent->reports_to_user_id,
            ...$agent->assignees()->pluck('users.id')->all(),
        ])));

        ApprovalRequested::live($approval, $notify);

        return $approval;
    }

    public function approve(Approval $approval, User $user, ?string $note = null): Approval
    {
        $this->decide($approval, $user, ApprovalStatus::Approved, $note);

        ExecuteApprovedAction::dispatch($approval->tenant_id, $approval->id);

        return $approval;
    }

    public function reject(Approval $approval, User $user, ?string $note = null): Approval
    {
        $this->decide($approval, $user, ApprovalStatus::Rejected, $note);

        $this->recorder->step($approval->run, StepType::Approval, [
            'approval_id' => $approval->id,
            'status' => 'rejected',
            'decided_by' => $user->name,
            'note' => $note,
        ], $approval->action_type);

        $this->settleRun($approval);

        return $approval;
    }

    /**
     * Run an approved action. Safe to call twice: only the first call runs.
     */
    public function execute(Approval $approval): Approval
    {
        $claimed = Approval::query()
            ->whereKey($approval->id)
            ->where('status', ApprovalStatus::Approved)
            ->where('execution_status', ExecutionStatus::NotExecuted)
            ->whereNull('executed_at')
            ->update(['executed_at' => now()]);

        if ($claimed === 0) {
            return $approval->refresh();
        }

        $approval->refresh();
        $run = $approval->run;
        $capability = $approval->capability;

        if ($capability === null) {
            $approval->forceFill(['execution_status' => ExecutionStatus::Failed, 'execution_result' => ['error' => 'A competência já não existe.']])->save();
        } else {
            $context = new CapabilityContext($approval->agent, $run, $approval);
            $result = app(CapabilityExecutor::class)->execute($capability, $approval->payload ?? [], $context);

            $approval->forceFill([
                'execution_status' => $result->ok ? ExecutionStatus::Executed : ExecutionStatus::Failed,
                'execution_result' => ['ok' => $result->ok, 'content' => Str::limit($result->content, 4000), 'data' => $result->data],
            ])->save();

            if ($capability->source->value === 'local') {
                AuditLog::record($approval->agent, $capability->key, [
                    'agent_run_id' => $run->id,
                    'approval_id' => $approval->id,
                    'arguments' => $approval->payload,
                    'result' => $result->ok ? Str::limit($result->content, 2000) : null,
                    'error' => $result->ok ? null : $result->content,
                ], $result->ok ? AuditResult::Ok : AuditResult::Error, $approval);
            }

            $this->recorder->step($run, $result->ok ? StepType::ToolResult : StepType::Error, [
                'approval_id' => $approval->id,
                'ok' => $result->ok,
                'content' => Str::limit($result->content, 4000),
            ], $capability->key);
        }

        ApprovalDecided::live($approval);
        $this->settleRun($approval);

        return $approval;
    }

    private function decide(Approval $approval, User $user, ApprovalStatus $status, ?string $note): void
    {
        $updated = Approval::query()
            ->whereKey($approval->id)
            ->where('status', ApprovalStatus::Pending)
            ->update([
                'status' => $status,
                'decided_by_user_id' => $user->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

        if ($updated === 0) {
            throw new LogicException('Esta aprovação já foi decidida.');
        }

        $approval->refresh();

        AuditLog::record($user, 'approval.'.$status->value, [
            'approval_id' => $approval->id,
            'action' => $approval->action_type,
            'note' => $note,
        ], AuditResult::Ok, $approval);

        if ($status === ApprovalStatus::Rejected) {
            ApprovalDecided::live($approval);
        }
    }

    /**
     * A run paused for approvals completes when the last one is settled.
     */
    private function settleRun(Approval $approval): void
    {
        DB::transaction(function () use ($approval) {
            $run = $approval->run()->lockForUpdate()->first();

            if ($run === null || $run->status !== RunStatus::AwaitingApproval) {
                return;
            }

            $unsettled = $run->approvals()
                ->where(fn ($q) => $q->where('status', ApprovalStatus::Pending)
                    ->orWhere(fn ($q) => $q->where('status', ApprovalStatus::Approved)->where('execution_status', ExecutionStatus::NotExecuted)->whereNull('executed_at')))
                ->exists();

            if (! $unsettled) {
                $run->forceFill(['status' => RunStatus::Completed, 'finished_at' => now()])->save();
                AgentRunFinished::live($run);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function summarise(Capability $capability, array $arguments): string
    {
        $local = $this->registry->find($capability->key);

        if ($local !== null) {
            return $local->summarise($arguments);
        }

        $details = collect($arguments)
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value, $key) => "{$key}: {$value}")
            ->take(4)
            ->implode(', ');

        return $capability->name.($details !== '' ? " ({$details})" : '');
    }
}
