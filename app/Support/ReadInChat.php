<?php

namespace App\Support;

use App\Enums\NotificationCategory;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a person has read in a conversation leaves the notifications centre
 * (docs/DECISOES.md, "Respostas lidas saem das notificações"): the replies
 * there go away, and anything else about that conversation is marked read.
 */
final class ReadInChat
{
    /** How many notices went away or were marked read. */
    public static function clear(User $user, Task $task): int
    {
        $about = fn (Builder $q) => $q->where('data->task_id', $task->id)->orWhere('data->url', "/tasks/{$task->id}");

        $gone = $user->notifications()->where($about)->where('type', NotificationCategory::Conversation->value)->delete();
        $read = $user->unreadNotifications()->where($about)->update(['read_at' => now()]);

        return (int) $gone + $read;
    }
}
