<?php

namespace Database\Factories;

use App\Models\PlatformSkill;
use App\Models\PlatformSkillFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSkillFile>
 */
class PlatformSkillFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'platform_skill_id' => PlatformSkill::factory(),
            'filename' => 'exemplos.md',
            'path' => 'skills/platform/exemplos.md',
            'mime' => 'text/markdown',
            'size' => 10,
            'content' => 'Exemplos.',
        ];
    }
}
