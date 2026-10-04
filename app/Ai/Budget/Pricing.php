<?php

namespace App\Ai\Budget;

/**
 * Turns token usage into USD (section 14.3), from config/agents.php.
 */
final class Pricing
{
    public function cost(?string $provider, ?string $model, int $inputTokens, int $outputTokens): float
    {
        /** @var array<string, array{input: float, output: float}> $prices */
        $prices = config('agents.pricing', []);
        $price = $prices["{$provider}:{$model}"] ?? config('agents.fallback_pricing');

        return round(($inputTokens * (float) $price['input'] + $outputTokens * (float) $price['output']) / 1_000_000, 6);
    }

    public function isKnown(?string $provider, ?string $model): bool
    {
        return array_key_exists("{$provider}:{$model}", (array) config('agents.pricing', []));
    }
}
