<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Budget\AiBudget;
use App\Ai\Budget\BudgetGuard;
use App\Enums\TenantStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\BudgetEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenants and their profile, including the AI budget (docs/DECISOES.md).
 */
class TenantController extends AdminController
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function index(): Response
    {
        $tenants = Tenant::query()->orderBy('name')->get()->map(fn (Tenant $tenant) => $this->tenants->run($tenant, fn () => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'url' => self::tenantUrl($tenant),
            'users' => User::query()->count(),
            'agents' => Agent::query()->count(),
            'spent_usd' => round(app(BudgetGuard::class)->tenantSpent(), 2),
            'cap_usd' => AiBudget::for($tenant)->tenantMonthly,
        ]));

        return Inertia::render('Admin/Tenants/Index', ['tenants' => $tenants]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Tenants/Create');
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $request->merge(['slug' => Str::lower((string) $request->input('slug')), 'owner_email' => Str::lower((string) $request->input('owner_email'))]);

        $data = $request->validate([
            ...TenantProvisioner::rules(),
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_password' => ['required', 'string', 'min:8'],
        ]);

        $tenant = $provisioner->create(
            Arr::only($data, ['name', 'slug', 'domain']),
            ['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $data['owner_password']],
        );

        $this->tenants->run($tenant, fn () => AuditLog::record($this->admin($request), 'tenant.created', ['slug' => $tenant->slug], subject: $tenant));

        return redirect()->route('admin.tenants.show', $tenant)->with('success', 'Organização criada.');
    }

    public function show(Tenant $tenant, BudgetGuard $budget): Response
    {
        return $this->tenants->run($tenant, fn () => Inertia::render('Admin/Tenants/Show', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'status' => $tenant->status->value,
                'url' => self::tenantUrl($tenant),
                'profile' => [
                    'legal_name' => $tenant->settings['profile']['legal_name'] ?? null,
                    'nuit' => $tenant->settings['profile']['nuit'] ?? null,
                    'contact_email' => $tenant->settings['profile']['contact_email'] ?? null,
                ],
                'budget' => AiBudget::for($tenant)->toArray(),
                'mail_domain' => $tenant->settings['mail_domain'] ?? null,
                'email_retention_days' => (int) ($tenant->settings['email_retention_days'] ?? config('mail_ingest.retention_days')),
                'tender_sources' => array_values($tenant->settings['tender_sources'] ?? []),
            ],
            'usage' => [
                'month' => now()->format('Y-m'),
                'spent_usd' => round($budget->tenantSpent(), 4),
                'runs' => AgentRun::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
            'agents' => Agent::query()->orderBy('name')->get()->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'key' => $agent->key,
                'name' => $agent->name,
                'title' => $agent->title,
                'status' => $agent->status->value,
                'status_label' => $agent->status->label(),
                'autonomy_level' => $agent->autonomy_level->value,
                'spent_usd' => round($budget->agentSpent($agent), 4),
            ]),
            'budgetEvents' => BudgetEvent::query()->latest('id')->limit(20)->get()->map(fn (BudgetEvent $event) => [
                'id' => $event->id,
                'scope' => $event->scope,
                'period' => $event->period,
                'threshold' => $event->threshold,
                'spent_usd' => $event->spent_usd,
                'cap_usd' => $event->cap_usd,
                'agent' => $event->agent?->name,
                'created_at' => $event->created_at->toIso8601String(),
            ]),
        ]));
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            ...Arr::except(TenantProvisioner::rules($tenant), ['slug']),
            'status' => ['required', Rule::enum(TenantStatus::class)],
            'profile.legal_name' => ['nullable', 'string', 'max:255'],
            'profile.nuit' => ['nullable', 'string', 'max:20'],
            'profile.contact_email' => ['nullable', 'email', 'max:255'],
            'budget.tenant_monthly_usd' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'budget.agent_monthly_usd' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'budget.run_usd' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'mail_domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'email_retention_days' => ['required', 'integer', 'between:7,3650'],
            'tender_sources' => ['array', 'max:50'],
            'tender_sources.*.name' => ['required', 'string', 'max:255'],
            'tender_sources.*.url' => ['required', 'url', 'max:2000'],
            'tender_sources.*.keywords' => ['nullable', 'string', 'max:1000'],
            'tender_sources.*.active' => ['boolean'],
        ]);

        $before = AiBudget::for($tenant)->toArray();
        $settings = $tenant->settings ?? [];
        $settings['profile'] = array_map(fn ($value) => $value === '' ? null : $value, $data['profile'] ?? []);
        $settings['ai_budget'] = AiBudget::fromArray($data['budget'] ?? [])->toArray();
        $settings['mail_domain'] = filled($data['mail_domain'] ?? null) ? Str::lower($data['mail_domain']) : null;
        $settings['email_retention_days'] = (int) $data['email_retention_days'];
        $settings['tender_sources'] = array_map(fn (array $source) => [
            'name' => $source['name'],
            'url' => $source['url'],
            'keywords' => $source['keywords'] ?? '',
            'active' => (bool) ($source['active'] ?? true),
        ], $data['tender_sources'] ?? []);

        $tenant->fill([
            'name' => $data['name'],
            'domain' => ($data['domain'] ?? null) ?: null,
            'status' => $data['status'],
            'settings' => $settings,
        ])->save();

        $this->tenants->run($tenant, fn () => AuditLog::record($this->admin($request), 'tenant.updated', [
            'status' => $tenant->status->value,
            'ai_budget' => ['before' => $before, 'after' => $settings['ai_budget']],
        ], subject: $tenant));

        return back()->with('success', 'Perfil guardado.');
    }

    public static function tenantUrl(Tenant $tenant): string
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($appUrl, PHP_URL_PORT);

        return $scheme.'://'.($tenant->domain ?: $tenant->slug.'.'.config('tenancy.central_domain')).($port ? ':'.$port : '');
    }
}
