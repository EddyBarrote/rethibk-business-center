<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\MemorySection;
use Database\Factories\AgentMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * An entry of what an agent remembers (docs/DECISOES.md, realinhamento L7;
 * entries since 12.10.2026): a section, a title, a one-line description and
 * the body (`content`). Each person has their own memory with each agent
 * (09.10.2026): an entry belongs to the person it was learned with
 * (`user_id`), or to the agent alone when the work had no person. Work
 * entries also go to the knowledge base, shared with everyone; personal ones
 * never leave the person's memory.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_id
 * @property int|null $user_id
 * @property MemorySection $section
 * @property string|null $title
 * @property string|null $description
 * @property string $content
 * @property string $kind
 * @property int|null $about_user_id
 * @property int|null $task_id
 * @property int|null $knowledge_item_id
 * @property int|null $edited_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['agent_id', 'user_id', 'section', 'title', 'description', 'content', 'kind', 'about_user_id', 'task_id', 'knowledge_item_id', 'edited_by_user_id'])]
class AgentMemory extends Model
{
    /** @use HasFactory<AgentMemoryFactory> */
    use BelongsToTenant, HasFactory;

    public const WORK = 'work';

    public const PERSONAL = 'personal';

    /** Longest title, description and body of an entry. */
    public const TITLE_CHARS = 120;

    public const DESCRIPTION_CHARS = 200;

    public const BODY_CHARS = 4000;

    /**
     * What a person may change in an entry, from the memory page or the agent's page.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'section' => ['required', Rule::enum(MemorySection::class)],
            'title' => ['required', 'string', 'max:'.self::TITLE_CHARS],
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_CHARS],
            'content' => ['required', 'string', 'max:'.self::BODY_CHARS],
        ];
    }

    protected function casts(): array
    {
        return ['section' => MemorySection::class];
    }

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
