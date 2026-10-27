<?php

namespace Database\Factories;

use App\Models\AiProviderKey;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderKey>
 */
class AiProviderKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'provider' => fake()->unique()->randomElement(['anthropic', 'openai', 'openrouter', 'deepseek', 'xai', 'mistral', 'gemini']),
            'key' => 'test-key-1234',
            'last_four' => '1234',
        ];
    }
}
