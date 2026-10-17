<?php

namespace App\Documents\Renderers;

use App\Documents\Brand;
use App\Documents\DocumentSpec;
use App\Documents\DocumentTemplate;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;

/**
 * PDF through Dompdf, from the same HTML the console shows, polished into a
 * business document (PdfContent) in the brand's font and colours. The header
 * (logo, title) and footer (brand line, page x of y) are drawn on each page,
 * except on a report's cover and a letter's letterhead page.
 */
final class PdfRenderer implements Renderer
{
    /** A4 in points, and the side margin (20 mm). */
    private const WIDTH = 595.28;

    private const HEIGHT = 841.89;

    private const SIDE = 56.69;

    public function render(DocumentSpec $spec, Brand $brand, string $path): void
    {
        $spec = $spec->withoutRepeatedTitle();
        $fontDir = storage_path('fonts');

        if (! is_dir($fontDir)) {
            mkdir($fontDir, 0755, true);
        }

        $options = new Options;
        // Remote resources are never fetched: the logo is inlined as data, fonts come from resources/fonts.
        $options->set([
            'isRemoteEnabled' => false,
            'defaultFont' => $brand->pdfFont(),
            'isPhpEnabled' => false,
            'chroot' => [storage_path('app'), resource_path('fonts')],
            'fontDir' => $fontDir,
            'fontCache' => $fontDir,
        ]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('documents.pdf', [
            'spec' => $spec,
            'brand' => $brand,
            'content' => self::polishFor($spec, $brand),
            'css' => $spec->type?->css,
            'logo' => $brand->logoDataUri(),
            'fonts' => $brand->pdfFontFiles(),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $this->decorate($dompdf, $spec, $brand);

        file_put_contents($path, $dompdf->output());
    }

    /** The document's content as the PDF lays it out. */
    public static function polishFor(DocumentSpec $spec, ?Brand $brand = null): string
    {
        $brand ??= Brand::current();
        $images = $spec->type?->imagesWith($brand) ?? array_map(fn (array $asset) => $asset['path'], $brand->assets);

        return PdfContent::polish($spec->withoutRepeatedTitle()->html(), $images);
    }

    private function decorate(Dompdf $dompdf, DocumentSpec $spec, Brand $brand): void
    {
        $metrics = $dompdf->getFontMetrics();
        $regular = $metrics->getFont($brand->pdfFont()) ?? $metrics->getFont('DejaVu Sans');
        $bold = $metrics->getFont($brand->pdfFont(), 'bold') ?? $regular;
        $skipFirst = in_array($spec->template, [DocumentTemplate::Report, DocumentTemplate::Letter], true);
        $logo = $brand->logoPath;
        $logoSize = $logo !== null ? @getimagesize($logo) : false;
        $grey = [0.42, 0.45, 0.5];
        $rule = self::rgb('#'.$brand->tint(0.75));
        $color = self::rgb($brand->color);
        $footer = $brand->footer ?? $brand->name;
        $title = $spec->title;

        $dompdf->getCanvas()->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $fm) use ($skipFirst, $logo, $logoSize, $regular, $bold, $grey, $rule, $color, $footer, $title, $brand) {
            if ($skipFirst && $page === 1) {
                return;
            }

            $right = self::WIDTH - self::SIDE;

            // Header: logo (or name) on the left, the document's title on the right, a thin rule.
            if ($logo !== null && $logoSize !== false && $logoSize[1] > 0) {
                $height = 20.0;
                $width = min(110.0, $height * $logoSize[0] / $logoSize[1]);
                $canvas->image($logo, self::SIDE, 26, $width, $width * $logoSize[1] / $logoSize[0]);
            } else {
                $canvas->text(self::SIDE, 32, mb_strtoupper($brand->name), $bold, 8, $color);
            }

            $label = self::fit($fm, $title, $regular, 7.5, 280);
            $canvas->text($right - $fm->getTextWidth($label, $regular, 7.5), 34, $label, $regular, 7.5, $grey);
            $canvas->line(self::SIDE, 54, $right, 54, $rule, 0.6);

            // Footer: the brand's line on the left, "Página x de y" on the right.
            $number = "Página {$page} de {$pages}";
            $numberWidth = $fm->getTextWidth($number, $regular, 7.5);
            $canvas->line(self::SIDE, self::HEIGHT - 46, $right, self::HEIGHT - 46, $rule, 0.6);
            $canvas->text(self::SIDE, self::HEIGHT - 39, self::fit($fm, $footer, $regular, 7.5, $right - self::SIDE - $numberWidth - 20), $regular, 7.5, $grey);
            $canvas->text($right - $numberWidth, self::HEIGHT - 39, $number, $regular, 7.5, $grey);
        });
    }

    /** The text cut with "…" to fit the width. */
    private static function fit(FontMetrics $fm, string $text, string $font, float $size, float $width): string
    {
        if ($fm->getTextWidth($text, $font, $size) <= $width) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $fm->getTextWidth($text.'…', $font, $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x') ?: [0, 0, 0];

        return [$r / 255, $g / 255, $b / 255];
    }
}
