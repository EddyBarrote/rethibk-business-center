<?php

namespace App\Documents\Renderers;

use App\Documents\DocumentType;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Gives the HTML from an agent's Markdown the shape of a business document
 * before it becomes a PDF: amounts right-aligned in tables, "**Rótulo:**
 * valor" lines as tidy label/value boxes, and subtotal/IVA/total lines as a
 * totals block. Only the layout changes; every word and value stays as the
 * agent wrote it.
 */
final class PdfContent
{
    /** A cell that holds only a number or an amount: "70 000,00", "16%", "MZN 1.200". */
    private const NUMBER = '/^[\s\x{00A0}\x{202F}]*(?:[A-Z]{2,3}|MT|€|\$)?[\s\x{00A0}\x{202F}]*[-+]?\d[\d\s\x{00A0}\x{202F}.,]*(?:%|[\s\x{00A0}\x{202F}]*(?:[A-Z]{2,3}|MT|€|\$))?[\s\x{00A0}\x{202F}]*$/u';

    private const TOTALS = '/\b(sub-?total|total|iva|imposto|impostos|desconto|reten[cç][aã]o)\b/iu';

    private const GRAND = '/^\s*total\b/iu';

    /**
     * @param  array<string, string>  $images  asset key => absolute path, for ![…](asset:key)
     */
    public static function polish(string $html, array $images = []): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="pdf-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//div[@id="pdf-root"]')->item(0);

        if (! $root instanceof DOMElement) {
            return $html;
        }

        foreach (iterator_to_array($xpath->query('.//img', $root)) as $image) {
            self::image($image, $images);
        }

        foreach (iterator_to_array($xpath->query('.//table', $root)) as $table) {
            self::table($table);
        }

        foreach (iterator_to_array($xpath->query('.//ul', $root)) as $list) {
            self::labelList($dom, $list);
        }

        foreach (iterator_to_array($xpath->query('.//p', $root)) as $paragraph) {
            self::labelParagraph($dom, $paragraph);
        }

        // A rule right before a section title only doubles the title's own line.
        foreach (iterator_to_array($xpath->query('.//hr', $root)) as $rule) {
            $next = self::nextElement($rule);

            if ($next === null || in_array($next->nodeName, ['h1', 'h2'], true)) {
                $rule->parentNode?->removeChild($rule);
            }
        }

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * An asset:… image inlined from its file; any other image (remote, unknown)
     * is left out, since a PDF never fetches anything.
     *
     * @param  array<string, string>  $images
     */
    private static function image(DOMElement $image, array $images): void
    {
        $src = trim($image->getAttribute('src'));
        $path = str_starts_with($src, 'asset:') ? ($images[DocumentType::key(substr($src, 6))] ?? null) : null;

        if ($path === null || ! is_file($path)) {
            $image->parentNode?->removeChild($image);

            return;
        }

        $mime = mime_content_type($path) ?: 'image/png';
        $image->setAttribute('src', 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path)));
        self::addClass($image, 'asset');

        // An image alone in its paragraph is a figure (a signature, a stamp…).
        $parent = $image->parentNode;

        if ($parent instanceof DOMElement && $parent->nodeName === 'p' && trim($parent->textContent) === '' && $parent->getElementsByTagName('img')->length === 1) {
            self::addClass($parent, 'figure');
        }
    }

    /** Amount columns right-aligned; a table of subtotal/IVA/total rows styled as totals. */
    private static function table(DOMElement $table): void
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];

            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array($cell->nodeName, ['td', 'th'], true)) {
                    $cell->removeAttribute('align');
                    $cell->removeAttribute('style');
                    $cells[] = $cell;
                }
            }

            $rows[] = ['row' => $row, 'cells' => $cells, 'head' => $row->parentNode?->nodeName === 'thead'];
        }

        $body = array_values(array_filter($rows, fn (array $r) => ! $r['head']));
        $columns = max(array_map(fn (array $r) => count($r['cells']), $rows ?: [['cells' => []]]));

        for ($c = 0; $c < $columns; $c++) {
            $values = array_filter(array_map(fn (array $r) => isset($r['cells'][$c]) ? trim($r['cells'][$c]->textContent) : '', $body), fn (string $v) => $v !== '');

            if ($values !== [] && count(array_filter($values, fn (string $v) => preg_match(self::NUMBER, $v) === 1)) === count($values)) {
                foreach ($rows as $r) {
                    if (isset($r['cells'][$c])) {
                        self::addClass($r['cells'][$c], 'num');
                    }
                }
            }
        }

        $firsts = array_map(fn (array $r) => trim($r['cells'][0]->textContent ?? ''), $body);
        $isTotals = $body !== [] && count(array_filter($firsts, fn (string $v) => preg_match(self::TOTALS, $v) === 1)) === count($firsts);

        self::addClass($table, $isTotals ? 'totals' : 'data');

        foreach ($body as $i => $r) {
            if (preg_match(self::GRAND, $firsts[$i]) === 1) {
                self::addClass($r['row'], 'grand');
            }
        }
    }

    /** A list of "**Rótulo:** valor" items becomes a label/value box (or totals). */
    private static function labelList(DOMDocument $dom, DOMElement $list): void
    {
        $items = [];

        foreach ($list->childNodes as $item) {
            if ($item instanceof DOMElement && $item->nodeName === 'li') {
                $items[] = $item;
            } elseif (! ($item->nodeType === XML_TEXT_NODE && trim($item->textContent) === '')) {
                return;
            }
        }

        $pairs = [];

        foreach ($items as $item) {
            if ($item->getElementsByTagName('ul')->length > 0 || $item->getElementsByTagName('ol')->length > 0) {
                return;
            }

            $nodes = iterator_to_array($item->childNodes);
            $elements = array_values(array_filter($nodes, fn (DOMNode $n) => $n instanceof DOMElement));

            // A loose list wraps each item in a paragraph.
            if (count($elements) === 1 && $elements[0]->nodeName === 'p' && trim($item->textContent) === trim($elements[0]->textContent)) {
                $nodes = iterator_to_array($elements[0]->childNodes);
            }

            $pair = self::split($nodes);

            if ($pair === null) {
                return;
            }

            $pairs[] = $pair;
        }

        if (count($pairs) < 2) {
            return;
        }

        $list->parentNode?->replaceChild(self::labelTable($dom, $pairs), $list);
    }

    /** A paragraph of "**Rótulo:** valor" lines (one per line) becomes a label/value box. */
    private static function labelParagraph(DOMDocument $dom, DOMElement $paragraph): void
    {
        $lines = [[]];

        foreach (iterator_to_array($paragraph->childNodes) as $node) {
            if ($node instanceof DOMElement && $node->nodeName === 'br') {
                $lines[] = [];
            } elseif ($node->nodeType === XML_TEXT_NODE && str_contains($node->textContent, "\n")) {
                // A soft break: CommonMark keeps the newline inside the text.
                $parts = explode("\n", $node->textContent);

                foreach ($parts as $i => $part) {
                    if ($i > 0) {
                        $lines[] = [];
                    }

                    $lines[count($lines) - 1][] = $dom->createTextNode($part);
                }
            } else {
                $lines[count($lines) - 1][] = $node;
            }
        }

        $lines = array_values(array_filter($lines, fn (array $nodes) => trim(implode('', array_map(fn (DOMNode $n) => $n->textContent, $nodes))) !== ''));

        if (count($lines) < 2) {
            return;
        }

        $pairs = [];

        foreach ($lines as $nodes) {
            $pair = self::split($nodes);

            if ($pair === null) {
                return;
            }

            $pairs[] = $pair;
        }

        $paragraph->parentNode?->replaceChild(self::labelTable($dom, $pairs), $paragraph);
    }

    /**
     * "<strong>Rótulo:</strong> valor" as [label, value nodes], or null.
     *
     * @param  list<DOMNode>  $nodes
     * @return array{0: string, 1: list<DOMNode>}|null
     */
    private static function split(array $nodes): ?array
    {
        while ($nodes !== [] && $nodes[0]->nodeType === XML_TEXT_NODE && trim($nodes[0]->textContent) === '') {
            array_shift($nodes);
        }

        $first = $nodes[0] ?? null;

        if (! $first instanceof DOMElement || ! in_array($first->nodeName, ['strong', 'b'], true)) {
            return null;
        }

        $label = trim($first->textContent);
        $rest = array_slice($nodes, 1);

        if (str_ends_with($label, ':')) {
            $label = rtrim(substr($label, 0, -1));
        } elseif (isset($rest[0]) && $rest[0]->nodeType === XML_TEXT_NODE && str_starts_with(ltrim($rest[0]->textContent), ':')) {
            $rest[0] = $rest[0]->ownerDocument->createTextNode(ltrim(ltrim($rest[0]->textContent), ':'));
        } else {
            return null;
        }

        if ($label === '' || mb_strlen($label) > 60) {
            return null;
        }

        return [$label, $rest];
    }

    /**
     * @param  list<array{0: string, 1: list<DOMNode>}>  $pairs
     */
    private static function labelTable(DOMDocument $dom, array $pairs): DOMElement
    {
        $labels = array_column($pairs, 0);
        $isTotals = count(array_filter($labels, fn (string $l) => preg_match(self::TOTALS, $l) === 1)) === count($labels);

        $table = $dom->createElement('table');
        $table->setAttribute('class', $isTotals ? 'totals' : 'info');
        $body = $dom->createElement('tbody');
        $table->appendChild($body);

        foreach ($pairs as [$label, $nodes]) {
            $row = $dom->createElement('tr');

            if ($isTotals && preg_match(self::GRAND, $label) === 1) {
                $row->setAttribute('class', 'grand');
            }

            $th = $dom->createElement('th');
            $th->appendChild($dom->createTextNode($label));
            $td = $dom->createElement('td');

            if ($isTotals) {
                $td->setAttribute('class', 'num');
            }

            foreach ($nodes as $node) {
                $td->appendChild($node);
            }

            // Trim the space that followed the label.
            if ($td->firstChild?->nodeType === XML_TEXT_NODE) {
                $td->firstChild->nodeValue = ltrim((string) $td->firstChild->nodeValue);
            }

            $row->appendChild($th);
            $row->appendChild($td);
            $body->appendChild($row);
        }

        return $table;
    }

    private static function nextElement(DOMNode $node): ?DOMElement
    {
        for ($n = $node->nextSibling; $n !== null; $n = $n->nextSibling) {
            if ($n instanceof DOMElement) {
                return $n;
            }
        }

        return null;
    }

    private static function addClass(DOMElement $element, string $class): void
    {
        $element->setAttribute('class', trim($element->getAttribute('class').' '.$class));
    }
}
