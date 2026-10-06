<?php

use App\Ai\Autonomy\AutonomyGate;
use App\Ai\Autonomy\GateDecision;
use App\Ai\Capabilities\CapabilityContext;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Capability;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

function gateFor(Tenant $tenant, AutonomyLevel $agentLevel, string $key, bool $mutating, AutonomyLevel $risk, array $arguments = []): GateDecision
{
    return asTenant($tenant, function () use ($agentLevel, $key, $mutating, $risk, $arguments) {
        $agent = Agent::factory()->level($agentLevel)->create();
        $capability = Capability::query()->where('key', $key)->first() ?? Capability::factory()->create(['key' => $key, 'is_mutating' => $mutating, 'risk' => $risk]);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        return app(AutonomyGate::class)->evaluate($capability, $arguments, new CapabilityContext($agent, $run));
    });
}

it('lets a mutating capability through only from its risk level up', function (AutonomyLevel $agent, AutonomyLevel $risk) {
    $decision = gateFor($this->tenant, $agent, 'erp.leads.update', true, $risk);

    expect($decision->allowed)->toBe($agent->value >= $risk->value)
        ->and($decision->requiredLevel)->toBe($risk);
})->with(AutonomyLevel::cases())->with(AutonomyLevel::cases());

it('always lets read-only capabilities through', function (AutonomyLevel $agent) {
    expect(gateFor($this->tenant, $agent, 'erp.crm.search_accounts', false, AutonomyLevel::Observe)->allowed)->toBeTrue();
})->with(AutonomyLevel::cases());

it('never lets the absolute ceiling through, not even at N4', function (string $key) {
    $decision = gateFor($this->tenant, AutonomyLevel::ExecuteAndReport, $key, true, AutonomyLevel::Observe);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->ceilingReason)->not->toBeNull();
})->with(['erp.payments.create', 'erp.invoices.issue', 'erp.contracts.sign', 'erp.hr.hire', 'platform.permissions.update']);

it('puts an email to a new external entity under the ceiling, and not one to a colleague', function () {
    $external = gateFor($this->tenant, AutonomyLevel::ExecuteAndReport, 'comms.send_email', true, AutonomyLevel::ExecuteWithinLimits, [
        'to' => ['novo@cliente.co.mz'], 'subject' => 'Olá', 'body' => 'Olá',
    ]);

    $this->tenant->update(['domain' => 'micomoc.co.mz']);
    $internal = gateFor($this->tenant->fresh(), AutonomyLevel::ExecuteAndReport, 'comms.send_email', true, AutonomyLevel::ExecuteWithinLimits, [
        'to' => ['direccao@micomoc.co.mz'], 'subject' => 'Olá', 'body' => 'Olá',
    ]);

    expect($external->allowed)->toBeFalse()
        ->and($external->ceilingReason)->toContain('novo@cliente.co.mz')
        ->and($internal->allowed)->toBeTrue();
});
