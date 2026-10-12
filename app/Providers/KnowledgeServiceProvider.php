<?php

namespace App\Providers;

use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Capabilities\Local\BrowseKnowledge;
use App\Ai\Capabilities\Local\GenerateDocument;
use App\Ai\Capabilities\Local\ProposeCompanyFact;
use App\Ai\Capabilities\Local\ReadKnowledge;
use App\Ai\Capabilities\Local\SaveKnowledge;
use Illuminate\Support\ServiceProvider;

/**
 * The knowledge base and document generation module (docs/CONHECIMENTO.md):
 * its capabilities join the catalogue, and every agent can browse and read
 * the knowledge it is allowed to see and propose facts for the company memory.
 */
class KnowledgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        CapabilityRegistry::register(BrowseKnowledge::class, ReadKnowledge::class, SaveKnowledge::class, GenerateDocument::class, ProposeCompanyFact::class);
        CapabilityRegistry::giveToEveryAgent('knowledge.browse', 'knowledge.read', 'company.propose_fact');
    }
}
