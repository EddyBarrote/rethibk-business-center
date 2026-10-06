<?php

namespace App\Documents\Renderers;

use App\Documents\Brand;
use App\Documents\DocumentSpec;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF through Dompdf, from the same HTML the console shows.
 */
final class PdfRenderer implements Renderer
{
    public function render(DocumentSpec $spec, Brand $brand, string $path): void
    {
        $spec = $spec->withoutRepeatedTitle();
        $options = new Options;
        // Remote resources are never fetched: the logo is inlined as data.
        $options->set(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'isPhpEnabled' => false, 'chroot' => storage_path('app')]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('documents.pdf', [
            'spec' => $spec,
            'brand' => $brand,
            'content' => $spec->html(),
            'logo' => $brand->logoDataUri(),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        // Page numbers on every page but the cover.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $skipCover = $spec->template->value === 'relatorio';
        $canvas->page_script(function (int $page, int $pages, $canvas) use ($font, $skipCover) {
            if ($skipCover && $page === 1) {
                return;
            }

            $canvas->text(520, 810, "{$page} / {$pages}", $font, 8, [0.45, 0.45, 0.45]);
        });

        file_put_contents($path, $dompdf->output());
    }
}
