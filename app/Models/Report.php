<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A document an agent drafted for people to review (DraftReport): month-close pack, quote comparison, payroll check, client sheet, meeting brief.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property int|null $agent_run_id
 * @property string $type
 * @property string $title
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string|null $subject_ref
 * @property string $content
 * @property array<string, mixed>|null $data
 * @property string $status
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 */
#[Fillable(['agent_id', 'agent_run_id', 'type', 'title', 'period_start', 'period_end', 'subject_ref', 'content', 'data', 'status', 'reviewed_by_user_id', 'reviewed_at'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'data' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
