<?php

namespace App\Ai\Memory;

use App\Ai\Agents\AgentDrafting;
use App\Ai\Budget\BudgetGuard;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Knowledge\KnowledgeLocator;
use App\Enums\ActorType;
use App\Enums\KnowledgeType;
use App\Jobs\EmbedKnowledgeItem;
use App\Models\Agent;
use App\Models\AgentMemory;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeItem;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Consolidated memory (docs/DECISOES.md, realinhamento L7): after a task, and
 * once a day for conversations, the agent keeps the facts worth remembering.
 * Each person has their own memory with the agent (09.10.2026); work facts
 * also reach the knowledge base automatically (one article per agent), which
 * is how what one person taught the agent serves the others.
 */
final class MemoryConsolidator
{
    /** How many facts reach the agent's prompt. */
    public const PROMPT_PERSON_FACTS = 40;

    public const PROMPT_AGENT_FACTS = 20;

    public function __construct(
        private readonly BudgetGuard $budget,
        private readonly KnowledgeBase $knowledge,
        private readonly KnowledgeAccess $access,
        private readonly KnowledgeLocator $locator,
    ) {}

    /**
     * Read what is new in the thread and keep the facts. Returns how many.
     */
    public function consolidate(Task $task): int
    {
        $agent = $task->assigneeAgent;

        if ($agent === null || ! $this->canCall()) {
            return 0;
        }

        $messages = TaskMessage::query()
            ->where('task_id', $task->id)
            ->where('id', '>', (int) $task->memory_message_id)
            ->whereIn('author_type', [ActorType::User, ActorType::Agent])
            ->with(['authorUser:id,name', 'authorAgent:id,name'])
            ->orderBy('id')
            ->limit(80)
            ->get();

        if ($messages->isEmpty()) {
            return 0;
        }

        $owner = $task->user_id ?? $task->created_by_user_id;
        $transcript = $messages->map(fn (TaskMessage $m) => '['.$m->authorName().'] '.Str::limit($m->body, 1500))->implode("\n\n");
        $response = (new MemoryExtractor($agent->name, $this->known($agent, $owner)))
            ->prompt("Excerto de {$task->identifier()} «{$task->title}»:\n\n{$transcript}", provider: $agent->provider ?: config('ai.default'), model: $agent->model ?: (config('agents.model') ?: null));

        $facts = (array) (($response instanceof StructuredAgentResponse ? $response->toArray() : (array) json_decode($response->text, true))['facts'] ?? []);
        $people = $this->people($task);
        $saved = 0;

        foreach ($facts as $fact) {
            $content = Str::limit(trim((string) ($fact['content'] ?? '')), 500, '…');

            if ($content === '' || $this->memoryOf($agent, $owner)->where('content', $content)->exists()) {
                continue;
            }

            $personal = ($fact['kind'] ?? 'work') === AgentMemory::PERSONAL;

            AgentMemory::query()->create([
                'agent_id' => $agent->id,
                'user_id' => $owner,
                'content' => $content,
                'kind' => $personal ? AgentMemory::PERSONAL : AgentMemory::WORK,
                'about_user_id' => $personal ? ($this->match($people, (string) ($fact['about'] ?? ''))->id ?? $task->user_id) : null,
                'task_id' => $task->id,
            ]);
            $saved++;
        }

        $task->forceFill(['memory_message_id' => $messages->last()->id])->save();

        if ($saved > 0) {
            $this->publish($agent);
        }

        return $saved;
    }

    /**
     * What the agent remembers, for its prompt: its memory with the person it
     * is talking to, and what it learned from work with no person. What other
     * people taught it reaches it through the knowledge base.
     */
    public function forPrompt(Agent $agent, ?User $person): string
    {
        $own = $person === null ? collect() : AgentMemory::query()->where('agent_id', $agent->id)->where('user_id', $person->id)->latest('id')->limit(self::PROMPT_PERSON_FACTS)->pluck('content');
        $agentOnly = AgentMemory::query()->where('agent_id', $agent->id)->whereNull('user_id')->latest('id')->limit(self::PROMPT_AGENT_FACTS)->pluck('content');

        if ($own->isEmpty() && $agentOnly->isEmpty()) {
            return '';
        }

        $list = fn (Collection $facts) => $facts->reverse()->map(fn (string $fact) => "- {$fact}")->implode("\n");

        return "## A tua memória\nO que aprendeste em conversas e tarefas anteriores. Usa-o, mas confirma o que pode ter mudado. O que aprendeste com outras pessoas está na base de conhecimento (memory.search).\n"
            .($own->isNotEmpty() ? "\nCom {$person?->name}:\n".$list($own) : '')
            .($agentOnly->isNotEmpty() ? ($own->isNotEmpty() ? "\n\n" : "\n")."Do teu trabalho sem pessoa (rotinas, email):\n".$list($agentOnly) : '');
    }

    /**
     * Rewrite the agent's article in the knowledge base from its work facts
     * (decisão 19: automatic). Personal facts never go there.
     */
    public function publish(Agent $agent): void
    {
        $facts = AgentMemory::query()->where('agent_id', $agent->id)->where('kind', AgentMemory::WORK)->orderBy('id')->get();
        $itemId = AgentMemory::query()->where('agent_id', $agent->id)->whereNotNull('knowledge_item_id')->value('knowledge_item_id');
        $item = $itemId !== null ? KnowledgeItem::query()->find($itemId) : null;
        $content = "O que {$agent->name} aprendeu no trabalho, actualizado automaticamente.\n\n".$facts->map(fn (AgentMemory $m) => "- {$m->content}")->implode("\n");

        if ($facts->isEmpty() && $item === null) {
            return;
        }

        if ($item === null) {
            $this->access->ensureDefaults();
            $domain = $this->domainFor($agent);
            $item = $this->knowledge->remember(KnowledgeType::Pattern, "Memória de {$agent->name}", $content, $agent, [
                'knowledge_domain_id' => $domain?->id,
                'knowledge_folder_id' => $domain ? $this->locator->folder($domain, 'Memória dos agentes', create: true)?->id : null,
                'status' => KnowledgeItem::PUBLISHED,
            ]);
        } else {
            $item->forceFill(['content' => $content])->save();
            EmbedKnowledgeItem::dispatch($item->tenant_id, $item->id)->onQueue('embeddings');
        }

        AgentMemory::query()->where('agent_id', $agent->id)->where('kind', AgentMemory::WORK)->update(['knowledge_item_id' => $item->id]);
    }

    /**
     * Same checks as the agent assistant: a key, and budget left this month.
     */
    private function canCall(): bool
    {
        return (MemoryExtractor::isFaked() || AgentDrafting::available())
            && ($this->budget->budget()->tenantMonthly === null || $this->budget->tenantSpent() < $this->budget->tenantCap());
    }

    /**
     * The person's memory with the agent, or the agent's own when there is no person.
     *
     * @return Builder<AgentMemory>
     */
    private function memoryOf(Agent $agent, ?int $person): Builder
    {
        return AgentMemory::query()->where('agent_id', $agent->id)->where('user_id', $person);
    }

    private function known(Agent $agent, ?int $person): string
    {
        $facts = $this->memoryOf($agent, $person)->latest('id')->limit(60)->pluck('content');

        return $facts->isEmpty() ? '(nada ainda)' : $facts->map(fn (string $fact) => "- {$fact}")->implode("\n");
    }

    /**
     * @return Collection<int, User>
     */
    private function people(Task $task): Collection
    {
        return User::query()->whereIn('id', array_filter([$task->user_id, $task->created_by_user_id, $task->assignee_user_id]))->get();
    }

    /**
     * @param  Collection<int, User>  $people
     */
    private function match(Collection $people, string $name): ?User
    {
        $name = mb_strtolower(trim($name));

        return $name === '' ? null : $people->first(fn (User $p) => str_contains(mb_strtolower($p->name), $name) || str_contains($name, mb_strtolower($p->name)));
    }

    /**
     * The domain of the agent's department, or "Geral".
     */
    private function domainFor(Agent $agent): ?KnowledgeDomain
    {
        $own = $agent->department_id === null ? null : KnowledgeDomain::query()->get()
            ->first(fn (KnowledgeDomain $d) => in_array($agent->department_id, $d->department_ids ?? [], true));

        return $own ?? $this->locator->domain('geral');
    }
}
