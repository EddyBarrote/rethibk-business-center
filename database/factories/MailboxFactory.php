<?php

namespace Database\Factories;

use App\Enums\MailboxStatus;
use App\Models\Mailbox;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => null,
            'address' => fake()->unique()->userName().'@agentes.example.test',
            'display_name' => 'Agente',
            'inbound_provider' => 'imap',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_username' => 'agente@example.test',
            'smtp_password' => 'segredo',
            'smtp_encryption' => 'ssl',
            'status' => MailboxStatus::Active,
        ];
    }

    public function personal(): static
    {
        return $this->state(['kind' => 'person', 'agent_id' => null, 'display_name' => fake()->name()]);
    }
}
