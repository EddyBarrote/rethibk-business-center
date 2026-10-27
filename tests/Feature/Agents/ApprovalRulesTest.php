<?php

use App\Ai\Autonomy\AutonomyGate;
use App\Ai\Capabilities\CapabilityContext;
use App\Enums\ApprovalStatus;
use App\Enums\AutonomyLevel;
use App\Enums\RuleEffect;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Capability;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Regras de aprovação".

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();
    [$this->owner, $this->member, $this->agent, $this->other, $this->capability] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create();

        return [
            $owner,
            User::factory()->create(),
            Agent::factory()->level(AutonomyLevel::Observe)->create(['name' => 'Gestor de Clientes', 'reports_to_user_id' => $owner->id]),
            Agent::factory()->level(AutonomyLevel::Observe)->create(['name' => 'Finanças']),
            Capability::factory()->create(['key' => 'crm.opportunity.create', 'name' => 'Registar oportunidade', 'is_mutating' => true, 'risk' => AutonomyLevel::ExecuteWithinLimits]),
        ];
    });
});

function gateRule(Tenant $tenant, Agent $agent, Capability $capability, array $arguments = [])
{
    return asTenant($tenant, fn () => app(AutonomyGate::class)->evaluate($capability, $arguments, new CapabilityContext($agent, AgentRun::factory()->create(['agent_id' => $agent->id]))));
}

it('lets a rule allow, ask or refuse an action, the agent\'s own rule before the rule for all', function () {
    expect(gateRule($this->tenant, $this->agent, $this->capability)->allowed)->toBeFalse();

    asTenant($this->tenant, fn () => ApprovalRule::factory()->create(['capability_key' => 'crm.opportunity.create', 'effect' => RuleEffect::Allow]));
    $allowed = gateRule($this->tenant, $this->agent, $this->capability);
    expect($allowed->allowed)->toBeTrue()->and($allowed->rule?->effect)->toBe(RuleEffect::Allow);

    asTenant($this->tenant, fn () => ApprovalRule::factory()->create(['agent_id' => $this->agent->id, 'capability_key' => 'crm.opportunity.create', 'effect' => RuleEffect::Deny]));
    $denied = gateRule($this->tenant, $this->agent, $this->capability);
    expect($denied->allowed)->toBeFalse()->and($denied->denied)->toBeTrue()
        ->and(asTenant($this->tenant, fn () => $denied->reason()))->toContain('Recusar sempre «Registar oportunidade» ao Gestor de Clientes')
        ->and(gateRule($this->tenant, $this->other, $this->capability)->allowed)->toBeTrue();

    // «Perguntar sempre» holds even an agent whose level would let it through.
    asTenant($this->tenant, function () {
        $this->agent->update(['autonomy_level' => AutonomyLevel::ExecuteAndReport]);
        ApprovalRule::query()->where('agent_id', $this->agent->id)->update(['effect' => RuleEffect::Ask]);
    });
    $asked = gateRule($this->tenant, $this->agent->fresh(), $this->capability);
    expect($asked->allowed)->toBeFalse()->and($asked->denied)->toBeFalse()->and($asked->rule?->effect)->toBe(RuleEffect::Ask);
});

it('never lets a rule past the absolute ceiling', function () {
    $payment = asTenant($this->tenant, function () {
        ApprovalRule::factory()->create(['agent_id' => $this->agent->id, 'capability_key' => 'erp.payments.create', 'effect' => RuleEffect::Allow]);

        return Capability::factory()->create(['key' => 'erp.payments.create', 'is_mutating' => true, 'risk' => AutonomyLevel::Observe]);
    });

    $decision = gateRule($this->tenant, $this->agent, $payment);
    expect($decision->allowed)->toBeFalse()->and($decision->ceilingReason)->not->toBeNull();
});

it('leaves a standing rule with «Permitir sempre», for who configures agents and not under the ceiling', function () {
    [$approval, $ceiling] = asTenant($this->tenant, fn () => [
        Approval::factory()->create(['agent_id' => $this->agent->id, 'agent_run_id' => AgentRun::factory()->create(['agent_id' => $this->agent->id])->id, 'action_type' => 'crm.opportunity.create']),
        Approval::factory()->create(['agent_id' => $this->agent->id, 'agent_run_id' => AgentRun::factory()->create(['agent_id' => $this->agent->id])->id, 'ceiling_reason' => 'Pagamento']),
    ]);

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "approvals/{$ceiling->id}/approve?always=1"))->assertForbidden();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve?always=1"))
        ->assertSessionHas('success', 'Permitido. Daqui em diante Gestor de Clientes faz «Registar oportunidade» sem perguntar.');

    asTenant($this->tenant, function () use ($approval) {
        $rule = ApprovalRule::query()->sole();
        expect($approval->fresh()->status)->toBe(ApprovalStatus::Approved)
            ->and([$rule->agent_id, $rule->capability_key, $rule->effect, $rule->from_approval_id])->toBe([$this->agent->id, 'crm.opportunity.create', RuleEffect::Allow, $approval->id]);
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'approvals'))
        ->assertInertia(fn (Assert $page) => $page->has('approvals'));
});

it('lets who configures agents manage the rules, and nobody else', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/approval-rules'))->assertForbidden();

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'settings/approval-rules'), ['agent_id' => '', 'capability_key' => 'crm.opportunity.create', 'effect' => 'deny'])
        ->assertSessionHas('success', 'Regra guardada: Recusar sempre «Registar oportunidade» a todos os agentes.');
    $rule = asTenant($this->tenant, fn () => ApprovalRule::query()->sole());

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/approval-rules'))
        ->assertInertia(fn (Assert $page) => $page->component('Settings/ApprovalRules')->where('rules.0.capability', 'Registar oportunidade')->where('rules.0.agent', null));

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/approval-rules/{$rule->id}"), ['effect' => 'allow'])->assertRedirect();
    expect(asTenant($this->tenant, fn () => $rule->fresh()->effect))->toBe(RuleEffect::Allow);

    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "settings/approval-rules/{$rule->id}"))->assertRedirect();
    expect(asTenant($this->tenant, fn () => ApprovalRule::query()->count()))->toBe(0);
});
