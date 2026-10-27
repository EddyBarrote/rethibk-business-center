<?php

use App\Http\Presenters\Present;

// docs/DECISOES.md, "Modelo de cada execução".

it('names the model a run used the human way, and keeps the id when the catalog no longer lists it', function () {
    expect(Present::modelLabels('gemini', 'gemini-3.6-flash'))->toBe(['model_label' => 'Gemini 3.6 Flash', 'provider_label' => 'Google Gemini'])
        ->and(Present::modelLabels('openrouter', 'anthropic/claude-haiku-5.5'))->toBe(['model_label' => 'Claude Haiku 5.5 (via OpenRouter)', 'provider_label' => 'OpenRouter'])
        ->and(Present::modelLabels('anthropic', 'claude-haiku-4-5-20251001'))->toBe(['model_label' => 'claude-haiku-4-5-20251001', 'provider_label' => 'Anthropic (API directa)'])
        ->and(Present::modelLabels(null, null))->toBe(['model_label' => null, 'provider_label' => null]);
});
