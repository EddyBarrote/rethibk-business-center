<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Enums\AgentStatus;
use App\Enums\AutonomyLevel;
use App\Enums\MailboxStatus;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Department;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generic agents are defined by the super admin (docs/DECISOES.md): identity,
 * personality, instructions, model, autonomy level, capabilities, routines and
 * mailbox. SetTenantFromRoute puts these routes inside {tenant}.
 */
class AgentController extends AdminController
{
    public function create(Tenant $tenant): Response
    {
        return Inertia::render('Admin/Agents/Form', [...$this->formOptions($tenant), 'agent' => null]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $this->validated($request);
        $admin = $this->admin($request);

        $agent = DB::transaction(function () use ($data, $admin) {
            $agent = new Agent(Arr::except($data, ['capabilities']));
            $agent->forceFill(['created_by_admin_id' => $admin->id])->save();
            $agent->capabilities()->sync(array_fill_keys($data['capabilities'] ?? [], ['enabled' => true]));

            return $agent;
        });

        AuditLog::record($admin, 'agent.created', ['key' => $agent->key, 'autonomy_level' => $agent->autonomy_level->value, 'capabilities' => $data['capabilities'] ?? []], subject: $agent);

        return redirect()->route('admin.tenants.agents.edit', [$tenant, $agent])->with('success', 'Agente criado.');
    }

    /**
     * Creates one or all of the six agents of section 6.3 from their
     * templates; agents that already exist are left alone.
     */
    public function installTemplates(Request $request, Tenant $tenant, TemplateInstaller $installer): RedirectResponse
    {
        $data = $request->validate(['template' => ['nullable', Rule::in(array_keys(AgentTemplates::all()))]]);
        $templates = isset($data['template']) ? [AgentTemplates::all()[$data['template']]] : array_values(AgentTemplates::all());
        $created = 0;
        $missing = [];

        foreach ($templates as $template) {
            $result = $installer->install($template, $this->admin($request));
            $created += $result['created'] ? 1 : 0;
            $missing = [...$missing, ...$result['missing_capabilities']];
        }

        return back()->with('success', "{$created} agente(s) criado(s) a partir dos modelos.".($missing !== [] ? ' Capacidades em falta (sincronize o ERP): '.implode(', ', array_unique($missing)).'.' : ''));
    }

    public function edit(Tenant $tenant, Agent $agent): Response
    {
        $mailbox = Mailbox::query()->where('agent_id', $agent->id)->first();

        return Inertia::render('Admin/Agents/Form', [
            ...$this->formOptions($tenant),
            'agent' => [
                ...$agent->only(['id', 'key', 'name', 'title', 'description', 'personality', 'instructions', 'department_id', 'reports_to_user_id', 'provider', 'model', 'temperature', 'max_tokens', 'max_steps']),
                'status' => $agent->status->value,
                'autonomy_level' => $agent->autonomy_level->value,
                'capabilities' => $agent->capabilities()->wherePivot('enabled', true)->pluck('capabilities.id'),
            ],
            'routines' => $agent->routines()->orderBy('name')->get()->map(fn (AgentRoutine $routine) => [
                ...$routine->only(['id', 'name', 'prompt', 'schedule', 'is_active']),
                'last_run_at' => $routine->last_run_at?->toIso8601String(),
            ]),
            'mailbox' => $mailbox === null ? null : [
                ...$mailbox->only(['id', 'address', 'display_name', 'imap_host', 'imap_port', 'imap_username', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption']),
                'status' => $mailbox->status->value,
                'has_imap_password' => filled($mailbox->imap_password),
                'has_smtp_password' => filled($mailbox->smtp_password),
                'last_error' => $mailbox->last_error,
            ],
        ]);
    }

    public function update(Request $request, Tenant $tenant, Agent $agent): RedirectResponse
    {
        $data = $this->validated($request, $agent);
        $before = ['autonomy_level' => $agent->autonomy_level->value, 'status' => $agent->status->value];

        DB::transaction(function () use ($agent, $data) {
            $agent->fill(Arr::except($data, ['capabilities']));

            if ($agent->status !== AgentStatus::Suspended) {
                $agent->suspended_reason = null;
            }

            $agent->save();
            $agent->capabilities()->sync(array_fill_keys($data['capabilities'] ?? [], ['enabled' => true]));
        });

        AuditLog::record($this->admin($request), 'agent.updated', [
            'before' => $before,
            'after' => ['autonomy_level' => $agent->autonomy_level->value, 'status' => $agent->status->value],
            'capabilities' => $data['capabilities'] ?? [],
        ], subject: $agent);

        return back()->with('success', 'Agente guardado.');
    }

    public function updateMailbox(Request $request, Tenant $tenant, Agent $agent): RedirectResponse
    {
        $mailbox = Mailbox::query()->where('agent_id', $agent->id)->first();

        $data = $request->validate([
            'address' => ['required', 'email', 'max:255', Rule::unique('mailboxes', 'address')->ignore($mailbox)],
            'display_name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(MailboxStatus::class)],
            'imap_host' => ['nullable', 'string', 'max:255'],
            'imap_port' => ['nullable', 'integer', 'between:1,65535'],
            'imap_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:255'],
            'imap_encryption' => ['nullable', 'in:ssl,tls,none'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'in:ssl,tls,none'],
        ]);

        // A blank password keeps the stored one.
        foreach (['imap_password', 'smtp_password'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        $mailbox ??= new Mailbox(['agent_id' => $agent->id]);
        $mailbox->fill([...$data, 'agent_id' => $agent->id])->save();

        AuditLog::record($this->admin($request), 'mailbox.updated', ['address' => $mailbox->address, 'status' => $mailbox->status->value], subject: $agent);

        return back()->with('success', 'Caixa de correio guardada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Agent $agent = null): array
    {
        $key = TenantRule::unique('agents', 'key');

        if ($agent !== null) {
            $key->ignore($agent->id);
        }

        return $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', $key],
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'personality' => ['nullable', 'string', 'max:5000'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'department_id' => ['nullable', 'integer', TenantRule::exists('departments')],
            'reports_to_user_id' => ['nullable', 'integer', TenantRule::exists('users')],
            'status' => ['required', Rule::enum(AgentStatus::class)],
            'autonomy_level' => ['required', Rule::enum(AutonomyLevel::class)],
            'provider' => ['nullable', 'string', Rule::in(array_keys((array) config('ai.providers')))],
            'model' => ['nullable', 'string', 'max:255'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'between:1,200000'],
            'max_steps' => ['nullable', 'integer', 'between:1,50'],
            'capabilities' => ['array'],
            'capabilities.*' => ['integer', TenantRule::exists('capabilities')],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Tenant $tenant): array
    {
        return [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'capabilities' => Capability::query()->orderBy('source')->orderBy('key')->get()->map(fn (Capability $capability) => [
                'id' => $capability->id,
                'key' => $capability->key,
                'name' => $capability->name,
                'description' => $capability->description,
                'source' => $capability->source->value,
                'is_mutating' => $capability->is_mutating,
                'is_available' => $capability->is_available,
                'risk' => $capability->risk->value,
                'ceiling' => config('autonomy.ceiling.'.$capability->key) !== null,
            ]),
            'levels' => AutonomyLevel::options(),
            'statuses' => array_map(fn (AgentStatus $status) => ['value' => $status->value, 'label' => $status->label()], AgentStatus::cases()),
            'providers' => array_keys((array) config('ai.providers')),
            'defaultProvider' => config('ai.default'),
        ];
    }
}
