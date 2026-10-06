<?php

namespace Database\Factories;

use App\Models\PlatformSkill;
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
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'platform_skill_id' => null,
            'key' => 'skill-'.fake()->unique()->slug(2),
            'name' => 'Propostas comerciais',
            'description' => 'Usar quando for preciso escrever uma proposta comercial para um cliente.',
            'instructions' => "# Propostas\n\n1. Começar pelo problema do cliente.\n2. Preço no fim, com validade de 30 dias.",
            'is_enabled' => true,
        ];
    }

    /**
     * Activates a global skill instead of holding its own content.
     */
    public function global(?PlatformSkill $skill = null): static
    {
        return $this->state(function () use ($skill) {
            $skill ??= PlatformSkill::factory()->create();

            return ['platform_skill_id' => $skill->id, 'key' => $skill->key, 'name' => null, 'description' => null, 'instructions' => null];
        });
    }
}
