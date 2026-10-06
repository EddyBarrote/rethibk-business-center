<?php

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Runs\AgentRunner;
use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\Capability;
use App\Models\ErpConnection;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // In CI (MySQL) ids keep growing across tests, because rolled-back
        // transactions do not reset auto-increment. Start SQLite ids far
        // from 1 too, and different per table, so no test relies on an id.
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::select("select name from sqlite_master where type = 'table' and sql like '%autoincrement%'") as $table) {
                DB::table('sqlite_sequence')->updateOrInsert(['name' => $table->name], ['seq' => 1000 + crc32($table->name) % 9000]);
            }
        }
    })
    ->in('Feature');

/**
 * Absolute URL on the tenant's subdomain, so IdentifyTenant resolves it.
 */
function tenantUrl(Tenant $tenant, string $path = '/'): string
{
    return 'http://'.$tenant->slug.'.'.config('tenancy.central_domain').'/'.ltrim($path, '/');
}

/**
 * Run a callback as the given tenant.
 *
 * @template TReturn
 *
 * @param  Closure(Tenant): TReturn  $callback
 * @return TReturn
 */
function asTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantManager::class)->run($tenant, $callback);
}

/**
 * Point the fake ERP at a fresh state file, for this process and for the
 * stdio server processes the rethink_erp client starts (they inherit the env).
 */
function freshFakeErp(): string
{
    $path = sys_get_temp_dir().'/fake-erp-'.bin2hex(random_bytes(6)).'.json';

    putenv("ERP_FAKE_STORAGE_PATH={$path}");
    config(['erp.fake.storage_path' => $path, 'erp.transport' => 'local']);

    return $path;
}

/**
 * Absolute URL on the super admin host.
 */
function adminUrl(string $path = '/'): string
{
    return 'http://'.config('tenancy.admin_domain').'/'.ltrim($path, '/');
}

/**
 * A raw email from tests/Fixtures/mail (also copied to docs/exemplos).
 */
function mailFixture(string $name): string
{
    return (string) file_get_contents(__DIR__."/Fixtures/mail/{$name}.eml");
}

/**
 * In the current tenant: the local fake ERP, the full capability catalogue, and
 * the agent created from the given template (section 6.3).
 */
function templateAgent(string $template, array $overrides = []): Agent
{
    if (ErpConnection::query()->doesntExist()) {
        ErpConnection::factory()->local()->create();
    }

    $catalog = app(CapabilityCatalog::class);
    $catalog->syncLocal();

    if (Capability::query()->where('key', 'like', 'erp.%')->doesntExist()) {
        $catalog->syncErp();
    }

    $agent = app(TemplateInstaller::class)->install(AgentTemplates::find($template))['agent'];
    $agent->update(['max_steps' => 12, ...$overrides]);

    return $agent->fresh();
}

/**
 * Runs one capability as the agent, inside a fresh run.
 */
function runCapability(Agent $agent, string $key, array $arguments = []): CapabilityResult
{
    $run = app(AgentRunner::class)->create($agent, 'teste', TriggerType::Manual);

    return app(CapabilityRegistry::class)->find($key)->execute($arguments, new CapabilityContext($agent, $run));
}

/**
 * A faked model tool call whose arguments are built when the model "calls"
 * it, so they can point at rows created during the run. Ids are never
 * hard-coded: MySQL keeps auto-increment values across rolled-back tests.
 */
function toolCall(string $id, string $tool, Closure|array $arguments): Closure
{
    return fn () => new ToolCall($id, $tool, $arguments instanceof Closure ? $arguments() : $arguments);
}

/**
 * @param  class-string<Model>  $model
 */
function lastId(string $model): int
{
    return (int) $model::query()->max('id');
}
