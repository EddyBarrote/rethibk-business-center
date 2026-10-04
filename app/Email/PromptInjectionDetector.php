<?php

namespace App\Email;

use Illuminate\Support\Str;

/**
 * Flags external text that reads like an order to an AI agent (section 9.4).
 * It never decides anything: the text is still fenced as untrusted data, and
 * the flag tells the agent and the console that someone tried.
 */
final class PromptInjectionDetector
{
    /** @var list<string> */
    private const PATTERNS = [
        // Portuguese
        '/ignor(a|e|em|ar)\s+(as\s+|todas\s+as\s+)?(tuas\s+|suas\s+)?(instru[cç][oõ]es|regras|ordens)/iu',
        '/esquece\s+(as\s+|todas\s+as\s+)?(tuas\s+)?(instru[cç][oõ]es|regras)/iu',
        '/(és|é)\s+agora\s+um/iu',
        '/(novas?|nova)\s+instru[cç](ão|ões)\s*(para\s+o\s+)?(agente|assistente|ia)/iu',
        '/(agente|assistente)\s*(de\s+ia)?\s*[,:]\s*(transfere|paga|envia|aprova|apaga)/iu',
        '/prompt\s+(de\s+)?sistema/iu',
        // English
        '/ignore\s+(all\s+|any\s+)?(previous|prior|above|your)\s+(instructions|rules|prompts?)/i',
        '/disregard\s+(all\s+|any\s+)?(previous|prior|your)\s+(instructions|rules)/i',
        '/you\s+are\s+now\s+(a|an|the)\b/i',
        '/system\s+prompt/i',
        '/(as\s+an?\s+)?(ai|assistant|agent)[,:]?\s+(please\s+)?(transfer|pay|wire|approve|delete|forward)\b/i',
    ];

    public function detect(string ...$texts): bool
    {
        $text = Str::limit(implode("\n", $texts), 100_000, '');

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
