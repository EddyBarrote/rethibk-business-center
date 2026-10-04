<?php

namespace App\Connectors;

use App\Enums\AuditResult;
use App\Enums\ConnectorKind;
use App\Erp\ErpTool;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Models\PlatformConnector;
use App\Models\Tenant;
use App\Support\SafeHttp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Primitives\Tool;
use Throwable;

/**
 * The only way the platform talks to connectors (docs/CAPACIDADES.md): remote
 * MCP servers and HTTP actions added by the super admin or a tenant's admins.
 * Every call lands in audit_logs. A tenant's connector may only reach public
 * addresses, so an admin can never point an agent at the platform's own
 * network; the super admin's global connectors are trusted.
 */
final class ConnectorGateway
{
    private const TIMEOUT = 30;

    /** What the model gets back at most, in characters. */
    private const RESULT_LIMIT = 12_000;

    public function __construct(private readonly SafeHttp $http) {}

    /**
     * The tools the connector offers: the MCP server's list, or the one HTTP
     * action described by the connector itself.
     *
     * @return list<ErpTool>
     */
    public function discover(PlatformConnector|Connector $connector, ?Model $actor = null): array
    {
        if ($connector->kind === ConnectorKind::Http) {
            return [new ErpTool($connector->key, $connector->name, $connector->description, $connector->input_schema ?? ['type' => 'object', 'properties' => []], ! $connector->is_mutating)];
        }

        return $this->audited($connector, 'connector.tools_list', [], $actor, function () use ($connector): array {
            $client = $this->mcp($connector);

            try {
                return $client->connect()->tools()->map(fn (Tool $tool) => ErpTool::fromMcp($tool))->values()->all();
            } finally {
                $this->close($client);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context  extra audit fields, e.g. the agent run
     * @return array{ok: bool, text: string, data: array<string, mixed>|null}
     */
    public function call(PlatformConnector|Connector $connector, string $tool, array $arguments, ?Model $actor = null, array $context = []): array
    {
        if (! $connector->is_active) {
            throw new ConnectorException("O conector {$connector->name} está desligado.");
        }

        $payload = [...array_filter($context, fn ($value) => $value !== null), 'tool' => $tool, 'arguments' => $arguments];

        return $this->audited($connector, 'connector.tool_call', $payload, $actor, fn (): array => $connector->kind === ConnectorKind::Http
            ? $this->http($connector, $arguments)
            : $this->mcpCall($connector, $tool, $arguments));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, text: string, data: array<string, mixed>|null}
     */
    private function http(PlatformConnector|Connector $connector, array $arguments): array
    {
        $this->guard($connector, $connector->url);

        $request = Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'MICOMOC-Agentes/1.0'])
            ->withOptions(['allow_redirects' => ['max' => 3, 'on_redirect' => fn ($request, $response, $uri) => $this->guard($connector, (string) $uri)]]);

        if (filled($connector->secret)) {
            $request = $request->withToken((string) $connector->secret);
        }

        $method = Str::upper($connector->http_method ?: 'POST');

        try {
            $response = $method === 'GET'
                ? $request->get($connector->url, $arguments)
                : $request->send($method, $connector->url, ['json' => $arguments]);
        } catch (ConnectionException $e) {
            throw new ConnectorException('Não foi possível contactar '.$connector->name.': '.Str::limit($e->getMessage(), 200));
        }

        $body = Str::limit($response->body(), self::RESULT_LIMIT);
        $data = $response->json();

        return ['ok' => $response->successful(), 'text' => $response->successful() ? $body : "O serviço respondeu {$response->status()}: {$body}", 'data' => is_array($data) ? $data : null];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, text: string, data: array<string, mixed>|null}
     */
    private function mcpCall(PlatformConnector|Connector $connector, string $tool, array $arguments): array
    {
        $client = $this->mcp($connector);

        try {
            $result = $client->connect()->callTool($tool, $arguments);
            $text = Str::limit($result->text(), self::RESULT_LIMIT);

            return ['ok' => ! $result->isError, 'text' => $text, 'data' => $result->structuredContent ?? (Str::isJson($text) ? (array) json_decode($text, true) : null)];
        } finally {
            $this->close($client);
        }
    }

    private function mcp(PlatformConnector|Connector $connector): Client
    {
        $this->guard($connector, $connector->url);

        $client = Client::web($connector->url)->withoutCache()->withTimeout(self::TIMEOUT);

        if (filled($connector->secret)) {
            $client->withToken((string) $connector->secret);
        }

        return $client;
    }

    /**
     * A tenant's connector reaches public https addresses only (http and
     * private addresses are allowed on a developer's machine).
     */
    public function guard(PlatformConnector|Connector $connector, string $url): void
    {
        if ($connector instanceof PlatformConnector || app()->isLocal()) {
            return;
        }

        if (! str_starts_with(Str::lower($url), 'https://') && ! app()->runningUnitTests()) {
            throw new ConnectorException('Os conectores da empresa têm de usar https.');
        }

        try {
            $this->http->assertPublic($url);
        } catch (Throwable $e) {
            throw new ConnectorException($e->getMessage());
        }
    }

    private function close(Client $client): void
    {
        try {
            $client->disconnect();
        } catch (Throwable) {
            // The session is already gone.
        }
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(): T  $operation
     * @return T
     */
    private function audited(PlatformConnector|Connector $connector, string $action, array $payload, ?Model $actor, callable $operation): mixed
    {
        $started = hrtime(true);
        $payload = [...$payload, 'connector' => $connector->key, 'scope' => $connector instanceof PlatformConnector ? 'global' : 'tenant'];

        try {
            $value = $operation();
            $ok = ! is_array($value) || ! array_key_exists('ok', $value) || $value['ok'] === true;

            $this->log($connector, $actor, $action, [...$payload, 'duration_ms' => $this->elapsed($started), ...($ok ? [] : ['error' => Str::limit((string) ($value['text'] ?? ''), 1000)])], $ok ? AuditResult::Ok : AuditResult::Error);

            return $value;
        } catch (Throwable $e) {
            $message = $e instanceof ConnectorException ? $e->getMessage() : 'Falha na comunicação com '.$connector->name.': '.Str::limit($e->getMessage(), 300);

            $this->log($connector, $actor, $action, [...$payload, 'error' => $message, 'duration_ms' => $this->elapsed($started)], AuditResult::Error);

            throw $e instanceof ConnectorException ? $e : new ConnectorException($message, previous: $e);
        }
    }

    /**
     * audit_logs belong to a tenant: the super admin testing a global
     * connector outside any tenant leaves no row.
     *
     * @param  array<string, mixed>  $payload
     */
    private function log(PlatformConnector|Connector $connector, ?Model $actor, string $action, array $payload, AuditResult $result): void
    {
        if (Tenant::current() === null) {
            return;
        }

        AuditLog::record($actor, $action, $payload, $result, $connector instanceof Connector ? $connector : null);
    }

    private function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
