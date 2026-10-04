<?php

namespace Database\Factories;

use App\Enums\ConnectorKind;
use App\Models\PlatformConnector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformConnector>
 */
class PlatformConnectorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => 'Câmbio do Banco de Moçambique',
            'description' => 'Taxa de câmbio oficial do dia.',
            'kind' => ConnectorKind::Http,
            'url' => 'https://api.example.test/bm',
            'secret' => null,
            'http_method' => 'GET',
            'input_schema' => ['type' => 'object', 'properties' => ['currency' => ['type' => 'string']], 'required' => ['currency']],
            'is_mutating' => false,
            'is_active' => true,
        ];
    }
}
