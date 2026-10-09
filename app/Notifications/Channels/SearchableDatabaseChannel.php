<?php

namespace App\Notifications\Channels;

use App\Notifications\PlatformNotice;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The database channel plus `notifications.search`: title, text and sender in
 * plain lower case, so the centre's search finds accented words (the JSON in
 * `data` escapes them).
 */
class SearchableDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification)
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof PlatformNotice) {
            $payload['search'] = self::searchText($notification->toArray($notifiable));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function searchText(array $data): string
    {
        $text = implode(' ', array_filter([$data['title'] ?? null, $data['body'] ?? null, $data['from'] ?? null], 'is_string'));

        return mb_substr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)))), 0, 2000);
    }
}
