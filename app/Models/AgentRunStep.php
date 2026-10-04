<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\StepType;
use Database\Factories\AgentRunStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_run_id
 * @property int $seq
 * @property StepType $type
 * @property string|null $tool_name
 * @property array<string, mixed>|null $payload
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
#[Fillable(['agent_run_id', 'seq', 'type', 'tool_name', 'payload', 'duration_ms'])]
class AgentRunStep extends Model
{
    /** @use HasFactory<AgentRunStepFactory> */
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toBroadcast(): array
    {
        return [
            'id' => $this->id,
            'seq' => $this->seq,
            'type' => $this->type->value,
            'tool_name' => $this->tool_name,
            'payload' => $this->payload,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    protected function casts(): array
    {
        return [
            'type' => StepType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
