<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super admin console: a route with a {tenant} parameter works inside that
 * tenant, so the scopes apply to the agents, skills and routines it edits.
 * Runs before route model binding, and only for a signed-in super admin.
 */
final class SetTenantFromRoute
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->route()?->parameter('tenant');

        if ($key === null || ! Auth::guard('admin')->check()) {
            return $next($request);
        }

        $tenant = Tenant::query()->find(is_numeric($key) ? (int) $key : 0);

        abort_if($tenant === null, 404);

        return $this->tenants->run($tenant, fn () => $next($request));
    }
}
