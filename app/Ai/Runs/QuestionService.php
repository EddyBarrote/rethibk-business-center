<?php

namespace App\Ai\Runs;

use App\Ai\Capabilities\CapabilityContext;
use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Enums\NotificationCategory;
use App\Enums\QuestionStatus;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Events\TaskUpdated;
use App\Jobs\RunAgent;
use App\Models\AgentQuestion;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use App\Support\Notifier;
use App\Tasks\TaskThread;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The life of an agent's questions (docs/DECISOES.md, "Perguntas dos agentes"):
 * the agent asks, its run pauses, a person answers on the card (or writes in
 * the conversation), and the same run picks up with the answers.
 */
final class QuestionService
{
    public function __construct(
        private readonly TaskThread $threads,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  list<array{question: string, header: string, options: list<array{label: string, description: string}>, multi_select: bool}>  $questions
     */
    public function ask(array $questions, CapabilityContext $context): AgentQuestion|string
    {
        $run = $context->run;
        $agent = $context->agent;

        if (AgentQuestion::query()->where('agent_run_id', $run->id)->pending()->exists()) {
            return 'já fizeste perguntas nesta execução e estás à espera das respostas. Termina a tua resposta agora.';
        }

        $task = $run->task;
        $person = $task->user ?? $task->createdByUser ?? $run->requestedBy ?? $agent->reportsTo;

        if ($person === null || ! $person->is_active) {
            return 'não há ninguém a quem perguntar (a tarefa não tem pessoa e não tens chefia). Decide com o que sabes e diz o que assumiste.';
        }

        // The question goes where the person talks to this agent: the task itself when it is
        // the agent's and the person's (a run with no task moves into their conversation),
        // otherwise their one conversation with the agent.
        $own = $task !== null && $task->assignee_agent_id === $agent->id
            && in_array($task->user_id ?? $task->created_by_user_id, [$person->id, null], true);

        if ($own) {
            $thread = $task;

            if ($thread->user_id === null) {
                $thread->forceFill(['user_id' => $person->id])->save();
            }
        } else {
            $thread = $this->threads->conversation($person, $agent);

            if ($task === null) {
                $run->forceFill(['task_id' => $thread->id])->save();
                $run->setRelation('task', $thread);
            }
        }

        $question = AgentQuestion::query()->create([
            'agent_run_id' => $run->id,
            'agent_id' => $agent->id,
            'task_id' => $thread->id,
            'asked_user_id' => $person->id,
            'questions' => $questions,
            'status' => QuestionStatus::Pending,
        ]);

        // In the run's own thread the card goes on the agent's reply when the run pauses
        // (TaskThread::recordReply); in another thread it needs a message of its own now.
        if ($thread->id !== $run->task_id) {
            $message = $thread->messages()->create([
                'author_type' => ActorType::Agent,
                'author_agent_id' => $agent->id,
                'kind' => TaskMessageKind::Message,
                'body' => '',
                'agent_run_id' => $run->id,
            ]);
            $question->forceFill(['task_message_id' => $message->id])->save();
        }

        $thread->forceFill([
            'status' => $thread->kind === TaskKind::Task && $thread->status->isClosed() ? $thread->status : TaskStatus::WaitingHuman,
            'last_activity_at' => now(),
        ])->save();
        TaskUpdated::live($thread);

        // Someone chatting with the agent sees the card appear; anyone else is told.
        $live = $run->trigger_type === TriggerType::Manual && $thread->id === $run->task_id && $run->requested_by_user_id === $person->id;

        if (! $live) {
            $count = count($questions);
            $this->notifier->notify(
                $person,
                "{$agent->name} tem ".($count === 1 ? 'uma pergunta' : "{$count} perguntas").' para si',
                Str::limit($questions[0]['question'], 300),
                "/tasks/{$thread->id}",
                $agent->name,
                'warning',
                NotificationCategory::Waiting,
                ['task_id' => $thread->id, 'run_id' => $run->id, 'question_id' => $question->id],
            );
        }

        return $question;
    }

    /**
     * Record a person's answers and resume the run.
     *
     * @param  list<array{selected?: list<string>, other?: string|null}>  $answers  one per question, in order
     */
    public function answer(AgentQuestion $question, User $user, array $answers): AgentQuestion
    {
        $normalised = $this->normalise($question, $answers);

        $updated = AgentQuestion::query()
            ->whereKey($question->id)
            ->where('status', QuestionStatus::Pending)
            ->update([
                'status' => QuestionStatus::Answered,
                'answers' => json_encode($normalised),
                'answered_by_user_id' => $user->id,
                'answered_at' => now(),
            ]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['answers' => 'Estas perguntas já foram respondidas.']);
        }

        $question->refresh();

        AuditLog::record($user, 'question.answered', ['question_id' => $question->id, 'agent_run_id' => $question->agent_run_id, 'answers' => $normalised], AuditResult::Ok, $question);

        $task = $question->task;

        if ($task !== null) {
            $stillWaiting = AgentQuestion::query()->where('task_id', $task->id)->pending()->exists();

            $task->forceFill([
                'status' => ! $stillWaiting && $task->status === TaskStatus::WaitingHuman ? TaskStatus::InProgress : $task->status,
                'last_activity_at' => now(),
            ])->save();
            TaskUpdated::live($task);
        }

        $this->resume($question);

        return $question;
    }

    /**
     * Someone wrote in the conversation instead of using the card: the message
     * answers every question as «Outra».
     */
    public function answerWithMessage(Task $task, TaskMessage $message, User $user): bool
    {
        $question = AgentQuestion::query()->where('task_id', $task->id)->pending()->latest('id')->first();

        if ($question === null) {
            return false;
        }

        $text = trim($message->body) !== '' ? Str::limit(trim($message->body), 2000) : '(enviou ficheiros)';
        $this->answer($question, $user, array_map(fn () => ['selected' => [], 'other' => $text], $question->questions));

        return true;
    }

    /**
     * A run that stopped without being resumed takes its questions with it.
     */
    public function cancelFor(AgentRun $run): void
    {
        AgentQuestion::query()->where('agent_run_id', $run->id)->pending()->update(['status' => QuestionStatus::Cancelled]);
    }

    /**
     * The answered questions a resumed run has not picked up yet, marked as picked up.
     */
    public function takeAnswered(AgentRun $run): ?AgentQuestion
    {
        $question = AgentQuestion::query()
            ->where('agent_run_id', $run->id)
            ->where('status', QuestionStatus::Answered)
            ->whereNull('resumed_at')
            ->latest('id')
            ->first();

        $question?->forceFill(['resumed_at' => now()])->save();

        return $question;
    }

    /**
     * What the resumed run is told.
     */
    public function continuation(AgentQuestion $question): string
    {
        $who = $question->answeredBy->name ?? 'A pessoa';

        return "[{$who}] Respostas às tuas perguntas:\n".$this->transcript($question)
            ."\n\nContinua o trabalho a partir daqui com estas respostas. Não voltes a perguntar o que já foi respondido.";
    }

    /**
     * The questions and, once answered, the answers, as text for the model.
     */
    public function transcript(AgentQuestion $question, bool $withAnswers = true): string
    {
        $lines = [];

        foreach ($question->questions as $index => $item) {
            $options = collect($item['options'])->pluck('label')->implode(' / ');
            $lines[] = "- {$item['question']} ({$options})";

            if ($withAnswers && $question->answers !== null) {
                $lines[] = '  Resposta: '.self::answerText($question->answers[$index] ?? null);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{selected?: list<string>, other?: string|null}|null  $answer
     */
    public static function answerText(?array $answer): string
    {
        $parts = [...($answer['selected'] ?? [])];

        if (filled($answer['other'] ?? null)) {
            $parts[] = '«'.$answer['other'].'»';
        }

        return $parts === [] ? '(sem resposta)' : implode(', ', $parts);
    }

    /**
     * A run that paused after its questions were already answered.
     */
    public function resumeIfAnswered(AgentRun $run): void
    {
        $question = AgentQuestion::query()->where('agent_run_id', $run->id)->where('status', QuestionStatus::Answered)->whereNull('resumed_at')->latest('id')->first();

        if ($question !== null) {
            $this->resume($question);
        }
    }

    private function resume(AgentQuestion $question): void
    {
        $resumed = AgentRun::query()
            ->whereKey($question->agent_run_id)
            ->where('status', RunStatus::AwaitingInput)
            ->update(['status' => RunStatus::Queued, 'finished_at' => null]);

        if ($resumed === 0) {
            return;
        }

        $run = AgentRun::query()->findOrFail($question->agent_run_id);

        RunAgent::dispatch($run->tenant_id, $run->id)
            ->onQueue((string) config('agents.queues.'.$run->trigger_type->value, 'agents'));
    }

    /**
     * @param  list<array{selected?: list<string>, other?: string|null}>  $answers
     * @return list<array{question: string, selected: list<string>, other: string|null}>
     */
    private function normalise(AgentQuestion $question, array $answers): array
    {
        $result = [];

        foreach ($question->questions as $index => $item) {
            $answer = $answers[$index] ?? [];
            $labels = collect($item['options'])->pluck('label')->all();
            $selected = array_values(array_intersect($labels, array_map('strval', (array) ($answer['selected'] ?? []))));
            $other = trim((string) ($answer['other'] ?? ''));
            $other = $other !== '' ? Str::limit($other, 2000, '') : null;

            if ($selected === [] && $other === null) {
                throw ValidationException::withMessages(["answers.{$index}" => "Responda a «{$item['header']}»."]);
            }

            if (! $item['multi_select'] && count($selected) + ($other !== null ? 1 : 0) > 1) {
                throw ValidationException::withMessages(["answers.{$index}" => "«{$item['header']}» aceita uma só resposta."]);
            }

            $result[] = ['question' => $item['question'], 'selected' => $selected, 'other' => $other];
        }

        return $result;
    }
}
