<?php

use App\Ai\Agents\AgentEditor;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

// docs/DECISOES.md, "Modelos de cada provedor".

beforeEach(function () {
    config(['agents.discover_models' => true, 'ai.providers.gemini.key' => 'test-key']);
    $this->tenant = Tenant::factory()->create();
});

it('offers the models the provider has today, after the catalogue, and only the ones that write text', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => [
        ['name' => 'models/gemini-3.6-flash', 'displayName' => 'Gemini 3.6 Flash', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/gemini-3.9-flash-preview', 'displayName' => 'Gemini 3.9 Flash Preview', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/gemini-3.8-flash-image', 'displayName' => 'Nano Banana', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/gemini-embedding-002', 'displayName' => 'Embedding', 'supportedGenerationMethods' => ['embedContent']],
    ]])]);

    $models = asTenant($this->tenant, fn () => collect(AgentEditor::models())->where('provider', 'gemini'));

    expect($models->first()['model'])->toBe('gemini-3.6-flash')
        ->and($models->pluck('model'))->toContain('gemini-3.8-flash', 'gemini-3.9-flash-preview')
        ->and($models->pluck('model'))->not->toContain('gemini-3.8-flash-image', 'gemini-embedding-002')
        ->and($models->firstWhere('model', 'gemini-3.9-flash-preview')['input'])->toBeNull()
        ->and($models->where('model', 'gemini-3.6-flash'))->toHaveCount(1);
});

it('keeps to the catalogue when the provider does not answer', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('', 500)]);

    $models = asTenant($this->tenant, fn () => collect(AgentEditor::models())->where('provider', 'gemini'));

    expect($models->pluck('model')->all())->toBe(collect(config('agents.models'))->where('provider', 'gemini')->pluck('model')->values()->all());
});
