<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\MailboxStatus;
use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An agent's mailbox (section 9). Credentials are encrypted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property string $address
 * @property string $display_name
 * @property string $inbound_provider
 * @property string|null $imap_host
 * @property int|null $imap_port
 * @property string|null $imap_username
 * @property string|null $imap_password
 * @property string|null $imap_encryption
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
    'agent_id', 'address', 'display_name', 'inbound_provider', 'imap_host', 'imap_port', 'imap_username', 'imap_password',
    'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'status',
])]
#[Hidden(['inbound_secret', 'imap_password', 'smtp_password'])]
class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use BelongsToTenant, HasFactory;

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
            'inbound_secret' => 'encrypted',
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }
}
