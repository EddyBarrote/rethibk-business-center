<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use App\Models\AgentRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The run ended, or paused waiting for a human (status awaiting_approval).
 */
final class AgentRunFinished implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    public function __construct(public AgentRun $run) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->run->tenant_id}.agents"),
            new PrivateChannel("tenant.{$this->run->tenant_id}.run.{$this->run->id}"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->run->id,
            'agent_id' => $this->run->agent_id,
            'status' => $this->run->status->value,
            'cost_usd' => $this->run->cost_usd,
            'error' => $this->run->error,
        ];
    }
}
