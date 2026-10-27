<?php

namespace App\Home;

use App\Ai\Runs\AgentDirectory;
use App\Ai\Runs\AgentRunner;
use App\Enums\AgentStatus;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\CalendarEvent;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Str;

/**
 * The first day (docs/DECISOES.md, "Primeiro dia", an idea from OpenDuet):
 * the first time a person opens the platform, the agent speaks first. It reads
 * what the platform knows of them (role, area, the subjects of their recent
 * emails, their agenda, their tasks), says who it thinks they are and three
 * ways it can help, and asks «Está certo?». Once per person.
 */
final class FirstDay
{
    public function __construct(private readonly TaskThread $threads, private readonly AgentRunner $runner) {}

    /**
     * The conversation it opened, or null when the person was already welcomed,
     * already talks to agents, or has nobody to be welcomed by.
     */
    public function welcome(User $user): ?Task
    {
        if (($user->getAttributes()['welcomed_at'] ?? null) !== null) {
            return null;
        }

        $user->forceFill(['welcomed_at' => now()])->save();

        if (TaskMessage::query()->where('author_user_id', $user->id)->exists()) {
            return null;
        }

        $agent = $this->host($user);

        if ($agent === null) {
            return null;
        }

        $chat = $this->threads->conversation($user, $agent);
        $note = $this->threads->note($chat, "Primeiro dia de {$user->name} na plataforma.");
        $this->runner->dispatch($agent, $this->brief($user), TriggerType::Manual, $user, $note, $chat->id);

        return $chat;
    }

    /** The Chief of Staff, or else the first agent the person may talk to. */
    private function host(User $user): ?Agent
    {
        $chief = app(AgentDirectory::class)->forRole('chief_of_staff');

        if ($chief !== null && $user->can('run', $chief)) {
            return $chief;
        }

        return Agent::query()->where('status', AgentStatus::Active)->orderBy('name')->get()->first(fn (Agent $agent) => $user->can('run', $agent));
    }

    private function brief(User $user): string
    {
        $user->loadMissing('department:id,name');
        $tz = (string) config('app.timezone');

        $emails = EmailMessage::query()->where('direction', 'inbound')->whereNull('removed_at')
            ->whereIn('mailbox_id', Mailbox::query()->ownedBy($user)->select('id'))
            ->where('received_at', '>=', now()->subDays(14))
            ->latest('received_at')->limit(15)->get(['from_name', 'from_address', 'subject'])
            ->map(fn (EmailMessage $e) => '- '.($e->from_name ?: $e->from_address).': «'.Str::limit((string) $e->subject, 90).'»')->implode("\n");
        $agenda = CalendarEvent::query()->where('user_id', $user->id)->whereBetween('starts_at', [now()->subDays(7), now()->addDays(7)])
            ->orderBy('starts_at')->limit(12)->get(['title', 'starts_at'])
            ->map(fn (CalendarEvent $e) => '- '.$e->starts_at?->setTimezone($tz)->format('d.m H:i').' '.Str::limit((string) $e->title, 80))->implode("\n");
        $tasks = Task::query()->where(fn ($q) => $q->where('assignee_user_id', $user->id)->orWhere('user_id', $user->id))->whereNull('chat_key')
            ->latest('id')->limit(10)->pluck('title')->map(fn (string $t) => "- {$t}")->implode("\n");

        return collect([
            "É o primeiro dia de {$user->name} na plataforma e és tu que falas primeiro.",
            'O que a plataforma sabe desta pessoa:',
            '- Cargo: '.($user->job_title ?: 'não indicado').'; papel: '.$user->role->label().($user->department ? "; área: {$user->department->name}" : '').'.',
            $emails !== '' ? "Emails recebidos nas últimas duas semanas (remetente e assunto):\n{$emails}" : 'Ainda não há emails dela na plataforma.',
            $agenda !== '' ? "Agenda desta semana e da próxima:\n{$agenda}" : 'A agenda dela ainda não está ligada.',
            $tasks !== '' ? "Tarefas com ela:\n{$tasks}" : null,
            'Escreve uma mensagem curta e calorosa, sem cerimónia:',
            '1. Apresenta-te numa frase.',
            '2. Diz quem achas que ela é e em que anda a trabalhar, só pelo que está acima. Não inventes: o que não sabes, não digas.',
            '3. Propõe três formas concretas de a ajudar já esta semana, numa lista numerada, ligadas ao que viste.',
            'No fim, pergunta com questions.ask se acertaste, com uma pergunta «Acertei?» e as opções «Está certo» e «Há algo errado». Não faças mais nada antes da resposta.',
        ])->filter()->implode("\n\n");
    }
}
