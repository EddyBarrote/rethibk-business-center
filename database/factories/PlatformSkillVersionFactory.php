<?php

namespace Database\Factories;

use App\Models\PlatformSkill;
use App\Models\PlatformSkillVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSkillVersion>
 */
class PlatformSkillVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'platform_skill_id' => PlatformSkill::factory(),
            'number' => 1,
            'key' => 'tom-de-voz',
            'name' => 'Tom de voz',
            'description' => 'Usar sempre que escreveres para fora da empresa.',
            'instructions' => 'Escreve de forma directa e cordial.',
            'files' => [],
            'created_at' => now(),
        ];
    }
}
