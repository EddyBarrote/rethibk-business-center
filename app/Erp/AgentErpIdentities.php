<?php

namespace App\Erp;

use App\Enums\CapabilitySource;
use App\Models\Agent;
use App\Models\AgentErpIdentity;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\ErpConnection;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Setting, replacing, removing and testing an agent's own identity in the ERP
 * (docs/DECISOES.md, "Identidade própria de cada agente no ERP"), for the
 * tenant's admins and the super admin. The token is written here and read only
 * by ErpGateway; nothing here ever returns it.
 */
final class AgentErpIdentities
{
    public function __construct(private readonly ErpGateway $erp, private readonly ErpClientFactory $factory) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return ['token' => ['required', 'string', 'min:8', 'max:4096']];
    }

    public function set(Agent $agent, string $token, User|PlatformAdmin $actor): AgentErpIdentity
    {
        // A new row: the last test and refusal of the old token say nothing about the new one, and created_at says when it was set.
        [$identity, $replaced] = DB::transaction(function () use ($agent, $token): array {
            $replaced = AgentErpIdentity::query()->where('agent_id', $agent->id)->delete() > 0;

            return [AgentErpIdentity::query()->create(['agent_id' => $agent->id, 'token' => trim($token)]), $replaced];
        });

        AuditLog::record($actor, 'erp.identity_updated', ['agent_id' => $agent->id, 'agent' => $agent->key, 'change' => $replaced ? 'replaced' : 'set'], subject: $agent);

        return $identity;
    }

    /**
     * Back to the tenant connection's token.
     */
    public function remove(Agent $agent, User|PlatformAdmin $actor): bool
    {
        $identity = AgentErpIdentity::query()->where('agent_id', $agent->id)->first();

        if ($identity === null) {
            return false;
        }

        $identity->delete();
        AuditLog::record($actor, 'erp.identity_updated', ['agent_id' => $agent->id, 'agent' => $agent->key, 'change' => 'removed'], subject: $agent);

        return true;
    }

    public function test(Agent $agent, User|PlatformAdmin $actor): ?AgentErpIdentity
    {
        $identity = AgentErpIdentity::of($agent);

        return $identity === null ? null : $this->erp->testIdentity($identity->setRelation('agent', $agent), $actor);
    }

    /**
     * What the agent's settings show: whether it has a token (never the token),
     * the last test against the agent's ERP capabilities, and the last refusal.
     *
     * @return array<string, mixed>
     */
    public function present(Agent $agent): array
    {
        $identity = AgentErpIdentity::of($agent);
        $connection = ErpConnection::query()->currentTenant()->first();
        $needed = $this->erpTools($agent);
        $test = $identity?->last_test;
        $testedAt = $identity?->last_tested_at;

        return [
            'has_token' => $identity !== null,
            'set_at' => $identity?->created_at->toIso8601String(),
            'applies' => $this->factory->sendsTokens(),
            'connection' => $connection === null ? null : ['name' => $connection->name, 'transport' => $connection->transport->value],
            'erp_tools' => $needed,
            'test' => $test === null || $testedAt === null ? null : [
                'at' => $testedAt->toIso8601String(),
                'ok' => $test['ok'],
                'error' => $test['error'],
                'count' => count($test['tools']),
                'tools' => $test['tools'],
                'whoami' => $test['whoami'],
                'staff' => $test['staff'],
                'missing' => $test['ok'] ? array_values(array_diff($needed, $test['tools'])) : [],
            ],
            'last_refusal' => $identity?->last_refusal === null || $identity->last_refused_at === null ? null : [
                ...$identity->last_refusal,
                'at' => $identity->last_refused_at->toIso8601String(),
            ],
        ];
    }

    /**
     * The ERP tools behind the agent's switched-on ERP capabilities.
     *
     * @return list<string>
     */
    private function erpTools(Agent $agent): array
    {
        return $agent->capabilities()
            ->wherePivot('enabled', true)
            ->where('source', CapabilitySource::Mcp)
            ->where('is_available', true)
            ->get()
            ->map(fn (Capability $capability) => (string) $capability->mcp_tool_name)
            ->filter(fn (string $tool) => $tool !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
