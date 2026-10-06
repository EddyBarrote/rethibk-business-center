<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create(['email' => 'ops@rethink.test']);
});

it('shows the forgot password page on the admin host', function () {
    $this->get(adminUrl('forgot-password'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Auth/ForgotPassword')
        ->where('admin', true)
        ->where('brand', null));
});

it('resets a super admin password from an admin-host link', function () {
    Notification::fake();

    $this->post(adminUrl('forgot-password'), ['email' => 'ops@rethink.test'])->assertSessionHas('status');

    Notification::assertSentTo($this->admin, ResetPassword::class, function (ResetPassword $notification) {
        $url = (string) call_user_func(ResetPassword::$createUrlCallback, $this->admin, $notification->token);
        expect($url)->toStartWith(adminUrl('reset-password/'));

        $this->post(adminUrl('reset-password'), [
            'token' => $notification->token,
            'email' => 'ops@rethink.test',
            'password' => 'nova-palavra-1',
            'password_confirmation' => 'nova-palavra-1',
        ])->assertRedirect(adminUrl('login'));

        return true;
    });

    expect(Hash::check('nova-palavra-1', $this->admin->fresh()->password))->toBeTrue();
});

it('does not reset a super admin from a tenant host', function () {
    Notification::fake();
    $tenant = Tenant::factory()->create();

    $this->post(tenantUrl($tenant, 'forgot-password'), ['email' => 'ops@rethink.test']);

    Notification::assertNothingSent();
});
