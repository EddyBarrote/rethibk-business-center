<?php

namespace App\Tenancy;

use App\Ai\Skills\SkillCatalog;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Creates a tenant with its owner and its local skills. Used by the
 * tenant:create command and by the super admin console.
 */
final class TenantProvisioner
{
    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(?Tenant $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn((array) config('tenancy.reserved_slugs')),
                Rule::unique('tenants', 'slug')->ignore($ignore),
            ],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')->ignore($ignore)],
        ];
    }

    /**
     * @param  array{name: string, slug: string, domain?: string|null}  $tenant
     * @param  array{name: string, email: string, password: string}  $owner
     */
    public function create(array $tenant, array $owner): Tenant
    {
        return DB::transaction(function () use ($tenant, $owner) {
            $model = Tenant::query()->create([
                'name' => $tenant['name'],
                'slug' => $tenant['slug'],
                'domain' => $tenant['domain'] ?? null,
                'status' => TenantStatus::Active,
                'settings' => [],
            ]);

            $this->tenants->run($model, function () use ($owner) {
                User::query()->create([
                    'name' => $owner['name'],
                    'email' => $owner['email'],
                    'password' => $owner['password'],
                    'role' => Role::Owner,
                ]);

                app(SkillCatalog::class)->syncLocal();
            });

            return $model;
        });
    }
}
