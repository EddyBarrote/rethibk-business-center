<?php

namespace App\Ai\Suggestions;

use App\Ai\Budget\BudgetGuard;
use App\Enums\TaskMessageKind;
use App\Models\Agent;
use App\Models\AgentMemory;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Support\AgentSuggestions;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Once a day each active agent gets new suggestions for its entry screen
 * (docs/DECISOES.md, "Sugestões geradas todos os dias"): written by the
 * cheapest model from its role, its capabilities, the work and the requests
 * of the last days and its memory, and kept on the agent (settings.daily_suggestions).
 */
class DailySuggestions
{
    public function __construct(private readonly BudgetGuard $budget) {}

    /**
     * @return list<string>|null The new suggestions, or null when they could not be written.
     */
    public function generate(Agent $agent): ?array
    {
        $provider = (string) config('ai.default');

        if (! SuggestionWriter::isFaked() && (blank(config("ai.providers.{$provider}.key")) || ! $this->budget->hasRoom())) {
            return null;
        }

        $model = (string) config('agents.suggestions_model') ?: null;
        $response = (new SuggestionWriter($this->context($agent)))->prompt('Escreve as 4 sugestões de hoje.', provider: $provider, model: $model, timeout: 60);
        $data = $response instanceof StructuredAgentResponse ? $response->toArray() : (array) json_decode($response->text, true);

        $this->budget->charge('agents.suggestions', $response->meta->provider ?? $provider, $response->meta->model ?? $model, (int) $response->usage->inputTokens, (int) $response->usage->outputTokens, subject: $agent, agent: $agent);

        $items = collect((array) ($data['suggestions'] ?? []))
            ->map(fn ($text) => Str::limit(trim((string) $text, " \t\n.\"«»"), 80, '…'))
            ->filter(fn (string $text) => $text !== '')
            ->unique()
            ->take(AgentSuggestions::MAX)
            ->values()
            ->all();

        if ($items === []) {
            return null;
        }

        $agent->forceFill(['settings' => [...($agent->settings ?? []), 'daily_suggestions' => ['date' => now()->toDateString(), 'items' => $items]]])->saveQuietly();

        return $items;
    }

    private function context(Agent $agent): string
    {
        $since = now()->subDays(7);
        $capabilities = $agent->capabilities()->wherePivot('enabled', true)->get(['capabilities.name', 'capabilities.key'])
            ->map(fn ($c) => "- {$c->name} ({$c->key})")->implode("\n");
        $tasks = Task::query()->where('assignee_agent_id', $agent->id)->where('kind', 'task')->where('created_at', '>=', $since)
            ->latest('id')->limit(15)->pluck('title')->map(fn (string $t) => "- {$t}")->implode("\n");
        $asked = TaskMessage::query()->where('kind', TaskMessageKind::Message)->whereNotNull('author_user_id')->where('created_at', '>=', $since)
            ->whereHas('task', fn ($q) => $q->where('assignee_agent_id', $agent->id))
            ->latest('id')->limit(12)->pluck('body')->map(fn (string $b) => '- '.Str::limit(Str::squish($b), 160))->implode("\n");
        $memory = AgentMemory::query()->where('agent_id', $agent->id)->latest('updated_at')->limit(20)->get(['title', 'description', 'content'])
            ->map(fn (AgentMemory $m) => '- '.Str::limit($m->title ?? $m->description ?? $m->content, 120))->implode("\n");
        $yesterday = implode(' · ', (array) ($agent->settings['daily_suggestions']['items'] ?? []));

        return collect([
            'Hoje: '.now((string) config('agents.schedule_timezone'))->locale('pt')->isoFormat('dddd, D [de] MMMM [de] YYYY').'.',
            "Agente: {$agent->name}".($agent->title ? ", {$agent->title}" : '').'.',
            $agent->description ? "O que faz: {$agent->description}" : null,
            $agent->instructions ? 'Instruções (resumo): '.Str::limit(Str::squish($agent->instructions), 2500) : null,
            $capabilities !== '' ? "Capacidades:\n{$capabilities}" : null,
            $tasks !== '' ? "Tarefas dos últimos 7 dias:\n{$tasks}" : null,
            $asked !== '' ? "O que as pessoas lhe pediram nos últimos 7 dias:\n{$asked}" : null,
            $memory !== '' ? "Da memória do agente:\n{$memory}" : null,
            $yesterday !== '' ? "Sugestões de ontem: {$yesterday}" : null,
        ])->filter()->implode("\n\n");
    }
}
