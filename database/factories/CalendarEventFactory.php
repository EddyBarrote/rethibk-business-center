<?php

namespace Database\Factories;

use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'calendar_connection_id' => CalendarConnection::factory(),
            'user_id' => fn (array $attributes) => CalendarConnection::query()->find($attributes['calendar_connection_id'])?->user_id,
            'direction' => CalendarEvent::IN,
            'external_id' => fake()->uuid(),
            'title' => 'Reunião com o cliente',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
            'all_day' => false,
        ];
    }
}
