<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\EmailCategory;
use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Support\TextExtractor;
use Database\Factories\EmailMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An email in or out of an agent's mailbox (section 5.5).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $mailbox_id
 * @property string $direction
 * @property string|null $message_id_header
 * @property string|null $in_reply_to
 * @property string|null $references
 * @property int|null $thread_id
 * @property string|null $from_address
 * @property string|null $from_name
 * @property list<string>|null $to
 * @property list<string>|null $cc
 * @property string|null $reply_to
 * @property string|null $subject
 * @property string|null $text_body
 * @property string|null $html_body
 * @property string|null $raw_path
 * @property EmailCategory|null $classification
 * @property float|null $classification_confidence
 * @property string|null $priority
 * @property string|null $summary
 * @property array<string, mixed>|null $extracted
 * @property list<string>|null $flags
 * @property string|null $erp_lead_id
 * @property int|null $routed_to_user_id
 * @property int|null $department_id
 * @property Carbon|null $deadline_at
 * @property EmailStatus $status
 * @property int|null $agent_run_id
 * @property Carbon|null $received_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $read_at
 * @property string|null $dedup_hash
 * @property Carbon $created_at
 */
#[Fillable([
    'mailbox_id', 'direction', 'provider_message_id', 'message_id_header', 'in_reply_to', 'references', 'thread_id',
    'from_address', 'from_name', 'to', 'cc', 'bcc', 'reply_to', 'subject', 'text_body', 'html_body', 'raw_path',
    'classification', 'classification_confidence', 'priority', 'summary', 'extracted', 'flags', 'erp_lead_id',
    'routed_to_user_id', 'department_id', 'deadline_at', 'status', 'agent_run_id', 'received_at', 'sent_at', 'read_at', 'dedup_hash',
])]
#[Hidden(['html_body'])]
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * What a person reads (docs/DECISOES.md, "Caixas de email por pessoa"):
     * the mailboxes they own; the email of the agents' mailboxes (triage)
     * if the matrix lets them; and a triage email routed to them. Nobody
     * reads someone else's personal mailbox, not even administrators.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $agentMailboxes = Mailbox::query()->where('kind', Mailbox::AGENT)->select('id');

        $query->where(fn (Builder $q) => $q
            ->whereIn('mailbox_id', Mailbox::query()->ownedBy($user)->select('id'))
            ->orWhere(fn (Builder $w) => $w->where('routed_to_user_id', $user->id)->whereIn('mailbox_id', $agentMailboxes))
            ->when($user->hasPermission(Permission::ReadTriageEmails), fn (Builder $w) => $w->orWhereIn('mailbox_id', $agentMailboxes)));
    }

    /**
     * What an agent reads: the triage email, and the personal mailboxes whose
     * owners let it read them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function readableBy(Builder $query, Agent $agent): void
    {
        $query->whereIn('mailbox_id', Mailbox::query()->readableBy($agent)->select('id'));
    }

    /**
     * Email of the agents' mailboxes, the company's triage; never a person's mailbox.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function triage(Builder $query): void
    {
        $query->whereIn('mailbox_id', Mailbox::query()->where('kind', Mailbox::AGENT)->select('id'));
    }

    /**
     * The body as plain text, whichever part the sender used.
     */
    public function plainText(): string
    {
        return trim($this->text_body ?: TextExtractor::htmlToText((string) $this->html_body));
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags ?? [], true);
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * @return BelongsTo<EmailThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'thread_id');
    }

    /**
     * @return HasMany<EmailAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function routedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'routed_to_user_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'extracted' => 'array',
            'flags' => 'array',
            'classification' => EmailCategory::class,
            'classification_confidence' => 'float',
            'status' => EmailStatus::class,
            'deadline_at' => 'datetime',
            'received_at' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
