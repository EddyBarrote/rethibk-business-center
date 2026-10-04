<?php

namespace Database\Factories;

use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailThread>
 */
class EmailThreadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'mailbox_id' => Mailbox::factory(),
            'subject_normalized' => fake()->sentence(4),
            'last_message_at' => now(),
            'message_count' => 1,
        ];
    }
}
