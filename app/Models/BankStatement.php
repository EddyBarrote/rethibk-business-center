<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\BankStatementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bank statement uploaded or received by email (E05).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $account_name
 * @property string|null $bank
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property float|null $closing_balance
 * @property string $currency
 * @property string $source
 * @property string|null $original_name
 * @property string|null $path
 * @property int|null $email_attachment_id
 * @property int|null $uploaded_by_user_id
 * @property int $transaction_count
 * @property Carbon $created_at
 */
#[Fillable(['account_name', 'bank', 'period_start', 'period_end', 'closing_balance', 'currency', 'source', 'original_name', 'path', 'email_attachment_id', 'uploaded_by_user_id', 'transaction_count'])]
class BankStatement extends Model
{
    /** @use HasFactory<BankStatementFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return HasMany<BankTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'closing_balance' => 'float',
        ];
    }
}
