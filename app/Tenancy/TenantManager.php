<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantException;
use Closure;

/**
 * Holds the tenant for the current request, job or command.
 *
 * Registered as a singleton. HTTP sets it in IdentifyTenant, queued jobs in
 * SetTenantForJob, and scheduled commands through run() per active tenant.
 */
final class TenantManager
{
    private ?Tenant $tenant = null;

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * The current tenant, or an exception when none is set. Use this wherever
     * running without a tenant would be a bug rather than a valid state.
     */
    public function currentOrFail(): Tenant
    {
        return $this->tenant ?? throw new NoTenantException;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function set(Tenant|int $tenant): Tenant
    {
        if (is_int($tenant)) {
            $tenant = Tenant::query()->findOrFail($tenant);
        }

        return $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    /**
     * Run a callback as the given tenant, restoring the previous one afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant|int $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;

        try {
            return $callback($this->set($tenant));
        } finally {
            $this->tenant = $previous;
        }
    }

    /**
     * Run a callback once per active tenant. Scheduled commands use this so
     * they never touch data without an explicit tenant.
     *
     * @param  Closure(Tenant): mixed  $callback
     */
    public function eachActive(Closure $callback): void
    {
        Tenant::query()->active()->orderBy('id')->each(
            fn (Tenant $tenant) => $this->run($tenant, $callback),
        );
    }
}
