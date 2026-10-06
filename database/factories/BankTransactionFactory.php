<?php

namespace Database\Factories;

use App\Enums\BankTransactionStatus;
use App\Models\BankStatement;
use App\Models\BankTransaction;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransaction>
 */
class BankTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'bank_statement_id' => BankStatement::factory(),
            'booked_at' => today(),
            'description' => 'TRF '.fake()->company(),
            'amount' => fake()->randomFloat(2, 1000, 500000),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'status' => BankTransactionStatus::Unmatched,
        ];
    }
}
