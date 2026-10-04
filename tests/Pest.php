<?php

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
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
 * In the current tenant: the local fake ERP, the full skill catalogue, and
 * the agent created from the given template (section 6.3).
 */
function templateAgent(string $template, array $overrides = []): App\Models\Agent
{
    if (App\Models\ErpConnection::query()->doesntExist()) {
        App\Models\ErpConnection::factory()->local()->create();
    }

    $catalog = app(App\Ai\Skills\SkillCatalog::class);
    $catalog->syncLocal();

    if (App\Models\Skill::query()->where('key', 'like', 'erp.%')->doesntExist()) {
        $catalog->syncErp();
    }

    $agent = app(App\Ai\Templates\TemplateInstaller::class)->install(App\Ai\Templates\AgentTemplates::find($template))['agent'];
    $agent->update(['max_steps' => 12, ...$overrides]);

    return $agent->fresh();
}

/**
 * Runs one skill as the agent, inside a fresh run.
 */
function runSkill(App\Models\Agent $agent, string $key, array $arguments = []): App\Ai\Skills\SkillResult
{
    $run = app(App\Ai\Runs\AgentRunner::class)->create($agent, 'teste', App\Enums\TriggerType::Manual);

    return app(App\Ai\Skills\SkillRegistry::class)->find($key)->execute($arguments, new App\Ai\Skills\SkillContext($agent, $run));
}
