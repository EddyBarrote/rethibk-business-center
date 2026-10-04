<?php

namespace App\Documents\Renderers;

use App\Documents\Brand;
use App\Documents\DocumentSpec;
use App\Documents\MarkdownBlocks;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\Table;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColor;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;

/**
 * PowerPoint through PhpPresentation, 16:9. A cover slide in the brand
 * colour, then one slide per "---" or, without rules, per # / ## heading.
 * Long slides continue on the next one; tables become real tables.
 *
 * @phpstan-import-type Block from MarkdownBlocks
 */
final class PptxRenderer implements Renderer
{
    private const WIDTH = 960;

    private const HEIGHT = 540;

    private const MARGIN = 56;

    /** Text lines (paragraphs and bullets) on one slide before it continues. */
    private const LINES_PER_SLIDE = 7;

    private const ROWS_PER_SLIDE = 9;

    private const FONT = 'Calibri';

    public function render(DocumentSpec $spec, Brand $brand, string $path): void
    {
        $deck = new PhpPresentation;
        $deck->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $deck->getDocumentProperties()->setTitle($spec->title)->setCreator($brand->name)->setCompany($brand->name);

        $this->cover($deck->getActiveSlide(), $spec, $brand);

        foreach ($this->slides($spec) as $number => $slide) {
            $this->content($deck->createSlide(), $slide['title'], $slide['blocks'], $brand, $number + 2);
        }

        IOFactory::createWriter($deck, 'PowerPoint2007')->save($path);
    }

    /**
     * @return list<array{title: string, blocks: list<Block>}>
     */
    private function slides(DocumentSpec $spec): array
    {
        $blocks = MarkdownBlocks::parse($spec->body());
        $byRule = in_array('rule', array_column($blocks, 'type'), true);
        $chunks = [[]];

        foreach ($blocks as $block) {
            $breaks = $byRule ? $block['type'] === 'rule' : ($block['type'] === 'heading' && $block['level'] <= 2);

            if ($breaks && end($chunks) !== []) {
                $chunks[] = [];
            }

            if ($block['type'] !== 'rule') {
                $chunks[count($chunks) - 1][] = $block;
            }
        }

        $slides = [];

        foreach (array_filter($chunks) as $chunk) {
            $title = $spec->title;

            if ($chunk[0]['type'] === 'heading') {
                $title = $chunk[0]['text'];
                array_shift($chunk);
            }

            // Split a long chunk over several slides.
            $before = count($slides);
            $page = [];
            $lines = 0;

            foreach ($chunk as $block) {
                $weight = match ($block['type']) {
                    'list' => count($block['items']),
                    'table' => self::LINES_PER_SLIDE,
                    default => 1,
                };

                if ($page !== [] && $lines + $weight > self::LINES_PER_SLIDE) {
                    $slides[] = ['title' => $title, 'blocks' => $page];
                    $title = str_ends_with($title, '(cont.)') ? $title : $title.' (cont.)';
                    $page = [];
                    $lines = 0;
                }

                if ($block['type'] === 'list' && count($block['items']) > self::LINES_PER_SLIDE) {
                    foreach (array_chunk($block['items'], self::LINES_PER_SLIDE) as $items) {
                        $slides[] = ['title' => $title, 'blocks' => [['type' => 'list', 'ordered' => $block['ordered'], 'items' => $items]]];
                        $title = str_ends_with($title, '(cont.)') ? $title : $title.' (cont.)';
                    }

                    continue;
                }

                $page[] = $block;
                $lines += $weight;
            }

            // A heading alone is a section slide.
            if ($page !== [] || count($slides) === $before) {
                $slides[] = ['title' => $title, 'blocks' => $page];
            }
        }

        return $slides;
    }

    private function cover(Slide $slide, DocumentSpec $spec, Brand $brand): void
    {
        $slide->setBackground((new BackgroundColor)->setColor(new Color('FF'.$brand->hex())));

        if ($brand->logoPath !== null) {
            $slide->createDrawingShape()->setPath($brand->logoPath)->setHeight(48)->setOffsetX(self::MARGIN)->setOffsetY(self::MARGIN);
        }

        $org = $this->text($slide, self::MARGIN, 190, self::WIDTH - 2 * self::MARGIN, 30);
        $this->run($org, mb_strtoupper($brand->name), 14, 'FFFFFF', true);

        $title = $this->text($slide, self::MARGIN, 225, self::WIDTH - 2 * self::MARGIN, 140);
        $this->run($title, $spec->title, 36, 'FFFFFF', true);

        $date = $this->text($slide, self::MARGIN, self::HEIGHT - 80, self::WIDTH - 2 * self::MARGIN, 30);
        $this->run($date, ($spec->subtitle !== null ? $spec->subtitle.' · ' : '').$spec->dateLabel(), 12, 'FFFFFF');
    }

    /**
     * @param  list<Block>  $blocks
     */
    private function content(Slide $slide, string $title, array $blocks, Brand $brand, int $number): void
    {
        $bar = $slide->createRichTextShape()->setOffsetX(self::MARGIN)->setOffsetY(40)->setWidth(48)->setHeight(5);
        $bar->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$brand->hex()));

        $heading = $this->text($slide, self::MARGIN, 52, self::WIDTH - 2 * self::MARGIN, 60);
        $this->run($heading, $title, 26, '111827', true);

        $y = 125;
        $text = null;

        foreach ($blocks as $block) {
            if ($block['type'] === 'table') {
                $y = $this->table($slide, $block['header'], $block['rows'], $brand, $y);
                $text = null;

                continue;
            }

            if ($text === null) {
                $text = $this->text($slide, self::MARGIN, $y, self::WIDTH - 2 * self::MARGIN, self::HEIGHT - $y - 50);
                $text->setAutoFit(RichText::AUTOFIT_NORMAL);
                $first = true;
            }

            $items = match ($block['type']) {
                'list' => $block['items'],
                'heading', 'paragraph' => [$block['text']],
                default => [],
            };

            foreach ($items as $index => $item) {
                $paragraph = ($first ?? false) ? $text->getActiveParagraph() : $text->createParagraph();
                $first = false;
                $paragraph->setSpacingAfter(8);

                if ($block['type'] === 'list') {
                    $paragraph->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET)->setBulletChar('•')->setBulletColor(new Color('FF'.$brand->hex()));
                    $paragraph->getAlignment()->setMarginLeft(22)->setIndent(-22);

                    if ($block['ordered']) {
                        $paragraph->getBulletStyle()->setBulletType(Bullet::TYPE_NUMERIC)->setBulletNumericStartAt($index + 1);
                    }
                }

                $run = $paragraph->createTextRun($item);
                $run->getFont()->setName(self::FONT)->setSize($block['type'] === 'heading' ? 18 : 17)->setBold($block['type'] === 'heading')->setColor(new Color('FF374151'));
            }

            $y += 40 * max(1, count($items));
        }

        $footer = $this->text($slide, self::MARGIN, self::HEIGHT - 36, self::WIDTH - 2 * self::MARGIN, 20);
        $footer->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $this->run($footer, $brand->name.'  ·  '.$number, 9, '9CA3AF');
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private function table(Slide $slide, array $header, array $rows, Brand $brand, int $y): int
    {
        $columns = max(1, count($header), ...array_map('count', $rows ?: [[]]));
        $extra = count($rows) - self::ROWS_PER_SLIDE;
        $rows = array_slice($rows, 0, self::ROWS_PER_SLIDE);

        if ($extra > 0) {
            $rows[] = array_pad(["… mais {$extra} linhas"], $columns, '');
        }

        $shape = new Table($columns);
        $shape->setOffsetX(self::MARGIN)->setOffsetY($y)->setWidth(self::WIDTH - 2 * self::MARGIN);
        $slide->addShape($shape);

        foreach ([$header, ...$rows] as $r => $cells) {
            $row = $shape->createRow()->setHeight(28);

            if ($r === 0) {
                $row->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$brand->hex()));
            }

            foreach (array_pad($cells, $columns, '') as $value) {
                $cell = $row->nextCell();
                $cell->getBorders()->getBottom()->setColor(new Color('FFD0D7DE'));
                $cell->createTextRun($value)->getFont()->setName(self::FONT)->setSize(12)->setBold($r === 0)->setColor(new Color($r === 0 ? 'FFFFFFFF' : 'FF1F2937'));
            }
        }

        return $y + 28 * (count($rows) + 1) + 16;
    }

    private function text(Slide $slide, int $x, int $y, int $width, int $height): RichText
    {
        return $slide->createRichTextShape()->setOffsetX($x)->setOffsetY($y)->setWidth($width)->setHeight(max(20, $height));
    }

    private function run(RichText $shape, string $text, int $size, string $color, bool $bold = false): void
    {
        $shape->createTextRun($text)->getFont()->setName(self::FONT)->setSize($size)->setBold($bold)->setColor(new Color('FF'.$color));
    }
}
