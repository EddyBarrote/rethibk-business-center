<?php

namespace App\Erp;

use App\Enums\AuditResult;
use App\Enums\ErpConnectionStatus;
use App\Erp\Exceptions\ErpException;
use App\Models\AuditLog;
use App\Models\ErpConnection;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\ClientManager;
use Laravel\Mcp\Client\Primitives\Tool;
use Throwable;

/**
 * The only way the platform talks to the ERP. Every MCP call goes through
 * here and lands in audit_logs (section 19, E01), with the tenant, the actor,
 * the arguments, the outcome and the duration.
 *
 * Each operation builds a fresh client for the current tenant and closes it
 * afterwards: the client manager caches clients by name, and a cached client
 * in a long-running worker would carry one tenant's connection into the next.
 */
final class ErpGateway
{
    public const CLIENT = 'rethink_erp';

    /** Results larger than this are summarised in the audit row. */
    private const AUDIT_RESULT_LIMIT = 8192;

    public function __construct(
        private readonly ClientManager $clients,
        private readonly ErpClientFactory $factory,
        private readonly TenantManager $tenants,
    ) {}

    /**
     * @return list<ErpTool>
     */
    public function tools(?Model $actor = null): array
    {
        return $this->audited('erp.tools_list', [], $actor, function (Client $client): array {
            $tools = $client->tools()->map(fn (Tool $tool) => ErpTool::fromMcp($tool))->values()->all();

            return [$tools, AuditResult::Ok, ['count' => count($tools)]];
        });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $tool, array $arguments = [], ?Model $actor = null): ErpCallResult
    {
        $started = hrtime(true);

        return $this->audited('erp.tool_call', ['tool' => $tool, 'arguments' => $arguments], $actor, function (Client $client) use ($tool, $arguments, $started): array {
            $result = $client->callTool($tool, $arguments);
            $text = $result->text();
            $data = $result->structuredContent ?? (Str::isJson($text) ? (array) json_decode($text, true) : null);

            $call = new ErpCallResult($tool, ! $result->isError, $data, $text, $this->elapsedMs($started));

            return [$call, $call->ok ? AuditResult::Ok : AuditResult::Error, $call->ok ? ['result' => $this->auditable($data ?? $text)] : ['error' => Str::limit($text, 2000)]];
        });
    }

    /**
     * Connect, list the tools and ask erp.health; store the outcome on the
     * connection so the settings screen can show it.
     */
    public function test(ErpConnection $connection, ?Model $actor = null): ErpConnection
    {
        try {
            $capabilities = $this->audited('erp.connection_test', [], $actor, function (Client $client): array {
                $tools = $client->tools()->map(fn (Tool $tool) => ErpTool::fromMcp($tool)->summary())->values()->all();

                if (collect($tools)->contains('name', 'erp.health')) {
                    $health = $client->callTool('erp.health');

                    if ($health->isError) {
                        throw new ErpException('erp.health respondeu com erro: '.$health->text());
                    }
                }

                return [$tools, AuditResult::Ok, ['tools' => count($tools)]];
            }, $connection);

            $connection->forceFill([
                'status' => ErpConnectionStatus::Ok,
                'capabilities' => $capabilities,
                'last_error' => null,
                'last_checked_at' => now(),
            ])->save();
        } catch (ErpException $e) {
            $connection->forceFill([
                'status' => ErpConnectionStatus::Error,
                'last_error' => $e->getMessage(),
                'last_checked_at' => now(),
            ])->save();
        }

        return $connection;
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(Client): array{0: T, 1: AuditResult, 2: array<string, mixed>}  $operation
     * @return T
     */
    private function audited(string $action, array $payload, ?Model $actor, Closure $operation, ?ErpConnection $using = null): mixed
    {
        $tenant = $this->tenants->currentOrFail();
        $connection = $using ?? ErpConnection::query()->currentTenant()->first();
        $started = hrtime(true);
        $client = null;

        try {
            // A connection under test may not be the one the registered client
            // would pick (for example a disabled row being re-enabled).
            $client = $using !== null
                ? $this->factory->make($using)->setName(self::CLIENT)
                : $this->clients->build(self::CLIENT);

            [$value, $result, $details] = $operation($client->connect());

            AuditLog::record($actor, $action, [...$payload, ...$details, 'duration_ms' => $this->elapsedMs($started), 'tenant' => $tenant->slug], $result, $connection);

            return $value;
        } catch (Throwable $e) {
            $message = $e instanceof ErpException ? $e->getMessage() : $this->describe($e);

            AuditLog::record($actor, $action, [...$payload, 'error' => $message, 'duration_ms' => $this->elapsedMs($started), 'tenant' => $tenant->slug], AuditResult::Error, $connection);

            throw $e instanceof ErpException ? $e : new ErpException($message, previous: $e);
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // The process or session is already gone.
            }
        }
    }

    private function describe(Throwable $e): string
    {
        return 'Falha na comunicação com o ERP: '.Str::limit($e->getMessage(), 500);
    }

    /**
     * @param  array<string, mixed>|string  $result
     * @return array<string, mixed>|string
     */
    private function auditable(array|string $result): array|string
    {
        $json = (string) json_encode($result, JSON_UNESCAPED_UNICODE);

        if (strlen($json) <= self::AUDIT_RESULT_LIMIT) {
            return $result;
        }

        return is_array($result)
            ? ['truncated' => true, 'bytes' => strlen($json), 'keys' => array_keys($result)]
            : Str::limit($result, self::AUDIT_RESULT_LIMIT);
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
