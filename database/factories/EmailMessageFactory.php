<?php

namespace Database\Factories;

use App\Enums\EmailStatus;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'mailbox_id' => Mailbox::factory(),
            'direction' => 'inbound',
            'message_id_header' => '<'.Str::uuid().'@exemplo.co.mz>',
            'from_address' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'to' => ['triagem@micomoc.test'],
            'subject' => 'Pedido de cotação '.fake()->words(2, true),
            'text_body' => fake()->paragraph(),
            'status' => EmailStatus::Received,
            'received_at' => now(),
            'dedup_hash' => hash('sha256', Str::uuid()->toString()),
        ];
    }
}
