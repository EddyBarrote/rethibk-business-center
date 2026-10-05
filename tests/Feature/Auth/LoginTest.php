<?php

use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = asTenant($this->tenant, fn () => User::factory()->create(['email' => 'ana@micomoc.test']));
});

it('shows the login page', function () {
    $this->get(tenantUrl($this->tenant, 'login'))->assertOk();
});

it('redirects guests to the login page', function () {
    $this->get(tenantUrl($this->tenant, '/'))->assertRedirect(route('login'));
});

it('signs in with email and password', function () {
    $this->post(tenantUrl($this->tenant, 'login'), ['email' => 'ana@micomoc.test', 'password' => 'password'])
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($this->user);
});

it('rejects a wrong password', function () {
    $this->post(tenantUrl($this->tenant, 'login'), ['email' => 'ana@micomoc.test', 'password' => 'errada'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects inactive users', function () {
    asTenant($this->tenant, fn () => $this->user->update(['is_active' => false]));

    $this->post(tenantUrl($this->tenant, 'login'), ['email' => 'ana@micomoc.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('throttles repeated attempts', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(tenantUrl($this->tenant, 'login'), ['email' => 'ana@micomoc.test', 'password' => 'errada']);
    }

    $this->post(tenantUrl($this->tenant, 'login'), ['email' => 'ana@micomoc.test', 'password' => 'password'])
        ->assertTooManyRequests();
});

it('signs out', function () {
    $this->actingAs($this->user)
        ->post(tenantUrl($this->tenant, 'logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('opens the personal desk for signed-in users, with the dashboard one click away', function () {
    $this->actingAs($this->user)
        ->get(tenantUrl($this->tenant, '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Home')->where('auth.user.email', 'ana@micomoc.test'));

    $this->actingAs($this->user)->get(tenantUrl($this->tenant, 'painel'))->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard'));
});

it('records when the user was last seen', function () {
    $this->actingAs($this->user)->get(tenantUrl($this->tenant, '/'));

    expect(asTenant($this->tenant, fn () => $this->user->fresh()->last_seen_at))->not->toBeNull();
});
