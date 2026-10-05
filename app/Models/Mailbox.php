<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\MailboxStatus;
use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A mailbox (section 9). Credentials are encrypted. It belongs to an agent
 * (the Triagem's, an area agent's) or to one or more people, who say which
 * agents read it (docs/DECISOES.md, "Caixas de email por pessoa").
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property string $kind
 * @property string $address
 * @property string $display_name
 * @property string $inbound_provider
 * @property string|null $imap_host
 * @property int|null $imap_port
 * @property string|null $imap_username
 * @property string|null $imap_password
 * @property string|null $imap_encryption
 * @property string $imap_folder
 * @property int|null $imap_last_uid
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property string|null $smtp_encryption
 * @property MailboxStatus $status
 * @property string|null $last_error
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_outbound_at
 */
#[Fillable([
    'agent_id', 'kind', 'address', 'display_name', 'inbound_provider', 'imap_host', 'imap_port', 'imap_username', 'imap_password',
    'imap_encryption', 'imap_folder', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'status',
])]
#[Hidden(['inbound_secret', 'imap_password', 'smtp_password'])]
class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use BelongsToTenant, HasFactory;

    /** An agent's own mailbox; its email is triage email. */
    public const AGENT = 'agent';

    /** A person's mailbox, personal or shared by several people. */
    public const PERSON = 'person';

    public function isPersonal(): bool
    {
        return $this->kind === self::PERSON;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->isPersonal() && MailboxOwner::query()->where('mailbox_id', $this->id)->where('user_id', $user->id)->exists();
    }

    /**
     * Mailboxes a person owns.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('kind', self::PERSON)
            ->whereIn('id', MailboxOwner::query()->where('user_id', $user->id)->select('mailbox_id'));
    }

    /**
     * Mailboxes an agent reads: the agents' mailboxes, which are the company's
     * triage, and the personal ones whose owners let it read them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function readableBy(Builder $query, Agent $agent): void
    {
        $query->where(fn (Builder $q) => $q->where('kind', self::AGENT)
            ->orWhereIn('id', MailboxReader::query()->where('agent_id', $agent->id)->select('mailbox_id')));
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'mailbox_owners')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Agent, $this>
     */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'mailbox_readers')->withPivot('processes_new')->withTimestamps();
    }

    /**
     * The agent that looks at new email as it arrives: the mailbox's own agent,
     * or for a person's mailbox the reader its owners chose.
     */
    public function processor(): ?Agent
    {
        if (! $this->isPersonal()) {
            return $this->agent;
        }

        $reader = MailboxReader::query()->where('mailbox_id', $this->id)->where('processes_new', true)->with('agent')->first();

        return $reader?->agent;
    }

    public function canSend(): bool
    {
        return $this->status === MailboxStatus::Active && filled($this->smtp_host) && filled($this->smtp_port);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    protected function casts(): array
    {
        return [
            'status' => MailboxStatus::class,
            'kind' => 'string',
            'inbound_secret' => 'encrypted',
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'imap_port' => 'integer',
            'imap_last_uid' => 'integer',
            'smtp_port' => 'integer',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }
}
