<?php

namespace App\Support;

use App\Enums\NotificationCategory;
use App\Models\User;

/**
 * How a person wants to hear about each kind of notification: in the centre,
 * as an alert on the screen, by email. What they never changed follows
 * NotificationCategory::defaults().
 */
final class NotificationPreferences
{
    public const CHANNELS = ['app', 'toast', 'email'];

    /**
     * @return array{app: bool, toast: bool, email: bool}
     */
    public static function for(User $user, NotificationCategory $category): array
    {
        $saved = $user->notification_preferences[$category->value] ?? [];
        $prefs = $category->defaults();

        foreach (self::CHANNELS as $channel) {
            if (is_bool($saved[$channel] ?? null)) {
                $prefs[$channel] = $saved[$channel];
            }
        }

        if ($category->required()) {
            $prefs['app'] = true;
        }

        // An alert on the screen opens a notification in the centre.
        $prefs['toast'] = $prefs['toast'] && $prefs['app'];

        return $prefs;
    }

    /**
     * @return array<string, array{app: bool, toast: bool, email: bool}>
     */
    public static function all(User $user): array
    {
        $all = [];

        foreach (NotificationCategory::cases() as $category) {
            $all[$category->value] = self::for($user, $category);
        }

        return $all;
    }
}
