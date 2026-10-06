<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\BankTransactionStatus;
use Database\Factories\BankTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a bank statement and its reconciliation.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $bank_statement_id
 * @property Carbon $booked_at
 * @property string $description
 * @property string|null $reference
 * @property float $amount
 * @property float|null $balance
 * @property string $fingerprint
 * @property BankTransactionStatus $status
 * @property string|null $match_type
 * @property string|null $match_ref
 * @property string|null $match_note
 * @property int|null $suggested_by_run_id
 * @property int|null $reconciled_by_user_id
 * @property Carbon|null $reconciled_at
 */
#[Fillable(['bank_statement_id', 'booked_at', 'description', 'reference', 'amount', 'balance', 'fingerprint', 'status', 'match_type', 'match_ref', 'match_note', 'suggested_by_run_id', 'reconciled_by_user_id', 'reconciled_at'])]
class BankTransaction extends Model
{
    /** @use HasFactory<BankTransactionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<BankStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    protected function casts(): array
    {
        return [
            'booked_at' => 'date',
            'amount' => 'float',
            'balance' => 'float',
            'status' => BankTransactionStatus::class,
            'reconciled_at' => 'datetime',
        ];
    }
}
