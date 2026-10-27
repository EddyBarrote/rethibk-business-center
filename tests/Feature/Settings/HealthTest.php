<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\Health\Ping;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Verificação da configuração".

beforeEach(function () {
    config(['ai.default' => 'gemini', 'ai.providers.gemini.key' => 'test-key']);
    $this->tenant = Tenant::factory()->create();
    [$this->owner, $this->member] = asTenant($this->tenant, fn () => [User::factory()->owner()->create(), User::factory()->create()]);
});

it('shows what the agents depend on, and tests it for real only when asked', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/health'))->assertForbidden();

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/health'))
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Health')
            ->has('checks', 8)
            ->where('checks.0.key', 'ai')->where('checks.0.status', 'unchecked')
            ->where('checks.2.key', 'mailboxes')->where('checks.2.status', 'unchecked'));

    Ping::fake(['OK']);
    Http::fake(['www.google.com/*' => Http::response('', 204)]);
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'settings/health'))->assertSessionHas('success');

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/health'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('checks.0.status', 'ready')
            ->where('checks.1.status', 'ready')
            ->where('checks.2.status', 'needs_setup')
            ->where('checks.2.href', '/settings/mailboxes')
            ->where('checks.3.status', 'needs_setup')
            ->whereNot('checks.0.checked_at', null));
});

it('says when the model has no credit left', function () {
    Ping::fake(fn () => throw new RuntimeException('The account has insufficient credits or quota.'));
    Http::fake();

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'settings/health'));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/health'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('checks.0.status', 'needs_setup')
            ->where('checks.0.fix', 'A conta do provedor ficou sem crédito ou quota: carregue a conta ou use outra chave.'));
});
