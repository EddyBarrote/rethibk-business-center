<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\ApprovalService;
use App\Ai\Tools\GatedTool;
use App\Enums\ApprovalStatus;
use App\Enums\AutonomyLevel;
use App\Enums\TaskPriority;
use App\Enums\TriggerType;
use App\Jobs\ExecuteApprovedAction;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Capability;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

// The Chief of Staff (docs/DECISOES.md, realinhamento L10, L11).

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->ceo, $this->manager, $this->chief, $this->finance] = asTenant($this->tenant, function () {
        $ceo = User::factory()->owner()->create(['name' => 'CEO']);

        return [
            $ceo,
            User::factory()->create(['role' => 'manager']),
            templateAgent('chief_of_staff', ['autonomy_level' => AutonomyLevel::ExecuteWithinLimits, 'reports_to_user_id' => $ceo->id]),
            templateAgent('finance', ['autonomy_level' => AutonomyLevel::Observe]),
        ];
    });
});

/** Call a capability through the autonomy gate, as the model would. */
function gated(Agent $agent, string $key, array $arguments, ?Task $task = null): string
{
    $run = app(AgentRunner::class)->create($agent, 'teste', TriggerType::Manual);

    if ($task !== null) {
        $run->forceFill(['task_id' => $task->id])->save();
    }

    return (new GatedTool(Capability::query()->where('key', $key)->sole(), new CapabilityContext($agent, $run->fresh())))->handle(new Request($arguments));
}

it('sends an action above an agent\'s level to the Chief of Staff first, who approves what fits its own level', function () {
    asTenant($this->tenant, function () {
        gated($this->finance, 'knowledge.save', ['title' => 'Prazo do fornecedor', 'content' => 'Paga a 60 dias.']);

        $approval = Approval::query()->sole();
        $review = Task::query()->where('assignee_agent_id', $this->chief->id)->sole();

        expect($approval->review_stage)->toBe(Approval::STAGE_AGENT)
            ->and($approval->review_agent_id)->toBe($this->chief->id)
            ->and($review->source->is($approval))->toBeTrue()
            // People read the task; only the Chief of Staff's brief names the tool.
            ->and($review->description)->not->toContain('approvals.review')
            ->and(AgentRun::query()->where('task_id', $review->id)->value('input'))->toContain("a aprovação #{$approval->id}. Revê-a com approvals.review");

        $result = gated($this->chief, 'approvals.review', ['approval_id' => $approval->id, 'decision' => 'approve', 'note' => 'Faz sentido.'], $review);

        expect($result)->toContain('Aprovaste')
            ->and($approval->fresh()->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->fresh()->decided_by_agent_id)->toBe($this->chief->id)
            ->and($review->fresh()->status->isClosed())->toBeTrue();

        Queue::assertPushed(ExecuteApprovedAction::class);
    });
});

it('passes to people what is above the Chief of Staff\'s level, and never reviews the absolute ceiling', function () {
    asTenant($this->tenant, function () {
        $this->chief->update(['autonomy_level' => AutonomyLevel::Observe]);
        gated($this->finance, 'knowledge.save', ['title' => 'x', 'content' => 'y']);
        $approval = Approval::query()->sole();

        gated($this->chief, 'approvals.review', ['approval_id' => $approval->id, 'decision' => 'approve', 'note' => 'Parece bem.']);

        expect($approval->fresh())->status->toBe(ApprovalStatus::Pending)
            ->review_stage->toBe(Approval::STAGE_HUMAN)
            ->review_note->toContain('decide uma pessoa');

        // Paying out money is under the ceiling: straight to people.
        gated($this->finance, 'comms.send_email', ['to' => ['novo@fora.example'], 'subject' => 'Olá', 'body' => 'Teste.']);
        expect(Approval::query()->latest('id')->first()->review_stage)->toBe(Approval::STAGE_HUMAN);
    });
});

it('lets the Chief of Staff propose a trust level that only the matrix lets people confirm', function () {
    $approval = asTenant($this->tenant, function () {
        gated($this->chief, 'agents.set_trust_level', ['agent' => 'finance', 'level' => 2, 'reason' => 'Três semanas sem acções devolvidas.']);

        return Approval::query()->sole();
    });

    expect($approval->review_stage)->toBe(Approval::STAGE_HUMAN)
        ->and($approval->action_summary)->toContain('de N0 para N2');

    $this->actingAs($this->manager)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve"))->assertForbidden();
    $this->actingAs($this->ceo)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve"))->assertRedirect();

    asTenant($this->tenant, function () use ($approval) {
        app(ApprovalService::class)->execute($approval->fresh());
        expect($this->finance->fresh()->autonomy_level)->toBe(AutonomyLevel::ExecuteWithApproval);
    });
});

it('searches conversations only when the person asking may request reports', function () {
    asTenant($this->tenant, function () {
        $this->finance->assignees()->attach($this->manager->id, ['role' => 'work']);
        $chat = app(TaskThread::class)->conversation($this->manager, $this->finance);
        app(TaskThread::class)->post($chat, $this->manager, 'O cliente Hotel Baía Azul ameaçou cancelar o contrato.', wake: false);

        $fromCeo = app(TaskThread::class)->conversation($this->ceo, $this->chief);
        $result = json_decode(gated($this->chief, 'conversations.search', ['query' => 'cancelar contrato'], $fromCeo), true);

        expect($result['matches'])->toHaveCount(1)
            ->and($result['matches'][0]['excerpt'])->toContain('Hotel Baía Azul')
            ->and($result['matches'][0]['link'])->toBe("/tasks/{$chat->id}");

        $this->chief->assignees()->attach($this->manager->id, ['role' => 'chat']);
        $fromManager = app(TaskThread::class)->conversation($this->manager, $this->chief);
        expect(gated($this->chief, 'conversations.search', ['query' => 'contrato'], $fromManager))->toContain('só pesquiso conversas');
    });
});

it('lets any agent raise something urgent to the Chief of Staff', function () {
    asTenant($this->tenant, function () {
        $task = Task::factory()->create(['assignee_agent_id' => $this->finance->id]);
        gated($this->finance, 'escalate.urgent', ['title' => 'Pagamento duplicado de 2,4 M MT', 'why_urgent' => 'Sai do banco hoje.', 'summary' => 'A factura FT 877 foi paga duas vezes.'], $task);

        $urgent = Task::query()->where('assignee_agent_id', $this->chief->id)->sole();

        expect($urgent->priority)->toBe(TaskPriority::Urgent)
            ->and($urgent->description)->toContain($task->identifier())
            ->and($task->messages()->latest('id')->value('body'))->toContain('escalou');
    });
});
