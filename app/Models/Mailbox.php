<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\MailboxStatus;
use App\Enums\Permission;
use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property bool $save_sent_copy
 * @property Carbon|null $folders_synced_at
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
    'imap_encryption', 'imap_folder', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'status', 'save_sent_copy',
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

    /**
     * What people see: an active mailbox whose last connection failed shows
     * as "Com erro". The stored status stays active, so the next fetch still
     * tries and clears the error once the connection works again.
     */
    public function shownStatus(): MailboxStatus
    {
        return $this->status === MailboxStatus::Active && filled($this->last_error) ? MailboxStatus::Error : $this->status;
    }

    /**
     * The last connection error in plain Portuguese; the server's own text
     * stays in last_error for whoever needs the detail.
     */
    public function errorMessage(): ?string
    {
        if (blank($this->last_error)) {
            return null;
        }

        $error = (string) $this->last_error;
        $side = str_starts_with($error, 'IMAP:') ? 'receber' : 'enviar';
        $reason = match (true) {
            (bool) preg_match('/AUTHENTICATIONFAILED|authenticat|credentials|login failed|password|\b535\b|\b534\b/i', $error) => 'o servidor recusou o utilizador ou a palavra-passe. Confirme os dados de acesso e guarde de novo.',
            (bool) preg_match('/certificate|ssl|tls|handshake/i', $error) => 'a ligação segura falhou. Confirme o tipo de segurança (SSL ou TLS) e a porta.',
            (bool) preg_match('/getaddrinfo|resolve|name or service|no such host/i', $error) => 'não foi possível encontrar o servidor. Confirme o endereço do servidor.',
            (bool) preg_match('/refused|timed out|timeout|unreachable|connection could not be established|failed to connect/i', $error) => 'não foi possível ligar ao servidor. Confirme o servidor e a porta.',
            default => 'a última ligação ao servidor falhou.',
        };

        return "Ao {$side} email, {$reason}";
    }

    public function canSend(): bool
    {
        return $this->status === MailboxStatus::Active && filled($this->smtp_host) && filled($this->smtp_port);
    }

    /**
     * @return HasMany<EmailFolder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(EmailFolder::class);
    }

    /**
     * Who may write from it in the email client: the owners of a person's
     * mailbox; for an agent's mailbox (the triage), whoever reads triage email.
     */
    public function canBeWrittenFromBy(User $user): bool
    {
        return $this->isPersonal() ? $this->isOwnedBy($user) : $user->hasPermission(Permission::ReadTriageEmails);
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
            'save_sent_copy' => 'boolean',
            'folders_synced_at' => 'datetime',
            'smtp_port' => 'integer',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }
}
