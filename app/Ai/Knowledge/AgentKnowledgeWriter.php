<?php

namespace App\Ai\Knowledge;

use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeItem;
use App\Support\Notifier;

/**
 * What happens after an agent writes to the knowledge base (docs/DECISOES.md):
 * from N3 up it is published at once; below, people see it marked "para
 * rever" and agents only after someone approves it. The agent's manager is
 * told either way that there is something new.
 */
final class AgentKnowledgeWriter
{
    public function __construct(private readonly Notifier $notifier) {}

    public static function statusFor(Agent $agent): string
    {
        return $agent->autonomy_level->value >= AutonomyLevel::ExecuteWithinLimits->value
            ? KnowledgeItem::PUBLISHED
            : KnowledgeItem::PENDING_REVIEW;
    }

    public function announce(Agent $agent, KnowledgeItem $item, ?KnowledgeDomain $domain): void
    {
        $manager = $agent->reportsTo;

        if ($manager === null || ! $manager->is_active) {
            return;
        }

        $pending = $item->status === KnowledgeItem::PENDING_REVIEW;

        $this->notifier->notify(
            $manager,
            ($pending ? 'Para rever: ' : 'Novo na base de conhecimento: ').$item->title,
            ($pending ? 'Os outros agentes só o vêem depois de aprovado.' : 'Publicado').($domain ? " Domínio {$domain->name}." : ''),
            "/knowledge/{$item->id}",
            $agent->name,
        );
    }
}
