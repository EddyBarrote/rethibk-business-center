<?php

namespace App\Ai\Initiatives;

use App\Ai\Budget\BudgetGuard;
use App\Enums\AgentStatus;
use App\Enums\EmailCategory;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentInitiative;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use App\Workflows\EmailRouter;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * What an agent offers to do (docs/DECISOES.md, "Iniciativas dos agentes"),
 * twice a day: the cheapest model reads the emails of the agent's area that
 * have no task yet and the agent's blocked tasks, and offers up to three
 * pieces of work to the person the agent answers to. Nothing is done until
 * that person presses «Começar». Quiet tasks need no offer: the hourly
 * heartbeat (agents:heartbeat) already wakes the agent for them.
 */
class OfferWork
{
    /** Emails of the last hours the heartbeat looks at. */
    private const EMAIL_HOURS = 48;

    public const MAX = 3;

    public function __construct(private readonly BudgetGuard $budget, private readonly EmailRouter $router) {}

    /**
     * @return int how many new initiatives were offered
     */
    public function for(Agent $agent): int
    {
        $person = $agent->reports_to_user_id === null ? null : User::query()->where('is_active', true)->find($agent->reports_to_user_id);

        if ($person === null || $agent->status !== AgentStatus::Active || in_array($agent->settings['template'] ?? $agent->key, ['triage'], true)) {
            return 0;
        }

        AgentInitiative::query()->where('status', AgentInitiative::OPEN)->where('created_at', '<', now()->subDays(AgentInitiative::DAYS_OPEN))->update(['status' => AgentInitiative::EXPIRED]);

        $evidence = collect($this->evidence($agent));

        if ($evidence->isEmpty()) {
            return 0;
        }

        $provider = (string) config('ai.default');

        if (! InitiativeWriter::isFaked() && (blank(config("ai.providers.{$provider}.key")) || ! $this->budget->hasRoom())) {
            return 0;
        }

        $model = (string) config('agents.suggestions_model') ?: null;
        $response = (new InitiativeWriter($this->context($agent, $evidence)))->prompt('Que iniciativas propões hoje?', provider: $provider, model: $model, timeout: 60);
        $data = $response instanceof StructuredAgentResponse ? $response->toArray() : (array) json_decode($response->text, true);

        $this->budget->charge('agents.heartbeat', $response->meta->provider ?? $provider, $response->meta->model ?? $model, (int) $response->usage->inputTokens, (int) $response->usage->outputTokens, subject: $agent, agent: $agent);

        $count = 0;

        foreach (array_slice((array) ($data['initiatives'] ?? []), 0, self::MAX) as $item) {
            // Only the references the heartbeat was given count: no evidence, no offer.
            $refs = collect((array) ($item['evidence'] ?? []))->map(fn ($ref) => trim((string) $ref))->filter(fn (string $ref) => $evidence->has($ref))->unique()->sort()->values();
            $title = Str::limit(trim((string) ($item['title'] ?? '')), 150, '…');

            if ($refs->isEmpty() || $title === '') {
                continue;
            }

            $key = Str::limit($refs->implode(','), 190, '');

            if (AgentInitiative::query()->where('agent_id', $agent->id)->where('dedupe_key', $key)->where('created_at', '>=', now()->subDays(7))->exists()) {
                continue;
            }

            AgentInitiative::query()->create([
                'agent_id' => $agent->id,
                'user_id' => $person->id,
                'title' => $title,
                'why' => Str::limit(trim((string) ($item['why'] ?? '')), 400),
                'plan' => Str::limit(trim((string) ($item['plan'] ?? '')), 900),
                'evidence' => $refs->map(fn (string $ref) => Arr::only((array) $evidence->get($ref), ['ref', 'label', 'href']))->all(),
                'dedupe_key' => $key,
                'status' => AgentInitiative::OPEN,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * What the offers may cite, by reference: emails of the agent's area with
     * no task or conversation yet, and its blocked tasks.
     *
     * @return array<string, array{ref: string, label: string, href: string, detail: string}>
     */
    private function evidence(Agent $agent): array
    {
        $categories = collect(EmailCategory::cases())
            ->filter(fn (EmailCategory $category) => ! in_array($category, [EmailCategory::Spam, EmailCategory::Newsletter], true))
            ->filter(fn (EmailCategory $category) => $this->router->route($category)['agent']?->id === $agent->id)
            ->values();
        $mine = Mailbox::query()->where('agent_id', $agent->id)->pluck('id');
        $offered = AgentInitiative::query()->where('agent_id', $agent->id)->where('created_at', '>=', now()->subDays(7))->pluck('dedupe_key')->implode(',');

        $emails = EmailMessage::query()
            ->readableBy($agent)
            ->where('direction', 'inbound')
            ->where('received_at', '>=', now()->subHours(self::EMAIL_HOURS))
            ->where(fn ($q) => $q->whereIn('classification', $categories)->orWhereIn('mailbox_id', $mine))
            ->whereNotIn('id', Task::query()->where('source_type', (new EmailMessage)->getMorphClass())->whereNotNull('source_id')->select('source_id'))
            ->whereNotIn('id', TaskMessage::query()->whereNotNull('email_message_id')->select('email_message_id'))
            ->latest('received_at')->limit(12)->get()
            ->reject(fn (EmailMessage $email) => str_contains(",{$offered},", ",email:{$email->id},"))
            ->mapWithKeys(fn (EmailMessage $email) => ["email:{$email->id}" => [
                'ref' => "email:{$email->id}",
                'label' => 'Email de '.($email->from_name ?: $email->from_address).': «'.Str::limit((string) $email->subject, 70).'»',
                'href' => "/inbox/{$email->id}",
                'detail' => Str::limit(Str::squish((string) ($email->summary ?: $email->text_body)), 500),
            ]]);

        $stalled = Task::query()
            ->where('assignee_agent_id', $agent->id)
            ->whereNull('chat_key')
            ->where('status', TaskStatus::Blocked)
            ->latest('id')->limit(8)->get(['id', 'title', 'status', 'description', 'last_activity_at'])
            ->reject(fn (Task $task) => str_contains(",{$offered},", ",task:{$task->id},"))
            ->mapWithKeys(fn (Task $task) => ["task:{$task->id}" => [
                'ref' => "task:{$task->id}",
                'label' => "Tarefa bloqueada: «{$task->title}»",
                'href' => "/tasks/{$task->id}",
                'detail' => "Estado {$task->status->label()}, sem movimento desde ".($task->last_activity_at?->format('d.m') ?? 'a criação').'. '.Str::limit(Str::squish((string) $task->description), 300),
            ]]);

        return $emails->merge($stalled)->all();
    }

    /**
     * @param  Collection<string, array{ref: string, label: string, href: string, detail: string}>  $evidence
     */
    private function context(Agent $agent, Collection $evidence): string
    {
        $capabilities = $agent->capabilities()->wherePivot('enabled', true)->get(['capabilities.name', 'capabilities.key'])
            ->map(fn ($c) => "- {$c->name} ({$c->key})")->implode("\n");

        return collect([
            'Hoje: '.now((string) config('agents.schedule_timezone'))->locale('pt')->isoFormat('dddd, D [de] MMMM [de] YYYY, HH:mm').'.',
            "Agente: {$agent->name}".($agent->title ? ", {$agent->title}" : '').'.',
            $agent->description ? "O que faz: {$agent->description}" : null,
            $agent->instructions ? 'Instruções (resumo): '.Str::limit(Str::squish($agent->instructions), 2000) : null,
            $capabilities !== '' ? "Capacidades:\n{$capabilities}" : null,
            "Evidências (cita pela referência entre parênteses rectos):\n".$evidence->map(fn (array $e) => "[{$e['ref']}] {$e['label']}\n  {$e['detail']}")->implode("\n"),
        ])->filter()->implode("\n\n");
    }
}
