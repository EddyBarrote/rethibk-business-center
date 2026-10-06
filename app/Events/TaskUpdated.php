<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use App\Models\Task;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened in a task's thread: a message, a reply or a status change.
 */
final class TaskUpdated implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    public function __construct(public Task $task) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->task->tenant_id}.tasks"),
            new PrivateChannel("tenant.{$this->task->tenant_id}.task.{$this->task->id}"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['task_id' => $this->task->id, 'status' => $this->task->status->value];
    }
}
