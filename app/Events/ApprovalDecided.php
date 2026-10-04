<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use App\Models\Approval;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ApprovalDecided implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    public function __construct(public Approval $approval) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->approval->tenant_id}.approvals"),
            new PrivateChannel("tenant.{$this->approval->tenant_id}.run.{$this->approval->agent_run_id}"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'approval_id' => $this->approval->id,
            'status' => $this->approval->status->value,
            'execution_status' => $this->approval->execution_status->value,
        ];
    }
}
