<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\AgentCapabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_id
 * @property int $capability_id
 * @property bool $enabled
 * @property array<string, mixed>|null $config
 */
#[Fillable(['agent_id', 'capability_id', 'enabled', 'config'])]
class AgentCapability extends Pivot
{
    /** @use HasFactory<AgentCapabilityFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'agent_capability';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
        ];
    }
}
