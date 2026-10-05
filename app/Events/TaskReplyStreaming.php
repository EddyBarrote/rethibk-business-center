<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use App\Models\AgentRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The agent's answer in a task thread while it is being written: the text so
 * far, and the tool it is using, if any. The final message replaces it.
 */
final class TaskReplyStreaming implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    public function __construct(public AgentRun $run, public string $text, public ?string $tool = null) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->run->tenant_id}.task.{$this->run->task_id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Pusher messages are capped (10 KB by default): send the tail of a long answer.
        return ['run_id' => $this->run->id, 'text' => mb_substr($this->text, -6000), 'tool' => $this->tool];
    }
}
