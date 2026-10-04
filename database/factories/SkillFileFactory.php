<?php

namespace Database\Factories;

use App\Models\Skill;
use App\Models\SkillFile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkillFile>
 */
class SkillFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'skill_id' => Skill::factory(),
            'filename' => 'modelo.md',
            'path' => 'skills/test/modelo.md',
            'mime' => 'text/markdown',
            'size' => 42,
            'content' => "# Modelo\n\nCliente: ...",
        ];
    }
}
