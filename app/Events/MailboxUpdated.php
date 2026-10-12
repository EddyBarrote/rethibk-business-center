<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A mailbox's unread count changed: email arrived, or was read or marked
 * unread. Carries only the mailbox; each person reloads their own count.
 */
final class MailboxUpdated implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    public function __construct(public int $tenantId, public int $mailboxId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.mail")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['mailbox_id' => $this->mailboxId];
    }
}
