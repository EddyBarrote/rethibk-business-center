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

it('holds what leaves the company when it carries sensitive data, whatever the level', function (string $body, string $kind) {
    $this->tenant->update(['domain' => 'micomoc.co.mz']);
    $decision = gateFor($this->tenant->fresh(), AutonomyLevel::ExecuteAndReport, 'comms.send_email', true, AutonomyLevel::ExecuteWithinLimits, [
        'to' => ['direccao@micomoc.co.mz'], 'subject' => 'Dados', 'body' => $body,
    ]);

    expect($decision->allowed)->toBeFalse()->and($decision->ceilingReason)->toContain($kind);
})->with([
    ['Pague com o cartão 4111 1111 1111 1111, validade 12/28.', 'um número de cartão'],
    ['O acesso ao portal: utilizador geral, palavra-passe: Maputo2026!', 'uma palavra-passe'],
    ['Use a chave AIzaSyA1234567890abcdefghijklmnopqrstu no teste.', 'uma chave de acesso'],
    ['O BI do colaborador é 110100123456B.', 'um número de BI'],
    ['A colaboradora está de baixa médica até sexta.', 'dados de saúde'],
]);

it('lets a clean message out, with bank details and invoice numbers', function () {
    $this->tenant->update(['domain' => 'micomoc.co.mz']);
    $decision = gateFor($this->tenant->fresh(), AutonomyLevel::ExecuteAndReport, 'comms.send_email', true, AutonomyLevel::ExecuteWithinLimits, [
        'to' => ['direccao@micomoc.co.mz'], 'subject' => 'Factura FT 2026/1043', 'body' => 'Segue a factura de 656,55 MZN. NIB 000100000012345678901, NUIT 400123456.',
    ]);

    expect($decision->allowed)->toBeTrue();
});
