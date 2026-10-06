<?php

namespace Database\Factories;

use App\Enums\ErpConnectionStatus;
use App\Enums\ErpTransport;
use App\Models\ErpConnection;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErpConnection>
 */
class ErpConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'name' => 'Rethink ERP',
            'transport' => ErpTransport::Web,
            'base_url' => 'https://erp.example.test/mcp',
            'auth_type' => 'token',
            'credentials' => ['token' => 'test-token-'.fake()->uuid()],
            'status' => ErpConnectionStatus::Untested,
        ];
    }

    /**
     * The local fake ERP server (section 8.2).
     */
    public function local(): static
    {
        return $this->state(['transport' => ErpTransport::Local, 'base_url' => null, 'credentials' => null]);
    }
}
