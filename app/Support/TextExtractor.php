<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Plain text out of documents and attachments (sections 9.2 and 13.1): text,
 * HTML, PDF, Word and spreadsheets. Images are read with Tesseract when it is
 * installed; otherwise they are kept without text (docs/DECISOES.md).
 */
final class TextExtractor
{
    public const MAX_CHARS = 200_000;

    public function extract(string $path, ?string $mime = null, ?string $filename = null): ?string
    {
        $extension = Str::lower(pathinfo($filename ?? $path, PATHINFO_EXTENSION));
        $mime = Str::lower($mime ?? (mime_content_type($path) ?: ''));

        try {
            $text = match (true) {
                $mime === 'application/pdf' || $extension === 'pdf' => $this->pdf($path),
                in_array($extension, ['docx'], true) => $this->docx($path),
                in_array($extension, ['xlsx'], true) => $this->xlsx($path),
                str_starts_with($mime, 'text/html') || in_array($extension, ['html', 'htm'], true) => self::htmlToText((string) file_get_contents($path)),
                str_starts_with($mime, 'text/') || in_array($extension, ['txt', 'md', 'csv', 'json', 'xml'], true) => (string) file_get_contents($path),
                str_starts_with($mime, 'image/') => $this->ocr($path),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($text === null) {
            return null;
        }

        $text = trim((string) preg_replace("/[ \t]+/u", ' ', (string) preg_replace("/\R{3,}/u", "\n\n", mb_convert_encoding($text, 'UTF-8', 'UTF-8'))));

        return $text === '' ? null : mb_substr($text, 0, self::MAX_CHARS);
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])[^>]*>#i', "\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function ocrAvailable(): bool
    {
        return Process::run(['which', 'tesseract'])->successful();
    }

    private function pdf(string $path): string
    {
        $process = Process::timeout(60)->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);

        if ($process->successful() && trim($process->output()) !== '') {
            return $process->output();
        }

        return (new PdfParser)->parseFile($path)->getText();
    }

    private function docx(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return self::htmlToText((string) preg_replace('#</w:p>#', "\n", $xml));
    }

    private function xlsx(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        $shared = (string) $zip->getFromName('xl/sharedStrings.xml');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return self::htmlToText((string) preg_replace('#</(si|row)>#', "\n", $shared."\n".$sheet));
    }

    private function ocr(string $path): ?string
    {
        if (! self::ocrAvailable()) {
            return null;
        }

        $process = Process::timeout(120)->run(['tesseract', $path, '-', '-l', 'por+eng']);

        return $process->successful() ? $process->output() : null;
    }
}
