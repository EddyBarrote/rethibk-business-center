<?php

namespace App\Http\Controllers\Auth;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * How the tenant shows itself on the sign-in screens: its name, colour and
 * logo from Definições > Marca, with the product brand underneath.
 */
final class LoginBrand
{
    /**
     * @return array{name: string, color: string|null, logo_url: string|null}|null
     */
    public static function props(): ?array
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return null;
        }

        $brand = (array) data_get($tenant->settings, 'brand', []);
        $color = (string) ($brand['color'] ?? '');
        $logo = $brand['logo_path'] ?? null;

        return [
            'name' => $tenant->name,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtoupper($color) : null,
            'logo_url' => is_string($logo) && Storage::disk('local')->exists($logo) ? route('login.logo') : null,
        ];
    }
}
