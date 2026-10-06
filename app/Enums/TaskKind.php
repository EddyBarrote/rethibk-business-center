<?php

namespace App\Enums;

/**
 * A task has a workflow (status, review, done); a chat is an open-ended
 * conversation with an agent used as an assistant.
 */
enum TaskKind: string
{
    case Task = 'task';
    case Chat = 'chat';

    public function label(): string
    {
        return match ($this) {
            self::Task => 'Tarefa',
            self::Chat => 'Conversa',
        };
    }
}
