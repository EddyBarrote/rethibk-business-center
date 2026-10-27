<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\UserAvatars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A person's photo: seen by everyone in the same company (the route binding
 * keeps to the tenant), changed only by the person, in A minha conta › Perfil.
 */
class UserAvatarController extends Controller
{
    public function __construct(private readonly UserAvatars $avatars) {}

    public function show(User $user): StreamedResponse
    {
        abort_if($user->avatar_path === null, 404);

        return Storage::disk(UserAvatars::DISK)->response($user->avatar_path, headers: ['Cache-Control' => 'private, max-age=86400']);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192', 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000']]);

        try {
            $this->avatars->upload($user, $data['avatar']);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['avatar' => 'Não foi possível usar esta imagem. Experimente outra (PNG, JPG ou WebP).']);
        }

        AuditLog::record($user, 'user.avatar_uploaded', [], subject: $user);

        return back()->with('success', 'Foto actualizada.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $this->avatars->remove($user);

        return back()->with('success', 'Foto retirada; ficam as iniciais.');
    }
}
