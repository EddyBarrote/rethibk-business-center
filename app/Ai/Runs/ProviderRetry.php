<?php

namespace App\Ai\Runs;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The model providers drop requests now and then ("Could not connect to AI
 * provider [gemini]", 429, 503): a request to one of them that did not get an
 * answer is sent again, a few times, after a short wait (docs/DECISOES.md,
 * "Repetir quando o provedor falha"). Only a request with no answer is
 * repeated, so no tool has run and nothing is done twice.
 */
final class ProviderRetry
{
    private const STATUSES = [408, 429, 500, 502, 503, 504];

    public static function middleware(): callable
    {
        return Middleware::retry(self::decider(...), self::delay(...));
    }

    public static function decider(int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?Throwable $error = null): bool
    {
        if ($retries >= (int) config('agents.provider_retry.times', 0) || ! in_array($request->getUri()->getHost(), (array) config('agents.provider_retry.hosts', []), true)) {
            return false;
        }

        return $error instanceof ConnectException || ($response !== null && in_array($response->getStatusCode(), self::STATUSES, true));
    }

    /**
     * Milliseconds before the next try: the provider's Retry-After when it
     * gives one (up to 20 s), else doubling from the configured base.
     */
    public static function delay(int $retries, ?ResponseInterface $response = null): int
    {
        $after = $response?->getHeaderLine('Retry-After');

        if (is_numeric($after)) {
            return (int) min((float) $after * 1000, 20_000);
        }

        return (int) config('agents.provider_retry.delay_ms', 0) * 2 ** max(0, $retries - 1);
    }
}
