<?php

namespace Tests\Fixtures;

use App\Models\User;
use App\Tenancy\TenantAwareJob;
use App\Tenancy\TenantManager;
use RuntimeException;

final class ProbeTenantJob extends TenantAwareJob
{
    public static ?int $seenTenant = null;

    public static ?int $seenUsers = null;

    public function __construct(public int $tenantId, public bool $fail = false) {}

    public function handle(TenantManager $tenants): void
    {
        self::$seenTenant = $tenants->id();
        self::$seenUsers = User::query()->count();

        if ($this->fail) {
            throw new RuntimeException('falhou');
        }
    }
}
