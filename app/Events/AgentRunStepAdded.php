<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use App\Models\AgentRunStep;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class AgentRunStepAdded implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    public function __construct(public AgentRunStep $step) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->step->tenant_id}.run.{$this->step->agent_run_id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['step' => $this->step->toBroadcast()];
    }
}
