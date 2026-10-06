<?php

namespace Database\Factories;

use App\Models\EmailFolder;
use App\Models\Mailbox;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailFolder>
 */
class EmailFolderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'mailbox_id' => Mailbox::factory(),
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::INBOX,
            'delimiter' => '.',
            'local' => false,
        ];
    }
}
