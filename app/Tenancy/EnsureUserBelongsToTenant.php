<?php

namespace App\Tenancy;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence in depth: the user provider is already tenant-scoped, but an
 * authenticated user that does not belong to the host's tenant is signed out.
 */
final class EnsureUserBelongsToTenant
{
    public function __construct(private TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User && $user->tenant_id !== $this->tenants->id()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
