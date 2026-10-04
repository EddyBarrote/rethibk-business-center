<?php

namespace App\Http\Middleware;

use App\Models\Approval;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // On the admin host the default guard is 'admin' (UseAdminGuard).
        $user = $request->user('web');
        $tenant = Tenant::current();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'locale' => app()->getLocale(),
            ],
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'admin' => fn () => ($admin = $request->user('admin')) instanceof PlatformAdmin ? [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ] : null,
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'can_manage_tenant' => $user->canManageTenant(),
                    'is_manager' => $user->isManager(),
                ] : null,
                'unread_notifications' => fn () => $user instanceof User && $tenant !== null ? $user->unreadNotifications()->count() : 0,
                'pending_approvals' => fn () => $user instanceof User && $tenant !== null ? Approval::query()->visibleTo($user)->pending()->count() : 0,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
