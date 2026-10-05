<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sets a new password from the emailed link.
 */
class ResetPasswordController extends Controller
{
    protected string $broker = 'users';

    protected string $loginRoute = 'login';

    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'brand' => $this->broker === 'users' ? LoginBrand::props() : null,
            'admin' => $this->broker !== 'users',
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::broker($this->broker)->reset(
            [...$request->only('email', 'password', 'password_confirmation', 'token'), 'is_active' => true],
            function (User|PlatformAdmin $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Este link já não é válido. Peça um novo.']);
        }

        return redirect()->route($this->loginRoute)->with('success', 'Palavra-passe alterada. Já pode entrar.');
    }
}
