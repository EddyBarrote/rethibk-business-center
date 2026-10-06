<?php

arch('tenant scopes are never bypassed in application code (section 4.2)')
    ->expect('App')
    ->not->toUse(['withoutGlobalScopes', 'withoutGlobalScope']);

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('tenant-aware jobs extend the base job')
    ->expect('App\Jobs')
    ->toExtend('App\Tenancy\TenantAwareJob');

arch('the platform reaches MCP servers only through the audited gateways (E01, connectors)')
    ->expect('Laravel\Mcp\Client')
    ->toOnlyBeUsedIn(['App\Erp', 'App\Connectors\ConnectorGateway', 'App\Providers\McpServiceProvider']);
