<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsLive;
use App\Models\AgentRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The agent's answer in a task thread while it is being written: the text so
 * far, what it is doing (the tool in use, in Portuguese) and the summary of its
 * reasoning, live while it thinks. The final message replaces it.
 */
final class TaskReplyStreaming implements ShouldBroadcastNow
{
    use BroadcastsLive, Dispatchable;

    public function __construct(
        public AgentRun $run,
        public string $text,
        public ?string $tool = null,
        public string $reasoning = '',
        public bool $thinking = false,
        public int $thoughtMs = 0,
    ) {}

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
        // Pusher messages are capped (10 KB by default): send the tail of a long answer and reasoning.
        return [
            'run_id' => $this->run->id,
            'text' => mb_substr($this->text, -5000),
            'tool' => $this->tool,
            'reasoning' => mb_substr($this->reasoning, -2000),
            'thinking' => $this->thinking,
            'thought_ms' => $this->thoughtMs,
        ];
    }
}
