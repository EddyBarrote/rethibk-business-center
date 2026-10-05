<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'brand' => LoginBrand::props(),
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        Password::sendResetLink(['email' => $request->string('email')->toString(), 'is_active' => true]);

        // The same answer whether or not the account exists, so the form
        // cannot be used to find out who has an account.
        return back()->with('status', 'Se existir uma conta activa com esse email, enviámos-lhe um link para definir uma nova palavra-passe.');
    }
}
