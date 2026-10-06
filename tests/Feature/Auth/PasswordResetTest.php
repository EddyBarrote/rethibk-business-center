<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = asTenant($this->tenant, fn () => User::factory()->create(['email' => 'ana@micomoc.test']));
});

it('shows the forgot password page with the tenant brand', function () {
    $this->get(tenantUrl($this->tenant, 'forgot-password'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword')->where('brand.name', $this->tenant->name));
});

it('emails a reset link to an active user of this tenant', function () {
    Notification::fake();

    $this->post(tenantUrl($this->tenant, 'forgot-password'), ['email' => 'ana@micomoc.test'])
        ->assertSessionHas('status');

    Notification::assertSentTo($this->user, ResetPassword::class);
});

it('answers the same way for an unknown email and sends nothing', function () {
    Notification::fake();

    $this->post(tenantUrl($this->tenant, 'forgot-password'), ['email' => 'ninguem@micomoc.test'])
        ->assertSessionHas('status');

    Notification::assertNothingSent();
});

it('does not send a link for an account on another tenant', function () {
    Notification::fake();
    $other = Tenant::factory()->create();

    $this->post(tenantUrl($other, 'forgot-password'), ['email' => 'ana@micomoc.test'])->assertSessionHas('status');

    Notification::assertNothingSent();
});

it('sets a new password from the emailed link', function () {
    Notification::fake();
    $this->post(tenantUrl($this->tenant, 'forgot-password'), ['email' => 'ana@micomoc.test']);

    Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) {
        $this->get(tenantUrl($this->tenant, 'reset-password/'.$notification->token.'?email=ana@micomoc.test'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/ResetPassword')->where('email', 'ana@micomoc.test'));

        $this->post(tenantUrl($this->tenant, 'reset-password'), [
            'token' => $notification->token,
            'email' => 'ana@micomoc.test',
            'password' => 'nova-palavra-1',
            'password_confirmation' => 'nova-palavra-1',
        ])->assertRedirect(route('login'));

        return true;
    });

    expect(Hash::check('nova-palavra-1', asTenant($this->tenant, fn () => $this->user->fresh()->password)))->toBeTrue();
});

it('rejects an invalid token', function () {
    $this->post(tenantUrl($this->tenant, 'reset-password'), [
        'token' => 'falso',
        'email' => 'ana@micomoc.test',
        'password' => 'nova-palavra-1',
        'password_confirmation' => 'nova-palavra-1',
    ])->assertSessionHasErrors('email');
});

it('serves no logo when the tenant has none', function () {
    $this->get(tenantUrl($this->tenant, 'login/logo'))->assertNotFound();
});

it('shows the reset link on screen when mail only goes to the log on a local machine', function () {
    config(['app.env' => 'local', 'mail.default' => 'log']);

    $this->post(tenantUrl($this->tenant, 'forgot-password'), ['email' => 'ana@micomoc.test'])
        ->assertSessionHas('dev_reset_url', fn (string $url) => str_contains($url, '/reset-password/') && str_contains($url, $this->tenant->slug.'.'));
});

it('never shows the reset link outside a local machine', function () {
    config(['mail.default' => 'log']);

    $this->post(tenantUrl($this->tenant, 'forgot-password'), ['email' => 'ana@micomoc.test'])
        ->assertSessionMissing('dev_reset_url');
});
