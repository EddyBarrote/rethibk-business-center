<?php

namespace App\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for every queued job that touches tenant data.
 *
 * The tenant id travels in the payload. The SetTenantForJob middleware sets the
 * tenant before handle() runs and restores the previous one afterwards, so subclasses
 * keep their own handle() signature with dependency injection.
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tenantId;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SetTenantForJob];
    }
}
