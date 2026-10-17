<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\QuestionStatus;
use Database\Factories\AgentQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Questions an agent asked a person with questions.ask, like Claude's
 * AskUserQuestion: its run waits until someone answers (docs/DECISOES.md,
 * "Perguntas dos agentes").
 *
 * questions: list of {question, header, options: list<{label, description}>, multi_select}, or an
 * image to upload for a document: kind "file" and asset {key, label}, no options
 * answers: list of {question, selected: list<string>, other: ?string}, in the same order
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_run_id
 * @property int $agent_id
 * @property int|null $task_id
 * @property int|null $task_message_id
 * @property int|null $asked_user_id
 * @property list<array{question: string, header: string, options: list<array{label: string, description: string}>, multi_select: bool, kind?: 'file', asset?: array{key: string, label: string}}> $questions
 * @property list<array{question: string, selected: list<string>, other: string|null, file?: string|null}>|null $answers
 * @property QuestionStatus $status
 * @property int|null $answered_by_user_id
 * @property Carbon|null $answered_at
 * @property Carbon|null $resumed_at
 * @property Carbon $created_at
 */
#[Fillable(['agent_run_id', 'agent_id', 'task_id', 'task_message_id', 'asked_user_id', 'questions', 'answers', 'status', 'answered_by_user_id', 'answered_at', 'resumed_at'])]
class AgentQuestion extends Model
{
    /** @use HasFactory<AgentQuestionFactory> */
    use BelongsToTenant, HasFactory;

    public function isPending(): bool
    {
        return $this->status === QuestionStatus::Pending;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', QuestionStatus::Pending);
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function askedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'answers' => 'array',
            'status' => QuestionStatus::class,
            'answered_at' => 'datetime',
            'resumed_at' => 'datetime',
        ];
    }
}
