<?php

namespace App\Providers;

use App\Erp\ErpClientFactory;
use App\Erp\ErpGateway;
use App\Mcp\FakeErp\FakeErpStore;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Client\ClientManager;
use Laravel\Mcp\Facades\Mcp;

/**
 * MCP wiring (section 8.1). The rethink_erp client is built per tenant by
 * ErpClientFactory; platform code goes through ErpGateway, which audits
 * every call.
 */
class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FakeErpStore::class, fn () => FakeErpStore::fromConfig());
    }

    public function boot(): void
    {
        Mcp::registerClient(ErpGateway::CLIENT, fn () => $this->app->make(ErpClientFactory::class)->forCurrentTenant());

        // Mcp::client() caches by name; never let a cached connection outlive a job.
        Event::listen(JobProcessed::class, fn () => $this->app->make(ClientManager::class)->disconnectAll());
    }
}
