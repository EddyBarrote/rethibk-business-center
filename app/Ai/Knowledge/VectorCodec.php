<?php

namespace App\Ai\Knowledge;

/**
 * Vectors as bytes (docs/DECISOES.md, 14.10.2026): normalised to length 1 and
 * packed as little-endian float32, so a search compares them with a plain dot
 * product and reads a quarter of what the JSON numbers took.
 */
final class VectorCodec
{
    /**
     * @param  list<float>  $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...self::normalise($vector));
    }

    /**
     * @return list<float>
     */
    public static function unpack(string $bytes): array
    {
        return array_values(unpack('g*', $bytes) ?: []);
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalise(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($v) => (float) $v ** 2, $vector)));

        return $norm > 0 ? array_map(fn ($v) => (float) $v / $norm, $vector) : array_map('floatval', $vector);
    }

    /**
     * Cosine of two normalised vectors.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        $dot = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }
}
