<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asks for a password reset link. The user lookup is tenant-scoped, so the
 * link only ever resets the account on the host it was asked from.
 */
class ForgotPasswordController extends Controller
{
    protected string $broker = 'users';

    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'brand' => $this->brand(),
            'admin' => $this->broker !== 'users',
            'status' => session('status'),
            'dev_reset_url' => session('dev_reset_url'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        Password::broker($this->broker)->sendResetLink(
            ['email' => $request->string('email')->toString(), 'is_active' => true],
            function (CanResetPassword $user, string $token): void {
                $user->sendPasswordResetNotification($token);

                // On a developer's machine mail only goes to the log, so the
                // link is shown on screen instead of being lost there.
                if (self::mailIsLocalOnly()) {
                    session()->flash('dev_reset_url', call_user_func(ResetPassword::$createUrlCallback, $user, $token));
                }
            },
        );

        // The same answer whether or not the account exists, so the form
        // cannot be used to find out who has an account.
        return back()->with('status', 'Se existir uma conta activa com esse email, enviámos-lhe um link para definir uma nova palavra-passe.');
    }

    /**
     * @return array{name: string, color: string|null, logo_url: string|null}|null
     */
    protected function brand(): ?array
    {
        return LoginBrand::props();
    }

    public static function mailIsLocalOnly(): bool
    {
        return config('app.env') === 'local' && in_array(config('mail.default'), ['log', 'array'], true);
    }
}
