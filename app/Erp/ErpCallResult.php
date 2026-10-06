<?php

namespace App\Erp;

/**
 * Outcome of one ERP tool call. A tool that answers with isError is a normal
 * result with ok = false; the ERP being unreachable throws instead.
 */
final readonly class ErpCallResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public string $tool,
        public bool $ok,
        public ?array $data,
        public string $text,
        public int $durationMs,
    ) {}

    public function error(): ?string
    {
        return $this->ok ? null : $this->text;
    }
}
