<?php

namespace Database\Factories;

use App\Models\PlatformSkill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSkill>
 */
class PlatformSkillFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => 'global-'.fake()->unique()->slug(2),
            'name' => 'Tom de voz',
            'description' => 'Usar sempre que escreveres para fora da empresa.',
            'instructions' => 'Escreve de forma directa e cordial.',
            'is_active' => true,
        ];
    }
}
