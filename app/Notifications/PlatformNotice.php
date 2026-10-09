<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An in-console notice for a person: from an agent (routing, follow-ups,
 * deadlines) or from the platform (budget, approvals).
 */
class PlatformNotice extends Notification
{
    use Queueable;

    /**
     * @param  array<string, int|string|null>  $meta  what it is about (approval_id, task_id, run_id), for the centre to act on it
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $from = null,
        public string $level = 'info',
        public NotificationCategory $category = NotificationCategory::Agent,
        public array $meta = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** The kind goes in `notifications.type`, so the centre can filter by it. */
    public function databaseType(object $notifiable): string
    {
        return $this->category->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'from' => $this->from,
            'level' => $this->level,
            ...array_filter($this->meta, fn ($value) => $value !== null),
        ];
    }
}
