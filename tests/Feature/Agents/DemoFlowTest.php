<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Demo\DemoScenario;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\ApprovalService;
use App\Enums\ApprovalStatus;
use App\Enums\ExecutionStatus;
use App\Enums\RunStatus;
use App\Enums\TriggerType;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;

// The E02 acceptance criterion (section 19), against the real fake ERP over stdio.

beforeEach(function () {
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create();
    $this->owner = asTenant($this->tenant, fn () => User::factory()->owner()->create());
});

afterEach(fn () => @unlink($this->store));

it('reads the ERP, blocks the write above its level, and completes it once a human approves', function () {
    GenericAgent::fake(DemoScenario::scriptedResponses());

    $run = asTenant($this->tenant, function () {
        $agent = app(DemoScenario::class)->ensureAgent();

        return app(AgentRunner::class)->dispatch($agent, DemoScenario::INPUT, TriggerType::Manual, $this->owner);
    });

    asTenant($this->tenant, function () use ($run) {
        $run = $run->fresh();
        $approval = Approval::query()->sole();

        expect($run->status)->toBe(RunStatus::AwaitingApproval)
            ->and($run->steps()->pluck('type')->map->value->all())->toContain('tool_call', 'tool_result', 'approval', 'message')
            ->and($approval->action_type)->toBe('erp.leads.create')
            ->and($approval->status)->toBe(ApprovalStatus::Pending)
            ->and($approval->payload['title'])->toBe('Manutenção anual do porto da Beira')
            ->and(AuditLog::query()->where('action', 'erp.tool_call')->orderBy('id')->get()->pluck('payload.tool')->all())->toBe(['crm.search_accounts']);

        app(ApprovalService::class)->approve($approval, $this->owner, 'Pode avançar.');

        $approval->refresh();
        expect($approval->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->execution_status)->toBe(ExecutionStatus::Executed)
            ->and($approval->execution_result['data']['lead']['title'] ?? null)->toBe('Manutenção anual do porto da Beira')
            ->and($run->fresh()->status)->toBe(RunStatus::Completed)
            ->and(AuditLog::query()->where('action', 'erp.tool_call')->orderBy('id')->get()->pluck('payload.tool')->all())->toBe(['crm.search_accounts', 'leads.create'])
            ->and(AuditLog::query()->where('action', 'approval.approved')->exists())->toBeTrue();
    });
});

it('runs the demo command with a scripted model', function () {
    $this->artisan('agents:demo', ['tenant' => $this->tenant->slug, '--scripted' => true])
        ->expectsOutputToContain('Aprovações pendentes')
        ->assertSuccessful();

    asTenant($this->tenant, fn () => expect(AgentRun::query()->sole()->status)->toBe(RunStatus::AwaitingApproval));
});
