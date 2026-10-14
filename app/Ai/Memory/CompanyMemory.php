<?php

namespace App\Ai\Memory;

use App\Ai\Knowledge\KnowledgeAccess;
use App\Enums\CompanySection;
use App\Enums\NotificationCategory;
use App\Enums\Permission;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\CompanyFact;
use App\Models\KnowledgeDomain;
use App\Models\Task;
use App\Models\User;
use App\Support\Notifier;
use App\Support\TenantSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The company memory (docs/DECISOES.md, 12.10.2026): one curated list of
 * facts, by section, that reaches every agent's prompt. Agents propose
 * facts; a person who curates the knowledge base approves, edits or rejects
 * them. Facts limited to a domain only reach agents that can open it.
 */
final class CompanyMemory
{
    public const MAX_FACT = 1000;

    public function __construct(
        private readonly KnowledgeAccess $access,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Published facts the agent or person may see.
     *
     * @return Builder<CompanyFact>
     */
    public function visibleTo(User|Agent|null $who): Builder
    {
        $query = CompanyFact::query()->where('status', CompanyFact::PUBLISHED);

        if ($who === null) {
            return $query;
        }

        $domains = $this->access->domainIds($who);

        return $query->where(fn (Builder $q) => $q->whereNull('knowledge_domain_id')->orWhereIn('knowledge_domain_id', $domains));
    }

    /**
     * The section of the prompt every agent reads. Past the size limit the
     * oldest facts are left out and the agent is told so.
     */
    public function forPrompt(Agent $agent): ?string
    {
        $facts = $this->visibleTo($agent)->orderByDesc('id')->get(['id', 'section', 'content', 'created_at']);

        if ($facts->isEmpty()) {
            return null;
        }

        $limit = max(500, TenantSettings::int('company_memory_chars'));
        $used = 0;
        $kept = $facts->takeWhile(function (CompanyFact $fact) use (&$used, $limit) {
            $used += mb_strlen($fact->content) + 3;

            return $used <= $limit;
        });

        $sections = collect(CompanySection::cases())
            ->map(fn (CompanySection $section) => [$section, $kept->where('section', $section)->sortBy('id')])
            ->filter(fn (array $pair) => $pair[1]->isNotEmpty())
            ->map(fn (array $pair) => "### {$pair[0]->label()}\n".$pair[1]
                ->map(fn (CompanyFact $f) => '- '.($pair[0] === CompanySection::Decisions ? $f->created_at->format('d/m/Y').' ' : '').$f->content)
                ->implode("\n"))
            ->implode("\n\n");

        $left = $facts->count() - $kept->count();

        return "## Memória da empresa\nFactos aprovados por pessoas, que valem para toda a empresa. Segue-os. Se uma conversa mostrar que um mudou ou falta algum importante, propõe-o com company.propose_fact; não o tomes como certo até ser aprovado.\n\n"
            .$sections
            .($left > 0 ? "\n\n(Ficaram de fora {$left} factos mais antigos por falta de espaço.)" : '');
    }

    /**
     * How much of the prompt the whole memory takes, for the screen.
     *
     * @return array{used: int, limit: int}
     */
    public function usage(): array
    {
        $used = (int) CompanyFact::query()->where('status', CompanyFact::PUBLISHED)->pluck('content')->sum(fn (string $c) => mb_strlen($c) + 3);

        return ['used' => $used, 'limit' => max(500, TenantSettings::int('company_memory_chars'))];
    }

    /**
     * An agent proposes a fact. The same text already published or waiting
     * is not proposed twice.
     */
    public function propose(Agent $agent, CompanySection $section, string $content, ?KnowledgeDomain $domain = null, ?CompanyFact $replaces = null, ?Task $task = null): CompanyFact
    {
        $content = Str::limit(trim($content), self::MAX_FACT, '…');
        $same = CompanyFact::query()
            ->whereIn('status', [CompanyFact::PUBLISHED, CompanyFact::PENDING])
            ->whereRaw('LOWER(content) = ?', [mb_strtolower($content)])
            ->first();

        if ($same !== null) {
            return $same;
        }

        $fact = CompanyFact::query()->create([
            'section' => $section,
            'content' => $content,
            'knowledge_domain_id' => $domain?->id,
            'status' => CompanyFact::PENDING,
            'replaces_id' => $replaces?->id,
            'proposed_by_agent_id' => $agent->id,
            'task_id' => $task?->id,
        ]);

        foreach ($this->curators($domain) as $user) {
            $this->notifier->notify(
                $user,
                'Para aprovar na memória da empresa: '.Str::limit($content, 80),
                "{$agent->name} propõe um facto para «{$section->label()}». Os agentes só o sabem depois de aprovado.",
                '/settings/company-memory',
                $agent->name,
                category: NotificationCategory::Document,
            );
        }

        return $fact;
    }

    /**
     * Someone who curates the domain approves a proposal, possibly after
     * editing it. The fact it replaces leaves the memory.
     *
     * @param  array{section?: CompanySection, content?: string, knowledge_domain_id?: int|null}  $changes
     */
    public function approve(CompanyFact $fact, User $user, array $changes = []): void
    {
        $fact->fill($changes)->forceFill([
            'status' => CompanyFact::PUBLISHED,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
        ])->save();

        if ($fact->replaces !== null && $fact->replaces->status === CompanyFact::PUBLISHED) {
            $fact->replaces->forceFill(['status' => CompanyFact::SUPERSEDED, 'reviewed_by_user_id' => $user->id, 'reviewed_at' => now()])->save();
        }

        AuditLog::record($user, 'company_memory.approved', ['section' => $fact->section->value, 'content' => $fact->content, 'replaces_id' => $fact->replaces_id], subject: $fact);
    }

    public function reject(CompanyFact $fact, User $user): void
    {
        $fact->forceFill(['status' => CompanyFact::REJECTED, 'reviewed_by_user_id' => $user->id, 'reviewed_at' => now()])->save();
        AuditLog::record($user, 'company_memory.rejected', ['section' => $fact->section->value, 'content' => $fact->content], subject: $fact);
    }

    /**
     * Facts for the whole company are for whoever manages all the knowledge;
     * a domain's facts also for the managers who can open it.
     */
    public function canCurate(User $user, ?KnowledgeDomain $domain): bool
    {
        return $domain === null ? $user->hasPermission(Permission::ManageKnowledge) : $this->access->canCurate($user, $domain);
    }

    /**
     * @return Collection<int, User>
     */
    private function curators(?KnowledgeDomain $domain): Collection
    {
        return User::query()->where('is_active', true)->get()
            ->filter(fn (User $user) => $this->canCurate($user, $domain))
            ->values();
    }
}
