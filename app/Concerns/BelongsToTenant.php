<?php

namespace App\Concerns;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every domain model with a tenant_id column uses this trait.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', Tenant::current()->id ?? throw new NoTenantException);
            }
        });

        // A record can never move to another tenant.
        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new \LogicException('tenant_id is immutable.');
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Whether the record belongs to the tenant currently set.
     */
    public function belongsToCurrentTenant(): bool
    {
        return $this->tenant_id === app(TenantManager::class)->id();
    }
}
