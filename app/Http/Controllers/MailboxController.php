<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\MailboxStatus;
use App\Enums\Permission;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\MailboxReader;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * People's mailboxes (docs/DECISOES.md, "Caixas de email por pessoa"): each
 * person connects one or more of their own, and says which agents read them.
 * Those who manage every mailbox create them for anyone and name the owners.
 * Agents' own mailboxes are set up with the agent.
 */
class MailboxController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $manageAll = $user->hasPermission(Permission::ManageMailboxes);
        abort_unless($manageAll || $user->hasPermission(Permission::ConnectOwnMailboxes), 403);

        $mailboxes = Mailbox::query()->where('kind', Mailbox::PERSON)
            ->when(! $manageAll, fn ($q) => $q->ownedBy($user))
            ->with('owners:id,name')
            ->orderBy('address')
            ->get();
        $readers = MailboxReader::query()->whereIn('mailbox_id', $mailboxes->modelKeys())->with('agent:id,name')->orderBy('id')->get()->groupBy('mailbox_id');

        return Inertia::render('Mailboxes/Index', [
            'mailboxes' => $mailboxes->map(fn (Mailbox $m) => [
                ...$m->only(['id', 'address', 'display_name', 'imap_host', 'imap_port', 'imap_username', 'imap_encryption', 'imap_folder', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption']),
                'status' => $m->status->value,
                'status_label' => $m->status->label(),
                'has_imap_password' => filled($m->imap_password),
                'has_smtp_password' => filled($m->smtp_password),
                'last_error' => $m->last_error,
                'last_inbound_at' => $m->last_inbound_at?->toIso8601String(),
                'owners' => $m->owners->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
                'readers' => ($readers[$m->id] ?? collect())->map(fn (MailboxReader $r) => ['id' => $r->agent_id, 'name' => $r->agent->name, 'processes_new' => $r->processes_new])->values(),
                'mine' => $m->owners->contains('id', $user->id),
            ]),
            'agents' => Agent::query()->where('status', AgentStatus::Active)->orderBy('name')->get(['id', 'name', 'department_id']),
            'people' => $manageAll ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'default_reader_id' => $this->areaAgent($user)?->id,
            'can' => ['manage_all' => $manageAll],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $manageAll = $user->hasPermission(Permission::ManageMailboxes);
        abort_unless($manageAll || $user->hasPermission(Permission::ConnectOwnMailboxes), 403);

        $data = $this->validated($request, null, $manageAll);
        $owners = $manageAll && filled($data['owners'] ?? null) ? $data['owners'] : [$user->id];

        // By default the agent of the person's area reads it and looks at new email.
        if (! array_key_exists('readers', $data)) {
            $agent = $this->areaAgent(User::query()->find($owners[0]) ?? $user);
            $data['readers'] = $agent ? [$agent->id] : [];
            $data['processor'] = $agent?->id;
        }

        $mailbox = DB::transaction(function () use ($data, $owners) {
            $mailbox = Mailbox::query()->create([...$this->settings($data), 'kind' => Mailbox::PERSON, 'agent_id' => null]);
            $this->syncOwners($mailbox, $owners);
            $this->syncReaders($mailbox, $data['readers'] ?? [], $data['processor'] ?? null);

            return $mailbox;
        });

        AuditLog::record($user, 'mailbox.created', ['address' => $mailbox->address, 'owners' => $owners, 'readers' => $data['readers'] ?? []], subject: $mailbox);

        return back()->with('success', 'Caixa de email ligada.');
    }

    public function update(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $user = $this->user($request);
        $manageAll = $this->authorizeMailbox($user, $mailbox);
        $data = $this->validated($request, $mailbox, $manageAll);

        DB::transaction(function () use ($mailbox, $data, $manageAll) {
            $mailbox->fill($this->settings($data))->save();

            if ($manageAll && filled($data['owners'] ?? null)) {
                $this->syncOwners($mailbox, $data['owners']);
            }

            if (array_key_exists('readers', $data)) {
                $this->syncReaders($mailbox, $data['readers'], $data['processor'] ?? null);
            }
        });

        AuditLog::record($user, 'mailbox.updated', ['address' => $mailbox->address, 'readers' => $data['readers'] ?? null], subject: $mailbox);

        return back()->with('success', 'Caixa de email guardada.');
    }

    public function destroy(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $user = $this->user($request);
        $this->authorizeMailbox($user, $mailbox);

        AuditLog::record($user, 'mailbox.deleted', ['address' => $mailbox->address], subject: $mailbox);
        $mailbox->delete();

        return back()->with('success', 'Caixa de email removida.');
    }

    /**
     * Owners change their mailbox; those who manage every mailbox change any.
     *
     * @return bool whether the person manages every mailbox
     */
    private function authorizeMailbox(User $user, Mailbox $mailbox): bool
    {
        abort_unless($mailbox->isPersonal(), 404);
        $manageAll = $user->hasPermission(Permission::ManageMailboxes);
        abort_unless($manageAll || ($user->hasPermission(Permission::ConnectOwnMailboxes) && $mailbox->isOwnedBy($user)), 403);

        return $manageAll;
    }

    /**
     * The first active agent of the person's department.
     */
    private function areaAgent(User $user): ?Agent
    {
        if ($user->department_id === null) {
            return null;
        }

        return Agent::query()->where('department_id', $user->department_id)->where('status', AgentStatus::Active)->orderBy('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Mailbox $mailbox, bool $manageAll): array
    {
        $data = $request->validate([
            'address' => ['required', 'email', 'max:255', Rule::unique('mailboxes', 'address')->ignore($mailbox)],
            'display_name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([MailboxStatus::Active->value, MailboxStatus::Disabled->value])],
            'imap_host' => ['nullable', 'string', 'max:255'],
            'imap_port' => ['nullable', 'integer', 'between:1,65535'],
            'imap_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:255'],
            'imap_encryption' => ['nullable', 'in:ssl,tls,none'],
            'imap_folder' => ['nullable', 'string', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'in:ssl,tls,none'],
            'owners' => ['sometimes', 'array', 'max:20'],
            'owners.*' => ['integer', TenantRule::exists('users')],
            'readers' => ['sometimes', 'array', 'max:20'],
            'readers.*' => ['integer', TenantRule::exists('agents')],
            'processor' => ['nullable', 'integer'],
        ]);

        if (! $manageAll && array_key_exists('owners', $data)) {
            unset($data['owners']);
        }

        if (($data['processor'] ?? null) !== null && ! in_array((int) $data['processor'], array_map('intval', $data['readers'] ?? []), true)) {
            throw ValidationException::withMessages(['processor' => 'O agente que vê o email novo tem de estar entre os que lêem a caixa.']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settings(array $data): array
    {
        $settings = collect($data)->only([
            'address', 'display_name', 'status', 'imap_host', 'imap_port', 'imap_username', 'imap_password', 'imap_encryption', 'imap_folder',
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
        ])->all();

        // A blank password keeps the stored one.
        foreach (['imap_password', 'smtp_password'] as $secret) {
            if (blank($settings[$secret] ?? null)) {
                unset($settings[$secret]);
            }
        }

        $settings['imap_folder'] = filled($settings['imap_folder'] ?? null) ? $settings['imap_folder'] : 'INBOX';
        $settings['status'] ??= MailboxStatus::Active->value;

        return $settings;
    }

    /**
     * @param  list<int|string>  $owners
     */
    private function syncOwners(Mailbox $mailbox, array $owners): void
    {
        $ids = array_values(array_unique(array_map('intval', $owners)));
        MailboxOwner::query()->where('mailbox_id', $mailbox->id)->whereNotIn('user_id', $ids)->delete();

        foreach ($ids as $id) {
            MailboxOwner::query()->firstOrCreate(['mailbox_id' => $mailbox->id, 'user_id' => $id]);
        }
    }

    /**
     * @param  list<int|string>  $readers
     */
    private function syncReaders(Mailbox $mailbox, array $readers, int|string|null $processor): void
    {
        $ids = array_values(array_unique(array_map('intval', $readers)));
        MailboxReader::query()->where('mailbox_id', $mailbox->id)->whereNotIn('agent_id', $ids)->delete();

        foreach ($ids as $id) {
            MailboxReader::query()->updateOrCreate(['mailbox_id' => $mailbox->id, 'agent_id' => $id], ['processes_new' => $processor !== null && (int) $processor === $id]);
        }
    }
}
