<?php

namespace Database\Factories;

use App\Models\Skill;
use App\Models\SkillVersion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkillVersion>
 */
class SkillVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'skill_id' => Skill::factory(),
            'number' => 1,
            'key' => 'propostas',
            'name' => 'Propostas comerciais',
            'description' => 'Usar quando for preciso escrever uma proposta comercial para um cliente.',
            'instructions' => "# Propostas\n\n1. Começar pelo problema do cliente.",
            'files' => [],
            'created_at' => now(),
        ];
    }
}
