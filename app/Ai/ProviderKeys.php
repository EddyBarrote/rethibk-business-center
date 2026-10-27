<?php

namespace App\Ai;

use App\Models\AiProviderKey;
use App\Models\Tenant;
use Laravel\Ai\AiManager;
use Throwable;

/**
 * Puts the current company's AI keys in the configuration (docs/DECISOES.md,
 * "Chaves dos provedores de IA na plataforma"). TenantManager calls it each
 * time the tenant changes (a request, a job, a command for each company): a
 * provider with a key of the company uses it, the others keep the .env's. A
 * provider whose key changed is built again on its next use.
 */
final class ProviderKeys
{
    /** @var array<string, string|null>|null The .env keys, read once. */
    private static ?array $defaults = null;

    /** @var array<string, true> The providers that hold a company's key now. */
    private static array $overridden = [];

    public static function apply(?Tenant $tenant): void
    {
        self::$defaults ??= array_map(fn ($provider) => is_array($provider) ? ($provider['key'] ?? null) : null, (array) config('ai.providers'));

        $own = [];
        if ($tenant !== null) {
            try {
                $own = AiProviderKey::query()->where('tenant_id', $tenant->id)->get(['provider', 'key'])->pluck('key', 'provider')->all();
            } catch (Throwable) {
                // Before the table exists (a fresh install migrating), the .env keys stand.
            }
        }

        // Only what a company set is touched: its keys go in, and the ones set for the previous company go back to the .env's.
        foreach (array_unique([...array_keys($own), ...array_keys(self::$overridden)]) as $provider) {
            $key = $own[$provider] ?? self::$defaults[$provider] ?? null;
            if (isset($own[$provider])) {
                self::$overridden[$provider] = true;
            } else {
                unset(self::$overridden[$provider]);
            }

            if (config("ai.providers.{$provider}.key") !== $key) {
                config(["ai.providers.{$provider}.key" => $key]);

                if (app()->resolved(AiManager::class)) {
                    app(AiManager::class)->forgetInstance($provider);
                }
            }
        }
    }

    /** The .env key of a provider, whatever the company set. */
    public static function fromEnv(string $provider): ?string
    {
        self::$defaults ??= array_map(fn ($p) => is_array($p) ? ($p['key'] ?? null) : null, (array) config('ai.providers'));

        return self::$defaults[$provider] ?? null;
    }
}
