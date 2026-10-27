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
    | A request to a model provider that got no answer (connection dropped,
    | 429, 5xx) is sent again this many times, waiting delay_ms, then double.
    */

    'provider_retry' => [
        'times' => (int) env('AI_PROVIDER_RETRIES', 3),
        'delay_ms' => (int) env('AI_PROVIDER_RETRY_DELAY_MS', 1500),
        'hosts' => ['generativelanguage.googleapis.com', 'api.anthropic.com', 'api.openai.com'],
    ],

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
    | The models an agent can be given (docs/DECISOES.md, "Agentes: escolher o modelo")
    |--------------------------------------------------------------------------
    |
    | Chosen from a list on the agent's page, never typed. A model shows only
    | when its provider has a key. Its price comes from `pricing` below.
    |
    */

    // The daily suggestions of the entry screen use the cheapest model.
    'suggestions_model' => env('AI_SUGGESTIONS_MODEL', 'gemini-3.1-flash-lite'),

    'models' => [
        ['provider' => 'gemini', 'model' => 'gemini-3.6-flash', 'label' => 'Gemini 3.6 Flash', 'hint' => 'Bom a raciocinar e a usar ferramentas. É o modelo por omissão.'],
        ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite', 'label' => 'Gemini 3.1 Flash-Lite', 'hint' => 'Mais rápido e cerca de 6 vezes mais barato. Para triagem e tarefas simples.'],
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
    | Running code (docs/DECISOES.md, "Correr código localmente")
    |--------------------------------------------------------------------------
    |
    | code.run runs the agent's Python on this server, shut in: sandbox-exec on
    | macOS (python from the venv `php artisan sandbox:install` makes) or
    | Docker on servers (image from docker/sandbox). driver: auto, macos,
    | docker or off. No AI cost; limits per run.
    |
    */

    'code_execution' => [
        'driver' => env('AI_CODE_SANDBOX', 'auto'),
        'python' => env('AI_CODE_PYTHON', storage_path('app/sandbox/venv/bin/python')),
        'base_python' => env('AI_CODE_BASE_PYTHON', 'python3'),
        'packages' => ['pandas', 'numpy', 'openpyxl'],
        'docker_image' => env('AI_CODE_DOCKER_IMAGE', 'micomoc-sandbox'),
        'timeout' => (int) env('AI_CODE_TIMEOUT', 60),
        'memory_mb' => (int) env('AI_CODE_MEMORY_MB', 1024),
        'max_mb' => (float) env('AI_CODE_MAX_MB', 50),
        'output_chars' => 20000,
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
