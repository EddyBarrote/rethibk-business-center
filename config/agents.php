<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults for generic agents (docs/DECISOES.md)
    |--------------------------------------------------------------------------
    |
    | Each agent may set its own provider, model, temperature and limits;
    | these apply when it does not. The provider default lives in
    | config/ai.php (AI_PROVIDER).
    |
    */

    'model' => env('AI_MODEL') ?: null,

    'max_steps' => 8,

    'timeout' => 120,

    /*
    | Routine cron expressions are read in this timezone (Mozambique, CAT).
    */

    'schedule_timezone' => env('AGENTS_SCHEDULE_TIMEZONE', 'Africa/Maputo'),

    /*
    | Queues per trigger (section 14.1).
    */

    'queues' => [
        'manual' => 'agents-high',
        'email' => 'agents',
        'agent' => 'agents',
        'webhook' => 'agents',
        'schedule' => 'agents-low',
    ],

    /*
    |--------------------------------------------------------------------------
    | Prices, in USD per million tokens (section 14.3)
    |--------------------------------------------------------------------------
    |
    | Keyed "provider:model". Fill in the models the tenants use; any model
    | not listed is charged at the fallback, which is deliberately high so a
    | missing price can only make the budget stricter, never looser.
    |
    */

    'pricing' => [
        // Gemini (ai.google.dev/gemini-api/docs/pricing, 04.10.2026). 3.6 Flash
        // at the price from 1 January 2027 (until then it is half).
        'gemini:gemini-3.6-flash' => ['input' => 1.50, 'output' => 7.50],
        'gemini:gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50],
    ],

    'fallback_pricing' => [
        'input' => (float) env('AI_FALLBACK_INPUT_USD_PER_MTOK', 15),
        'output' => (float) env('AI_FALLBACK_OUTPUT_USD_PER_MTOK', 75),
    ],

    /*
    | Budget thresholds (section 14.3): warn at this share of a cap.
    */

    'budget_warning_ratio' => 0.8,

];
