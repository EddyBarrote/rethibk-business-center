<?php

namespace App\Erp;

use App\Ai\Autonomy\PeopleData;
use App\Enums\AuditResult;
use App\Enums\ErpConnectionStatus;
use App\Erp\Exceptions\ErpException;
use App\Erp\Exceptions\ErpIdentityRefused;
use App\Erp\Perfex\PerfexAdapter;
use App\Models\Agent;
use App\Models\AgentErpIdentity;
use App\Models\AuditLog;
use App\Models\ErpConnection;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\ClientManager;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Client\Primitives\Tool;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Throwable;

/**
 * The only way the platform talks to the ERP. Every MCP call goes through
 * here and lands in audit_logs (section 19, E01), with the tenant, the actor,
 * the arguments, the outcome and the duration.
 *
 * Each operation builds a fresh client for the current tenant and closes it
 * afterwards: the client manager caches clients by name, and a cached client
 * in a long-running worker would carry one tenant's connection into the next.
 *
 * A call on behalf of an agent that has its own ERP token (AgentErpIdentity)
 * goes with that token, so the ERP records it under the agent's staff member
 * and allows only the agent's tools; everything else (people, the system, the
 * tool list, the dialect, connection tests) uses the connection's token. A
 * refusal of the agent's token is never retried with the tenant's
 * (docs/DECISOES.md, "Identidade própria de cada agente no ERP").
 */
final class ErpGateway
{
    public const CLIENT = 'rethink_erp';

    /** JSON-RPC "invalid params": how the Perfex MCP server answers a tool the token may not use ("Unknown tool: …"). */
    private const INVALID_PARAMS = -32602;

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
        $tools = $this->audited('erp.tools_list', [], $actor, function (Client $client): array {
            $tools = $client->tools()->map(fn (Tool $tool) => ErpTool::fromMcp($tool))->values()->all();

            return [$tools, AuditResult::Ok, ['count' => count($tools)]];
        });

        // Keep the connection's tool list fresh: the dialect is read from it.
        ErpConnection::query()->currentTenant()->first()?->forceFill([
            'capabilities' => array_map(fn (ErpTool $tool) => $tool->summary(), $tools),
        ])->save();

        return $tools;
    }

    /**
     * The tool vocabulary of the tenant's ERP, from the tools it last listed.
     * Without a list (or a connection) it is the contract, as the fake ERP speaks.
     */
    public function dialect(): ErpDialect
    {
        $tools = (array) (ErpConnection::query()->currentTenant()->first()->capabilities ?? []);

        return ErpDialect::fromToolNames(array_values(array_filter(array_column($tools, 'name'), 'is_string')));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context  extra audit fields, e.g. the agent run
     */
    public function call(string $tool, array $arguments = [], ?Model $actor = null, array $context = []): ErpCallResult
    {
        // The platform's own code asks in contract terms; a Perfex ERP answers
        // through its own reads, each audited below.
        if (PerfexAdapter::supports($tool) && $this->dialect() === ErpDialect::Perfex) {
            return (new PerfexAdapter(fn (string $native, array $nativeArguments) => $this->call($native, $nativeArguments, $actor, $context)))
                ->call($tool, $arguments);
        }

        $identity = $this->identityOf($actor);
        $started = hrtime(true);
        $payload = [...array_filter($context, fn ($value) => $value !== null), 'tool' => $tool, 'arguments' => $arguments, ...$this->identityFields($identity)];

        return $this->audited('erp.tool_call', $payload, $actor, function (Client $client) use ($tool, $arguments, $started, $identity): array {
            $result = $client->callTool($tool, $arguments);
            $text = $result->text();

            // A tool that answers "no permission" to an agent's own token refuses the identity, not the request.
            if ($result->isError && $identity !== null && $this->soundsLikeRefusal($text)) {
                throw ErpIdentityRefused::toolNotAllowed($identity->agent, $tool);
            }

            $data = $result->structuredContent ?? (Str::isJson($text) ? (array) json_decode($text, true) : null);

            $call = new ErpCallResult($tool, ! $result->isError, $data, $text, $this->elapsedMs($started));

            return [$call, $call->ok ? AuditResult::Ok : AuditResult::Error, $call->ok ? ['result' => $this->auditable($data ?? $text, $tool)] : ['error' => Str::limit($text, 2000)]];
        }, identity: $identity, tool: $tool);
    }

    /**
     * With an agent's own token: list the tools the token sees and ask the
     * ERP who it acts as (whoami on Perfex, erp.whoami in the contract), when
     * the ERP has that tool. Stored on the identity for the agent's settings;
     * the connection's tool list and dialect are left alone.
     */
    public function testIdentity(AgentErpIdentity $identity, ?Model $actor = null): AgentErpIdentity
    {
        $failed = fn (string $error): array => ['ok' => false, 'tools' => [], 'staff' => null, 'whoami' => false, 'error' => $error];

        if (! $this->factory->sendsTokens()) {
            $test = $failed('A ligação usa o ERP de demonstração local, que não tem identidades: o token do agente só conta com o servidor MCP do ERP (transporte web).');
        } else {
            try {
                $test = $this->audited('erp.identity_test', $this->identityFields($identity), $actor, function (Client $client): array {
                    $tools = $client->tools()->map(fn (Tool $tool) => $tool->name)->values()->all();
                    $whoami = collect(['whoami', 'erp.whoami'])->first(fn (string $name) => in_array($name, $tools, true));
                    $staff = $whoami !== null ? $this->staffOf($client, $whoami) : null;

                    return [
                        ['ok' => true, 'tools' => $tools, 'staff' => $staff, 'whoami' => $whoami !== null, 'error' => null],
                        AuditResult::Ok,
                        ['tools' => count($tools), 'staff_id' => $staff['id'] ?? null],
                    ];
                }, identity: $identity);
            } catch (ErpIdentityRefused $e) {
                $test = $failed($e->reason());
            } catch (ErpException $e) {
                $test = $failed($e->getMessage());
            }
        }

        $identity->forceFill(['last_test' => $test, 'last_tested_at' => now()])->save();

        return $identity;
    }

    /**
     * The staff member whoami says the token acts as, or null when it does not answer.
     *
     * @return array{id: int|null, name: string|null, email: string|null}|null
     */
    private function staffOf(Client $client, string $whoami): ?array
    {
        $answer = $client->callTool($whoami);
        $text = $answer->text();
        $data = $answer->structuredContent ?? (Str::isJson($text) ? json_decode($text, true) : null);

        if ($answer->isError || ! is_array($data)) {
            return null;
        }

        $name = $data['staff_name'] ?? $data['principal'] ?? null;
        $email = $data['staff_email'] ?? null;

        return [
            'id' => is_numeric($data['staff_id'] ?? null) ? (int) $data['staff_id'] : null,
            'name' => is_string($name) ? $name : null,
            'email' => is_string($email) ? $email : null,
        ];
    }

    /**
     * The agent's own ERP identity, when the actor is an agent that has one and
     * the ERP is reached over HTTP. People and the system use the tenant's.
     */
    private function identityOf(?Model $actor): ?AgentErpIdentity
    {
        if (! $actor instanceof Agent || ! $this->factory->sendsTokens()) {
            return null;
        }

        return AgentErpIdentity::of($actor)?->setRelation('agent', $actor);
    }

    /**
     * Which identity a call used, for the audit: never the token.
     *
     * @return array{identity: string, identity_agent_id?: int}
     */
    private function identityFields(?AgentErpIdentity $identity): array
    {
        return $identity === null ? ['identity' => 'tenant'] : ['identity' => 'agent', 'identity_agent_id' => $identity->agent_id];
    }

    /**
     * The ERP turning an agent's token away: the token itself (HTTP 401/403),
     * or a tool not ticked on it (the Perfex server says "Unknown tool").
     */
    private function refusal(Throwable $e, AgentErpIdentity $identity, ?string $tool): ?ErpIdentityRefused
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ErpIdentityRefused) {
                return $cause;
            }

            if ($cause instanceof AuthorizationRequiredException) {
                return ErpIdentityRefused::tokenRejected($identity->agent, $e);
            }

            if ($tool !== null && $cause instanceof JsonRpcException && $cause->getCode() === self::INVALID_PARAMS
                && preg_match('/^(unknown tool|tool is disabled|unauthori[sz]ed tool)/i', $cause->getMessage()) === 1) {
                return ErpIdentityRefused::toolNotAllowed($identity->agent, $tool, $e);
            }
        }

        return null;
    }

    private function soundsLikeRefusal(string $text): bool
    {
        return preg_match('/unknown tool|not allowed|not authori[sz]ed|unauthori[sz]ed|forbidden|permission|sem permiss|não autorizad|nao autorizad/iu', $text) === 1;
    }

    /**
     * Kept on the identity so the admins see it on the agent's settings.
     */
    private function noteRefusal(AgentErpIdentity $identity, string $tool, ErpIdentityRefused $refused, mixed $runId): void
    {
        $identity->forceFill([
            'last_refusal' => ['tool' => $tool, 'reason' => $refused->reason(), 'agent_run_id' => is_int($runId) ? $runId : null],
            'last_refused_at' => now(),
        ])->save();
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
    private function audited(string $action, array $payload, ?Model $actor, Closure $operation, ?ErpConnection $using = null, ?AgentErpIdentity $identity = null, ?string $tool = null): mixed
    {
        $tenant = $this->tenants->currentOrFail();
        $connection = $using ?? ErpConnection::query()->currentTenant()->first();
        $started = hrtime(true);
        $client = null;

        try {
            // A connection under test may not be the one the registered client
            // would pick (for example a disabled row being re-enabled). An
            // agent's own identity sends its token to the tenant's ERP.
            $client = match (true) {
                $using !== null => $this->factory->make($using)->setName(self::CLIENT),
                $identity !== null => $this->factory->forCurrentTenant($identity->token),
                default => $this->clients->build(self::CLIENT),
            };

            [$value, $result, $details] = $operation($client->connect());

            AuditLog::record($actor, $action, [...$payload, ...$details, 'duration_ms' => $this->elapsedMs($started), 'tenant' => $tenant->slug], $result, $connection);

            return $value;
        } catch (Throwable $e) {
            $refused = $identity !== null ? $this->refusal($e, $identity, $tool) : null;
            $message = match (true) {
                $refused !== null => $refused->reason(),
                $e instanceof ErpException => $e->getMessage(),
                default => $this->describe($e),
            };

            AuditLog::record($actor, $action, [...$payload, 'error' => $message, 'duration_ms' => $this->elapsedMs($started), 'tenant' => $tenant->slug], $refused !== null ? AuditResult::Denied : AuditResult::Error, $connection);

            // No retry with the tenant's token: that would act as someone the agent is not.
            if ($refused !== null) {
                if ($tool !== null) {
                    $this->noteRefusal($identity, $tool, $refused, $payload['agent_run_id'] ?? null);
                }

                throw $refused;
            }

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
    private function auditable(array|string $result, string $tool): array|string
    {
        $json = (string) json_encode($result, JSON_UNESCAPED_UNICODE);

        // Pay and personal data stay out of the audit (docs/DECISOES.md, "Director de Recursos Humanos"): what was read, not its content.
        if (PeopleData::isPeopleData('erp.'.$tool)) {
            return ['withheld' => 'dados de pessoal', 'bytes' => strlen($json), 'keys' => is_array($result) ? array_keys($result) : []];
        }

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
