<?php

use App\Models\AiProviderKey;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Chaves dos provedores de IA na plataforma".

beforeEach(function () {
    config(['ai.providers.anthropic.key' => null]);
    $this->tenant = Tenant::factory()->create();
    $this->other = Tenant::factory()->create();
    [$this->owner, $this->member] = asTenant($this->tenant, fn () => [User::factory()->owner()->create(), User::factory()->create()]);
});

it('keeps the company key encrypted, shows only its end and uses it for that company only', function () {
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, 'settings/ai-providers/anthropic'), ['key' => 'sk-ant-teste-abcdef9876'])
        ->assertRedirect()->assertSessionHas('success');

    expect(DB::table('ai_provider_keys')->value('key'))->not->toContain('sk-ant-teste')
        ->and(asTenant($this->tenant, fn () => AiProviderKey::query()->sole()->key))->toBe('sk-ant-teste-abcdef9876');

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/ai-providers'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('providers.1.key', 'anthropic')
            ->where('providers.1.own.last_four', '9876')
            ->missing('providers.1.own.key'));

    $tenants = app(TenantManager::class);
    $tenants->run($this->tenant, fn () => expect(config('ai.providers.anthropic.key'))->toBe('sk-ant-teste-abcdef9876'));
    $tenants->run($this->other, fn () => expect(config('ai.providers.anthropic.key'))->toBeEmpty());

    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, 'settings/ai-providers/anthropic'))->assertRedirect();
    expect(asTenant($this->tenant, fn () => AiProviderKey::query()->count()))->toBe(0);
});

it('lets only owners and admins set keys', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/ai-providers'))->assertForbidden();
    $this->actingAs($this->member)->put(tenantUrl($this->tenant, 'settings/ai-providers/openai'), ['key' => 'sk-proj-qualquer-coisa'])->assertForbidden();
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, 'settings/ai-providers/inventado'), ['key' => 'sk-proj-qualquer-coisa'])->assertNotFound();
});
