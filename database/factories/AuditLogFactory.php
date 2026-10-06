<?php

namespace Database\Factories;

use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'actor_type' => ActorType::System,
            'actor_id' => null,
            'action' => 'test.'.fake()->word(),
            'payload' => [],
            'result' => AuditResult::Ok,
            'ip' => null,
        ];
    }
}
