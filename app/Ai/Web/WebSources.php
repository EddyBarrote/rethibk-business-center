<?php

namespace App\Ai\Web;

use App\Support\SafeHttp;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gemini's search cites pages through short-lived Google redirect links. This
 * follows each one a single hop to the page itself, so the agent and the
 * person get the real address, and drops repeats.
 */
final class WebSources
{
    private const REDIRECT_HOST = 'vertexaisearch.cloud.google.com';

    public function __construct(private readonly SafeHttp $http) {}

    /**
     * @param  list<array{title: string|null, url: string}>  $citations
     * @return list<array{title: string|null, url: string}>
     */
    public function resolve(array $citations): array
    {
        $redirects = array_values(array_unique(array_filter(array_column($citations, 'url'), fn (string $url) => parse_url($url, PHP_URL_HOST) === self::REDIRECT_HOST)));
        $resolved = [];

        if ($redirects !== []) {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (string $url) => $pool->as($url)->timeout(8)->withOptions(['allow_redirects' => false])->head($url),
                    $redirects,
                ));

                foreach ($responses as $url => $response) {
                    $location = $response instanceof Response ? (string) $response->header('Location') : '';

                    if ($location !== '' && $this->isPublic($location)) {
                        $resolved[$url] = $location;
                    }
                }
            } catch (Throwable) {
                // Keep the redirect links: they still open the page for a while.
            }
        }

        $sources = [];

        foreach ($citations as $citation) {
            $url = $resolved[$citation['url']] ?? $citation['url'];
            $sources[$url] ??= ['title' => $citation['title'], 'url' => $url];
        }

        return array_values($sources);
    }

    private function isPublic(string $url): bool
    {
        try {
            $this->http->assertPublic($url);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
