<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Runs\ApprovalService;
use App\Enums\ApprovalStatus;
use App\Enums\TaskStatus;
use App\Models\Approval;
use App\Models\Task;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * The Chief of Staff revalidates an action another agent wants to take above
 * its trust level (docs/DECISOES.md, realinhamento L11): approves what fits
 * its own level, sends back what is wrong, passes the rest to people.
 * Deciding is the review itself, so it is not gated again.
 */
final class ReviewApproval extends LocalCapability
{
    public function __construct(private readonly ApprovalService $approvals, private readonly TaskThread $threads) {}

    public function key(): string
    {
        return 'approvals.review';
    }

    public function name(): string
    {
        return 'Revalidar acção pendente';
    }

    public function description(): string
    {
        return 'Revê uma acção que outro agente quer fazer acima do seu nível de confiança: approve (cabe no teu nível e está certa), reject (está errada; explica porquê) ou escalate (passa às pessoas: quando não tens a certeza ou não cabe no teu nível).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'approval_id' => $schema->integer()->required(),
            'decision' => $schema->string()->enum(['approve', 'reject', 'escalate'])->required(),
            'note' => $schema->string()->description('O porquê, numa ou duas frases; fica no registo.')->required(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'approval_id' => 'required|integer',
            'decision' => 'required|in:approve,reject,escalate',
            'note' => 'required|string|max:2000',
        ])->validate();

        $approval = Approval::query()->find($data['approval_id']);
        $agent = $context->agent;

        if ($approval === null || $approval->review_agent_id !== $agent->id || $approval->review_stage !== Approval::STAGE_AGENT || $approval->status !== ApprovalStatus::Pending) {
            return CapabilityResult::error('essa aprovação não está à tua espera.');
        }

        $decision = $data['decision'];

        if ($decision === 'approve' && ($approval->ceiling_reason !== null || $agent->autonomy_level->value < $approval->required_level->value)) {
            $decision = 'escalate';
            $data['note'] .= " (Acima do nível de {$agent->name}: decide uma pessoa.)";
        }

        match ($decision) {
            'approve' => $this->approvals->approveByAgent($approval, $agent, $data['note']),
            'reject' => $this->approvals->rejectByAgent($approval, $agent, $data['note']),
            default => $this->approvals->escalate($approval, $agent, $data['note']),
        };

        $review = Task::query()->where('source_type', $approval->getMorphClass())->where('source_id', $approval->id)->first();

        if ($review !== null && ! $review->status->isClosed()) {
            $this->threads->setStatus($review, TaskStatus::Done, $agent, $data['note']);
        }

        return CapabilityResult::text(match ($decision) {
            'approve' => "Aprovaste a acção #{$approval->id}; vai ser executada.",
            'reject' => "Devolveste a acção #{$approval->id} a {$approval->agent->name}.",
            default => "Passaste a acção #{$approval->id} às pessoas.",
        });
    }

    public function summarise(array $arguments): string
    {
        return 'Revalidar a acção #'.($arguments['approval_id'] ?? '?');
    }
}
