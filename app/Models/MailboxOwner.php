<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\MailboxOwnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person who owns a personal or shared mailbox (docs/DECISOES.md, "Caixas
 * de email por pessoa"): they read it, send from it and say which agents read it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $mailbox_id
 * @property int $user_id
 */
#[Fillable(['mailbox_id', 'user_id'])]
class MailboxOwner extends Model
{
    /** @use HasFactory<MailboxOwnerFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
