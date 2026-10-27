<?php

namespace App\Http\Controllers\Settings;

use App\Ai\ProviderKeys;
use App\Http\Controllers\Controller;
use App\Models\AiProviderKey;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definições › Provedores de IA (docs/DECISOES.md, "Chaves dos provedores de
 * IA na plataforma"): owners and admins paste the company's key for each
 * provider; it is stored encrypted and shown only by its last four
 * characters. Without one, the .env key (if any) is used.
 */
class AiProviderController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless($this->user($request)->canManageTenant(), 403);
        $own = AiProviderKey::query()->with('setBy:id,name')->get()->keyBy('provider');

        return Inertia::render('Settings/AiProviders', [
            'providers' => collect((array) config('agents.providers'))->map(function (array $provider, string $key) use ($own) {
                $row = $own->get($key);

                return [
                    'key' => $key,
                    'label' => (string) $provider['label'],
                    'models' => collect((array) config('agents.models'))->where('provider', $key)->pluck('label')->values(),
                    'own' => $row ? ['last_four' => $row->last_four, 'by' => $row->setBy?->name, 'at' => $row->updated_at->toIso8601String()] : null,
                    'from_env' => filled(ProviderKeys::fromEnv($key)),
                ];
            })->values(),
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);
        abort_unless(array_key_exists($provider, (array) config('agents.providers')), 404);
        $data = $request->validate(['key' => ['required', 'string', 'min:10', 'max:500', 'regex:/^\S+$/']], [
            'key.regex' => 'A chave não pode ter espaços.',
        ]);

        $key = trim($data['key']);
        AiProviderKey::query()->updateOrCreate(['provider' => $provider], ['key' => $key, 'last_four' => substr($key, -4), 'set_by_user_id' => $user->id]);
        AuditLog::record($user, 'ai.provider_key_set', ['provider' => $provider]);

        return back()->with('success', 'Chave guardada. Os modelos de '.config("agents.providers.{$provider}.label").' já se podem escolher nos agentes.');
    }

    public function destroy(Request $request, string $provider): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);
        AiProviderKey::query()->where('provider', $provider)->delete();
        AuditLog::record($user, 'ai.provider_key_removed', ['provider' => $provider]);

        return back()->with('success', 'Chave retirada.');
    }
}
