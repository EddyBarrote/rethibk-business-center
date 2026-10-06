<?php

namespace App\Erp;

use Laravel\Mcp\Client\Primitives\Tool;

/**
 * A tool the ERP exposes, detached from the MCP client so it can be cached in
 * erp_connections.capabilities and handed to agents without a live connection.
 */
final readonly class ErpTool
{
    /**
     * @param  array<string, mixed>  $inputSchema
     */
    public function __construct(
        public string $name,
        public ?string $title,
        public ?string $description,
        public array $inputSchema,
        public bool $readOnly,
    ) {}

    public static function fromMcp(Tool $tool): self
    {
        return new self($tool->name, $tool->title, $tool->description, $tool->inputSchema, (bool) ($tool->annotations['readOnlyHint'] ?? false));
    }

    /**
     * @return array{name: string, title: string|null, description: string|null, read_only: bool}
     */
    public function summary(): array
    {
        return ['name' => $this->name, 'title' => $this->title, 'description' => $this->description, 'read_only' => $this->readOnly];
    }
}
