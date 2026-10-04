<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches public web pages for agents and the tender scanner. Refuses
 * anything but http(s) on public addresses, so a URL found in an email can
 * never reach the platform's internal network.
 */
final class SafeHttp
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function get(string $url): Response
    {
        $this->assertPublic($url);

        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'RethinkAgentes/1.0 (+https://rethink.co.mz)'])
            ->withOptions(['allow_redirects' => ['max' => 3, 'on_redirect' => fn ($request, $response, $uri) => $this->assertPublic((string) $uri)]])
            ->get($url);

        if (strlen($response->body()) > self::MAX_BYTES) {
            throw new RuntimeException('Página demasiado grande.');
        }

        return $response;
    }

    public function assertPublic(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Só são permitidos endereços http(s).');
        }

        if (app()->runningUnitTests() && str_ends_with($host, '.test')) {
            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new RuntimeException("Não foi possível resolver {$host}.");
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('Endereço interno recusado.');
            }
        }
    }
}
