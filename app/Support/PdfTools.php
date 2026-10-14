<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Page-level work on PDFs with Poppler (pdfinfo, pdftotext, pdfseparate,
 * pdfunite, pdftoppm), with smalot/pdfparser when Poppler is missing:
 * counting pages, the text of each page, a smaller PDF with some of the
 * pages, and a picture of the first page.
 */
final class PdfTools
{
    public function pageCount(string $path): ?int
    {
        $process = Process::timeout(30)->run(['pdfinfo', $path]);

        if ($process->successful() && preg_match('/^Pages:\s+(\d+)/m', $process->output(), $match) === 1) {
            return (int) $match[1];
        }

        try {
            return count((new PdfParser)->parseFile($path)->getPages()) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The text of each page, in order (index 0 is page 1). Pages without
     * text are empty strings.
     *
     * @return list<string>
     */
    public function pageTexts(string $path): array
    {
        $process = Process::timeout(120)->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);

        if ($process->successful()) {
            // pdftotext ends every page with a form feed.
            $pages = explode("\f", $process->output());

            if (count($pages) > 1 && trim((string) end($pages)) === '') {
                array_pop($pages);
            }

            return array_map(fn (string $page) => TextExtractor::clean($page), $pages);
        }

        try {
            return array_values(array_map(fn ($page) => TextExtractor::clean((string) $page->getText()), (new PdfParser)->parseFile($path)->getPages()));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * A new PDF with only these pages, in this order, in a temporary file
     * the caller deletes; null when Poppler is not installed.
     *
     * @param  list<int>  $pages
     */
    public function onlyPages(string $path, array $pages): ?string
    {
        if ($pages === []) {
            return null;
        }

        $dir = sys_get_temp_dir().'/pdf-'.bin2hex(random_bytes(6));
        @mkdir($dir);
        $parts = [];

        try {
            foreach ($pages as $page) {
                $part = "{$dir}/p{$page}.pdf";

                if (! Process::timeout(30)->run(['pdfseparate', '-f', (string) $page, '-l', (string) $page, $path, $part])->successful()) {
                    return null;
                }

                $parts[] = $part;
            }

            $out = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';

            if (count($parts) === 1) {
                copy($parts[0], $out);

                return $out;
            }

            return Process::timeout(60)->run(['pdfunite', ...$parts, $out])->successful() ? $out : null;
        } finally {
            foreach ($parts as $part) {
                @unlink($part);
            }

            @rmdir($dir);
        }
    }

    /**
     * A PNG of the first page, about 480 px wide; null when it cannot be made.
     */
    public function thumbnail(string $path): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'thumb');
        @unlink($base);
        $process = Process::timeout(60)->run(['pdftoppm', '-png', '-f', '1', '-l', '1', '-scale-to-x', '480', '-scale-to-y', '-1', '-singlefile', $path, $base]);

        return $process->successful() && is_file("{$base}.png") ? "{$base}.png" : null;
    }

    /**
     * Parses a page range like "3", "3-5" or "2, 4, 7-9" into page numbers
     * within 1..$last, at most $limit of them.
     *
     * @return list<int>
     */
    public static function range(?string $range, int $last, int $limit): array
    {
        $pages = [];

        foreach (preg_split('/\s*[,;]\s*/', trim((string) $range)) ?: [] as $part) {
            if (preg_match('/^(\d+)\s*(?:-|a|até)\s*(\d+)$/u', $part, $m) === 1) {
                $from = (int) $m[1];
                $to = (int) $m[2];
            } elseif (preg_match('/^\d+$/', $part) === 1) {
                $from = $to = (int) $part;
            } else {
                continue;
            }

            for ($p = max(1, $from); $p <= min($last, $to); $p++) {
                $pages[$p] = $p;
            }
        }

        return array_slice(array_values($pages), 0, $limit);
    }
}
