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
     * Chunks with the page each one starts on, read from the [Página N]
     * lines the repository writes in a file's text (FileReader); null when
     * the text has no pages.
     *
     * @return list<array{text: string, page: int|null}>
     */
    public function splitWithPages(string $text): array
    {
        $text = $this->normalise($text);
        preg_match_all('/^\[P[áa]gina (\d+)\]/mu', $text, $markers, PREG_OFFSET_CAPTURE);
        $starts = [];

        foreach ($markers[0] as $index => [$marker, $byte]) {
            $starts[] = [mb_strlen(substr($text, 0, $byte)), (int) $markers[1][$index][0]];
        }

        return array_map(function (array $piece) use ($starts) {
            $page = $starts[0][1] ?? null;

            foreach ($starts as [$offset, $number]) {
                if ($offset > $piece[0]) {
                    break;
                }

                $page = $number;
            }

            return ['text' => $piece[1], 'page' => $page];
        }, $this->pieces($text));
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
