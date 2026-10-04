<?php

namespace Database\Factories;

use App\Enums\AutonomyLevel;
use App\Enums\SkillSource;
use App\Models\Skill;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    public function definition(): array
    {
        $tool = 'crm.'.fake()->unique()->word();

        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'key' => 'erp.'.$tool,
            'name' => $tool,
            'description' => 'Ferramenta de teste.',
            'source' => SkillSource::Mcp,
            'mcp_tool_name' => $tool,
            'input_schema' => null,
            'is_mutating' => false,
            'risk' => AutonomyLevel::Observe,
            'is_available' => true,
        ];
    }
}
