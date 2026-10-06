<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentSkill;
use App\Models\Skill;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentSkill>
 */
class AgentSkillFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => Agent::factory(),
            'skill_id' => Skill::factory(),
        ];
    }
}
