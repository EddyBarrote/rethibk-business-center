<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Mailbox;
use App\Models\MailboxReader;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxReader>
 */
class MailboxReaderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'mailbox_id' => Mailbox::factory()->personal(),
            'agent_id' => Agent::factory(),
            'processes_new' => false,
        ];
    }
}
