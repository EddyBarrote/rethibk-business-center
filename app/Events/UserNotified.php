<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class UserNotified implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    /**
     * @param  array<string, mixed>  $notice
     */
    public function __construct(public int $tenantId, public int $userId, public array $notice) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.user.{$this->userId}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->notice;
    }
}
