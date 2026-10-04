<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeLocator;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Lists the knowledge base the agent may open: its domains, or the folders
 * and items of one domain. Given to every agent.
 */
final class BrowseKnowledge extends LocalCapability
{
    public function __construct(
        private readonly KnowledgeAccess $access,
        private readonly KnowledgeLocator $locator,
    ) {}

    public function key(): string
    {
        return 'knowledge.browse';
    }

    public function name(): string
    {
        return 'Explorar base de conhecimento';
    }

    public function description(): string
    {
        return 'Lista os domínios da base de conhecimento (Finanças, RH, Clientes...) ou, dado um domínio, as suas pastas e os itens mais recentes. Para ler um item use knowledge.read; para procurar por assunto use memory.search.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'domain' => $schema->string()->description('Nome do domínio. Vazio para listar os domínios.'),
            'folder' => $schema->string()->description('Caminho da pasta dentro do domínio, ex.: "Contratos/2026".'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $arguments = Validator::make($arguments, ['domain' => 'nullable|string|max:120', 'folder' => 'nullable|string|max:255'])->validate();
        $this->access->ensureDefaults();
        $agent = $context->agent;

        if (blank($arguments['domain'] ?? null)) {
            $domains = KnowledgeDomain::query()->whereIn('id', $this->access->domainIds($agent))->orderBy('position')->withCount(['items' => fn ($q) => $q->where('status', KnowledgeItem::PUBLISHED)])->get();

            return CapabilityResult::data(['domains' => $domains->map(fn (KnowledgeDomain $d) => ['name' => $d->name, 'description' => $d->description, 'items' => $d->items_count])->all()]);
        }

        $domain = $this->locator->domain($arguments['domain']);

        if ($domain === null || ! $this->access->canOpen($agent, $domain)) {
            return CapabilityResult::error('Domínio não encontrado ou sem acesso.');
        }

        $folder = filled($arguments['folder'] ?? null) ? $this->locator->folder($domain, $arguments['folder']) : null;

        if (filled($arguments['folder'] ?? null) && $folder === null) {
            return CapabilityResult::error('Pasta não encontrada neste domínio.');
        }

        $folders = KnowledgeFolder::query()->where('knowledge_domain_id', $domain->id)->where('parent_id', $folder?->id)->orderBy('name')->pluck('name');
        $items = $this->access->items($agent)
            ->where('knowledge_domain_id', $domain->id)
            ->where('knowledge_folder_id', $folder?->id)
            ->latest('id')
            ->limit(30)
            ->get(['id', 'type', 'title', 'filename', 'created_at']);

        return CapabilityResult::data([
            'domain' => $domain->name,
            'folder' => $folder?->path(),
            'folders' => $folders->all(),
            'items' => $items->map(fn (KnowledgeItem $i) => ['id' => $i->id, 'title' => $i->title, 'type' => $i->type->label(), 'file' => $i->filename, 'date' => $i->created_at->toDateString()])->all(),
        ]);
    }
}
