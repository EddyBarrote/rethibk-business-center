<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\AiChargeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A model call made outside an agent run's own conversation (reading a
 * scanned PDF, looking at a file's pages for an agent), so it still counts
 * in the monthly budget (BudgetGuard::tenantSpent()).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property string $purpose
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $provider
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float $cost_usd
 * @property Carbon $created_at
 */
#[Fillable(['agent_id', 'purpose', 'subject_type', 'subject_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'cost_usd'])]
class AiCharge extends Model
{
    /** @use HasFactory<AiChargeFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return ['cost_usd' => 'float'];
    }
}
