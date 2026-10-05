<?php

namespace Database\Factories;

use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxOwner>
 */
class MailboxOwnerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'mailbox_id' => Mailbox::factory()->personal(),
            'user_id' => User::factory(),
        ];
    }
}
