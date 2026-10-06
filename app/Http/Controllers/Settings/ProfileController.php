<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A minha conta › Perfil: each person changes their own name and password.
 * The email, role and department are set by whoever manages people.
 */
class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = User::query()->with(['department', 'accessRole'])->findOrFail($this->user($request)->id);

        return Inertia::render('Settings/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'job_title' => $user->job_title,
                'role' => $user->accessRole->name ?? $user->role->label(),
                'department' => $user->department?->name,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $user->update($data);

        return back()->with('success', 'Nome guardado.');
    }

    public function password(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        AuditLog::record($user, 'user.password_changed', subject: $user);

        return back()->with('success', 'Palavra-passe mudada.');
    }
}
