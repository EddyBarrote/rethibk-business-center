<?php

namespace App\Support;

use App\Models\User;

/**
 * A person's memory switches (A minha conta › Memória, 12.10.2026), the same
 * three as claude.ai. All on until the person turns one off.
 *
 * - search_chats: the agent may search the person's past conversations with it (memory.search_chats).
 * - generate: the agent keeps and uses a memory of the person; off, it neither updates nor reads it.
 * - sensitive: the memory may hold sensitive subjects (health, religion, politics, family, money).
 */
final class MemoryPreferences
{
    public const KEYS = ['search_chats', 'generate', 'sensitive'];

    /**
     * @return array{search_chats: bool, generate: bool, sensitive: bool}
     */
    public static function for(?User $user): array
    {
        $saved = $user->memory_preferences ?? [];
        $prefs = ['search_chats' => true, 'generate' => true, 'sensitive' => true];

        foreach (self::KEYS as $key) {
            if (is_bool($saved[$key] ?? null)) {
                $prefs[$key] = $saved[$key];
            }
        }

        return $prefs;
    }

    public static function allows(?User $user, string $key): bool
    {
        return self::for($user)[$key] ?? true;
    }
}
