<?php

use App\Ai\Runs\AgentRunner;
use App\Enums\RunStatus;
use App\Enums\TriggerType;
use App\Models\Tenant;

beforeEach(fn () => $this->store = freshFakeErp());

afterEach(fn () => @unlink($this->store));

it('fails the run with a clear message when the provider has no API key', function () {
    config(['ai.default' => 'gemini', 'ai.providers.gemini.key' => null]);

    asTenant(Tenant::factory()->create(), function () {
        $run = app(AgentRunner::class)->dispatch(templateAgent('triage'), 'Olá', TriggerType::Manual);

        expect($run->fresh())
            ->status->toBe(RunStatus::Failed)
            ->error->toStartWith('Falta a chave da API do provedor gemini');
    });
});
