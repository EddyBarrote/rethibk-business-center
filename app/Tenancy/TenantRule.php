<?php

namespace App\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules that hit the database directly, so they bypass Eloquent
 * scopes. These add the tenant condition explicitly.
 */
final class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', app(TenantManager::class)->currentOrFail()->id);
    }

    public static function unique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', app(TenantManager::class)->currentOrFail()->id);
    }
}
