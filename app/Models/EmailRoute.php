<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\EmailCategory;
use Database\Factories\EmailRouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which agent handles a kind of email after triage, and who takes over when
 * it cannot (Definições › Regras de email). No row means the default for the
 * category (EmailCategory::handlerRole()); a row without an agent means the
 * email stays with triage and the person it was routed to.
 *
 * @property int $id
 * @property int $tenant_id
 * @property EmailCategory $category
 * @property int|null $agent_id
 * @property int|null $fallback_user_id
 */
#[Fillable(['category', 'agent_id', 'fallback_user_id'])]
class EmailRoute extends Model
{
    /** @use HasFactory<EmailRouteFactory> */
    use BelongsToTenant, HasFactory;

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
    public function fallbackUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fallback_user_id');
    }

    protected function casts(): array
    {
        return ['category' => EmailCategory::class];
    }
}
