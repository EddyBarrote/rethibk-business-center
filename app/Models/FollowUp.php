<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\FollowUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A reminder scheduled by an agent or a person (ScheduleFollowUp).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property int|null $user_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $title
 * @property string|null $note
 * @property Carbon $due_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $done_at
 */
#[Fillable(['agent_id', 'user_id', 'subject_type', 'subject_id', 'title', 'note', 'due_at', 'notified_at', 'done_at'])]
class FollowUp extends Model
{
    /** @use HasFactory<FollowUpFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
        return ['due_at' => 'datetime', 'notified_at' => 'datetime', 'done_at' => 'datetime'];
    }
}
