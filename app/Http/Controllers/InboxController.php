<?php

namespace App\Http\Controllers;

use App\Ai\Runs\AgentRunner;
use App\Email\MailboxMailer;
use App\Enums\EmailCategory;
use App\Enums\EmailStatus;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\Mailbox;
use App\Models\Tender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The triaged inbox (section 11.1, /inbox): by category and mailbox, each
 * conversation with what the agents did, and the drafts waiting for a
 * person to send.
 */
class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'category' => ['nullable', 'string'],
            'mailbox' => ['nullable', 'integer'],
            'view' => ['nullable', 'in:inbound,drafts,sent'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $view = $filters['view'] ?? 'inbound';

        $messages = EmailMessage::query()
            ->visibleTo($user)
            ->with(['mailbox:id,address', 'routedTo:id,name'])
            ->withCount('attachments')
            ->when($view === 'inbound', fn ($q) => $q->where('direction', 'inbound'))
            ->when($view === 'drafts', fn ($q) => $q->where('status', EmailStatus::Draft))
            ->when($view === 'sent', fn ($q) => $q->where('direction', 'outbound')->where('status', EmailStatus::Sent))
            ->when($filters['category'] ?? null, fn ($q, $c) => $c === 'none' ? $q->whereNull('classification') : $q->where('classification', $c))
            ->when($filters['mailbox'] ?? null, fn ($q, $m) => $q->where('mailbox_id', $m))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($w) => $w->where('subject', 'like', "%{$text}%")->orWhere('from_address', 'like', "%{$text}%")->orWhere('summary', 'like', "%{$text}%")))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (EmailMessage $m) => $this->summary($m));

        $counts = EmailMessage::query()->visibleTo($user)->where('direction', 'inbound')->where('received_at', '>=', now()->subDays(30))
            ->selectRaw('classification, count(*) as total')->groupBy('classification')->pluck('total', 'classification');

        return Inertia::render('Inbox/Index', [
            'messages' => $messages,
            'filters' => ['category' => $filters['category'] ?? null, 'mailbox' => $filters['mailbox'] ?? null, 'view' => $view, 'q' => $filters['q'] ?? ''],
            'categories' => array_map(fn (array $c) => [...$c, 'count' => (int) ($counts[$c['value']] ?? 0)], EmailCategory::options()),
            'mailboxes' => Mailbox::query()->with('agent:id,name')->orderBy('address')->get()->map(fn (Mailbox $m) => ['id' => $m->id, 'address' => $m->address, 'agent' => $m->agent?->name, 'status' => $m->status->value]),
            'drafts' => EmailMessage::query()->visibleTo($user)->where('status', EmailStatus::Draft)->count(),
        ]);
    }

    public function show(Request $request, EmailMessage $message): Response
    {
        $this->authorizeView($request, $message);

        if ($message->direction === 'inbound' && $message->read_at === null) {
            $message->forceFill(['read_at' => now()])->save();
        }

        $conversation = $message->thread_id
            ? EmailMessage::query()->where('thread_id', $message->thread_id)->with(['attachments', 'routedTo:id,name', 'department:id,name'])->orderBy('id')->get()
            : collect([$message->load(['attachments', 'routedTo:id,name', 'department:id,name'])]);

        return Inertia::render('Inbox/Show', [
            'message' => $this->summary($message),
            'conversation' => $conversation->map(fn (EmailMessage $m) => [
                ...$this->summary($m),
                'to' => $m->to ?? [],
                'cc' => $m->cc ?? [],
                'body' => $m->plainText(),
                'extracted' => $m->extracted,
                'flags' => $m->flags ?? [],
                'department' => $m->department?->name,
                'erp_lead_id' => $m->erp_lead_id,
                'agent_run_id' => $m->agent_run_id,
                'attachments' => $m->attachments->map(fn (EmailAttachment $a) => [
                    'id' => $a->id, 'filename' => $a->filename, 'mime_type' => $a->mime_type, 'size_bytes' => $a->size_bytes,
                    'downloadable' => $a->path !== null, 'ocr_status' => $a->ocr_status,
                ]),
            ]),
            'tenders' => Tender::query()->where('email_message_id', $message->id)->get(['id', 'title', 'deadline_at', 'status'])->map(fn (Tender $t) => [
                'id' => $t->id, 'title' => $t->title, 'deadline_at' => $t->deadline_at?->toIso8601String(), 'status_label' => $t->status->label(),
            ]),
            'followUps' => FollowUp::query()->where('subject_type', $message->getMorphClass())->where('subject_id', $message->id)->orderBy('due_at')->get()->map(fn (FollowUp $f) => [
                'id' => $f->id, 'title' => $f->title, 'due_at' => $f->due_at->toIso8601String(), 'done' => $f->done_at !== null,
            ]),
            'categories' => EmailCategory::options(),
        ]);
    }

    public function attachment(Request $request, EmailAttachment $attachment): StreamedResponse
    {
        $this->authorizeView($request, $attachment->message);
        abort_if($attachment->path === null, 404, 'O ficheiro já foi apagado pela retenção.');

        return Storage::disk($attachment->disk ?: (string) config('mail_ingest.disk'))->download($attachment->path, $attachment->filename);
    }

    /**
     * A person sends a draft an agent prepared, after reading and editing it.
     */
    public function send(Request $request, EmailMessage $message, MailboxMailer $mailer): RedirectResponse
    {
        $this->authorizeView($request, $message);
        abort_unless($message->status === EmailStatus::Draft, 409, 'Este email já não é um rascunho.');

        $data = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:20'],
            'to.*' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
        ]);

        $original = $message->in_reply_to ? EmailMessage::query()->where('message_id_header', $message->in_reply_to)->first() : null;

        try {
            $mailer->send($message->mailbox, $data['to'], $message->cc ?? [], $data['subject'], $data['body'], $original, draft: $message);
        } catch (RuntimeException $e) {
            return back()->with('error', 'Não foi enviado: '.$e->getMessage());
        }

        AuditLog::record($this->user($request), 'email.sent_by_user', ['email_id' => $message->id, 'to' => $data['to'], 'subject' => $data['subject']], subject: $message);

        return to_route('inbox.show', $original ?? $message)->with('success', 'Email enviado.');
    }

    public function discard(Request $request, EmailMessage $message): RedirectResponse
    {
        $this->authorizeView($request, $message);
        abort_unless($message->status === EmailStatus::Draft, 409);

        AuditLog::record($this->user($request), 'email.draft_discarded', ['email_id' => $message->id, 'subject' => $message->subject], subject: $message);
        $message->delete();

        return to_route('inbox.index', ['view' => 'drafts'])->with('success', 'Rascunho descartado.');
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
        $agent = $message->mailbox->agent;
        abort_unless($agent instanceof Agent && $agent->isActive(), 409, 'A caixa não tem um agente activo.');

        $message->forceFill(['status' => EmailStatus::Processing])->save();
        $runner->dispatch($agent, "Volta a triar o email #{$message->id} (pedido de {$this->user($request)->name}). Lê-o com email.read e classifica-o.", TriggerType::Manual, $this->user($request), $message);

        return back()->with('success', 'Enviado de novo para triagem.');
    }

    private function authorizeView(Request $request, ?EmailMessage $message): void
    {
        abort_if($message === null, 404);
        abort_unless(EmailMessage::query()->visibleTo($this->user($request))->whereKey($message->id)->exists(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(EmailMessage $m): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'from' => $m->from_name ? "{$m->from_name} <{$m->from_address}>" : $m->from_address,
            'from_address' => $m->from_address,
            'subject' => $m->subject ?: '(sem assunto)',
            'summary' => $m->summary,
            'category' => $m->classification?->value,
            'category_label' => $m->classification?->label(),
            'priority' => $m->priority,
            'status' => $m->status->value,
            'status_label' => $m->status->label(),
            'mailbox' => $m->mailbox?->address,
            'routed_to' => $m->routedTo?->name,
            'deadline_at' => $m->deadline_at?->toIso8601String(),
            'injection' => $m->hasFlag('prompt_injection'),
            'unread' => $m->direction === 'inbound' && $m->read_at === null,
            'attachments_count' => $m->attachments_count ?? null,
            'date' => ($m->received_at ?? $m->sent_at ?? $m->created_at)->toIso8601String(),
        ];
    }
}
