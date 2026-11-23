<?php

use App\Support\LineDiff;

// The line diff behind «Comparar com a actual» on a skill version.

/** The diff as "+line", "-line", " line". */
function diffed(string $old, string $new): array
{
    return array_map(fn (array $line) => ['same' => ' ', 'added' => '+', 'removed' => '-'][$line['type']].$line['text'], LineDiff::lines($old, $new));
}

it('marks the lines removed and added between two texts', function () {
    expect(diffed("a\nb\nc", "a\nc\nd"))->toBe([' a', '-b', ' c', '+d'])
        ->and(diffed("a\nb\nc\nd\ne", "a\nX\nc\nY\ne"))->toBe([' a', '-b', '+X', ' c', '-d', '+Y', ' e'])
        ->and(diffed('', "x\ny"))->toBe(['+x', '+y'])
        ->and(diffed("x\ny", ''))->toBe(['-x', '-y'])
        ->and(diffed('igual', 'igual'))->toBe([' igual'])
        ->and(diffed("a\r\nb\n", "a\nb"))->toBe([' a', ' b']);
});

it('always rebuilds both texts from the diff', function () {
    mt_srand(23112026);

    foreach (range(1, 300) as $_) {
        $old = array_map(fn () => chr(97 + mt_rand(0, 3)), range(0, mt_rand(0, 15)));
        $new = array_map(fn () => chr(97 + mt_rand(0, 3)), range(0, mt_rand(0, 15)));
        $lines = LineDiff::lines(implode("\n", $old), implode("\n", $new));

        expect(array_values(array_map(fn (array $line) => $line['text'], array_filter($lines, fn (array $line) => $line['type'] !== 'added'))))->toBe($old)
            ->and(array_values(array_map(fn (array $line) => $line['text'], array_filter($lines, fn (array $line) => $line['type'] !== 'removed'))))->toBe($new);
    }
});

it('shows a long, wholly different middle as removed then added', function () {
    $old = implode("\n", array_map(fn (int $i) => "antiga {$i}", range(1, 5000)));
    $new = implode("\n", array_map(fn (int $i) => "nova {$i}", range(1, 5000)));
    $lines = LineDiff::lines("topo\n{$old}\nfim", "topo\n{$new}\nfim");

    expect($lines)->toHaveCount(10002)
        ->and($lines[0])->toBe(['type' => 'same', 'text' => 'topo'])
        ->and($lines[1])->toBe(['type' => 'removed', 'text' => 'antiga 1'])
        ->and($lines[5001])->toBe(['type' => 'added', 'text' => 'nova 1'])
        ->and(end($lines))->toBe(['type' => 'same', 'text' => 'fim']);
});
