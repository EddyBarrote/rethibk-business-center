<?php

namespace App\Documents\Renderers;

use App\Documents\Brand;
use App\Documents\DocumentSpec;
use App\Documents\DocumentTemplate;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * Word through PhpWord: the Markdown becomes HTML and PhpWord lays it out
 * with the brand's heading styles, header and page numbers.
 */
final class DocxRenderer implements Renderer
{
    public function render(DocumentSpec $spec, Brand $brand, string $path): void
    {
        $spec = $spec->withoutRepeatedTitle();
        $word = new PhpWord;
        $word->getSettings()->setThemeFontLang(new Language('pt-PT'));
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);
        $word->getDocInfo()->setTitle($spec->title)->setCreator($brand->name)->setCompany($brand->name);

        $word->addTitleStyle(1, ['size' => 16, 'bold' => true, 'color' => $brand->hex()], ['spaceBefore' => 240, 'spaceAfter' => 80]);
        $word->addTitleStyle(2, ['size' => 13, 'bold' => true, 'color' => $brand->hex()], ['spaceBefore' => 200, 'spaceAfter' => 60]);
        $word->addTitleStyle(3, ['size' => 11, 'bold' => true], ['spaceBefore' => 160, 'spaceAfter' => 40]);
        $word->addParagraphStyle('Normal', ['spaceAfter' => 100, 'lineHeight' => 1.15]);

        $margins = ['marginTop' => 1300, 'marginBottom' => 1100, 'marginLeft' => 1150, 'marginRight' => 1150];

        if ($spec->template === DocumentTemplate::Report) {
            $cover = $word->addSection($margins);
            $cover->addTextBreak(6);

            if ($brand->logoPath !== null) {
                $cover->addImage($brand->logoPath, ['height' => 45]);
            }

            $cover->addText(mb_strtoupper($brand->name), ['bold' => true, 'size' => 10, 'color' => $brand->hex()]);
            $cover->addText($spec->title, ['bold' => true, 'size' => 28], ['spaceBefore' => 200]);

            if ($spec->subtitle !== null) {
                $cover->addText($spec->subtitle, ['size' => 13, 'color' => '4B5563']);
            }

            $cover->addTextBreak(10);
            $cover->addText($spec->dateLabel(), ['size' => 9, 'color' => '6B7280']);
        }

        $section = $word->addSection($margins);
        $header = $section->addHeader();

        if ($brand->logoPath !== null && $spec->template !== DocumentTemplate::Letter) {
            $header->addImage($brand->logoPath, ['height' => 24, 'alignment' => Jc::END]);
        }

        $header->addText(mb_strtoupper($brand->name), ['bold' => true, 'size' => 8, 'color' => $brand->hex()], [
            'borderBottomSize' => 8, 'borderBottomColor' => $brand->hex(), 'spaceAfter' => 120,
        ]);

        $section->addFooter()->addPreserveText(
            ($brand->footer ?? $brand->name).' · '.$spec->title.'    {PAGE} / {NUMPAGES}',
            ['size' => 7.5, 'color' => '6B7280'],
            ['alignment' => Jc::END],
        );

        if ($spec->template === DocumentTemplate::Letter) {
            if ($brand->logoPath !== null) {
                $section->addImage($brand->logoPath, ['height' => 45]);
            }

            $section->addText($spec->dateLabel(), ['color' => '4B5563'], ['alignment' => Jc::END, 'spaceAfter' => 360]);
        } elseif ($spec->template === DocumentTemplate::Document) {
            $section->addText($spec->title, ['bold' => true, 'size' => 20, 'color' => $brand->hex()], ['spaceAfter' => 40]);
            $section->addText(($spec->subtitle !== null ? $spec->subtitle.' · ' : '').$spec->dateLabel(), ['size' => 9, 'color' => '6B7280'], ['spaceAfter' => 300]);
        }

        Html::addHtml($section, $this->tables($spec->html(), $brand), false, false);

        IOFactory::createWriter($word, 'Word2007')->save($path);
    }

    /**
     * PhpWord reads table borders and header shading from inline styles.
     */
    private function tables(string $html, Brand $brand): string
    {
        $html = str_replace('<table>', '<table style="width: 100%; border: 1px #D0D7DE solid;">', $html);
        $html = (string) preg_replace('/<th(\s[^>]*)?>/', '<th style="background-color: #'.$brand->tint().'; border: 1px #D0D7DE solid;">', $html);

        return (string) preg_replace('/<td(\s[^>]*)?>/', '<td style="border: 1px #D0D7DE solid;">', $html);
    }
}
