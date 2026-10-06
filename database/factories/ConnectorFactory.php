<?php

namespace Database\Factories;

use App\Enums\ConnectorKind;
use App\Models\Connector;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connector>
 */
class ConnectorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'key' => fake()->unique()->slug(2),
            'name' => 'Cotações',
            'description' => 'Devolve a cotação de uma moeda.',
            'kind' => ConnectorKind::Http,
            'url' => 'https://api.example.test/rates',
            'secret' => null,
            'http_method' => 'GET',
            'input_schema' => ['type' => 'object', 'properties' => ['currency' => ['type' => 'string', 'description' => 'Código ISO, ex.: USD']], 'required' => ['currency']],
            'is_mutating' => false,
            'is_active' => true,
        ];
    }

    public function mcp(): static
    {
        return $this->state(['kind' => ConnectorKind::Mcp, 'url' => 'https://mcp.example.test/mcp', 'http_method' => null, 'input_schema' => null]);
    }
}
