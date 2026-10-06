<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\AutonomyLevel;
use App\Enums\CapabilitySource;
use App\Enums\Scope;
use Database\Factories\CapabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope as QueryScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Catalogue of what agents can do (section 7): local tools and the ERP tools
 * discovered over MCP. `risk` is the autonomy level an agent needs to run a
 * mutating capability without approval.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property CapabilitySource $source
 * @property string|null $mcp_tool_name
 * @property array<string, mixed>|null $input_schema
 * @property bool $is_mutating
 * @property AutonomyLevel $risk
 * @property bool $is_available
 * @property Scope $scope
 * @property bool $is_enabled
 * @property int|null $platform_connector_id
 * @property int|null $connector_id
 */
#[Fillable(['key', 'name', 'description', 'source', 'scope', 'mcp_tool_name', 'platform_connector_id', 'connector_id', 'input_schema', 'is_mutating', 'risk', 'is_available', 'is_enabled'])]
class Capability extends Model
{
    /** @use HasFactory<CapabilityFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'source' => CapabilitySource::class,
            'input_schema' => 'array',
            'is_mutating' => 'boolean',
            'risk' => AutonomyLevel::class,
            'is_available' => 'boolean',
            'scope' => Scope::class,
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Agents can use it: still offered by its source and switched on by the tenant.
     */
    public function isUsable(): bool
    {
        return $this->is_available && $this->is_enabled;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[QueryScope]
    protected function usable(Builder $query): void
    {
        $query->where('is_available', true)->where('is_enabled', true);
    }

    /**
     * @return BelongsTo<PlatformConnector, $this>
     */
    public function platformConnector(): BelongsTo
    {
        return $this->belongsTo(PlatformConnector::class);
    }

    /**
     * @return BelongsTo<Connector, $this>
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function connectorDefinition(): PlatformConnector|Connector|null
    {
        return $this->connector ?? $this->platformConnector;
    }

    /**
     * @return BelongsToMany<Agent, $this, AgentCapability>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class)->using(AgentCapability::class)->withPivot(['id', 'tenant_id', 'enabled', 'config'])->withTimestamps();
    }
}
