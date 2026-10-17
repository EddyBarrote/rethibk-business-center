<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\EmailFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A folder of a mailbox (docs/DECISOES.md, "Email: um cliente de email na
 * plataforma"), as the IMAP server lists it. A mailbox without IMAP gets the
 * standard ones locally.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $mailbox_id
 * @property string $path
 * @property string $name
 * @property string|null $role
 * @property string|null $delimiter
 * @property bool $local
 * @property int|null $uidvalidity
 * @property int|null $last_uid
 * @property int|null $first_uid
 * @property bool $has_older
 * @property int $total
 * @property int $unread
 * @property Carbon|null $synced_at
 */
#[Fillable(['mailbox_id', 'path', 'name', 'role', 'delimiter', 'local', 'uidvalidity', 'last_uid', 'first_uid', 'has_older', 'total', 'unread', 'synced_at'])]
class EmailFolder extends Model
{
    /** @use HasFactory<EmailFolderFactory> */
    use BelongsToTenant, HasFactory;

    public const INBOX = 'inbox';

    public const DRAFTS = 'drafts';

    public const SENT = 'sent';

    public const ARCHIVE = 'archive';

    public const JUNK = 'junk';

    public const TRASH = 'trash';

    /** The standard folders in the order people expect them, with their Portuguese names. */
    public const ROLES = [
        self::INBOX => 'Entrada',
        self::DRAFTS => 'Rascunhos',
        self::SENT => 'Enviados',
        self::ARCHIVE => 'Arquivo',
        self::JUNK => 'Spam',
        self::TRASH => 'Lixo',
    ];

    public function label(): string
    {
        return $this->role !== null ? self::ROLES[$this->role] : $this->name;
    }

    public function position(): int
    {
        $index = array_search($this->role, array_keys(self::ROLES), true);

        return $index === false ? 100 : $index;
    }

    public function onServer(): bool
    {
        return ! $this->local;
    }

    /**
     * The messages people see in this folder. The standard folders also show
     * what has no folder yet: a draft or an email being sent before the
     * server confirms where it is.
     *
     * @return Builder<EmailMessage>
     */
    public function messages(): Builder
    {
        $query = EmailMessage::query()->where('mailbox_id', $this->mailbox_id)->whereNull('removed_at');

        $unfiled = match ($this->role) {
            self::INBOX => fn (Builder $q) => $q->where('direction', 'inbound'),
            self::DRAFTS => fn (Builder $q) => $q->where('status', 'draft'),
            self::SENT => fn (Builder $q) => $q->where('direction', 'outbound')->where('status', '!=', 'draft'),
            default => null,
        };

        // An agent's proposal is not in the Rascunhos until a person keeps it (docs/DECISOES.md, "Rascunho só quando a pessoa escolhe").
        if ($this->role === self::DRAFTS) {
            $query->notProposals();
        }

        return $query->where(fn (Builder $q) => $q->where('email_folder_id', $this->id)
            ->when($unfiled, fn (Builder $w) => $w->orWhere(fn (Builder $u) => $u->whereNull('email_folder_id')->where($unfiled))));
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function filed(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    protected function casts(): array
    {
        return [
            'local' => 'boolean',
            'has_older' => 'boolean',
            'uidvalidity' => 'integer',
            'last_uid' => 'integer',
            'first_uid' => 'integer',
            'total' => 'integer',
            'unread' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
