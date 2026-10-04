<?php

namespace Database\Factories;

use App\Enums\BriefingType;
use App\Models\Briefing;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Briefing>
 */
class BriefingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'type' => BriefingType::Daily,
            'title' => 'Briefing de '.now()->format('d/m/Y'),
            'period_start' => today(),
            'period_end' => today(),
            'content' => "## Prioridades\n- ".fake()->sentence(),
            'highlights' => [fake()->sentence()],
            'decisions_pending' => [],
        ];
    }
}
