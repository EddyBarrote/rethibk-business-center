<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\MailboxReaderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent the owners let read a person's mailbox. It reads, summarises,
 * creates tasks and prepares drafts; it never sends from it. The one that
 * processes new email looks at each message as it arrives.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $mailbox_id
 * @property int $agent_id
 * @property bool $processes_new
 */
#[Fillable(['mailbox_id', 'agent_id', 'processes_new'])]
class MailboxReader extends Model
{
    /** @use HasFactory<MailboxReaderFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
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
        return ['processes_new' => 'boolean'];
    }
}
