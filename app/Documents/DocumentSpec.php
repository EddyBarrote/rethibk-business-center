<?php

namespace App\Documents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What to render: a title and Markdown content.
 */
final readonly class DocumentSpec
{
    public function __construct(
        public string $title,
        public string $markdown,
        public DocumentTemplate $template = DocumentTemplate::Document,
        public ?string $subtitle = null,
        public ?Carbon $date = null,
    ) {}

    public function dateLabel(): string
    {
        return ($this->date ?? now())->locale('pt')->translatedFormat('j \d\e F \d\e Y');
    }

    /**
     * Content as HTML, raw HTML in the Markdown escaped.
     */
    public function html(): string
    {
        return (string) Str::markdown($this->markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * The Markdown without a leading "# Title" that repeats the title.
     */
    public function body(): string
    {
        $pattern = '/^\s*#\s+'.preg_quote(trim($this->title), '/').'\s*(\R|$)/u';

        return (string) preg_replace($pattern, '', $this->markdown, 1);
    }

    public function withoutRepeatedTitle(): self
    {
        return new self($this->title, $this->body(), $this->template, $this->subtitle, $this->date);
    }
}
