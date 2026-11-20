<?php

namespace App\Events;

use App\Ai\Autonomy\PeopleData;
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
        // The channel has no single viewer: a people-data result never goes out in full.
        return ['step' => PeopleData::present($this->step, null)];
    }
}
