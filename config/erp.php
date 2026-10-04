<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default ERP connection (section 8)
    |--------------------------------------------------------------------------
    |
    | Used when the tenant has no row in erp_connections. "local" starts the
    | fake ERP server over stdio; "web" talks to the real ERP over HTTP.
    | The local command is only ever read from here, never from the
    | database, so a tenant setting cannot start an arbitrary process.
    |
    */

    'transport' => env('ERP_MCP_TRANSPORT', 'local'),

    'url' => env('ERP_MCP_URL'),

    'token' => env('ERP_MCP_TOKEN'),

    'local_command' => env('ERP_MCP_LOCAL_COMMAND', 'php artisan mcp:start fake-erp'),

    'timeout' => (float) env('ERP_MCP_TIMEOUT', 30),

    'fake' => [
        // Where the fake ERP keeps its state between calls.
        'storage_path' => env('ERP_FAKE_STORAGE_PATH') ?: storage_path('app/private/fake-erp/state.json'),
    ],

];
