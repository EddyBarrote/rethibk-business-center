<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\AgentMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fact an agent learned (docs/DECISOES.md, realinhamento L7). Each person
 * has their own memory with each agent (09.10.2026): a fact belongs to the
 * person it was learned with (`user_id`), or to the agent alone when the work
 * had no person. Work facts also go to the knowledge base, shared with
 * everyone; personal facts never leave the person's memory.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_id
 * @property int|null $user_id
 * @property string $content
 * @property string $kind
 * @property int|null $about_user_id
 * @property int|null $task_id
 * @property int|null $knowledge_item_id
 * @property int|null $edited_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['agent_id', 'user_id', 'content', 'kind', 'about_user_id', 'task_id', 'knowledge_item_id', 'edited_by_user_id'])]
class AgentMemory extends Model
{
    /** @use HasFactory<AgentMemoryFactory> */
    use BelongsToTenant, HasFactory;

    public const WORK = 'work';

    public const PERSONAL = 'personal';

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * The person whose memory with the agent this is.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function aboutUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'about_user_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
