<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\AutonomyLevel;
use App\Enums\SkillSource;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Catalogue of what agents can do (section 7): local tools and the ERP tools
 * discovered over MCP. `risk` is the autonomy level an agent needs to run a
 * mutating skill without approval.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property SkillSource $source
 * @property string|null $mcp_tool_name
 * @property array<string, mixed>|null $input_schema
 * @property bool $is_mutating
 * @property AutonomyLevel $risk
 * @property bool $is_available
 */
#[Fillable(['key', 'name', 'description', 'source', 'mcp_tool_name', 'input_schema', 'is_mutating', 'risk', 'is_available'])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'source' => SkillSource::class,
            'input_schema' => 'array',
            'is_mutating' => 'boolean',
            'risk' => AutonomyLevel::class,
            'is_available' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Agent, $this, AgentSkill>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class)->using(AgentSkill::class)->withPivot(['id', 'tenant_id', 'enabled', 'config'])->withTimestamps();
    }
}
