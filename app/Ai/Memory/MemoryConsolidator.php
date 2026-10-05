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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Consolidated memory (docs/DECISOES.md, realinhamento L7): after a task, and
 * once a day for conversations, the agent keeps the facts worth remembering.
 * Work facts reach every conversation of the agent and, automatically, the
 * knowledge base (one article per agent); personal facts stay with the person.
 */
final class MemoryConsolidator
{
    /** How many facts reach the agent's prompt. */
    public const PROMPT_WORK_FACTS = 40;

    public const PROMPT_PERSONAL_FACTS = 10;

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

        $transcript = $messages->map(fn (TaskMessage $m) => '['.$m->authorName().'] '.Str::limit($m->body, 1500))->implode("\n\n");
        $response = (new MemoryExtractor($agent->name, $this->known($agent)))
            ->prompt("Excerto de {$task->identifier()} «{$task->title}»:\n\n{$transcript}", provider: $agent->provider ?: config('ai.default'), model: $agent->model ?: (config('agents.model') ?: null));

        $facts = (array) (($response instanceof StructuredAgentResponse ? $response->toArray() : (array) json_decode($response->text, true))['facts'] ?? []);
        $people = $this->people($task);
        $saved = 0;

        foreach ($facts as $fact) {
            $content = Str::limit(trim((string) ($fact['content'] ?? '')), 500, '…');

            if ($content === '' || AgentMemory::query()->where('agent_id', $agent->id)->where('content', $content)->exists()) {
                continue;
            }

            $personal = ($fact['kind'] ?? 'work') === AgentMemory::PERSONAL;

            AgentMemory::query()->create([
                'agent_id' => $agent->id,
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
     * What the agent remembers, for its prompt: its work facts, and the
     * personal ones about the person it is talking to.
     */
    public function forPrompt(Agent $agent, ?User $person): string
    {
        $work = AgentMemory::query()->where('agent_id', $agent->id)->where('kind', AgentMemory::WORK)->latest('id')->limit(self::PROMPT_WORK_FACTS)->pluck('content');
        $personal = $person === null ? collect() : AgentMemory::query()->where('agent_id', $agent->id)->where('kind', AgentMemory::PERSONAL)->where('about_user_id', $person->id)->latest('id')->limit(self::PROMPT_PERSONAL_FACTS)->pluck('content');

        if ($work->isEmpty() && $personal->isEmpty()) {
            return '';
        }

        return "## A tua memória\nO que aprendeste em conversas e tarefas anteriores. Usa-o, mas confirma o que pode ter mudado.\n"
            .$work->reverse()->map(fn (string $fact) => "- {$fact}")->implode("\n")
            .($personal->isNotEmpty() ? "\n\nSobre {$person?->name} (só para conversas com esta pessoa):\n".$personal->reverse()->map(fn (string $fact) => "- {$fact}")->implode("\n") : '');
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

    private function known(Agent $agent): string
    {
        $facts = AgentMemory::query()->where('agent_id', $agent->id)->latest('id')->limit(60)->pluck('content');

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
