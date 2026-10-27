<?php

use App\Ai\Eval\TriageJudge;
use App\Models\Agent;
use App\Models\AiCharge;
use App\Models\Tenant;

// docs/DECISOES.md, "Avaliação da triagem".

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'aval']);
    asTenant($this->tenant, fn () => Agent::factory()->create(['key' => 'triage', 'name' => 'Agente de Triagem', 'instructions' => 'Classifica cada email que entra.']));
});

it('scores the triage against the labelled emails without writing anything', function () {
    TriageJudge::fake([
        ['category' => 'client_rfq', 'priority' => 'high'],
        ['category' => 'supplier_quote', 'priority' => 'normal'],
        ['category' => 'other', 'priority' => 'normal'],
    ]);

    $this->artisan('triage:eval aval --limit=3 --min=0.9')
        ->expectsOutputToContain('Categoria certa')
        ->assertFailed();

    expect(asTenant($this->tenant, fn () => AiCharge::query()->where('purpose', 'triage.eval')->count()))->toBe(3);
});

it('passes when the triage gets them right', function () {
    TriageJudge::fake([
        ['category' => 'client_rfq', 'priority' => 'high'],
        ['category' => 'supplier_quote', 'priority' => 'normal'],
    ]);

    $this->artisan('triage:eval aval --limit=2')->assertSuccessful();
});
