<?php

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityExecutor;
use App\Ai\Runs\AgentRunner;
use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Enums\StepType;
use App\Enums\TriggerType;
use App\Erp\AgentErpIdentities;
use App\Erp\ErpDialect;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpIdentityRefused;
use App\Models\Agent;
use App\Models\AgentErpIdentity;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\ErpConnection;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// Each agent's own identity in the ERP (docs/DECISOES.md, "Identidade própria de
// cada agente no ERP"): a static token of the Perfex MCP server that acts as the
// agent's staff member, with only its tools ticked.

const TENANT_TOKEN = 'tenant-token-edy-barrote';
const AGENT_TOKEN = 'agent-token-amelia';

/**
 * A Perfex MCP server on erp.example.test that knows these static tokens, each
 * acting as a staff member with the tools ticked for it (plus whoami, which
 * every token gets, unless $whoami is false). An unknown token gets HTTP 401;
 * a tool not ticked gets JSON-RPC -32602 "Unknown tool", as the real server
 * answers. Every request lands in $requests as [token, method, tool]. Fakes
 * stack, so a second server needs its own host.
 *
 * @param  array<string, array{staff: array{id: int, name: string, email: string}, tools: array<string, bool>}>  $tokens
 * @param  list<array{0: string, 1: string|null, 2: string|null}>  $requests
 */
function identityErp(array $tokens, array &$requests = [], bool $whoami = true, string $host = 'erp.example.test'): void
{
    Http::fake(["{$host}/*" => function (HttpRequest $request) use ($tokens, &$requests, $whoami) {
        $token = Str::after($request->header('Authorization')[0] ?? '', 'Bearer ');
        $message = json_decode($request->body(), true);
        $requests[] = [$token, $message['method'] ?? null, $message['params']['name'] ?? null];
        $account = $tokens[$token] ?? null;

        if ($account === null) {
            return Http::response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Missing, invalid, expired or revoked token']], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $tools = $account['tools'] + ($whoami ? ['whoami' => true] : []);
        $reply = fn (array $result) => Http::response(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result], 200, ['Content-Type' => 'application/json']);
        $data = fn (array $content) => $reply(['content' => [['type' => 'text', 'text' => json_encode($content)]], 'structuredContent' => $content, 'isError' => false]);

        return match ($message['method'] ?? null) {
            'initialize' => $reply(['protocolVersion' => $message['params']['protocolVersion'], 'capabilities' => ['tools' => (object) []], 'serverInfo' => ['name' => 'perfex-mcp-server', 'version' => '1.4.0']]),
            'tools/list' => $reply(['tools' => array_map(fn (string $name, bool $read) => [
                'name' => $name, 'description' => $name, 'inputSchema' => ['type' => 'object', 'properties' => (object) []], 'annotations' => ['readOnlyHint' => $read],
            ], array_keys($tools), $tools)]),
            'tools/call' => match (true) {
                ! isset($tools[$message['params']['name']]) => Http::response(['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32602, 'message' => 'Unknown tool: '.$message['params']['name']]], 200, ['Content-Type' => 'application/json']),
                $message['params']['name'] === 'whoami' => $data(['connection' => 'agente', 'staff_id' => $account['staff']['id'], 'staff_name' => $account['staff']['name'], 'staff_email' => $account['staff']['email'], 'tools' => array_keys($tools)]),
                default => $data(['total' => 0, 'items' => [], 'as_staff' => $account['staff']['id']]),
            },
            default => isset($message['id'])
                ? Http::response(['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32601, 'message' => 'Method not found']], 200, ['Content-Type' => 'application/json'])
                : Http::response('', 202),
        };
    }]);
}

/** The tools/call requests the fake ERP got, as [token, tool]. */
function toolCallsOf(array $requests): array
{
    return array_values(array_map(fn (array $r) => [$r[0], $r[2]], array_filter($requests, fn (array $r) => $r[1] === 'tools/call')));
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->requests = [];

    identityErp([
        TENANT_TOKEN => ['staff' => ['id' => 1, 'name' => 'Edy Barrote', 'email' => 'edy@example.test'], 'tools' => ['list_customers' => true, 'list_invoices' => true, 'create_lead' => false]],
        AGENT_TOKEN => ['staff' => ['id' => 7, 'name' => 'Amélia (agente)', 'email' => 'amelia@example.test'], 'tools' => ['list_customers' => true, 'create_lead' => false]],
    ], $this->requests);

    [$this->owner, $this->member, $this->agent, $this->other] = asTenant($this->tenant, function () {
        ErpConnection::factory()->create(['credentials' => ['token' => TENANT_TOKEN]]);
        app(CapabilityCatalog::class)->syncLocal();
        app(CapabilityCatalog::class)->syncErp();

        $agent = Agent::factory()->create(['name' => 'Amélia']);
        $agent->capabilities()->sync(array_fill_keys(Capability::query()->whereIn('key', ['erp.list_customers', 'erp.list_invoices', 'erp.create_lead'])->pluck('id')->all(), ['enabled' => true]));

        return [User::factory()->owner()->create(), User::factory()->create(['role' => 'member']), $agent, Agent::factory()->create(['name' => 'Sem token'])];
    });

    $this->requests = [];
});

function giveToken(Agent $agent, string $token = AGENT_TOKEN): AgentErpIdentity
{
    return AgentErpIdentity::factory()->create(['agent_id' => $agent->id, 'token' => $token]);
}

it('sends the agent\'s own token on the agent\'s ERP calls and audits which identity was used', function () {
    asTenant($this->tenant, function () {
        giveToken($this->agent);

        $result = throughGate($this->agent, 'erp.list_customers', []);
        $log = AuditLog::query()->where('action', 'erp.tool_call')->sole();

        expect($result)->toContain('"as_staff":7')
            ->and(toolCallsOf($this->requests))->toBe([[AGENT_TOKEN, 'list_customers']])
            ->and($log->actor_type)->toBe(ActorType::Agent)
            ->and($log->actor_id)->toBe($this->agent->id)
            ->and($log->payload)->toMatchArray(['tool' => 'list_customers', 'identity' => 'agent', 'identity_agent_id' => $this->agent->id])
            ->and($log->payload)->toHaveKey('agent_run_id');
    });
});

it('writes with the agent\'s token too, when a capability runs for it (an approved action included)', function () {
    asTenant($this->tenant, function () {
        giveToken($this->agent);
        $run = app(AgentRunner::class)->create($this->agent, 'teste', TriggerType::Manual);

        $result = app(CapabilityExecutor::class)->execute(Capability::query()->where('key', 'erp.create_lead')->sole(), ['name' => 'Loop'], new CapabilityContext($this->agent, $run));

        expect($result->ok)->toBeTrue()
            ->and(toolCallsOf($this->requests))->toBe([[AGENT_TOKEN, 'create_lead']]);
    });
});

it('keeps the connection\'s token for people, the system and agents without a token of their own', function () {
    asTenant($this->tenant, function () {
        giveToken($this->agent);
        $gateway = app(ErpGateway::class);

        $gateway->call('list_customers', [], $this->owner);
        $gateway->call('list_customers');
        $gateway->call('list_customers', [], $this->other);

        expect(toolCallsOf($this->requests))->toBe(array_fill(0, 3, [TENANT_TOKEN, 'list_customers']))
            ->and(AuditLog::query()->where('action', 'erp.tool_call')->get()->pluck('payload.identity')->unique()->all())->toBe(['tenant'])
            ->and(AuditLog::query()->where('action', 'erp.tool_call')->get()->every(fn (AuditLog $log) => ! array_key_exists('identity_agent_id', $log->payload)))->toBeTrue();
    });
});

it('lists the tools and reads the dialect with the connection\'s token, even with agent tokens around', function () {
    asTenant($this->tenant, function () {
        giveToken($this->agent);

        app(CapabilityCatalog::class)->syncErp();

        expect(collect($this->requests)->where(1, 'tools/list')->pluck(0)->unique()->all())->toBe([TENANT_TOKEN])
            ->and(collect(ErpConnection::query()->sole()->capabilities)->pluck('name')->all())->toContain('list_invoices')
            ->and(app(ErpGateway::class)->dialect())->toBe(ErpDialect::Perfex)
            ->and(Capability::query()->where('key', 'erp.list_invoices')->value('is_available'))->toBeTrue();
    });
});

it('tells the agent its ERP identity lacks a tool, and never falls back to the connection\'s token', function () {
    asTenant($this->tenant, function () {
        giveToken($this->agent);

        $result = throughGate($this->agent, 'erp.list_invoices', []);
        $run = AgentRun::query()->latest('id')->first();
        $log = AuditLog::query()->where('action', 'erp.tool_call')->sole();
        $identity = AgentErpIdentity::query()->sole();

        expect($result)->toContain('A identidade própria de Amélia no ERP não tem permissão para usar «list_invoices»')
            ->and($result)->toContain('Setup › MCP Server')
            ->and(toolCallsOf($this->requests))->toBe([[AGENT_TOKEN, 'list_invoices']])
            ->and($log->result)->toBe(AuditResult::Denied)
            ->and($log->payload)->toMatchArray(['identity' => 'agent', 'error' => 'A identidade própria de Amélia no ERP não tem permissão para usar «list_invoices».'])
            ->and($run->steps()->where('type', StepType::Error)->sole()->payload['content'])->toContain('não tem permissão')
            // The admins see it on the agent's settings.
            ->and($identity->last_refusal)->toMatchArray(['tool' => 'list_invoices', 'agent_run_id' => $run->id])
            ->and(app(AgentErpIdentities::class)->present($this->agent)['last_refusal']['reason'])->toContain('não tem permissão');
    });
});

it('says plainly when the ERP turns the agent\'s token away, without trying the connection\'s', function () {
    asTenant($this->tenant, function () {
        giveToken($this->agent, 'revoked-token');

        expect(fn () => app(ErpGateway::class)->call('list_customers', [], $this->agent))
            ->toThrow(ErpIdentityRefused::class, 'O ERP recusou a identidade própria de Amélia: o token deste agente é inválido, expirou ou foi revogado.');

        expect(collect($this->requests)->pluck(0)->unique()->all())->toBe(['revoked-token'])
            ->and(AuditLog::query()->where('action', 'erp.tool_call')->sole()->result)->toBe(AuditResult::Denied);
    });
});

it('lets the admins set, replace and remove an agent\'s token, audited without the token; members cannot', function () {
    $url = tenantUrl($this->tenant, "agents/{$this->agent->id}/erp-identity");

    $this->actingAs($this->member)->put($url, ['token' => AGENT_TOKEN])->assertForbidden();
    $this->actingAs($this->member)->delete($url)->assertForbidden();
    $this->actingAs($this->member)->post("{$url}/test")->assertForbidden();

    $this->actingAs($this->owner)->put($url, ['token' => ''])->assertSessionHasErrors('token');
    $this->actingAs($this->owner)->put($url, ['token' => 'first-token-123'])->assertSessionHas('success');
    asTenant($this->tenant, fn () => AgentErpIdentity::query()->sole()->forceFill(['last_refusal' => ['tool' => 'x', 'reason' => 'y', 'agent_run_id' => null], 'last_refused_at' => now()])->save());
    $this->actingAs($this->owner)->put($url, ['token' => AGENT_TOKEN])->assertSessionHas('success');

    // What was learnt about the old token goes with it.
    expect(asTenant($this->tenant, fn () => AgentErpIdentity::of($this->agent)?->token))->toBe(AGENT_TOKEN)
        ->and(asTenant($this->tenant, fn () => app(AgentErpIdentities::class)->present($this->agent)['last_refusal']))->toBeNull();

    $this->actingAs($this->owner)->delete($url)->assertSessionHas('success');

    asTenant($this->tenant, function () {
        $logs = AuditLog::query()->where('action', 'erp.identity_updated')->orderBy('id')->get();

        expect(AgentErpIdentity::query()->count())->toBe(0)
            ->and($logs->pluck('payload.change')->all())->toBe(['set', 'replaced', 'removed'])
            ->and($logs->every(fn (AuditLog $log) => $log->actor_id === $this->owner->id && $log->subject_id === $this->agent->id))->toBeTrue()
            ->and($logs->toJson())->not->toContain('first-token-123')->not->toContain(AGENT_TOKEN);
    });
});

it('never shows the token: not in the page, the model, the audit or the database in clear', function () {
    asTenant($this->tenant, function () {
        giveToken($this->agent);
        throughGate($this->agent, 'erp.list_customers', []);
        app(ErpGateway::class)->testIdentity(AgentErpIdentity::query()->sole()->setRelation('agent', $this->agent));
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}/edit"))
        ->assertOk()
        ->assertDontSee(AGENT_TOKEN)
        ->assertInertia(fn (Assert $page) => $page->component('Agents/Form')
            ->where('erp_identity.has_token', true)
            ->where('erp_identity.applies', true)
            ->missing('erp_identity.token'));

    asTenant($this->tenant, function () {
        expect(AgentErpIdentity::query()->sole()->toJson())->not->toContain(AGENT_TOKEN)
            ->and(AuditLog::query()->get()->toJson())->not->toContain(AGENT_TOKEN)->not->toContain(TENANT_TOKEN)
            ->and(DB::table('agent_erp_identities')->value('token'))->not->toBe(AGENT_TOKEN)->not->toContain('amelia');
    });
});

it('tests the identity: who it acts as, how many tools it sees, and which of the agent\'s ERP tools it lacks', function () {
    asTenant($this->tenant, fn () => giveToken($this->agent));

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/erp-identity/test"))->assertSessionHas('success');

    asTenant($this->tenant, function () {
        $shown = app(AgentErpIdentities::class)->present($this->agent);
        $log = AuditLog::query()->where('action', 'erp.identity_test')->sole();

        expect($shown['test'])->toMatchArray([
            'ok' => true,
            'count' => 3,
            'whoami' => true,
            'staff' => ['id' => 7, 'name' => 'Amélia (agente)', 'email' => 'amelia@example.test'],
            'missing' => ['list_invoices'],
        ])
            ->and($shown['erp_tools'])->toBe(['create_lead', 'list_customers', 'list_invoices'])
            ->and(collect($this->requests)->pluck(0)->unique()->all())->toBe([AGENT_TOKEN])
            ->and($log->actor_id)->toBe($this->owner->id)
            ->and($log->payload)->toMatchArray(['identity' => 'agent', 'identity_agent_id' => $this->agent->id, 'tools' => 3, 'staff_id' => 7])
            // The connection's tool list is the tenant token's, untouched.
            ->and(collect(ErpConnection::query()->sole()->capabilities)->pluck('name')->all())->toContain('list_invoices');
    });
});

it('says when the ERP cannot tell who a token acts as, and when the token is refused', function () {
    $this->requests = [];
    identityErp([AGENT_TOKEN => ['staff' => ['id' => 7, 'name' => 'Amélia', 'email' => 'a@example.test'], 'tools' => ['list_customers' => true]]], $this->requests, whoami: false, host: 'second.example.test');

    asTenant($this->tenant, function () {
        ErpConnection::query()->sole()->forceFill(['base_url' => 'https://second.example.test/mcp'])->save();
        $identity = giveToken($this->agent)->setRelation('agent', $this->agent);
        app(ErpGateway::class)->testIdentity($identity);

        expect($identity->fresh()->last_test)->toMatchArray(['ok' => true, 'whoami' => false, 'staff' => null, 'tools' => ['list_customers']]);

        $identity->forceFill(['token' => 'revoked-token'])->save();
        app(ErpGateway::class)->testIdentity($identity);

        expect($identity->fresh()->last_test)->toMatchArray(['ok' => false])
            ->and($identity->fresh()->last_test['error'])->toContain('inválido, expirou ou foi revogado')
            ->and(AuditLog::query()->where('action', 'erp.identity_test')->latest('id')->first()->result)->toBe(AuditResult::Denied);
    });
});

it('does not apply an agent\'s token to the local demo ERP, and says so', function () {
    asTenant($this->tenant, function () {
        ErpConnection::query()->sole()->forceFill(['transport' => 'local', 'base_url' => null, 'credentials' => null])->save();
        $identity = giveToken($this->agent)->setRelation('agent', $this->agent);

        app(ErpGateway::class)->testIdentity($identity);

        expect(app(AgentErpIdentities::class)->present($this->agent))->toMatchArray(['has_token' => true, 'applies' => false])
            ->and($identity->fresh()->last_test['error'])->toContain('demonstração local')
            ->and($this->requests)->toBe([]);
    });
});

it('keeps each tenant\'s agent identities to itself', function () {
    $other = Tenant::factory()->create();
    $foreign = asTenant($other, function () {
        ErpConnection::factory()->create(['credentials' => ['token' => TENANT_TOKEN]]);
        $agent = Agent::factory()->create();
        giveToken($agent, 'other-tenant-token');

        return $agent;
    });

    // Another tenant's agent is not found here, so its token never travels.
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "agents/{$foreign->id}/erp-identity"), ['token' => 'hijack-1234'])->assertNotFound();

    asTenant($this->tenant, function () use ($foreign) {
        expect(AgentErpIdentity::of($foreign))->toBeNull();

        app(ErpGateway::class)->call('list_customers', [], $foreign);

        expect(toolCallsOf($this->requests))->toBe([[TENANT_TOKEN, 'list_customers']]);
    });

    expect(asTenant($other, fn () => AgentErpIdentity::of($foreign)?->token))->toBe('other-tenant-token');
});

it('lets the super admin set, test and remove an agent\'s token in the console', function () {
    $admin = PlatformAdmin::factory()->create();
    $url = adminUrl("tenants/{$this->tenant->id}/agents/{$this->agent->id}");

    $this->actingAs($admin, 'admin')->put("{$url}/erp-identity", ['token' => AGENT_TOKEN])->assertSessionHas('success');
    $this->actingAs($admin, 'admin')->post("{$url}/erp-identity/test")->assertSessionHas('success');
    $this->actingAs($admin, 'admin')->get("{$url}/edit")
        ->assertOk()
        ->assertDontSee(AGENT_TOKEN)
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Agents/Form')
            ->where('erp_identity.has_token', true)
            ->where('erp_identity.test.staff.name', 'Amélia (agente)'));
    $this->actingAs($admin, 'admin')->delete("{$url}/erp-identity")->assertSessionHas('success');

    asTenant($this->tenant, function () {
        expect(AgentErpIdentity::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'erp.identity_updated')->pluck('actor_type')->unique()->all())->toBe([ActorType::PlatformAdmin]);
    });
});
