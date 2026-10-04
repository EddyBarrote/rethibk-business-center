<?php

namespace App\Tenders;

use App\Enums\TenderStatus;
use App\Models\Tender;
use App\Support\SafeHttp;
use DOMDocument;
use Illuminate\Support\Str;
use Throwable;

/**
 * Looks for new tenders on the sources configured per tenant (super admin,
 * "Fontes de concursos"): HTML pages or RSS feeds. A link is a tender when
 * its text matches one of the source's keywords. Each URL is recorded once.
 */
final class TenderScanner
{
    private const DEFAULT_KEYWORDS = ['concurso', 'concurso público', 'cotação', 'ajuste directo', 'manifestação de interesse', 'tender'];

    public function __construct(private readonly SafeHttp $http) {}

    /**
     * @param  array{name?: string, url?: string, keywords?: string|null, active?: bool}  $source
     * @return array{found: list<Tender>, error: string|null}
     */
    public function scan(array $source): array
    {
        $url = (string) ($source['url'] ?? '');

        try {
            $response = $this->http->get($url);
        } catch (Throwable $e) {
            return ['found' => [], 'error' => Str::limit($e->getMessage(), 200)];
        }

        if (! $response->successful()) {
            return ['found' => [], 'error' => "HTTP {$response->status()}"];
        }

        $keywords = $this->keywords($source['keywords'] ?? null);
        $body = $response->body();
        $links = str_contains((string) $response->header('Content-Type'), 'xml') || str_starts_with(ltrim($body), '<?xml')
            ? $this->feedLinks($body)
            : $this->htmlLinks($body, $url);

        $found = [];

        foreach ($links as [$title, $href]) {
            $matched = array_values(array_filter($keywords, fn (string $k) => Str::contains(Str::ascii(Str::lower($title)), Str::ascii(Str::lower($k)))));

            if ($matched === [] || mb_strlen($title) < 12) {
                continue;
            }

            $hash = hash('sha256', $href);

            if (Tender::query()->where('url_hash', $hash)->exists()) {
                continue;
            }

            $found[] = Tender::query()->create([
                'source' => (string) ($source['name'] ?? parse_url($url, PHP_URL_HOST)),
                'title' => Str::limit($title, 250),
                'url' => $href,
                'url_hash' => $hash,
                'status' => TenderStatus::New,
                'matched_keywords' => $matched,
            ]);
        }

        return ['found' => $found, 'error' => null];
    }

    /**
     * @return list<string>
     */
    private function keywords(?string $configured): array
    {
        $list = array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', (string) $configured) ?: [])));

        return $list === [] ? self::DEFAULT_KEYWORDS : $list;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function htmlLinks(string $html, string $base): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $links = [];

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $title = trim((string) preg_replace('/\s+/u', ' ', $anchor->textContent ?: $anchor->getAttribute('title')));
            $href = $this->absolute(trim($anchor->getAttribute('href')), $base);

            if ($href !== null && $title !== '') {
                $links[$href] = [$title, $href];
            }
        }

        return array_values($links);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function feedLinks(string $xml): array
    {
        try {
            $feed = new \SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOERROR);
        } catch (Throwable) {
            return [];
        }

        $links = [];

        foreach ($feed->xpath('//item') ?: [] as $item) {
            $links[] = [trim((string) $item->title), trim((string) $item->link)];
        }

        return array_values(array_filter($links, fn (array $l) => str_starts_with($l[1], 'http')));
    }

    private function absolute(string $href, string $base): ?string
    {
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|javascript|tel):/i', $href)) {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($base);
        $root = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return $root.$href;
        }

        $path = rtrim(dirname(($parts['path'] ?? '/').'x'), '/');

        return $root.$path.'/'.$href;
    }
}
