<?php

namespace App\Home;

use App\Ai\ProviderKeys;
use App\Ai\Runs\AgentDirectory;
use App\Ai\Runs\ApprovalService;
use App\Email\EmailReferences;
use App\Enums\EmailCategory;
use App\Enums\ErpConnectionStatus;
use App\Enums\MailboxStatus;
use App\Enums\QuestionStatus;
use App\Enums\TaskStatus;
use App\Models\AgentQuestion;
use App\Models\Approval;
use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\EmailMessage;
use App\Models\ErpConnection;
use App\Models\Mailbox;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use App\Workflows\EmailRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The morning summary (docs/DECISOES.md, "Resumo da manhã", an idea from
 * OpenDuet): the first time a person opens the entry screen each day, the
 * Chief of Staff has written what needs them and why, what can wait, today's
 * agenda and what it could not check. Built from what the platform knows, no
 * model: every line is a fact with its link. An email line has «Começar»,
 * which hands the email to the agent that handles its kind.
 */
final class MorningSummary
{
    private const MAX_NEEDS = 6;

    private const MAX_WAIT = 4;

    public function __construct(private readonly EmailRouter $router) {}

    /**
     * @return array<string, mixed>|null null once the person closed it today
     */
    public function for(User $user): ?array
    {
        $today = now()->setTimezone((string) config('app.timezone'));

        // Read without the strict-attribute check: a person made in memory has no column yet.
        $seen = $user->getAttributes()['morning_summary_seen_on'] ?? null;

        if ($seen !== null && Carbon::parse((string) $seen)->toDateString() === $today->toDateString()) {
            return null;
        }

        $chief = app(AgentDirectory::class)->forRole('chief_of_staff');
        $needs = array_slice([...$this->approvals($user), ...$this->questions($user), ...$this->waiting($user), ...$this->emails($user), ...$this->overdue($user, $today)], 0, self::MAX_NEEDS);

        return [
            'date' => $today->toDateString(),
            'from' => $chief === null ? null : ['name' => $chief->name, 'avatar_url' => $chief->avatarUrl()],
            'needs' => $needs,
            'can_wait' => $this->canWait($user),
            'agenda' => $this->agenda($user, $today),
            'unverified' => $this->unverified($user),
        ];
    }

    /** A task title without the «(Email #143)» it was given: an email is never named by its number. */
    private function named(string $title): string
    {
        return app(EmailReferences::class)->inTitle($title);
    }

    public function close(User $user): void
    {
        $user->forceFill(['morning_summary_seen_on' => now()->setTimezone((string) config('app.timezone'))->toDateString()])->save();
    }

    /** @return list<array<string, mixed>> */
    private function approvals(User $user): array
    {
        $pending = Approval::query()->visibleTo($user)->pending()->with('agent:id,name')->latest('id')->get();

        if ($pending->isEmpty()) {
            return [];
        }

        $first = $pending->first();

        return [[
            'kind' => 'approval',
            'title' => $pending->count() === 1 ? '1 acção à espera da sua decisão' : "{$pending->count()} acções à espera da sua decisão",
            'why' => 'A mais recente: «'.ApprovalService::actionName($first)."» de {$first->agent->name}. Sem decisão, o trabalho não avança.",
            'href' => '/approvals',
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function questions(User $user): array
    {
        return AgentQuestion::query()->where('asked_user_id', $user->id)->where('status', QuestionStatus::Pending)->whereNotNull('task_id')
            ->with('agent:id,name')->latest('id')->limit(3)->get()
            ->map(fn (AgentQuestion $question) => [
                'kind' => 'question',
                'title' => "{$question->agent->name} fez-lhe uma pergunta",
                'why' => 'Está parado à espera da resposta.',
                'href' => "/tasks/{$question->task_id}",
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function waiting(User $user): array
    {
        return Task::query()->where('user_id', $user->id)->where('status', TaskStatus::WaitingHuman)->whereNull('chat_key')
            ->latest('last_activity_at')->limit(3)->get(['id', 'title'])
            ->map(fn (Task $task) => [
                'kind' => 'task',
                'title' => $this->named($task->title),
                'why' => 'O agente precisa de si para continuar.',
                'href' => "/tasks/{$task->id}",
            ])->values()->all();
    }

    /**
     * Important emails of the last three days to the person, with no answer
     * sent in their conversation yet.
     *
     * @return list<array<string, mixed>>
     */
    private function emails(User $user): array
    {
        return EmailMessage::query()
            ->where('direction', 'inbound')
            ->whereNull('removed_at')
            ->where('received_at', '>=', now()->subDays(3))
            ->whereIn('priority', ['high', 'urgent'])
            ->where(fn (Builder $q) => $q->whereIn('mailbox_id', Mailbox::query()->ownedBy($user)->select('id'))->orWhere('routed_to_user_id', $user->id))
            // Already with an agent («Começar», shared in a conversation, or a task made from it): it is moving.
            ->whereNotIn('id', TaskMessage::query()->whereNotNull('email_message_id')->select('email_message_id'))
            ->whereNotIn('id', Task::query()->where('source_type', (new EmailMessage)->getMorphClass())->whereNotNull('source_id')->select('source_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('email_messages as replies')
                ->whereColumn('replies.thread_id', 'email_messages.thread_id')
                ->whereNotNull('email_messages.thread_id')
                ->where('replies.direction', 'outbound')
                ->whereColumn('replies.created_at', '>', 'email_messages.received_at'))
            ->latest('received_at')->limit(4)->get()
            ->map(function (EmailMessage $email) {
                $agent = $email->classification instanceof EmailCategory ? $this->router->route($email->classification)['agent'] : null;

                return [
                    'kind' => 'email',
                    'title' => ($email->from_name ?: $email->from_address ?: 'Email').': «'.Str::limit((string) $email->subject, 80).'»',
                    'why' => $email->summary ? Str::limit($email->summary, 160) : ($email->priority === 'urgent' ? 'Urgente, ainda sem resposta.' : 'Importante, ainda sem resposta.'),
                    'href' => "/inbox/{$email->id}",
                    'start' => $agent === null ? null : ['email_id' => $email->id, 'agent_id' => $agent->id, 'agent' => $agent->name],
                ];
            })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function overdue(User $user, Carbon $today): array
    {
        return Task::query()->open()->where('assignee_user_id', $user->id)->whereNotNull('due_at')->where('due_at', '<', $today->copy()->startOfDay())
            ->orderBy('due_at')->limit(3)->get(['id', 'title', 'due_at'])
            ->map(fn (Task $task) => [
                'kind' => 'overdue',
                'title' => $this->named($task->title),
                'why' => 'Passou do prazo ('.$task->due_at?->setTimezone((string) config('app.timezone'))->format('d.m').').',
                'href' => "/tasks/{$task->id}",
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function canWait(User $user): array
    {
        return Task::query()->where('user_id', $user->id)->where('status', TaskStatus::InReview)
            ->latest('last_activity_at')->limit(self::MAX_WAIT)->get(['id', 'title'])
            ->map(fn (Task $task) => [
                'title' => $this->named($task->title),
                'why' => 'Pronto para rever quando puder.',
                'href' => "/tasks/{$task->id}",
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function agenda(User $user, Carbon $today): array
    {
        $tz = (string) config('app.timezone');
        $start = $today->copy()->startOfDay()->utc();
        $end = $today->copy()->endOfDay()->utc();

        $events = CalendarEvent::query()->where('user_id', $user->id)->whereBetween('starts_at', [$start, $end])->orderBy('starts_at')->limit(8)->get()
            ->map(fn (CalendarEvent $event) => [
                'time' => $event->all_day ? 'Todo o dia' : $event->starts_at?->setTimezone($tz)->format('H:i'),
                'title' => $event->title ?: 'Sem título',
                'href' => '/agenda',
            ]);

        $due = Task::query()->open()->where('assignee_user_id', $user->id)->whereBetween('due_at', [$start, $end])->orderBy('due_at')->limit(5)->get(['id', 'title', 'due_at'])
            ->map(fn (Task $task) => [
                'time' => 'Prazo',
                'title' => $this->named($task->title),
                'href' => "/tasks/{$task->id}",
            ]);

        return $events->concat($due)->values()->all();
    }

    /**
     * What the summary could not check, so the person does not read silence
     * as «nada de novo».
     *
     * @return list<array<string, mixed>>
     */
    private function unverified(User $user): array
    {
        $lines = Mailbox::query()->ownedBy($user)->where('status', MailboxStatus::Error)->get(['id', 'address', 'last_error'])
            ->map(fn (Mailbox $mailbox) => ['what' => "A caixa {$mailbox->address}", 'why' => 'não sincroniza: '.Str::limit((string) $mailbox->last_error, 120), 'href' => '/settings/health'])
            ->all();

        if (CalendarConnection::query()->where('user_id', $user->id)->whereNotNull('last_error')->exists()) {
            $lines[] = ['what' => 'A sua agenda Google', 'why' => 'não sincronizou da última vez.', 'href' => '/settings/health'];
        }

        if (ErpConnection::query()->where('status', ErpConnectionStatus::Error)->exists()) {
            $lines[] = ['what' => 'O ERP', 'why' => 'não respondeu da última vez que foi testado.', 'href' => '/settings/health'];
        }

        $provider = (string) config('ai.default');
        if (! filled(config("ai.providers.{$provider}.key")) && ! filled(ProviderKeys::fromEnv($provider))) {
            $lines[] = ['what' => 'Os agentes', 'why' => 'não têm chave do modelo de IA: não conseguem trabalhar.', 'href' => '/settings/ai-providers'];
        }

        return $lines;
    }
}
