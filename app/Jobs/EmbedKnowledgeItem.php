<?php

namespace App\Jobs;

use App\Ai\Knowledge\KnowledgeBase;
use App\Models\KnowledgeItem;
use App\Tenancy\TenantAwareJob;

class EmbedKnowledgeItem extends TenantAwareJob
{
    public int $tries = 3;

    public function __construct(int $tenantId, public int $itemId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(KnowledgeBase $knowledge): void
    {
        $item = KnowledgeItem::query()->find($this->itemId);

        if ($item !== null) {
            $knowledge->embed($item);
        }
    }
}
