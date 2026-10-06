<?php

namespace App\Http\Controllers\Settings;

use App\Ai\Runs\AgentDirectory;
use App\Enums\AgentStatus;
use App\Enums\EmailCategory;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\EmailRoute;
use App\Models\User;
use App\Tenancy\TenantRule;
use App\Workflows\EmailRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Regras de email (docs/DECISOES.md, "Fluxos de trabalho"): which agent each
 * kind of email goes to after triage, and the person who takes over when the
 * agent cannot. An active flow for the kind of email decides its agent.
 */
class EmailRuleController extends Controller
{
    public function index(Request $request, EmailRouter $router): Response
    {
        abort_unless($this->user($request)->hasPermission(Permission::ManageAgents), 403);

        $routes = EmailRoute::query()->get()->keyBy(fn (EmailRoute $route) => $route->category->value);

        return Inertia::render('Settings/EmailRules', [
            'rules' => collect(EmailCategory::cases())->map(function (EmailCategory $category) use ($router, $routes) {
                $route = $router->route($category);
                $role = $category->handlerRole();

                return [
                    'category' => $category->value,
                    'label' => $category->label(),
                    'custom' => isset($routes[$category->value]),
                    'agent_id' => isset($routes[$category->value]) ? $routes[$category->value]->agent_id : null,
                    'fallback_user_id' => $routes[$category->value]->fallback_user_id ?? null,
                    'default_agent' => $role !== null ? app(AgentDirectory::class)->forRole($role)?->name : null,
                    'handled_by' => $route['agent']?->name,
                    'source' => $route['source'],
                    'workflow' => $route['workflow'] ? ['id' => $route['workflow']->id, 'name' => $route['workflow']->name] : null,
                ];
            })->values(),
            'agents' => Agent::query()->where('status', AgentStatus::Active)->orderBy('name')->get(['id', 'name']),
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, string $category): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::ManageAgents), 403);

        $kind = EmailCategory::tryFrom($category) ?? abort(404);
        $data = $request->validate([
            'mode' => ['required', 'in:default,agent,none'],
            'agent_id' => ['nullable', 'required_if:mode,agent', 'integer', TenantRule::exists('agents')],
            'fallback_user_id' => ['nullable', 'integer', TenantRule::exists('users')],
        ]);

        if ($data['mode'] === 'default' && empty($data['fallback_user_id'])) {
            EmailRoute::query()->where('category', $kind)->delete();
        } else {
            EmailRoute::query()->updateOrCreate(['category' => $kind], [
                'agent_id' => $data['mode'] === 'agent' ? $data['agent_id'] : ($data['mode'] === 'default' ? $this->defaultAgentId($kind) : null),
                'fallback_user_id' => $data['fallback_user_id'] ?? null,
            ]);
        }

        AuditLog::record($user, 'email_route.updated', ['category' => $kind->value, ...$data]);

        return back()->with('success', "Regra de «{$kind->label()}» guardada.");
    }

    private function defaultAgentId(EmailCategory $category): ?int
    {
        $role = $category->handlerRole();

        return $role !== null ? app(AgentDirectory::class)->forRole($role)?->id : null;
    }
}
