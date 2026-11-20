<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\ActorType;
use App\Enums\TaskMessageKind;
use Database\Factories\TaskMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One entry in a task's thread: a person's or an agent's message, a direct
 * action, or a platform event.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $task_id
 * @property ActorType $author_type
 * @property int|null $author_user_id
 * @property int|null $author_agent_id
 * @property TaskMessageKind $kind
 * @property string $body
 * @property int|null $agent_run_id
 * @property int|null $handoff_id
 * @property int|null $email_message_id
 * @property int|null $agent_routine_id
 * @property Carbon $created_at
 */
#[Fillable(['task_id', 'author_type', 'author_user_id', 'author_agent_id', 'kind', 'body', 'agent_run_id', 'handoff_id', 'email_message_id', 'agent_routine_id'])]
class TaskMessage extends Model
{
    /** @use HasFactory<TaskMessageFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The email a person brought into the conversation.
     *
     * @return BelongsTo<EmailMessage, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function authorAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'author_agent_id');
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    /**
     * The hand-off between agents this row shows (docs/DECISOES.md, "Mensagens entre agentes").
     *
     * @return BelongsTo<AgentHandoff, $this>
     */
    public function handoff(): BelongsTo
    {
        return $this->belongsTo(AgentHandoff::class, 'handoff_id');
    }

    /**
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('id');
    }

    /**
     * The questions shown as a card on this message (questions.ask).
     *
     * @return HasOne<AgentQuestion, $this>
     */
    public function question(): HasOne
    {
        return $this->hasOne(AgentQuestion::class);
    }

    public function authorName(): string
    {
        return match ($this->author_type) {
            ActorType::Agent => $this->authorAgent->name ?? 'Agente',
            ActorType::User => $this->authorUser->name ?? 'Pessoa',
            default => 'Plataforma',
        };
    }

    protected function casts(): array
    {
        return [
            'author_type' => ActorType::class,
            'kind' => TaskMessageKind::class,
        ];
    }
}
