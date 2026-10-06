<?php

namespace Database\Factories;

use App\Enums\EmailCategory;
use App\Models\EmailRoute;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailRoute>
 */
class EmailRouteFactory extends Factory
{
    /** One row per category and tenant: each new row takes the next category. */
    private static int $next = 0;

    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'category' => EmailCategory::cases()[self::$next++ % count(EmailCategory::cases())],
        ];
    }
}
