<?php

namespace App\Support;

/**
 * A line diff (Myers), enough to compare two versions of a skill's text.
 * Common lines at the start and the end are set aside first; when what is
 * left is too long (MAX_LINES) or differs too much (MAX_EDITS lines added or
 * removed), the middle is shown as removed and then added instead of
 * searching for the shortest edit, which keeps time and memory bounded.
 */
final class LineDiff
{
    public const SAME = 'same';

    public const ADDED = 'added';

    public const REMOVED = 'removed';

    private const MAX_EDITS = 800;

    private const MAX_LINES = 10000;

    /**
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    public static function lines(string $old, string $new): array
    {
        $a = self::split($old);
        $b = self::split($new);

        $start = 0;
        while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) {
            $start++;
        }

        $end = 0;
        while ($end < count($a) - $start && $end < count($b) - $start && $a[count($a) - 1 - $end] === $b[count($b) - 1 - $end]) {
            $end++;
        }

        $middle = self::myers(array_slice($a, $start, count($a) - $start - $end), array_slice($b, $start, count($b) - $start - $end));

        return [
            ...array_map(fn (string $line) => ['type' => self::SAME, 'text' => $line], array_slice($a, 0, $start)),
            ...$middle,
            ...array_map(fn (string $line) => ['type' => self::SAME, 'text' => $line], array_slice($a, count($a) - $end)),
        ];
    }

    /**
     * @return list<string>
     */
    private static function split(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return $text === '' ? [] : explode("\n", rtrim($text, "\n"));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    private static function myers(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0 || $n + $m > self::MAX_LINES) {
            return self::replaced($a, $b);
        }

        $v = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= min($n + $m, self::MAX_EDITS); $d++) {
            $trace[] = $v;

            for ($k = -$d; $k <= $d; $k += 2) {
                $x = $k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1)) ? ($v[$k + 1] ?? 0) : ($v[$k - 1] ?? 0) + 1;
                $y = $x - $k;

                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }

                $v[$k] = $x;

                if ($x >= $n && $y >= $m) {
                    return self::backtrack($a, $b, $trace);
                }
            }
        }

        return self::replaced($a, $b);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @param  list<array<int, int>>  $trace
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    private static function backtrack(array $a, array $b, array $trace): array
    {
        $x = count($a);
        $y = count($b);
        $lines = [];

        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            $previousK = $k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1)) ? $k + 1 : $k - 1;
            $previousX = $v[$previousK] ?? 0;
            $previousY = $previousX - $previousK;

            while ($x > $previousX && $y > $previousY) {
                $lines[] = ['type' => self::SAME, 'text' => $a[--$x]];
                $y--;
            }

            if ($d > 0) {
                $lines[] = $x === $previousX ? ['type' => self::ADDED, 'text' => $b[--$y]] : ['type' => self::REMOVED, 'text' => $a[--$x]];
            }
        }

        return array_reverse($lines);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    private static function replaced(array $a, array $b): array
    {
        return [
            ...array_map(fn (string $line) => ['type' => self::REMOVED, 'text' => $line], $a),
            ...array_map(fn (string $line) => ['type' => self::ADDED, 'text' => $line], $b),
        ];
    }
}
