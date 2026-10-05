<?php

use App\Enums\ApprovalStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// Approving several actions at once follows the rule of approving one, and never
// covers what sits under the absolute ceiling.

beforeEach(function () {
    Queue::fake();
    [$this->a, $this->b] = Tenant::factory()->count(2)->create();

    [$this->boss, $this->member, $this->agent] = asTenant($this->a, function () {
        $boss = User::factory()->create();

        return [$boss, User::factory()->create(), Agent::factory()->create(['reports_to_user_id' => $boss->id])];
    });

    $this->pending = fn (array $attributes = []) => asTenant($this->a, fn () => Approval::factory()->create([
        'agent_id' => $this->agent->id,
        'agent_run_id' => AgentRun::factory()->create(['agent_id' => $this->agent->id])->id,
        ...$attributes,
    ]));
});

it('approves the selected actions the person may decide, skipping the absolute ceiling', function () {
    $first = ($this->pending)();
    $second = ($this->pending)();
    $ceiling = ($this->pending)(['ceiling_reason' => 'Comunicação externa a entidade nova']);
    $other = asTenant($this->b, fn () => Approval::factory()->create());

    $this->actingAs($this->boss)->post(tenantUrl($this->a, 'approvals/approve'), ['ids' => [$first->id, $second->id, $ceiling->id, $other->id]])
        ->assertSessionHas('success', 'Aprovadas 2 acções. Vão ser executadas.');

    asTenant($this->a, function () use ($first, $second, $ceiling) {
        expect($first->fresh()->status)->toBe(ApprovalStatus::Approved)
            ->and($second->fresh()->status)->toBe(ApprovalStatus::Approved)
            ->and($ceiling->fresh()->status)->toBe(ApprovalStatus::Pending);
    });
    expect(asTenant($this->b, fn () => $other->fresh()->status))->toBe(ApprovalStatus::Pending);
});

it('approves nothing for someone who does not decide for the agent', function () {
    $approval = ($this->pending)();

    $this->actingAs($this->member)->post(tenantUrl($this->a, 'approvals/approve'), ['ids' => [$approval->id]])->assertSessionHas('error');

    expect(asTenant($this->a, fn () => $approval->fresh()->status))->toBe(ApprovalStatus::Pending);
});
