<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Agents\AgentAvatars;
use App\Ai\Agents\AgentEditor;
use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Enums\MailboxStatus;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AuditLog;
use App\Models\Mailbox;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generic agents, defined here by the super admin or by the tenant's own
 * admins (docs/CAPACIDADES.md): identity, personality, instructions, model,
 * autonomy level, capabilities, skills, routines and mailbox.
 * SetTenantFromRoute puts these routes inside {tenant}.
 */
class AgentController extends AdminController
{
    public function create(Tenant $tenant): Response
    {
        return Inertia::render('Admin/Agents/Form', [...$this->formOptions($tenant), 'agent' => null]);
    }

    public function store(Request $request, Tenant $tenant, AgentEditor $editor): RedirectResponse
    {
        $agent = $editor->save(new Agent, $request->validate($editor->rules()), $this->admin($request));

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
            ...$this->formOptions($tenant, $agent),
            'agent' => app(AgentEditor::class)->present($agent, $agent->avatar_path ? route('admin.tenants.agents.avatar', [$tenant, $agent], false) : null),
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

    public function update(Request $request, Tenant $tenant, Agent $agent, AgentEditor $editor): RedirectResponse
    {
        $editor->save($agent, $request->validate($editor->rules($agent)), $this->admin($request));

        return back()->with('success', 'Agente guardado.');
    }

    /**
     * The agent's photo, for the console (tenant pages use AgentAvatarController).
     */
    public function avatar(Tenant $tenant, Agent $agent): StreamedResponse
    {
        abort_if($agent->avatar_path === null, 404);

        return Storage::disk(AgentAvatars::DISK)->response($agent->avatar_path);
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
    private function formOptions(Tenant $tenant, ?Agent $agent = null): array
    {
        return [
            ...app(AgentEditor::class)->options($agent),
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
        ];
    }
}
