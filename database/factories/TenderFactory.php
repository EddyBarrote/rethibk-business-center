<?php

namespace Database\Factories;

use App\Enums\TenderStatus;
use App\Models\Tenant;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tender>
 */
class TenderFactory extends Factory
{
    public function definition(): array
    {
        $url = fake()->url();

        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'source' => 'UFSA',
            'reference' => 'CP-'.fake()->numerify('###/2026'),
            'title' => 'Concurso para '.fake()->words(4, true),
            'entity' => 'Entidade pública',
            'url' => $url,
            'url_hash' => hash('sha256', $url.fake()->uuid()),
            'deadline_at' => now()->addDays(14),
            'status' => TenderStatus::New,
        ];
    }
}
