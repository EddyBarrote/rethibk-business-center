<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Agents\InstructionComposer;
use App\Ai\Agents\ToolResolver;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Runs\AgentRunner;
use App\Connectors\ConnectorException;
use App\Enums\AutonomyLevel;
use App\Enums\CapabilitySource;
use App\Enums\RunStatus;
use App\Enums\Scope;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Connector;
use App\Models\PlatformConnector;
use App\Models\PlatformSkill;
use App\Models\PlatformSkillFile;
use App\Models\Skill;
use App\Models\SkillFile;
use App\Models\Tenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ToolCall;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

function toolNames(Agent $agent): array
{
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    return collect(app(ToolResolver::class)->for(new CapabilityContext($agent, $run)))->map->name()->sort()->values()->all();
}

it('lists an agent\'s skills in its prompt and lets it load one, own or global', function () {
    asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->create();

        expect(toolNames($agent))->not->toContain('skills_load')
            ->and(app(InstructionComposer::class)->for($agent))->not->toContain('## Skills');

        $own = Skill::factory()->create(['key' => 'propostas']);
        SkillFile::factory()->create(['skill_id' => $own->id, 'filename' => 'modelo.md', 'content' => 'Cliente: ACME']);
        $platform = PlatformSkill::factory()->create(['key' => 'tom-de-voz', 'name' => 'Tom de voz', 'instructions' => 'Trata o cliente por você.']);
        PlatformSkillFile::factory()->create(['platform_skill_id' => $platform->id, 'filename' => 'exemplos.md', 'content' => 'Exemplo global']);
        $global = Skill::factory()->global($platform)->create();
        $off = Skill::factory()->create(['key' => 'desligada', 'is_enabled' => false]);
        $agent->skills()->attach([$own->id, $global->id, $off->id]);

        $prompt = app(InstructionComposer::class)->for($agent);
        expect($prompt)->toContain('## Skills')
            ->toContain('Propostas comerciais (propostas): Usar quando')
            ->toContain('Tom de voz (tom-de-voz)')
            ->not->toContain('desligada')
            ->and(toolNames($agent))->toContain('skills_load', 'skills_read_file');

        expect(runCapability($agent, 'skills.load', ['skill' => 'tom-de-voz'])->content)->toContain('Trata o cliente por você.')->toContain('exemplos.md')
            ->and(runCapability($agent, 'skills.read_file', ['skill' => 'propostas', 'filename' => 'modelo.md'])->content)->toContain('Cliente: ACME')
            ->and(runCapability($agent, 'skills.read_file', ['skill' => 'tom-de-voz', 'filename' => 'exemplos.md'])->content)->toContain('Exemplo global')
            ->and(runCapability($agent, 'skills.load', ['skill' => 'desligada'])->ok)->toBeFalse();

        // The super admin withdrawing a global skill takes it away everywhere.
        $platform->update(['is_active' => false]);
        expect(app(InstructionComposer::class)->for($agent->fresh()))->not->toContain('tom-de-voz');
    });
});

it('turns a tenant HTTP connector into a capability whose answers are untrusted data', function () {
    Http::fake(['api.example.test/*' => Http::response(['currency' => 'USD', 'rate' => 63.9])]);

    asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $connector = Connector::factory()->create(['key' => 'cambio', 'secret' => 'segredo-123']);

        expect(app(CapabilityCatalog::class)->syncConnector($connector))->toBe(1);

        $capability = Capability::query()->where('key', 'conn.cambio')->sole();
        expect($capability->source)->toBe(CapabilitySource::Connector)
            ->and($capability->scope)->toBe(Scope::Tenant)
            ->and($capability->risk)->toBe(AutonomyLevel::Observe);

        $agent = Agent::factory()->create();
        $agent->capabilities()->attach($capability->id, ['enabled' => true]);
        expect(toolNames($agent))->toContain('conn_cambio');

        GenericAgent::fake([new ToolCall('c1', 'conn_cambio', ['currency' => 'USD']), 'O dólar está a 63,9.']);
        $run = app(AgentRunner::class)->create($agent, 'Quanto está o dólar?', TriggerType::Manual);
        app(AgentRunner::class)->run($run);

        expect($run->fresh()->status)->toBe(RunStatus::Completed)
            ->and($run->steps()->where('type', 'tool_result')->sole()->payload['content'])->toContain('<resposta_externa_nao_confiavel')->toContain('63.9');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.test/rates?currency=USD' && $request->hasHeader('Authorization', 'Bearer segredo-123'));
        expect(AuditLog::query()->where('action', 'connector.tool_call')->sole()->payload['connector'])->toBe('cambio');
    });
});

it('sends a connector write for approval until an admin lowers its risk', function () {
    Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);

    asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $connector = Connector::factory()->create(['key' => 'crm-lead', 'http_method' => 'POST', 'is_mutating' => true, 'url' => 'https://api.example.test/leads']);
        app(CapabilityCatalog::class)->syncConnector($connector);
        $capability = Capability::query()->where('key', 'conn.crm-lead')->sole();
        $agent = Agent::factory()->create(['autonomy_level' => AutonomyLevel::ExecuteWithinLimits]);
        $agent->capabilities()->attach($capability->id, ['enabled' => true]);

        GenericAgent::fake([new ToolCall('c1', 'conn_crm-lead', ['currency' => 'X']), 'Pedi aprovação.']);
        app(AgentRunner::class)->run(app(AgentRunner::class)->create($agent, 'Regista o lead', TriggerType::Manual));

        expect($capability->risk)->toBe(AutonomyLevel::ExecuteAndReport)
            ->and(Approval::query()->where('action_type', 'conn.crm-lead')->exists())->toBeTrue();
        Http::assertNothingSent();
    });
});

it('refuses tenant connectors that point at internal addresses', function () {
    asTenant($this->tenant, function () {
        $connector = Connector::factory()->mcp()->create(['url' => 'https://127.0.0.1/mcp']);

        expect(fn () => app(CapabilityCatalog::class)->syncConnector($connector))->toThrow(ConnectorException::class, 'interno');
        expect($connector->fresh()->last_error)->toContain('interno');
    });
});

it('lets a tenant activate and switch off a global connector', function () {
    $global = PlatformConnector::factory()->create(['key' => 'bm']);

    asTenant($this->tenant, function () use ($global) {
        app(CapabilityCatalog::class)->syncConnector($global);
        $capability = Capability::query()->where('key', 'global.bm')->sole();
        expect($capability->scope)->toBe(Scope::Global)->and($capability->isUsable())->toBeTrue();

        $agent = Agent::factory()->create();
        $agent->capabilities()->attach($capability->id, ['enabled' => true]);
        expect(toolNames($agent))->toContain('global_bm');

        app(CapabilityCatalog::class)->deactivate($global);
        expect(toolNames($agent->fresh()))->not->toContain('global_bm');
    });
});

it('accepts capabilities registered by other modules', function () {
    $class = new class extends LocalCapability
    {
        public function key(): string
        {
            return 'documents.test';
        }

        public function name(): string
        {
            return 'Teste';
        }

        public function description(): string
        {
            return 'Teste.';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function execute(array $arguments, CapabilityContext $context): CapabilityResult
        {
            return CapabilityResult::text('ok');
        }
    };
    app()->instance($class::class, $class);
    CapabilityRegistry::register($class::class);

    asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        expect(Capability::query()->where('key', 'documents.test')->exists())->toBeTrue();
    });
});
