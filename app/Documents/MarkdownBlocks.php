<?php

namespace App\Documents;

/**
 * A small Markdown reader for the formats that are not flowing text
 * (slides and spreadsheets): headings, paragraphs, lists, tables and rules.
 * Word and PDF go through the full CommonMark converter instead.
 *
 * @phpstan-type Block array{type: 'heading', level: int, text: string}|array{type: 'paragraph', text: string}|array{type: 'list', ordered: bool, items: list<string>}|array{type: 'table', header: list<string>, rows: list<list<string>>}|array{type: 'rule'}
 */
final class MarkdownBlocks
{
    /**
     * @return list<Block>
     */
    public static function parse(string $markdown): array
    {
        $lines = preg_split('/\R/u', str_replace("\t", '    ', $markdown)) ?: [];
        $blocks = [];
        $paragraph = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            $trimmed = ltrim($line);

            if ($trimmed === '') {
                self::flush($paragraph, $blocks);

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/u', $trimmed, $m)) {
                self::flush($paragraph, $blocks);
                $blocks[] = ['type' => 'heading', 'level' => strlen($m[1]), 'text' => self::inline(rtrim($m[2], '# '))];

                continue;
            }

            if (preg_match('/^([-*_])(\s*\1){2,}$/u', $trimmed)) {
                self::flush($paragraph, $blocks);
                $blocks[] = ['type' => 'rule'];

                continue;
            }

            if (str_starts_with($trimmed, '|')) {
                self::flush($paragraph, $blocks);
                $rows = [];

                while ($i < $count && str_starts_with(ltrim($lines[$i]), '|')) {
                    $cells = self::cells(trim($lines[$i]));

                    if (! self::isSeparator($cells)) {
                        $rows[] = $cells;
                    }

                    $i++;
                }

                $i--;
                $header = array_shift($rows) ?? [];
                $blocks[] = ['type' => 'table', 'header' => $header, 'rows' => $rows];

                continue;
            }

            if (preg_match('/^([-*+]|\d+[.)])\s+(.*)$/u', $trimmed, $m)) {
                self::flush($paragraph, $blocks);
                $ordered = ctype_digit(substr($m[1], 0, 1));
                $items = [];

                while ($i < $count && preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/u', $lines[$i], $item)) {
                    $indent = strlen($lines[$i]) - strlen(ltrim($lines[$i]));
                    $items[] = ($indent >= 2 ? '– ' : '').self::inline($item[2]);
                    $i++;
                }

                $i--;
                $blocks[] = ['type' => 'list', 'ordered' => $ordered, 'items' => $items];

                continue;
            }

            $paragraph[] = $trimmed;
        }

        self::flush($paragraph, $blocks);

        return $blocks;
    }

    /**
     * @param  list<string>  $paragraph
     * @param  list<Block>  $blocks
     */
    private static function flush(array &$paragraph, array &$blocks): void
    {
        if ($paragraph !== []) {
            $blocks[] = ['type' => 'paragraph', 'text' => self::inline(implode(' ', $paragraph))];
            $paragraph = [];
        }
    }

    /**
     * Plain text of inline Markdown: emphasis, code and links.
     */
    public static function inline(string $text): string
    {
        $text = (string) preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text);
        $text = (string) preg_replace('/(\*\*|__)(.+?)\1/u', '$2', $text);
        $text = (string) preg_replace('/(?<![\w*])([*_])(?!\s)(.+?)(?<!\s)\1(?![\w*])/u', '$2', $text);
        $text = str_replace('`', '', $text);

        return trim($text);
    }

    /**
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $line = trim($line, '|');

        return array_map(fn (string $cell) => self::inline(trim(str_replace('\|', '|', $cell))), preg_split('/(?<!\\\\)\|/u', $line) ?: []);
    }

    /**
     * @param  list<string>  $cells
     */
    private static function isSeparator(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (! preg_match('/^:?-{2,}:?$/', $cell)) {
                return false;
            }
        }

        return $cells !== [];
    }
}
