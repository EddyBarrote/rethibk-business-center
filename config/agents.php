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

    'max_steps' => 15,

    'timeout' => 120,

    /*
    | Ask the model for a summary of its reasoning (Gemini thought summaries),
    | shown live in the chat and folded to «Pensou durante…» afterwards. Its
    | tokens are output tokens, so they count in the AI budget.
    */

    'thought_summaries' => (bool) env('AGENTS_THOUGHT_SUMMARIES', true),

    /*
    | A run still "running" this long after its last update lost its worker
    | (the job is killed at 300 s), and one "queued" this long never got one:
    | both stop showing as working and are closed as failed.
    */

    'stale_running_minutes' => (int) env('AGENTS_STALE_RUNNING_MINUTES', 10),

    'stale_queued_minutes' => (int) env('AGENTS_STALE_QUEUED_MINUTES', 60),

    /*
    | Routine cron expressions are read in this timezone (Mozambique, CAT).
    */

    'schedule_timezone' => env('AGENTS_SCHEDULE_TIMEZONE', 'Africa/Maputo'),

    // A routine missed while the scheduler was down (a laptop asleep) runs once
    // when it comes back, if it is not older than this.
    'routine_catch_up_hours' => (int) env('AGENTS_ROUTINE_CATCH_UP_HOURS', 12),

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

    /*
    |--------------------------------------------------------------------------
    | Searching the internet (docs/DECISOES.md, "Pesquisa na internet")
    |--------------------------------------------------------------------------
    |
    | web.search asks a model with the provider's own search (Google Search on
    | Gemini). Empty provider or model: the platform's default. fee_usd is what
    | the provider bills per search on top of tokens (Gemini grounding: about
    | USD 14 per 1000 queries), charged to the AI budget with the tokens.
    |
    */

    'web_search' => [
        'provider' => env('AI_WEB_SEARCH_PROVIDER', 'gemini'),
        'model' => env('AI_WEB_SEARCH_MODEL'),
        'fee_usd' => (float) env('AI_WEB_SEARCH_FEE_USD', 0.014),
    ],

    /*
    |--------------------------------------------------------------------------
    | Running code (docs/DECISOES.md, "Correr código")
    |--------------------------------------------------------------------------
    |
    | code.run asks a model with the provider's own code execution (a Python
    | sandbox on Google's side for Gemini); nothing runs on this server. The
    | files go whole into the model, so max_mb caps what one call may send.
    |
    */

    'code_execution' => [
        'provider' => env('AI_CODE_EXECUTION_PROVIDER', 'gemini'),
        'model' => env('AI_CODE_EXECUTION_MODEL'),
        'max_mb' => (float) env('AI_CODE_EXECUTION_MAX_MB', 5),
    ],

    'fallback_pricing' => [
        'input' => (float) env('AI_FALLBACK_INPUT_USD_PER_MTOK', 15),
        'output' => (float) env('AI_FALLBACK_OUTPUT_USD_PER_MTOK', 75),
    ],

    /*
    | Budget thresholds (section 14.3): warn at this share of a cap.
    */

    'budget_warning_ratio' => 0.8,

    /*
    | Percentages an agent may apply without a source (docs/DECISOES.md,
    | "Perguntar, nunca inventar"): the IVA in Mozambique. Any other rate,
    | commission or discount has to come from a source or a person.
    */

    'known_rates' => [16],

];
