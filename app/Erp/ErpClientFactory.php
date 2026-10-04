<?php

namespace App\Erp;

use App\Enums\ErpTransport;
use App\Erp\Exceptions\ErpNotConfiguredException;
use App\Models\ErpConnection;
use Laravel\Mcp\Client;

/**
 * Builds the MCP client for the rethink_erp connection (section 8.1), with the
 * transport chosen per tenant: "web" talks to the real ERP, "local" starts the
 * fake ERP over stdio (section 8.2).
 */
final class ErpClientFactory
{
    /**
     * The client for the current tenant. Without a configured connection the
     * .env defaults apply, outside production only: in production a tenant
     * must never fall back to credentials it does not own.
     */
    public function forCurrentTenant(): Client
    {
        $connection = ErpConnection::query()->currentTenant()->first();

        if ($connection === null && app()->isProduction()) {
            throw new ErpNotConfiguredException;
        }

        return $this->make($connection);
    }

    public function make(?ErpConnection $connection): Client
    {
        $transport = $connection->transport ?? ErpTransport::from((string) config('erp.transport'));
        $timeout = (float) config('erp.timeout', 30);

        if ($transport === ErpTransport::Local) {
            [$command, $args] = $this->localCommand();

            return Client::local($command, $args)->withoutCache()->withTimeout($timeout);
        }

        $url = $connection === null ? config('erp.url') : $connection->base_url;

        if (! is_string($url) || $url === '') {
            throw new ErpNotConfiguredException;
        }

        $token = $connection === null ? config('erp.token') : $connection->decryptedToken();
        $client = Client::web($url)->withoutCache()->withTimeout($timeout);

        if (is_string($token) && $token !== '') {
            $client->withToken($token);
        }

        return $client;
    }

    /**
     * The local command comes from config only, never from the database, so a
     * tenant setting can never start an arbitrary process.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function localCommand(): array
    {
        $parts = array_values(array_filter(str_getcsv((string) config('erp.local_command'), ' ', '"', '\\'), fn ($part) => $part !== null && $part !== ''));

        if ($parts === []) {
            throw new ErpNotConfiguredException;
        }

        $command = (string) array_shift($parts);

        if ($command === 'php') {
            $command = PHP_BINARY;
        }

        $args = array_map(fn ($arg) => $arg === 'artisan' ? base_path('artisan') : (string) $arg, $parts);

        return [$command, $args];
    }
}
