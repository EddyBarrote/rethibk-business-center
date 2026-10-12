<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\CompanySection;
use Database\Factories\CompanyFactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One fact of the company memory (docs/DECISOES.md, 12.10.2026): what every
 * agent always knows. People write them directly; agents propose them and a
 * person approves. A fact limited to a domain only reaches the agents that
 * can open that domain. Approving a fact that replaces another retires it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property CompanySection $section
 * @property string $content
 * @property int|null $knowledge_domain_id
 * @property string $status
 * @property int|null $replaces_id
 * @property int|null $proposed_by_agent_id
 * @property int|null $created_by_user_id
 * @property int|null $task_id
 * @property int|null $knowledge_item_id
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['section', 'content', 'knowledge_domain_id', 'status', 'replaces_id', 'proposed_by_agent_id', 'created_by_user_id', 'task_id', 'knowledge_item_id', 'reviewed_by_user_id', 'reviewed_at'])]
class CompanyFact extends Model
{
    /** @use HasFactory<CompanyFactFactory> */
    use BelongsToTenant, HasFactory;

    public const PUBLISHED = 'published';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    /**
     * @return BelongsTo<KnowledgeDomain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDomain::class, 'knowledge_domain_id');
    }

    /**
     * @return BelongsTo<CompanyFact, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'proposed_by_agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected function casts(): array
    {
        return [
            'section' => CompanySection::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
