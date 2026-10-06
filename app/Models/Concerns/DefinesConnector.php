<?php

namespace App\Models\Concerns;

use App\Enums\ConnectorKind;
use Illuminate\Support\Carbon;

/**
 * Shared by PlatformConnector (global) and Connector (one tenant's): where
 * the tools live and how to authenticate. The secret is encrypted at rest
 * and never sent to the browser.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property ConnectorKind $kind
 * @property string $url
 * @property string|null $secret
 * @property string|null $http_method
 * @property array<string, mixed>|null $input_schema
 * @property bool $is_mutating
 * @property list<array<string, mixed>>|null $tools
 * @property string|null $last_error
 * @property Carbon|null $last_synced_at
 * @property bool $is_active
 */
trait DefinesConnector
{
    public function initializeDefinesConnector(): void
    {
        $this->mergeCasts([
            'kind' => ConnectorKind::class,
            'secret' => 'encrypted',
            'input_schema' => 'array',
            'is_mutating' => 'boolean',
            'tools' => 'array',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ]);

        $this->mergeHidden(['secret']);
    }

    /**
     * Prefix of the capability keys this connector produces: "conn.<key>" for
     * a tenant's own, "global.<key>" for the platform's.
     */
    abstract public function capabilityPrefix(): string;

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'url' => $this->url,
            'has_secret' => filled($this->secret),
            'http_method' => $this->http_method,
            'input_schema' => $this->input_schema,
            'is_mutating' => $this->is_mutating,
            'tools' => count($this->tools ?? []),
            'last_error' => $this->last_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'is_active' => $this->is_active,
        ];
    }
}
