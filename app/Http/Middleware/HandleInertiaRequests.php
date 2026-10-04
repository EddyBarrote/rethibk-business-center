<?php

namespace App\Http\Middleware;

use App\Enums\AgentStatus;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\PlatformAdmin;
use App\Models\Task;
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
                'waiting_tasks' => fn () => $user instanceof User && $tenant !== null ? Task::query()->where('user_id', $user->id)->where('status', TaskStatus::WaitingHuman)->count() : 0,
                'pending_approvals' => fn () => $user instanceof User && $tenant !== null ? Approval::query()->visibleTo($user)->pending()->count() : 0,
            ],
            // Agents listed in the sidebar with a live "running" marker (Paperclip-style navigation).
            'sidebar_agents' => fn () => $user instanceof User && $tenant !== null ? $this->sidebarAgents() : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Agents for the sidebar, each with how many of its runs are queued or running.
     *
     * @return list<array{id: int, name: string, avatar_url: string|null, status: string, running: int}>
     */
    private function sidebarAgents(): array
    {
        $running = AgentRun::query()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->selectRaw('agent_id, count(*) as total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        return Agent::query()
            ->where('status', '!=', AgentStatus::Draft)
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'avatar_path'])
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'avatar_url' => $agent->avatarUrl(),
                'status' => $agent->status->value,
                'running' => (int) ($running[$agent->id] ?? 0),
            ])
            ->values()
            ->all();
    }
}
