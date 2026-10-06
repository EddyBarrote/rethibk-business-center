<?php

namespace App\Ai\Knowledge;

use App\Enums\Permission;
use App\Models\Agent;
use App\Models\Department;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Who sees what in the knowledge base (docs/DECISOES.md): a domain open to
 * everyone, or only to some departments; owners and admins see every
 * domain. Agents follow their own department and only ever see published
 * items; people also see what agents wrote and is waiting for review.
 */
final class KnowledgeAccess
{
    /**
     * The default domains, with the department slugs that may open the
     * restricted ones (a word of the slug is enough: "direccao-de-rh").
     */
    private const DEFAULTS = [
        ['name' => 'Geral', 'slug' => 'geral', 'color' => '#64748b', 'description' => 'Políticas, procedimentos e informação para toda a organização.', 'departments' => null],
        ['name' => 'Finanças', 'slug' => 'financas', 'color' => '#0f766e', 'description' => 'Orçamentos, fechos, impostos e relatórios financeiros.', 'departments' => ['financ']],
        ['name' => 'Recursos Humanos', 'slug' => 'rh', 'color' => '#9333ea', 'description' => 'Pessoas, contratos de trabalho, salários e recrutamento.', 'departments' => ['rh', 'recursos-humanos', 'pessoas']],
        ['name' => 'Clientes', 'slug' => 'clientes', 'color' => '#2563eb', 'description' => 'Fichas de clientes, propostas, contratos e histórico comercial.', 'departments' => null],
        ['name' => 'Operações', 'slug' => 'operacoes', 'color' => '#c2410c', 'description' => 'Projectos, obras, fornecedores e procedimentos operacionais.', 'departments' => null],
    ];

    /**
     * Creates the default domains the first time a tenant opens the
     * knowledge base.
     */
    public function ensureDefaults(): void
    {
        if (KnowledgeDomain::query()->exists()) {
            return;
        }

        $departments = Department::query()->get(['id', 'slug']);

        foreach (self::DEFAULTS as $position => $domain) {
            $ids = null;

            if ($domain['departments'] !== null) {
                $ids = $departments
                    ->filter(fn (Department $d) => array_intersect(explode('-', $d->slug), $domain['departments']) !== [] || Str::contains($d->slug, $domain['departments']))
                    ->pluck('id')->values()->all();
            }

            KnowledgeDomain::query()->create([
                'name' => $domain['name'],
                'slug' => $domain['slug'],
                'color' => $domain['color'],
                'description' => $domain['description'],
                'department_ids' => $ids,
                'position' => $position,
            ]);
        }
    }

    public function canOpen(User|Agent $who, ?KnowledgeDomain $domain): bool
    {
        if ($domain === null || ! $domain->isRestricted() || ($who instanceof User && $who->hasPermission(Permission::ManageKnowledge))) {
            return true;
        }

        return $who->department_id !== null && in_array($who->department_id, $domain->department_ids ?? [], true);
    }

    /**
     * @return list<int>
     */
    public function domainIds(User|Agent $who): array
    {
        return KnowledgeDomain::query()->get()
            ->filter(fn (KnowledgeDomain $domain) => $this->canOpen($who, $domain))
            ->pluck('id')->values()->all();
    }

    /**
     * Items the person or agent may read. Without anyone (system context),
     * every published item.
     *
     * @return Builder<KnowledgeItem>
     */
    public function items(User|Agent|null $who): Builder
    {
        $query = KnowledgeItem::query();

        if ($who === null) {
            return $query->where('status', KnowledgeItem::PUBLISHED);
        }

        $domains = $this->domainIds($who);
        $query->where(fn (Builder $q) => $q->whereNull('knowledge_domain_id')->orWhereIn('knowledge_domain_id', $domains));

        return match (true) {
            $who instanceof Agent => $query->where('status', KnowledgeItem::PUBLISHED),
            $who->hasPermission(Permission::ManageKnowledge) => $query,
            default => $query->where('status', '!=', KnowledgeItem::REJECTED),
        };
    }

    public function canRead(User|Agent $who, KnowledgeItem $item): bool
    {
        return $this->items($who)->whereKey($item->id)->exists();
    }

    /**
     * Owners and admins, and managers of a department the domain is open
     * to, approve what agents wrote and organise the domain.
     */
    public function canCurate(User $user, ?KnowledgeDomain $domain): bool
    {
        if ($user->hasPermission(Permission::ManageKnowledge)) {
            return true;
        }

        return $user->isManager() && $this->canOpen($user, $domain);
    }
}
