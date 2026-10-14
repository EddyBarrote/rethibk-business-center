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
        return array_column($this->pieces($this->normalise($text)), 1);
    }

    /**
     * Chunks of a file's text page by page, read from the [Página N] lines
     * the repository writes (FileReader): a chunk never spans two pages, so
     * a match says where it is, and each chunk starts with the document's
     * title for context. Text without pages is chunked as a whole.
     *
     * @return list<array{text: string, body: string, page: int|null}> text is what is embedded (with the title), body what is kept
     */
    public function splitWithPages(string $text, string $title = ''): array
    {
        $parts = preg_split('/^\[P[áa]gina (\d+)\]\s*$/mu', $this->normalise($text), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $prefix = trim($title) === '' ? '' : trim($title)."\n\n";

        if (count($parts) < 3) {
            $pieces = $this->pieces($this->normalise($text));

            // An item with only a title (a file with no text) is still found by it.
            return $pieces === [] && trim($title) !== ''
                ? [['text' => trim($title), 'body' => trim($title), 'page' => null]]
                : array_map(fn (array $piece) => ['text' => $prefix.$piece[1], 'body' => $piece[1], 'page' => null], $pieces);
        }

        $chunks = [];
        $lead = trim((string) array_shift($parts));

        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $page = (int) $parts[$i];
            $body = trim(($i === 0 && $lead !== '' ? $lead."\n\n" : '').$parts[$i + 1]);

            foreach ($this->pieces($body) as [, $piece]) {
                $chunks[] = ['text' => "{$prefix}[Página {$page}]\n{$piece}", 'body' => $piece, 'page' => $page];
            }
        }

        return $chunks;
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/[ \t]+/', ' ', $text));
    }

    /**
     * @return list<array{0: int, 1: string}> start offset and text of each chunk
     */
    private function pieces(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $this->chunkChars) {
            return [[0, $text]];
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

            $chunks[] = [$start, trim($chunk)];

            if ($start + $this->chunkChars >= $length) {
                break;
            }
        }

        return $chunks;
    }
}
