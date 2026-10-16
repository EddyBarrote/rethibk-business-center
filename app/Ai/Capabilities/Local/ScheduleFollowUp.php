<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use App\Enums\TaskKind;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * A reminder for a person or for the agent itself; `followups:notify`
 * delivers it when due, and it shows in the Agenda. It belongs to the task
 * it is about, and is not scheduled when someone already follows the same
 * thing that day (docs/DECISOES.md, "Agenda").
 */
final class ScheduleFollowUp extends LocalCapability
{
    public function key(): string
    {
        return 'followups.schedule';
    }

    public function name(): string
    {
        return 'Agendar seguimento';
    }

    public function description(): string
    {
        return 'Agenda um lembrete de seguimento (por exemplo, voltar a um cliente que não respondeu) para uma data. Aparece na Agenda. Se alguém já acompanha o mesmo nesse dia, não cria outro e diz quem.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Suggest;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'due' => $schema->string()->description('AAAA-MM-DD ou AAAA-MM-DD HH:MM (hora de Maputo).')->required(),
            'note' => $schema->string(),
            'for' => $schema->string()->description('Email da pessoa. Vazio: a pessoa da conversa, ou a chefia do agente.'),
            'email_id' => $schema->integer()->description('Email a que o seguimento se refere.'),
            'task_id' => $schema->integer()->description('Tarefa a que o seguimento se refere. Vazio: a tarefa em curso, ou a tarefa aberta sobre o email.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'title' => 'required|string|max:255',
            'due' => 'required|date',
            'note' => 'nullable|string|max:4000',
            'for' => 'nullable|string|max:255',
            'email_id' => 'nullable|integer',
            'task_id' => 'nullable|integer',
        ])->validate();

        $timezone = (string) config('agents.schedule_timezone');
        $due = Carbon::parse($data['due'], $timezone)->utc();
        $email = isset($data['email_id']) ? EmailMessage::query()->readableBy($context->agent)->find($data['email_id']) : null;
        $current = $context->run->task;
        $chat = $current?->kind === TaskKind::Chat ? $current : null;
        $task = $this->task($data['task_id'] ?? null, $current, $email);
        $email ??= $task?->source instanceof EmailMessage ? $task->source : null;

        // One thing, one reminder (docs/DECISOES.md, "Agenda"): what someone
        // already follows on that day is not scheduled twice.
        if (($tracked = $this->alreadyTracked($task, $email, $due, $timezone)) !== null) {
            return CapabilityResult::data(['scheduled' => false, 'already_tracked' => $tracked]);
        }

        // In a conversation the reminder is that person's, never the boss's:
        // conversations are private.
        $user = match (true) {
            filled($data['for'] ?? null) => User::query()->where('email', mb_strtolower($data['for']))->first(),
            $chat?->user !== null => $chat->user,
            default => $context->agent->reportsTo,
        };

        $followUp = FollowUp::query()->create([
            'agent_id' => $context->agent->id,
            'user_id' => $user?->id,
            'task_id' => $task?->id,
            'subject_type' => $email?->getMorphClass(),
            'subject_id' => $email?->id,
            'title' => $data['title'],
            'note' => $data['note'] ?? null,
            'due_at' => $due,
        ]);

        return CapabilityResult::data([
            'scheduled' => true,
            'follow_up_id' => $followUp->id,
            'due_at' => $followUp->due_at->toIso8601String(),
            'for' => $user?->name,
            'task' => $task?->title,
            'agenda' => '/agenda',
        ]);
    }

    /**
     * The task the reminder belongs to: the one named, the one being worked
     * on, or the open task about the email.
     */
    private function task(?int $id, ?Task $current, ?EmailMessage $email): ?Task
    {
        if ($id !== null) {
            return Task::query()->where('kind', TaskKind::Task)->find($id);
        }

        if ($current?->kind === TaskKind::Task) {
            return $current;
        }

        if ($email === null) {
            return null;
        }

        return Task::query()->where('kind', TaskKind::Task)->open()
            ->where('source_type', $email->getMorphClass())->where('source_id', $email->id)
            ->orderByRaw('assignee_user_id is null')->latest('id')->first();
    }

    /**
     * Who already follows this on that day: an open task about the same
     * email (or the same task) due that day, or an open reminder for it.
     */
    private function alreadyTracked(?Task $task, ?EmailMessage $email, Carbon $due, string $timezone): ?string
    {
        if ($task === null && $email === null) {
            return null;
        }

        $local = $due->copy()->setTimezone($timezone);
        [$from, $to] = [$local->copy()->startOfDay()->utc(), $local->copy()->endOfDay()->utc()];

        $tasks = Task::query()->where('kind', TaskKind::Task)->open()->whereBetween('due_at', [$from, $to])
            ->where(fn (Builder $q) => $q
                ->when($task !== null, fn (Builder $q) => $q->orWhereKey($task->id))
                ->when($email !== null, fn (Builder $q) => $q->orWhere(fn (Builder $q) => $q->where('source_type', $email->getMorphClass())->where('source_id', $email->id))))
            ->with(['assigneeUser', 'assigneeAgent'])->orderByRaw('assignee_user_id is null')->first();

        if ($tasks !== null) {
            $who = $tasks->assigneeUser->name ?? $tasks->assigneeAgent->name ?? 'ninguém';

            return "A tarefa «{$tasks->title}» ({$who}) já acompanha isto, com prazo {$local->format('d/m/Y')}. Não agendei outro seguimento.";
        }

        $reminder = FollowUp::query()->whereNull('done_at')->whereBetween('due_at', [$from, $to])
            ->where(fn (Builder $q) => $q
                ->when($task !== null, fn (Builder $q) => $q->orWhere('task_id', $task->id))
                ->when($email !== null, fn (Builder $q) => $q->orWhere(fn (Builder $q) => $q->where('subject_type', $email->getMorphClass())->where('subject_id', $email->id))))
            ->with(['user', 'agent'])->first();

        if ($reminder !== null) {
            $who = $reminder->user->name ?? $reminder->agent->name ?? '';

            return "Já há o seguimento «{$reminder->title}» para {$local->format('d/m/Y')}".($who !== '' ? " ({$who})" : '').'. Não agendei outro.';
        }

        return null;
    }

    public function summarise(array $arguments): string
    {
        return 'Seguimento: '.($arguments['title'] ?? '').' ('.($arguments['due'] ?? '').')';
    }
}
