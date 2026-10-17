<?php

namespace App\Http\Controllers;

use App\Ai\Runs\AgentDirectory;
use App\Ai\Runs\AgentRunner;
use App\Attachments\AttachmentLimits;
use App\Attachments\ModelInput;
use App\Email\MailboxSync;
use App\Email\MailClient;
use App\Enums\EmailCategory;
use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\EmailAttachment;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\Mailbox;
use App\Models\Task;
use App\Models\User;
use App\Tasks\TaskThread;
use App\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Email (/inbox): one email client for each person's mailboxes, with the
 * triage mailbox as one more for whoever may read it (docs/DECISOES.md,
 * "Email: um cliente de email na plataforma"). Folders, reading, writing,
 * drafts, files and search; the triage of each email next to it. Nobody
 * opens someone else's mailbox, not even administrators.
 */
class InboxController extends Controller
{
    /** The presets of the date filter: from that many days ago until now. */
    private const PERIODS = [
        'hoje' => ['days' => 0],
        '7d' => ['days' => 7],
        '30d' => ['days' => 30],
        '90d' => ['days' => 90],
    ];

    /** Where the triage of an email is, by its status. */
    private const TRIAGE_STATES = [
        'por_triar' => ['label' => 'Por triar', 'statuses' => [EmailStatus::Received, EmailStatus::Processing]],
        'triado' => ['label' => 'Triado', 'statuses' => [EmailStatus::Processed]],
        'falhou' => ['label' => 'Falhou', 'statuses' => [EmailStatus::Failed]],
    ];

    public function index(Request $request, MailboxSync $sync): Response
    {
        return $this->client($request, $sync, null);
    }

    public function show(Request $request, EmailMessage $message, MailboxSync $sync): Response
    {
        $this->authorizeView($request, $message);

        return $this->client($request, $sync, $message);
    }

    /**
     * Brings a mailbox (or one of its folders) up to date now.
     */
    public function sync(Request $request, MailboxSync $sync): RedirectResponse
    {
        $data = $request->validate([
            'mailbox_id' => ['required', 'integer', TenantRule::exists('mailboxes', 'id')],
            'folder_id' => ['nullable', 'integer', TenantRule::exists('email_folders', 'id')],
        ]);
        $mailbox = $this->readableMailbox($request, (int) $data['mailbox_id']);
        $folder = isset($data['folder_id']) ? EmailFolder::query()->where('mailbox_id', $mailbox->id)->findOrFail($data['folder_id']) : null;

        try {
            $sync->folders($mailbox, refresh: $folder === null);
            $sync->run($mailbox, $folder);
            $mailbox->forceFill(['last_error' => null])->save();
        } catch (Throwable $e) {
            $mailbox->forceFill(['last_error' => 'IMAP: '.str($e->getMessage())->limit(400)])->save();

            return back()->with('error', $mailbox->errorMessage());
        }

        return back();
    }

    public function older(Request $request, EmailFolder $folder, MailboxSync $sync): RedirectResponse
    {
        $mailbox = $this->readableMailbox($request, $folder->mailbox_id);

        try {
            $count = $sync->older($mailbox, $folder);
        } catch (Throwable $e) {
            return back()->with('error', 'Não foi possível ler as mensagens antigas: '.str($e->getMessage())->limit(160));
        }

        return back()->with('success', $count === 0 ? 'Não há mensagens mais antigas.' : "{$count} mensagens antigas carregadas.");
    }

    public function read(Request $request, EmailMessage $message, MailClient $client): RedirectResponse
    {
        $this->authorizeView($request, $message);
        $data = $request->validate(['read' => ['required', 'boolean']]);

        return $this->attempt(fn () => $client->markRead($message, (bool) $data['read']));
    }

    public function flag(Request $request, EmailMessage $message, MailClient $client): RedirectResponse
    {
        $this->authorizeView($request, $message);
        $data = $request->validate(['flagged' => ['required', 'boolean']]);

        return $this->attempt(fn () => $client->flag($message, (bool) $data['flagged']));
    }

    public function move(Request $request, EmailMessage $message, MailClient $client): RedirectResponse
    {
        $this->authorizeFolders($request, $message);
        $data = $request->validate(['folder_id' => ['required', 'integer', TenantRule::exists('email_folders', 'id')]]);
        $target = EmailFolder::query()->where('mailbox_id', $message->mailbox_id)->findOrFail($data['folder_id']);

        return $this->attempt(fn () => $client->move($message, $target), "Movido para {$target->label()}.");
    }

    public function archive(Request $request, EmailMessage $message, MailClient $client): RedirectResponse
    {
        $this->authorizeFolders($request, $message);

        return $this->attempt(fn () => $client->archive($message), 'Arquivado.');
    }

    /**
     * To the Lixo; from the Lixo or Spam, and a draft, deleted for good.
     */
    public function destroy(Request $request, EmailMessage $message, MailClient $client): RedirectResponse
    {
        $this->authorizeFolders($request, $message);
        $draft = $message->status === EmailStatus::Draft;
        $final = $draft || $message->folder === null || in_array($message->folder->role, [EmailFolder::TRASH, EmailFolder::JUNK], true);

        if ($draft) {
            AuditLog::record($this->user($request), 'email.draft_discarded', ['email_id' => $message->id, 'subject' => $message->subject], subject: $message);
        }

        return $this->attempt(fn () => $client->delete($message), $draft ? 'Rascunho descartado.' : ($final ? 'Apagado.' : 'Movido para o Lixo.'));
    }

    /**
     * Saves what the person is writing as a draft (new or the same one).
     */
    public function saveDraft(Request $request, MailClient $client, ?EmailMessage $message = null): RedirectResponse
    {
        [$mailbox, $data, $replyTo] = $this->composition($request, $message);

        $draft = $client->saveDraft($mailbox, $this->user($request), $data['fields'], $data['uploads'], $data['keep'], $message, $replyTo);

        // The files it has now, for the composer to keep on the next save.
        $saved = ['id' => $draft->id, 'attachments' => $draft->attachments()->get()->map(fn (EmailAttachment $a) => $this->file($a))->values()->all()];

        // Saved while writing: quietly.
        return $request->boolean('autosave')
            ? back()->with('draft_saved', $saved)
            : back()->with('success', 'Rascunho guardado.')->with('draft_saved', $saved);
    }

    /**
     * «Guardar em rascunho»: the person keeps an agent's proposal as it is, in
     * their Rascunhos and on the server (docs/DECISOES.md, "Rascunho só quando
     * a pessoa escolhe").
     */
    public function keepDraft(Request $request, EmailMessage $message, MailClient $client): RedirectResponse
    {
        $this->authorizeView($request, $message);
        abort_unless($message->status === EmailStatus::Draft, 409, 'Este email já não é um rascunho.');
        abort_unless($message->mailbox->canBeWrittenFromBy($this->user($request)), 403, 'Só quem gere esta caixa guarda os rascunhos dela.');

        if ($message->isProposal()) {
            AuditLog::record($this->user($request), 'email.draft_kept', ['email_id' => $message->id, 'subject' => $message->subject], subject: $message);
        }

        $client->keepDraft($message);

        return back()->with('success', 'Guardado nos Rascunhos.');
    }

    /**
     * Sends what the person wrote: a new email, a reply, a forward, or an
     * agent's draft after review. Agents never send in a person's name.
     */
    public function send(Request $request, MailClient $client, ?EmailMessage $message = null): RedirectResponse
    {
        [$mailbox, $data, $replyTo] = $this->composition($request, $message, sending: true);
        $user = $this->user($request);
        $fields = $data['fields'];

        $draft = $client->saveDraft($mailbox, $user, $fields, $data['uploads'], $data['keep'], $message, $replyTo);

        try {
            $sent = $client->send($draft, $user, $fields['to'], $fields['cc'], $fields['bcc'], $fields['subject'], $fields['body']);
        } catch (RuntimeException $e) {
            return back()->with('error', 'Não foi enviado: '.$e->getMessage())
                ->with('draft_saved', ['id' => $draft->id, 'attachments' => $draft->attachments()->get()->map(fn (EmailAttachment $a) => $this->file($a))->values()->all()]);
        }

        AuditLog::record($user, 'email.sent_by_user', ['email_id' => $sent->id, 'to' => $fields['to'], 'subject' => $fields['subject']], subject: $sent);

        return to_route('inbox.index', array_filter([
            'mailbox' => $mailbox->id,
            'folder' => $request->integer('return_folder') ?: null,
            'message' => $request->integer('return_message') ?: null,
        ]))->with('success', 'Email enviado.');
    }

    public function attachment(Request $request, EmailAttachment $attachment): StreamedResponse
    {
        $this->authorizeView($request, $attachment->message);
        abort_if($attachment->path === null, 404, 'O ficheiro já foi apagado pela retenção.');

        $disk = Storage::disk($attachment->diskName());
        abort_unless($disk->exists((string) $attachment->path), 404);
        $mime = (string) $attachment->mime_type;

        // Opened in the browser only when it is safe to (images, audio, video, PDF).
        $inline = ! $request->boolean('download') && preg_match('#^(image/(png|jpeg|webp|gif)|audio/|video/|application/pdf$)#', $mime) === 1;
        $headers = ['Content-Type' => $inline ? $mime : 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600'];

        return $inline
            ? $disk->response((string) $attachment->path, $attachment->filename, $headers, 'inline')
            : $disk->download((string) $attachment->path, $attachment->filename, $headers);
    }

    /**
     * The text of a file, for the preview window shared with the conversations.
     */
    public function attachmentPreview(Request $request, EmailAttachment $attachment, ModelInput $input): JsonResponse
    {
        $this->authorizeView($request, $attachment->message);
        $extension = strtolower(pathinfo($attachment->filename, PATHINFO_EXTENSION));
        $text = $attachment->path === null ? null : $input->text($attachment);

        return response()->json([
            'format' => $extension === 'xlsx' ? 'sheet' : 'text',
            'text' => $text === null ? null : mb_substr($text, 0, 200_000),
        ]);
    }

    /**
     * A person corrects the triage. The correction is kept for the agent.
     */
    public function reclassify(Request $request, EmailMessage $message): RedirectResponse
    {
        $this->authorizeView($request, $message);
        $data = $request->validate(['category' => ['required', Rule::enum(EmailCategory::class)]]);

        $before = $message->classification?->value;
        $message->forceFill(['classification' => $data['category'], 'classification_confidence' => 1])->save();

        AuditLog::record($this->user($request), 'email.reclassified', ['email_id' => $message->id, 'from' => $before, 'to' => $data['category']], subject: $message);

        return back()->with('success', 'Classificação corrigida.');
    }

    /**
     * Hands the email to its mailbox's agent again (after a failure, or after
     * a person corrected something).
     */
    public function retriage(Request $request, EmailMessage $message, AgentRunner $runner): RedirectResponse
    {
        $this->authorizeView($request, $message);
        $agent = $message->sent_to_triage_at !== null ? app(AgentDirectory::class)->forRole('triage') : $message->mailbox->processor();
        abort_unless($agent instanceof Agent && $agent->isActive(), 409, 'A caixa não tem um agente activo.');

        $message->forceFill(['status' => EmailStatus::Processing])->save();
        $runner->dispatch($agent, "Volta a triar o email #{$message->id} (pedido de {$this->user($request)->name}). Lê-o com email.read e classifica-o.", TriggerType::Manual, $this->user($request), $message);

        return back()->with('success', 'Enviado de novo para triagem.');
    }

    /**
     * Brings the open email into the person's conversation with an agent, to
     * talk about it or hand it over (docs/DECISOES.md, "Falar com um agente
     * sobre um email"). The agent can then read it with its email tools.
     */
    public function talk(Request $request, EmailMessage $message, TaskThread $threads): RedirectResponse
    {
        $this->authorizeView($request, $message);
        $user = $this->user($request);
        $data = $request->validate([
            'agent_id' => ['required', 'integer', TenantRule::exists('agents')],
            'message' => ['nullable', 'string', 'max:10000'],
        ]);

        $agent = Agent::query()->findOrFail($data['agent_id']);
        Gate::authorize('run', $agent);

        $chat = $threads->conversation($user, $agent);
        $body = trim((string) ($data['message'] ?? '')) ?: "Veja este email: «{$message->subject}».";
        $threads->post($chat, $user, $body, email: $message);
        AuditLog::record($user, 'email.shared_with_agent', ['email_id' => $message->id, 'agent_id' => $agent->id, 'task_id' => $chat->id], subject: $message);

        return to_route('tasks.show', $chat);
    }

    private function client(Request $request, MailboxSync $sync, ?EmailMessage $open): Response
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'mailbox' => ['nullable', 'string', 'max:20'],
            'folder' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:200'],
            'unread' => ['nullable', 'boolean'],
            'message' => ['nullable', 'integer'],
            // Filters of the list (docs/DECISOES.md, "Email: pesquisa e filtros").
            'em' => ['nullable', Rule::in(['todas'])],
            'leitura' => ['nullable', Rule::in(['nao_lidos', 'lidos'])],
            'anexos' => ['nullable', 'boolean'],
            'estrela' => ['nullable', 'boolean'],
            'periodo' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'triagem' => ['nullable', Rule::in(array_keys(self::TRIAGE_STATES))],
        ]);
        // The old "Não lidos" switch still opens the same list.
        $filters['leitura'] ??= ($filters['unread'] ?? false) ? 'nao_lidos' : null;

        if ($open === null && isset($filters['message'])) {
            $open = EmailMessage::query()->visibleTo($user)->find($filters['message']);
        }

        // Opening marks it read, before the list and the counts are read.
        $conversation = $open !== null ? $this->conversation($user, $open) : null;
        $mailboxes = $this->mailboxes($user);
        $routed = EmailMessage::query()->where('routed_to_user_id', $user->id)->triage()->whereNull('removed_at')->exists() && ! $user->hasPermission(Permission::ReadTriageEmails);
        $errors = [];

        // Folders come from the server the first time a mailbox is opened.
        $folders = $mailboxes->mapWithKeys(function (Mailbox $mailbox) use ($sync, &$errors) {
            try {
                return [$mailbox->id => $sync->folders($mailbox)->sortBy(fn (EmailFolder $f) => [$f->position(), $f->path])->values()];
            } catch (Throwable $e) {
                $mailbox->forceFill(['last_error' => 'IMAP: '.str($e->getMessage())->limit(400)])->save();
                $errors[$mailbox->id] = $mailbox->errorMessage();

                return [$mailbox->id => EmailFolder::query()->where('mailbox_id', $mailbox->id)->get()];
            }
        });

        // Raw: a user built in memory (not read back) has no such attribute yet.
        $last = $user->getRawOriginal('last_mailbox_id');
        $last = $last === null ? null : (int) $last;

        $selectedMailbox = match (true) {
            ($filters['mailbox'] ?? null) === 'routed' => 'routed',
            $open !== null && $mailboxes->contains('id', $open->mailbox_id) => $open->mailbox_id,
            $open !== null => 'routed',
            isset($filters['mailbox']) && $mailboxes->contains('id', (int) $filters['mailbox']) => (int) $filters['mailbox'],
            // Otherwise the one the person had open last time.
            $last !== null && $mailboxes->contains('id', $last) => $last,
            default => $mailboxes->first()->id ?? ($routed ? 'routed' : null),
        };

        if (is_int($selectedMailbox) && $last !== $selectedMailbox) {
            $user->forceFill(['last_mailbox_id' => $selectedMailbox])->saveQuietly();
        }
        $mailboxFolders = is_int($selectedMailbox) ? $folders[$selectedMailbox] : collect();
        $folder = match (true) {
            ! is_int($selectedMailbox) => null,
            isset($filters['folder']) => $mailboxFolders->firstWhere('id', (int) $filters['folder']) ?? $mailboxFolders->firstWhere('role', EmailFolder::INBOX),
            $open !== null && $open->email_folder_id !== null && ! isset($filters['q']) => $mailboxFolders->firstWhere('id', $open->email_folder_id),
            default => $mailboxFolders->firstWhere('role', EmailFolder::INBOX) ?? $mailboxFolders->first(),
        };
        $search = trim((string) ($filters['q'] ?? ''));
        $selected = is_int($selectedMailbox) ? $mailboxes->firstWhere('id', $selectedMailbox) : null;
        $triage = $selectedMailbox === 'routed' || ($selected !== null && (! $selected->isPersonal() || $selected->sendsToTriage()));

        [$from, $until] = $this->period($filters);
        $list = [
            'q' => $search,
            'everywhere' => ($filters['em'] ?? null) === 'todas',
            'category' => $filters['category'] ?? null,
            'reading' => $filters['leitura'],
            'attachments' => (bool) ($filters['anexos'] ?? false),
            'flagged' => (bool) ($filters['estrela'] ?? false),
            'from' => $from,
            'until' => $until,
            'triage' => $triage ? ($filters['triagem'] ?? null) : null,
        ];

        $messages = $this->listing($user, $selectedMailbox, $folder, $list);

        return Inertia::render('Email/Index', [
            'mailboxes' => $mailboxes->map(fn (Mailbox $m) => [
                'id' => $m->id,
                'address' => $m->address,
                'name' => $m->isPersonal() ? $m->display_name : ($m->agent?->name ? "Triagem · {$m->agent->name}" : 'Triagem'),
                'personal' => $m->isPersonal(),
                'imap' => filled($m->imap_host),
                'can_write' => $m->canBeWrittenFromBy($user) && $m->canSend(),
                'status' => $m->shownStatus()->value,
                'error' => $errors[$m->id] ?? $m->errorMessage(),
                // Unread in the Entrada, shown next to the mailbox in the menu.
                'unread' => (int) $folders[$m->id]->firstWhere('role', EmailFolder::INBOX)?->unread,
                'folders' => $folders[$m->id]->map(fn (EmailFolder $f) => [
                    'id' => $f->id, 'name' => $f->label(), 'role' => $f->role, 'unread' => $f->unread, 'total' => $f->total, 'has_older' => $f->has_older,
                    'depth' => $f->delimiter !== null && $f->delimiter !== '' ? substr_count(Str::after($f->path, 'INBOX'.$f->delimiter), $f->delimiter) : 0,
                ])->values(),
            ])->values(),
            'routed' => $routed,
            'current' => [
                'mailbox' => $selectedMailbox,
                'folder' => $folder?->id,
                'folder_role' => $folder?->role,
                'q' => $search,
                'category' => $filters['category'] ?? null,
                'unread' => $filters['leitura'] === 'nao_lidos',
                'triage' => $triage,
                'em' => $list['everywhere'] ? 'todas' : null,
                'leitura' => $filters['leitura'],
                'anexos' => $list['attachments'],
                'estrela' => $list['flagged'],
                'periodo' => $filters['periodo'] ?? null,
                'de' => isset($filters['periodo']) ? null : ($filters['de'] ?? null),
                'ate' => isset($filters['periodo']) ? null : ($filters['ate'] ?? null),
                'triagem' => $list['triage'],
            ],
            'triage_states' => $triage ? collect(self::TRIAGE_STATES)->map(fn (array $s, string $value) => ['value' => $value, 'label' => $s['label']])->values() : [],
            'messages' => $messages,
            'categories' => $selected !== null && (! $selected->isPersonal() || $selected->sendsToTriage()) ? $this->categories($selected) : [],
            'open' => $conversation,
            'attachment_limits' => AttachmentLimits::current()->forScreen(),
            'can_connect' => $user->hasPermission(Permission::ConnectOwnMailboxes) || $user->hasPermission(Permission::ManageMailboxes),
            'draft_saved' => $request->session()->get('draft_saved'),
            // External images load on their own unless the person asked to be asked (raw, as above).
            'images_ask' => (bool) $user->getRawOriginal('email_images_ask'),
        ]);
    }

    /**
     * The mailboxes a person reads in the client: their own, and the agents'
     * (triage) if the matrix lets them. Never someone else's.
     *
     * @return Collection<int, Mailbox>
     */
    private function mailboxes(User $user): Collection
    {
        return Mailbox::query()
            ->inClientOf($user)
            ->with('agent:id,name')
            ->orderByRaw("case when kind = 'person' then 0 else 1 end")
            ->orderBy('address')
            ->get();
    }

    /**
     * The start and end of the dates asked for: a preset, or the two dates.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function period(array $filters): array
    {
        if (isset($filters['periodo'])) {
            $days = self::PERIODS[$filters['periodo']]['days'];

            return [CarbonImmutable::today()->subDays($days), null];
        }

        $from = isset($filters['de']) ? CarbonImmutable::createFromFormat('Y-m-d', $filters['de'])?->startOfDay() : null;
        $until = isset($filters['ate']) ? CarbonImmutable::createFromFormat('Y-m-d', $filters['ate'])?->endOfDay() : null;

        // Dates given the wrong way round still mean the days between them.
        return $from !== null && $until !== null && $from->greaterThan($until) ? [$until->startOfDay(), $from->endOfDay()] : [$from ?: null, $until ?: null];
    }

    /**
     * @param  array{q: string, everywhere: bool, category: ?string, reading: ?string, attachments: bool, flagged: bool, from: ?CarbonImmutable, until: ?CarbonImmutable, triage: ?string}  $filters
     * @return array<string, mixed>
     */
    private function listing(User $user, int|string|null $mailbox, ?EmailFolder $folder, array $filters): array
    {
        $search = $filters['q'];

        if ($mailbox === null) {
            return ['data' => [], 'next' => null, 'prev' => null, 'from' => 0, 'to' => 0, 'total' => 0];
        }

        $query = match (true) {
            $mailbox === 'routed' => EmailMessage::query()->triage()->where('routed_to_user_id', $user->id)->whereNull('removed_at'),
            // Asked to look in every folder of the mailbox.
            $filters['everywhere'] => EmailMessage::query()->where('mailbox_id', $mailbox)->whereNull('removed_at'),
            $folder !== null => $folder->messages(),
            default => EmailMessage::query()->whereRaw('1 = 0'),
        };

        $query->visibleTo($user)
            ->with(['folder:id,name,role', 'mailbox:id,address', 'routedTo:id,name'])
            ->withCount('attachments')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('subject', 'like', "%{$search}%")
                ->orWhere('from_address', 'like', "%{$search}%")
                ->orWhere('from_name', 'like', "%{$search}%")
                ->orWhere('to', 'like', "%{$search}%")
                ->orWhere('text_body', 'like', "%{$search}%")
                ->orWhere('summary', 'like', "%{$search}%")))
            ->when($filters['category'], fn (Builder $q, string $c) => $c === 'none' ? $q->whereNull('classification') : $q->where('classification', $c))
            // A draft is never unread, and never counts as read either.
            ->when($filters['reading'] === 'nao_lidos', fn (Builder $q) => $q->whereNull('read_at')->where('status', '!=', EmailStatus::Draft))
            ->when($filters['reading'] === 'lidos', fn (Builder $q) => $q->whereNotNull('read_at'))
            ->when($filters['attachments'], fn (Builder $q) => $q->whereHas('attachments'))
            ->when($filters['flagged'], fn (Builder $q) => $q->where('is_flagged', true))
            ->when($filters['from'], fn (Builder $q, CarbonImmutable $d) => $q->whereRaw('coalesce(received_at, sent_at, updated_at) >= ?', [$d]))
            ->when($filters['until'], fn (Builder $q, CarbonImmutable $d) => $q->whereRaw('coalesce(received_at, sent_at, updated_at) <= ?', [$d]))
            ->when($filters['triage'], fn (Builder $q, string $t) => $q->whereIn('status', self::TRIAGE_STATES[$t]['statuses']))
            ->orderByRaw('coalesce(received_at, sent_at, updated_at) desc')
            ->orderByDesc('id');

        $page = $query->paginate(50)->withQueryString();

        return [
            'data' => collect($page->items())->map(fn (EmailMessage $m) => $this->summary($m))->values(),
            'next' => $page->nextPageUrl(),
            'prev' => $page->previousPageUrl(),
            'from' => (int) $page->firstItem(),
            'to' => (int) $page->lastItem(),
            'total' => $page->total(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categories(Mailbox $mailbox): array
    {
        $counts = EmailMessage::query()->where('mailbox_id', $mailbox->id)->where('direction', 'inbound')->whereNull('removed_at')
            ->where('received_at', '>=', now()->subDays(30))
            ->selectRaw('classification, count(*) as total')->groupBy('classification')->pluck('total', 'classification');

        return array_map(fn (array $c) => [...$c, 'count' => (int) ($counts[$c['value']] ?? 0)], EmailCategory::options());
    }

    /**
     * The open email with its conversation, and its triage when an agent read it.
     *
     * @return array<string, mixed>
     */
    private function conversation(User $user, EmailMessage $message): array
    {
        if ($message->direction === 'inbound' && $message->read_at === null) {
            try {
                app(MailClient::class)->markRead($message, true);
            } catch (Throwable) {
                $message->forceFill(['read_at' => now()])->save();
            }
        }

        $relations = ['attachments', 'mailbox', 'folder:id,name,role', 'routedTo:id,name', 'department:id,name', 'run:id,agent_id', 'run.agent:id,name,avatar_path'];
        $conversation = $message->thread_id
            ? EmailMessage::query()->visibleTo($user)->where('thread_id', $message->thread_id)->whereNull('removed_at')->with($relations)->orderByRaw('coalesce(received_at, sent_at, updated_at)')->orderBy('id')->get()
            : collect([$message->load($relations)]);

        if (! $conversation->contains('id', $message->id)) {
            $conversation->push($message->load($relations));
        }

        $writable = $message->mailbox->canSend() && ($message->mailbox->canBeWrittenFromBy($user)
            || (! $message->mailbox->isPersonal() && $message->routed_to_user_id === $user->id));

        return [
            'id' => $message->id,
            'mailbox_id' => $message->mailbox_id,
            'subject' => $message->subject ?: '(sem assunto)',
            'can_reply' => $writable,
            'can_file' => $message->mailbox->canBeWrittenFromBy($user),
            'messages' => $conversation->map(fn (EmailMessage $m) => [
                ...$this->summary($m),
                'to' => $m->to ?? [],
                'cc' => $m->cc ?? [],
                'bcc' => $m->status === EmailStatus::Draft ? ($m->bcc ?? []) : [],
                'reply_to' => $m->reply_to,
                'body' => $m->plainText(),
                'html' => $m->status === EmailStatus::Draft ? null : $this->withEmbeddedImages($m),
                // Spam never loads its images on its own.
                'junk' => $m->folder?->role === EmailFolder::JUNK,
                'message_id' => $m->message_id_header,
                'extracted' => $m->extracted,
                'department' => $m->department?->name,
                'erp_lead_id' => $m->erp_lead_id,
                'agent_run_id' => $m->agent_run_id,
                'written_by_agent' => $m->status === EmailStatus::Draft && $m->agent_run_id !== null && $m->written_by_user_id === null,
                // An agent's proposal, not yet in the Rascunhos (docs/DECISOES.md, "Rascunho só quando a pessoa escolhe").
                'proposal' => $m->isProposal(),
                // Which agent wrote the draft, from the run that created it.
                'agent' => $m->status === EmailStatus::Draft && $m->written_by_user_id === null && $m->run?->agent
                    ? ['name' => $m->run->agent->name, 'avatar_url' => $m->run->agent->avatarUrl()]
                    : null,
                // The images shown in the body are not listed again as files.
                'attachments' => $m->attachments->reject(fn (EmailAttachment $a) => $this->embedded($m, $a))->map(fn (EmailAttachment $a) => $this->file($a))->values(),
            ])->values(),
            'tasks' => Task::query()->where('source_type', $message->getMorphClass())->where('source_id', $message->id)->get()
                ->map(fn (Task $t) => ['id' => $t->id, 'ref' => $t->identifier(), 'title' => $t->title]),
            'follow_ups' => FollowUp::query()->where('subject_type', $message->getMorphClass())->where('subject_id', $message->id)->orderBy('due_at')->get()->map(fn (FollowUp $f) => [
                'id' => $f->id, 'title' => $f->title, 'due_at' => $f->due_at->toIso8601String(), 'done' => $f->done_at !== null,
            ]),
            'categories' => EmailCategory::options(),
        ];
    }

    /**
     * What the composer sent, checked: the mailbox it leaves from, the
     * recipients, the files, and the email it answers.
     *
     * @return array{0: Mailbox, 1: array{fields: array{to: list<string>, cc: list<string>, bcc: list<string>, subject: string, body: string}, uploads: list<int>, keep: list<int>}, 2: EmailMessage|null}
     */
    private function composition(Request $request, ?EmailMessage $draft, bool $sending = false): array
    {
        $user = $this->user($request);

        // Sending an agent's draft as it is: it leaves from its own mailbox.
        if ($draft !== null && ! $request->filled('mailbox_id')) {
            $request->merge(['mailbox_id' => $draft->mailbox_id]);
        }

        $data = $request->validate([
            'mailbox_id' => ['required', 'integer', TenantRule::exists('mailboxes', 'id')],
            'reply_to_id' => ['nullable', 'integer', TenantRule::exists('email_messages', 'id')],
            'to' => [$sending ? 'required' : 'nullable', 'array', $sending ? 'min:1' : 'min:0', 'max:50'],
            'to.*' => ['required', 'email'],
            'cc' => ['nullable', 'array', 'max:50'],
            'cc.*' => ['required', 'email'],
            'bcc' => ['nullable', 'array', 'max:50'],
            'bcc.*' => ['required', 'email'],
            'subject' => [$sending ? 'required' : 'nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:200000'],
            'uploads' => ['nullable', 'array', 'max:20'],
            'uploads.*' => ['integer'],
            'keep' => ['nullable', 'array', 'max:50'],
            'keep.*' => ['integer'],
        ], ['to.required' => 'Indique pelo menos um destinatário.', 'subject.required' => 'Escreva o assunto.']);

        $mailbox = Mailbox::query()->findOrFail($data['mailbox_id']);
        $replyTo = isset($data['reply_to_id']) ? EmailMessage::query()->visibleTo($user)->findOrFail($data['reply_to_id']) : null;

        if ($draft !== null) {
            $this->authorizeView($request, $draft);
            abort_unless($draft->status === EmailStatus::Draft, 409, 'Este email já não é um rascunho.');
            abort_unless($draft->mailbox_id === $mailbox->id, 422, 'O rascunho é de outra caixa.');
            $replyTo ??= $draft->in_reply_to ? EmailMessage::query()->visibleTo($user)->where('mailbox_id', $mailbox->id)->where('message_id_header', $draft->in_reply_to)->first() : null;
        }

        // A person's mailbox: only its owners. The triage mailbox: who reads triage, or the person an email was routed to, answering it.
        $routedReply = $replyTo !== null && ! $mailbox->isPersonal() && $replyTo->mailbox_id === $mailbox->id && $replyTo->routed_to_user_id === $user->id;
        abort_unless($mailbox->canBeWrittenFromBy($user) || $routedReply, 403, 'Não pode escrever a partir desta caixa.');
        abort_if($replyTo !== null && $replyTo->mailbox_id !== $mailbox->id, 422, 'A resposta sai da caixa do email original.');

        $clean = fn (?array $list) => array_values(array_unique(array_map('strtolower', array_map('trim', $list ?? []))));

        return [$mailbox, [
            'fields' => [
                'to' => $clean($data['to'] ?? []),
                'cc' => $clean($data['cc'] ?? []),
                'bcc' => $clean($data['bcc'] ?? []),
                'subject' => (string) ($data['subject'] ?? ''),
                'body' => (string) ($data['body'] ?? ''),
            ],
            'uploads' => array_map('intval', $data['uploads'] ?? []),
            'keep' => array_map('intval', $data['keep'] ?? []),
        ], $replyTo];
    }

    private function readableMailbox(Request $request, int $id): Mailbox
    {
        $mailbox = $this->mailboxes($this->user($request))->firstWhere('id', $id);
        abort_if($mailbox === null, 403);

        return $mailbox;
    }

    private function authorizeView(Request $request, ?EmailMessage $message): void
    {
        abort_if($message === null, 404);
        abort_unless(EmailMessage::query()->visibleTo($this->user($request))->whereKey($message->id)->exists(), 403);
    }

    /**
     * Moving and deleting change the mailbox itself: its owners, or who reads triage.
     */
    private function authorizeFolders(Request $request, EmailMessage $message): void
    {
        $this->authorizeView($request, $message);
        abort_unless($message->mailbox->canBeWrittenFromBy($this->user($request)), 403, 'Só quem gere esta caixa a organiza.');
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action, ?string $success = null): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->with('error', 'O servidor de email recusou: '.str($e->getMessage())->limit(200));
        }

        return $success !== null ? back()->with('success', $success) : back();
    }

    /**
     * A file the HTML shows in its body (an image it embeds with cid:).
     */
    private function embedded(EmailMessage $m, EmailAttachment $a): bool
    {
        return $a->content_id !== null && $a->path !== null && str_contains((string) $m->html_body, 'cid:'.$a->content_id);
    }

    /**
     * The HTML with the images it embeds (cid:) pointing at their stored file.
     */
    private function withEmbeddedImages(EmailMessage $m): ?string
    {
        $html = $m->html_body;

        if ($html === null || ! str_contains($html, 'cid:')) {
            return $html;
        }

        $files = $m->attachments->filter(fn (EmailAttachment $a) => $this->embedded($m, $a));

        return $files->isEmpty() ? $html : strtr($html, $files->mapWithKeys(fn (EmailAttachment $a) => ['cid:'.$a->content_id => "/attachments/{$a->id}"])->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function file(EmailAttachment $a): array
    {
        return [
            'id' => $a->id, 'name' => $a->filename, 'mime' => (string) $a->mime_type, 'size' => $a->size_bytes,
            'extension' => strtolower(pathinfo($a->filename, PATHINFO_EXTENSION)), 'available' => $a->path !== null && $a->ocr_status !== 'discarded',
            'url' => "/attachments/{$a->id}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(EmailMessage $m): array
    {
        $outbound = $m->direction === 'outbound';

        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'from' => $m->from_name ? "{$m->from_name} <{$m->from_address}>" : $m->from_address,
            'from_name' => $m->from_name ?: $m->from_address,
            'from_address' => $m->from_address,
            'to_list' => $outbound ? implode(', ', $m->to ?? []) : null,
            'subject' => $m->subject ?: '(sem assunto)',
            'snippet' => str($m->plainText())->squish()->limit(140)->toString(),
            'summary' => $m->summary,
            'category' => $m->classification?->value,
            'category_label' => $m->classification?->label(),
            'priority' => $m->priority,
            'status' => $m->status->value,
            'status_label' => $m->status->label(),
            'mailbox' => $m->mailbox?->address,
            'folder' => $m->folder?->label(),
            'folder_role' => $m->folder->role ?? null,
            'routed_to' => $m->routedTo?->name,
            'deadline_at' => $m->deadline_at?->toIso8601String(),
            'injection' => $m->hasFlag('prompt_injection'),
            'unread' => $m->read_at === null && $m->status !== EmailStatus::Draft,
            'flagged' => (bool) $m->is_flagged,
            'attachments_count' => $m->attachments_count ?? null,
            'date' => $m->shownAt()?->toIso8601String(),
        ];
    }
}
