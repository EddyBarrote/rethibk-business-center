<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\RuleEffect;
use Database\Factories\ApprovalRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A standing rule for one capability (docs/DECISOES.md, "Regras de
 * aprovação"): for one agent, or for all of them when agent_id is null. It
 * sits on top of the autonomy levels; the absolute ceiling stays above it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property string $capability_key
 * @property RuleEffect $effect
 * @property int|null $created_by_user_id
 * @property int|null $from_approval_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['agent_id', 'capability_key', 'effect', 'created_by_user_id', 'from_approval_id'])]
class ApprovalRule extends Model
{
    /** @use HasFactory<ApprovalRuleFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return ['effect' => RuleEffect::class];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** «Permitir sempre «Registar oportunidade» ao Gestor de Clientes», for cards and the audit log. */
    public function describe(): string
    {
        $action = Capability::query()->where('key', $this->capability_key)->value('name') ?? $this->capability_key;
        $who = $this->agent_id === null ? 'a todos os agentes' : ($this->loadMissing('agent:id,name')->agent->name ?? 'um agente');

        return match ($this->effect) {
            RuleEffect::Allow => "Permitir sempre «{$action}» {$this->to($who)}",
            RuleEffect::Ask => "Perguntar sempre antes de «{$action}» ({$who})",
            RuleEffect::Deny => "Recusar sempre «{$action}» {$this->to($who)}",
        };
    }

    private function to(string $who): string
    {
        return str_starts_with($who, 'a ') ? $who : "ao {$who}";
    }
}
