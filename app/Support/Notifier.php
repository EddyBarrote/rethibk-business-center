<?php

namespace App\Support;

use App\Events\UserNotified;
use App\Models\User;
use App\Notifications\PlatformNotice;

/**
 * Tells a person something in the console, live when possible.
 */
final class Notifier
{
    public function notify(User $user, string $title, string $body, ?string $url = null, ?string $from = null, string $level = 'info'): void
    {
        $notice = new PlatformNotice($title, $body, $url, $from, $level);
        $user->notify($notice);

        UserNotified::live($user->tenant_id, $user->id, $notice->toArray($user));
    }
}
