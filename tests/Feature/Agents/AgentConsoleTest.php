<?php

use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    [$this->a, $this->b] = Tenant::factory()->count(2)->create();

    [$this->owner, $this->member, $this->boss, $this->agent] = asTenant($this->a, function () {
        $boss = User::factory()->create(['name' => 'Chefe']);

        return [
            User::factory()->owner()->create(),
            User::factory()->create(),
            $boss,
            Agent::factory()->create(['name' => 'Triagem', 'reports_to_user_id' => $boss->id]),
        ];
    });

    [$this->agentB, $this->approvalB, $this->runB] = asTenant($this->b, function () {
        $agent = Agent::factory()->create();
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        return [$agent, Approval::factory()->create(['agent_id' => $agent->id, 'agent_run_id' => $run->id]), $run];
    });
});

it('lists and shows the tenant agents only', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->a, 'agents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Agents/Index')->has('agents', 1)->where('agents.0.name', 'Triagem'));

    $this->actingAs($this->member)->get(tenantUrl($this->a, "agents/{$this->agent->id}"))->assertOk();
    $this->actingAs($this->member)->get(tenantUrl($this->a, "agents/{$this->agentB->id}"))->assertNotFound();
    $this->actingAs($this->member)->get(tenantUrl($this->a, "runs/{$this->runB->id}"))->assertNotFound();
    $this->actingAs($this->owner)->post(tenantUrl($this->a, "approvals/{$this->approvalB->id}/approve"))->assertNotFound();
});

it('queues a run for people who work with the agent, and refuses others', function () {
    Queue::fake();

    $this->actingAs($this->member)->post(tenantUrl($this->a, "agents/{$this->agent->id}/runs"), ['input' => 'Olá'])->assertForbidden();

    $this->actingAs($this->boss)->post(tenantUrl($this->a, "agents/{$this->agent->id}/runs"), ['input' => 'Resume os leads.'])
        ->assertRedirect();

    Queue::assertPushedOn('agents-high', RunAgent::class, fn (RunAgent $job) => $job->tenantId === $this->a->id);
    expect(asTenant($this->a, fn () => AgentRun::query()->sole()->requested_by_user_id))->toBe($this->boss->id);
});

it('lets owners suspend and reactivate, and nobody else', function () {
    $this->actingAs($this->boss)->put(tenantUrl($this->a, "agents/{$this->agent->id}/status"), ['status' => 'suspended'])->assertForbidden();

    $this->actingAs($this->owner)->put(tenantUrl($this->a, "agents/{$this->agent->id}/status"), ['status' => 'suspended', 'reason' => 'Revisão'])->assertRedirect();
    expect(asTenant($this->a, fn () => $this->agent->fresh()->status))->toBe(AgentStatus::Suspended);

    $this->actingAs($this->owner)->put(tenantUrl($this->a, "agents/{$this->agent->id}/status"), ['status' => 'active'])->assertRedirect();
    expect(asTenant($this->a, fn () => $this->agent->fresh()->status))->toBe(AgentStatus::Active);
});

it('assigns only people of the same tenant', function () {
    $userB = asTenant($this->b, fn () => User::factory()->create());

    $this->actingAs($this->owner)->put(tenantUrl($this->a, "agents/{$this->agent->id}/assignees"), ['user_ids' => [$userB->id]])
        ->assertSessionHasErrors('user_ids.0');

    $this->actingAs($this->owner)->put(tenantUrl($this->a, "agents/{$this->agent->id}/assignees"), ['user_ids' => [$this->member->id]])->assertRedirect();

    expect(asTenant($this->a, fn () => $this->agent->assignees()->pluck('users.id')->all()))->toBe([$this->member->id]);
});

it('lets the right people decide, and only once', function () {
    $approval = asTenant($this->a, fn () => Approval::factory()->create([
        'agent_id' => $this->agent->id,
        'agent_run_id' => AgentRun::factory()->create(['agent_id' => $this->agent->id])->id,
    ]));

    $this->actingAs($this->member)->post(tenantUrl($this->a, "approvals/{$approval->id}/reject"), ['note' => 'Não'])->assertForbidden();
    $this->actingAs($this->boss)->post(tenantUrl($this->a, "approvals/{$approval->id}/reject"))->assertSessionHasErrors('note');
    $this->actingAs($this->boss)->post(tenantUrl($this->a, "approvals/{$approval->id}/reject"), ['note' => 'Ainda não.'])->assertSessionHas('success');
    $this->actingAs($this->owner)->post(tenantUrl($this->a, "approvals/{$approval->id}/approve"))->assertSessionHas('error');

    expect(asTenant($this->a, fn () => $approval->fresh()->status))->toBe(ApprovalStatus::Rejected);
});

it('shows each person only the approvals they answer for', function () {
    asTenant($this->a, fn () => Approval::factory()->create([
        'agent_id' => $this->agent->id,
        'agent_run_id' => AgentRun::factory()->create(['agent_id' => $this->agent->id])->id,
    ]));

    $this->actingAs($this->member)->get(tenantUrl($this->a, 'approvals'))->assertInertia(fn (Assert $page) => $page->has('approvals.data', 0));
    $this->actingAs($this->boss)->get(tenantUrl($this->a, 'approvals'))->assertInertia(fn (Assert $page) => $page->has('approvals.data', 1)->where('approvals.data.0.can_decide', true));
    $this->actingAs($this->owner)->get(tenantUrl($this->a, '/'))->assertInertia(fn (Assert $page) => $page->where('auth.pending_approvals', 1));
});

it('authorises live channels per tenant', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
    app('Illuminate\Broadcasting\BroadcastManager')->purge();
    require base_path('routes/channels.php');

    $run = asTenant($this->a, fn () => AgentRun::factory()->create(['agent_id' => $this->agent->id]));
    $auth = fn (User $user, Tenant $tenant, string $channel) => $this->actingAs($user)->post(tenantUrl($tenant, 'broadcasting/auth'), ['socket_id' => '1.1', 'channel_name' => "private-{$channel}"]);

    $auth($this->member, $this->a, "tenant.{$this->a->id}.run.{$run->id}")->assertOk();
    $auth($this->member, $this->a, "tenant.{$this->b->id}.run.{$this->runB->id}")->assertForbidden();
    $auth($this->member, $this->a, "tenant.{$this->a->id}.run.{$this->runB->id}")->assertForbidden();
    $auth($this->member, $this->a, "tenant.{$this->a->id}.approvals")->assertForbidden();
    $auth($this->owner, $this->a, "tenant.{$this->a->id}.approvals")->assertOk();
    $auth($this->member, $this->a, "tenant.{$this->a->id}.user.{$this->owner->id}")->assertForbidden();
});
