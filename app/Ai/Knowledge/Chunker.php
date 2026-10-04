<?php

namespace App\Ai\Knowledge;

/**
 * Splits text into chunks of about 800 tokens with 15% overlap (section
 * 13.2), using ~4 characters per token and breaking on whitespace.
 */
final class Chunker
{
    public function __construct(
        private readonly int $chunkChars = 3200,
        private readonly float $overlap = 0.15,
    ) {}

    /**
     * @return list<string>
     */
    public function split(string $text): array
    {
        $text = trim((string) preg_replace('/[ \t]+/', ' ', $text));

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $this->chunkChars) {
            return [$text];
        }

        $chunks = [];
        $step = (int) floor($this->chunkChars * (1 - $this->overlap));
        $length = mb_strlen($text);

        for ($start = 0; $start < $length; $start += $step) {
            $chunk = mb_substr($text, $start, $this->chunkChars);

            if ($start + $this->chunkChars < $length) {
                $break = mb_strrpos($chunk, ' ');
                $chunk = $break !== false && $break > $this->chunkChars / 2 ? mb_substr($chunk, 0, $break) : $chunk;
            }

            $chunks[] = trim($chunk);

            if ($start + $this->chunkChars >= $length) {
                break;
            }
        }

        return $chunks;
    }
}
