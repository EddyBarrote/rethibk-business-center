<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use App\Models\BudgetEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class BudgetThresholdReached implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    public function __construct(public BudgetEvent $event) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->event->tenant_id}.agents")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'scope' => $this->event->scope,
            'agent_id' => $this->event->agent_id,
            'threshold' => $this->event->threshold,
            'spent_usd' => $this->event->spent_usd,
            'cap_usd' => $this->event->cap_usd,
        ];
    }
}
