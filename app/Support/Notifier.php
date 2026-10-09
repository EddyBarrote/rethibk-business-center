<?php

namespace App\Support;

use App\Enums\NotificationCategory;
use App\Events\UserNotified;
use App\Mail\NoticeMail;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PlatformNotice;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells a person something in the console, live when possible, and by email
 * for the kinds they asked for (NotificationPreferences).
 */
final class Notifier
{
    /**
     * @param  array<string, int|string|null>  $meta  what it is about (approval_id, task_id, run_id)
     */
    public function notify(
        User $user,
        string $title,
        string $body,
        ?string $url = null,
        ?string $from = null,
        string $level = 'info',
        NotificationCategory $category = NotificationCategory::Agent,
        array $meta = [],
    ): void {
        $prefs = NotificationPreferences::for($user, $category);
        $notice = new PlatformNotice($title, $body, $url, $from, $level, $category, $meta);
        $link = $url;

        if ($prefs['app']) {
            // Laravel sends a copy; an id set here is the one stored, so the alert and the email can link to it.
            $notice->id = (string) Str::uuid();
            $user->notify($notice);
            $link = "/notifications/{$notice->id}";

            UserNotified::live($user->tenant_id, $user->id, [
                ...$notice->toArray($user),
                'id' => $notice->id,
                'category' => $category->value,
                'toast' => $prefs['toast'],
                'created_at' => now()->toIso8601String(),
            ]);
        }

        if ($prefs['email'] && $user->email !== '') {
            $this->email($user, $notice, $link);
        }
    }

    /** An email that cannot go out never stops what the agent or watcher was doing. */
    private function email(User $user, PlatformNotice $notice, ?string $link): void
    {
        $base = rtrim((string) (Tenant::current() ?? $user->tenant)?->url(), '/');

        try {
            Mail::to($user->email)->send(new NoticeMail(
                $notice->title,
                strip_tags($notice->body),
                $notice->from,
                $link !== null && str_starts_with($link, '/') ? $base.$link : null,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
