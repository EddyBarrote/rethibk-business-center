<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the request host: a tenant's own domain first,
 * then a subdomain of the central domain (micomoc.plataforma.rethink.co.mz).
 */
final class IdentifyTenant
{
    public function __construct(private TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolve($request->getHost());

        abort_if($tenant === null, 404);
        abort_if($tenant->isSuspended(), 403, __('Esta organização está suspensa.'));

        return $this->tenants->run($tenant, fn () => $next($request));
    }

    private function resolve(string $host): ?Tenant
    {
        $host = strtolower($host);

        $byDomain = Tenant::query()->where('domain', $host)->first();

        if ($byDomain !== null) {
            return $byDomain;
        }

        $central = strtolower((string) config('tenancy.central_domain'));
        $suffix = '.'.$central;

        if ($central === '' || ! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        if ($slug === '' || str_contains($slug, '.')) {
            return null;
        }

        return Tenant::query()->where('slug', $slug)->first();
    }
}
