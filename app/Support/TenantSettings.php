<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Per-tenant business settings, falling back to config/business.php.
 */
final class TenantSettings
{
    public static function business(string $key): mixed
    {
        $value = data_get(Tenant::current()?->settings, 'business.'.$key);

        return $value ?? config('business.'.$key);
    }

    public static function int(string $key): int
    {
        return (int) self::business($key);
    }

    /**
     * Every business setting with its effective value, for the admin form.
     *
     * @return array<string, int>
     */
    public static function allBusiness(?Tenant $tenant): array
    {
        $values = [];

        foreach ((array) config('business') as $key => $default) {
            $values[$key] = (int) (data_get($tenant?->settings, 'business.'.$key) ?? $default);
        }

        return $values;
    }
}
