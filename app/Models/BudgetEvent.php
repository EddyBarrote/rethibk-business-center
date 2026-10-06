<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\BudgetEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A budget threshold crossed (80% warning, 100% cut-off), once per subject
 * and month (section 14.3).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property int|null $agent_run_id
 * @property string $scope
 * @property string $subject_key
 * @property string $period
 * @property int $threshold
 * @property float $spent_usd
 * @property float $cap_usd
 * @property Carbon $created_at
 */
#[Fillable(['agent_id', 'agent_run_id', 'scope', 'subject_key', 'period', 'threshold', 'spent_usd', 'cap_usd'])]
class BudgetEvent extends Model
{
    /** @use HasFactory<BudgetEventFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'spent_usd' => 'float',
            'cap_usd' => 'float',
        ];
    }
}
