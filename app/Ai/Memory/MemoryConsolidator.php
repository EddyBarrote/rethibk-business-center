<?php

namespace App\Ai\Memory;

use App\Ai\Agents\AgentDrafting;
use App\Ai\Budget\BudgetGuard;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Knowledge\KnowledgeLocator;
use App\Enums\ActorType;
use App\Enums\CompanySection;
use App\Enums\KnowledgeType;
use App\Enums\MemorySection;
use App\Jobs\EmbedKnowledgeItem;
use App\Models\Agent;
use App\Models\AgentMemory;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeItem;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use App\Support\MemoryPreferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Consolidated memory (docs/DECISOES.md, realinhamento L7): after a task, once
 * a day for conversations, and whenever a thread has enough new messages, the
 * agent updates its memory entries and a summary of where the thread stands.
 * Each person has their own memory with the agent (09.10.2026), as named
 * entries in sections like claude.ai (12.10.2026); work entries also reach the
 * knowledge base automatically (one article per agent), which is how what one
 * person taught the agent serves the others.
 */
final class MemoryConsolidator
{
    /** How many entries the index in the agent's prompt lists, and how many the extractor reads in full. */
    public const PROMPT_INDEX = 60;

    /** Longest rolling summary of a thread. */
    public const SUMMARY_CHARS = 2000;

    /** The titles the 12.10.2026 migration gave to the loose facts of the old memory. */
    public const LOOSE_NOTES = ['Preferências' => MemorySection::Preferences, 'Notas de trabalho' => MemorySection::Topics];

    public function __construct(
        private readonly BudgetGuard $budget,
        private readonly KnowledgeBase $knowledge,
        private readonly KnowledgeAccess $access,
        private readonly KnowledgeLocator $locator,
        private readonly CompanyMemory $company,
    ) {}

    /**
     * Read what is new in the thread, update the memory entries and the
     * thread's rolling summary. Returns how many entries changed.
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

        $owner = User::query()->find($task->user_id ?? $task->created_by_user_id);
        $prefs = MemoryPreferences::for($owner);
        $remember = $owner === null || $prefs['generate'];
        $transcript = $messages->map(fn (TaskMessage $m) => '['.$m->authorName().'] '.Str::limit($m->body, 1500))->implode("\n\n");
        $output = $this->extract($agent, $owner, "Excerto de {$task->identifier()} «{$task->title}»:\n\n{$transcript}", $this->known($agent, $owner), $task->summary ?: '(nada ainda)', $prefs['sensitive'], $remember);
        $summary = Str::limit(trim((string) ($output['summary'] ?? '')), self::SUMMARY_CHARS, '…');
        $saved = $remember ? $this->apply($agent, $owner, $output, $task) : 0;

        // The rolling summary of the thread, for when its start leaves the agent's history.
        $task->forceFill(['memory_message_id' => $messages->last()->id, 'summary' => $summary !== '' ? $summary : $task->summary])->save();

        return $saved;
    }

    /**
     * Sort the loose notes the migration left (one «Preferências» and one
     * «Notas de trabalho» per memory) into entries by subject. Returns how
     * many entries it wrote; the notes go once their content has a home.
     */
    public function organise(Agent $agent, ?User $person): int
    {
        $loose = $this->memoryOf($agent, $person?->id)->get()->filter(fn (AgentMemory $m) => $this->isLoose($m));

        if ($loose->isEmpty() || ! $this->canCall()) {
            return 0;
        }

        $prefs = MemoryPreferences::for($person);
        $notes = $loose->map(fn (AgentMemory $m) => "{$m->title} (".($m->kind === AgentMemory::PERSONAL ? 'pessoal' : 'trabalho')."):\n{$m->content}")->implode("\n\n");
        $others = $this->memoryOf($agent, $person?->id)->whereNotIn('id', $loose->modelKeys())->get();
        $output = $this->extract($agent, $person, "Notas soltas da memória antiga, para arrumar em entradas por assunto (tudo o que lá está vale a pena; não percas nada):\n\n{$notes}", $this->describe($others), '(não se aplica: são notas, não uma conversa)', $prefs['sensitive'], true);
        $output['forget'] = [];
        $saved = $this->apply($agent, $person, $output, null, except: $loose->modelKeys());

        if ($saved > 0) {
            AgentMemory::query()->whereIn('id', $loose->modelKeys())->delete();
            $this->publish($agent);
        }

        return $saved;
    }

    /**
     * What the agent remembers, for its prompt: «Sobre si» in full and the
     * index of the other entries of its memory with the person it is talking
     * to (unless the person turned memory off), then the index of what it
     * learned from work with no person. What other people taught it reaches
     * it through the knowledge base.
     */
    public function forPrompt(Agent $agent, ?User $person): string
    {
        $prefs = MemoryPreferences::for($person);
        $own = $person === null || ! $prefs['generate'] ? collect() : $this->memoryOf($agent, $person->id)->orderByDesc('updated_at')->limit(self::PROMPT_INDEX)->get();
        $agentOnly = $this->memoryOf($agent, null)->orderByDesc('updated_at')->limit(self::PROMPT_INDEX)->get();
        $search = $person !== null && $prefs['search_chats'] ? "\nPodes procurar nas conversas anteriores com {$person->name} (memory.search_chats) quando a pessoa falar de algo que já trataram." : '';

        if ($own->isEmpty() && $agentOnly->isEmpty()) {
            return $search === '' ? '' : "## A tua memória{$search}";
        }

        $about = $own->filter(fn (AgentMemory $m) => $m->section->aboutYou())->sortBy(fn (AgentMemory $m) => $m->section === MemorySection::Preferences ? 0 : 1);
        $text = "## A tua memória\nO que aprendeste em conversas e tarefas anteriores, em entradas com nome. Usa-o, mas confirma o que pode ter mudado. Das entradas do índice só vês o título e a descrição: quando uma interessar, lê-a inteira com memory.read (pelo número). O que aprendeste com outras pessoas está na base de conhecimento (memory.search).{$search}";

        if ($about->isNotEmpty()) {
            $text .= "\n\n### Sobre {$person?->name}\n".$about->map(fn (AgentMemory $m) => "#### {$m->title}\n{$m->content}")->implode("\n\n");
        }

        if ($own->count() > $about->count()) {
            $text .= "\n\n### O índice da tua memória com {$person?->name}\n".$this->index($own->reject(fn (AgentMemory $m) => $m->section->aboutYou()));
        }

        if ($agentOnly->isNotEmpty()) {
            $text .= "\n\n### Do teu trabalho sem pessoa (rotinas, email)\n".$this->index($agentOnly);
        }

        return $text;
    }

    /**
     * One entry in full, for memory.read: the person's own, or the agent's
     * work with no person. Null when there is none or the person turned memory off.
     */
    public function entry(Agent $agent, ?User $person, int|string $key): ?AgentMemory
    {
        $mine = $person !== null && MemoryPreferences::allows($person, 'generate') ? $person->id : null;
        $query = AgentMemory::query()->where('agent_id', $agent->id)
            ->where(fn (Builder $q) => $q->whereNull('user_id')->when($mine !== null, fn (Builder $q) => $q->orWhere('user_id', $mine)));

        return is_int($key) || ctype_digit($key)
            ? $query->find((int) $key)
            : $query->get()->first(fn (AgentMemory $m) => mb_strtolower((string) $m->title) === mb_strtolower(trim($key)));
    }

    /**
     * Rewrite the agent's article in the knowledge base from its work entries
     * (decisão 19: automatic). Personal entries never go there.
     */
    public function publish(Agent $agent): void
    {
        $facts = AgentMemory::query()->where('agent_id', $agent->id)->where('kind', AgentMemory::WORK)->orderBy('id')->get();
        $itemId = AgentMemory::query()->where('agent_id', $agent->id)->whereNotNull('knowledge_item_id')->value('knowledge_item_id');
        $item = $itemId !== null ? KnowledgeItem::query()->find($itemId) : null;
        $content = "O que {$agent->name} aprendeu no trabalho, actualizado automaticamente.\n\n".$facts->map(fn (AgentMemory $m) => "## {$m->title}\n".($m->description ? "{$m->description}\n\n" : '').$m->content)->implode("\n\n");

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

    /**
     * @return array<string, mixed>
     */
    private function extract(Agent $agent, ?User $person, string $excerpt, string $known, string $summary, bool $sensitive, bool $remember): array
    {
        $response = (new MemoryExtractor($agent->name, $known, $this->companyFacts($agent), $summary, $person?->name, $sensitive, $remember))
            ->prompt($excerpt, provider: $agent->provider ?: config('ai.default'), model: $agent->model ?: (config('agents.model') ?: null));

        return $response instanceof StructuredAgentResponse ? $response->toArray() : (array) json_decode($response->text, true);
    }

    /**
     * Write what the extractor returned: each entry updates the one with the
     * same title, or is new; `forget` deletes by title; company facts go to
     * the company memory for a person to approve. Returns how many entries changed.
     *
     * @param  array<string, mixed>  $output
     * @param  list<int>  $except  entries the extractor must not touch
     */
    private function apply(Agent $agent, ?User $person, array $output, ?Task $task, array $except = []): int
    {
        $existing = $this->memoryOf($agent, $person?->id)->whereNotIn('id', $except)->get()->keyBy(fn (AgentMemory $m) => mb_strtolower((string) $m->title));
        $people = $task !== null ? $this->people($task) : collect(array_filter([$person]));
        $changed = 0;
        $work = false;

        foreach ((array) ($output['entries'] ?? []) as $entry) {
            $title = Str::limit(trim((string) ($entry['title'] ?? '')), AgentMemory::TITLE_CHARS, '…');
            $body = Str::limit(trim((string) ($entry['body'] ?? '')), AgentMemory::BODY_CHARS, '…');
            $section = MemorySection::tryFrom((string) ($entry['section'] ?? '')) ?? MemorySection::Topics;

            if ($title === '' || $body === '') {
                continue;
            }

            if ($person === null && $section->aboutYou()) {
                $section = MemorySection::Topics;
            }

            $kind = $section->aboutYou() || ($entry['kind'] ?? 'work') === AgentMemory::PERSONAL ? AgentMemory::PERSONAL : AgentMemory::WORK;
            $memory = $existing->get(mb_strtolower($title)) ?? new AgentMemory(['agent_id' => $agent->id, 'user_id' => $person?->id]);

            if ($memory->exists && $memory->content === $body && $memory->section === $section) {
                continue;
            }

            $memory->fill([
                'section' => $section,
                'title' => $title,
                'description' => Str::limit(trim((string) ($entry['description'] ?? '')), AgentMemory::DESCRIPTION_CHARS, '…') ?: null,
                'content' => $body,
                'kind' => $kind,
                'about_user_id' => $section === MemorySection::People ? $this->match($people, (string) ($entry['about'] ?? $title))?->id : null,
                'task_id' => $task->id ?? $memory->task_id,
                'edited_by_user_id' => null,
            ])->save();
            $existing->put(mb_strtolower($title), $memory);
            $work = $work || $kind === AgentMemory::WORK;
            $changed++;
        }

        foreach ((array) ($output['forget'] ?? []) as $title) {
            $memory = $existing->pull(mb_strtolower(trim((string) $title)));

            if ($memory instanceof AgentMemory) {
                $work = $work || $memory->kind === AgentMemory::WORK;
                $memory->delete();
                $changed++;
            }
        }

        // Facts for the whole company also go to the company memory, for a person to approve (12.10.2026).
        foreach ((array) ($output['company'] ?? []) as $fact) {
            $content = Str::limit(trim((string) ($fact['content'] ?? '')), 500, '…');

            if ($content !== '') {
                $this->company->propose($agent, CompanySection::tryFrom((string) ($fact['section'] ?? '')) ?? CompanySection::Rules, $content, task: $task);
            }
        }

        if ($work) {
            $this->publish($agent);
        }

        return $changed;
    }

    /**
     * The memory's entries in full, for the extractor to update.
     */
    private function known(Agent $agent, ?User $person): string
    {
        return $this->describe($this->memoryOf($agent, $person?->id)->orderByDesc('updated_at')->limit(self::PROMPT_INDEX)->get());
    }

    /**
     * @param  Collection<int, AgentMemory>  $entries
     */
    private function describe(Collection $entries): string
    {
        return $entries->isEmpty() ? '(nada ainda)' : $entries->map(fn (AgentMemory $m) => "[{$m->section->value}] {$m->title} ({$m->kind}): {$m->description}\n".Str::limit($m->content, 1500))->implode("\n\n");
    }

    /**
     * @param  Collection<int, AgentMemory>  $entries
     */
    private function index(Collection $entries): string
    {
        return $entries->sortBy(fn (AgentMemory $m) => array_search($m->section, MemorySection::cases(), true))
            ->map(fn (AgentMemory $m) => "- #{$m->id} {$m->section->label()} · {$m->title}".($m->description ? " — {$m->description}" : '')." (actualizada {$m->updated_at->format('d.m.Y')})")
            ->implode("\n");
    }

    private function isLoose(AgentMemory $memory): bool
    {
        return (self::LOOSE_NOTES[(string) $memory->title] ?? null) === $memory->section && str_ends_with((string) $memory->description, 'por organizar.');
    }

    private function companyFacts(Agent $agent): string
    {
        $facts = $this->company->visibleTo($agent)->latest('id')->limit(80)->pluck('content');

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
