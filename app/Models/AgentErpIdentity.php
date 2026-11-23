<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\AgentErpIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An agent's own identity in the ERP (docs/DECISOES.md, "Identidade própria de
 * cada agente no ERP"): a static token of the ERP's MCP server that acts as the
 * agent's staff member there, with only the agent's tools ticked. ErpGateway
 * sends it on the calls made on the agent's behalf. The token is encrypted,
 * hidden, and never leaves the server.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_id
 * @property string $token
 * @property array{ok: bool, tools: list<string>, staff: array{id: int|null, name: string|null, email: string|null}|null, whoami: bool, error: string|null}|null $last_test
 * @property Carbon|null $last_tested_at
 * @property array{tool: string, reason: string, agent_run_id: int|null}|null $last_refusal
 * @property Carbon|null $last_refused_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['agent_id', 'token'])]
#[Hidden(['token'])]
class AgentErpIdentity extends Model
{
    /** @use HasFactory<AgentErpIdentityFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The agent's identity, when it has a token. Tenant-scoped: an agent of
     * another tenant has none here.
     */
    public static function of(Agent $agent): ?self
    {
        $identity = self::query()->where('agent_id', $agent->id)->first();

        return $identity !== null && $identity->token !== '' ? $identity : null;
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_test' => 'array',
            'last_tested_at' => 'datetime',
            'last_refusal' => 'array',
            'last_refused_at' => 'datetime',
        ];
    }
}
