<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use App\Models\Approval;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ApprovalRequested implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    /**
     * @param  list<int>  $notifyUserIds
     */
    public function __construct(public Approval $approval, public array $notifyUserIds = []) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $tenant = $this->approval->tenant_id;

        return [
            new PrivateChannel("tenant.{$tenant}.approvals"),
            ...array_map(fn (int $id) => new PrivateChannel("tenant.{$tenant}.user.{$id}"), $this->notifyUserIds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'approval_id' => $this->approval->id,
            'agent_id' => $this->approval->agent_id,
            'run_id' => $this->approval->agent_run_id,
            'summary' => $this->approval->action_summary,
        ];
    }
}
