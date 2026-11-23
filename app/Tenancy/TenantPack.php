<?php

namespace App\Tenancy;

use App\Ai\Autonomy\PeopleData;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Documents\Brand;
use App\Enums\AgentStatus;
use App\Enums\CompanySection;
use App\Enums\KnowledgeType;
use App\Enums\Role;
use App\Jobs\EmbedKnowledgeItem;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\CompanyFact;
use App\Models\Department;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sets up a company from a folder kept outside the repository
 * (docs/PACOTE-DA-EMPRESA.md): brand and logo, departments, where each
 * template agent sits and what it says about itself, the facts of the
 * Memória da empresa and knowledge articles. Everything is checked before
 * anything is written, and loading the same pack again changes nothing; an
 * edited pack updates what it names and leaves the rest of the company alone.
 *
 * Runs inside the tenant (TenantManager::run).
 */
final class TenantPack
{
    /** @var array<string, mixed> */
    private array $company = [];

    /** @var list<array{section: CompanySection, content: string, status: string}> */
    private array $facts = [];

    /** @var list<array{title: string, summary: string|null, folder: list<string>, domain: string, content: string}> */
    private array $articles = [];

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(
        private readonly TemplateInstaller $installer,
        private readonly CapabilityCatalog $catalog,
        private readonly KnowledgeBase $knowledge,
        private readonly KnowledgeAccess $access,
    ) {}

    /**
     * @return array<string, int> what was created or changed, by kind
     *
     * @throws TenantPackException when the pack is incomplete or wrong; nothing is written then
     */
    public function load(Tenant $tenant, string $path, AgentStatus $newAgents = AgentStatus::Active): array
    {
        $path = rtrim($path, '/');
        $this->read($path);

        $owner = User::query()->where('role', Role::Owner)->where('is_active', true)->orderBy('id')->first()
            ?? throw new TenantPackException("O tenant {$tenant->slug} não tem um proprietário activo.");

        $this->counts = array_fill_keys(['departments', 'agents_installed', 'agents_updated', 'departments_removed', 'facts', 'articles_created', 'articles_updated'], 0);
        $logo = $this->storeLogo($tenant, $path);

        DB::transaction(function () use ($tenant, $owner, $logo, $newAgents) {
            $this->brand($tenant, $logo);
            $departments = $this->departments($owner);
            $this->agents($owner, $departments, $newAgents);
            $this->removeEmptyDepartments($departments);
            $this->loadFacts($owner);
            $this->loadArticles($owner);
        });

        AuditLog::record($owner, 'tenant.pack_loaded', ['source' => $this->company['source'] ?? null, ...$this->counts]);

        return $this->counts;
    }

    /**
     * Reads and checks the whole pack before anything is written.
     */
    private function read(string $path): void
    {
        if (! is_file($path.'/company.json')) {
            throw new TenantPackException("Falta {$path}/company.json.");
        }

        $company = json_decode((string) file_get_contents($path.'/company.json'), true);

        if (! is_array($company)) {
            throw new TenantPackException('company.json não é JSON válido: '.json_last_error_msg().'.');
        }

        $brand = (array) ($company['brand'] ?? []);

        foreach (['color', 'accent_color'] as $key) {
            if (isset($brand[$key]) && ! preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $brand[$key])) {
                throw new TenantPackException("brand.{$key} tem de ser uma cor #RRGGBB, não «{$brand[$key]}».");
            }
        }

        if (isset($brand['font']) && ! array_key_exists((string) $brand['font'], Brand::FONTS)) {
            throw new TenantPackException("brand.font «{$brand['font']}» não é uma das letras da marca: ".implode(', ', array_keys(Brand::FONTS)).'.');
        }

        if (isset($brand['logo'])) {
            $logo = $path.'/'.ltrim((string) $brand['logo'], '/');

            if (! is_file($logo) || ! in_array(strtolower(pathinfo($logo, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true)) {
                throw new TenantPackException("O logótipo {$brand['logo']} não existe ou não é PNG nem JPG.");
            }
        }

        $names = [];

        foreach ((array) ($company['departments'] ?? []) as $department) {
            $name = trim((string) ($department['name'] ?? ''));

            if ($name === '') {
                throw new TenantPackException('Há um departamento sem nome em company.json.');
            }

            $names[] = $name;
        }

        foreach ((array) ($company['departments'] ?? []) as $department) {
            if (isset($department['parent']) && ! in_array($department['parent'], $names, true)) {
                throw new TenantPackException("O departamento «{$department['name']}» está debaixo de «{$department['parent']}», que não está na lista.");
            }
        }

        if (isset($company['owner_department']) && ! in_array($company['owner_department'], $names, true)) {
            throw new TenantPackException("owner_department «{$company['owner_department']}» não está na lista de departamentos.");
        }

        foreach ((array) ($company['agents'] ?? []) as $key => $agent) {
            if (! array_key_exists((string) $key, AgentTemplates::all())) {
                throw new TenantPackException("Não há modelo de agente «{$key}». Os modelos são: ".implode(', ', array_keys(AgentTemplates::all())).'.');
            }

            if (isset($agent['department']) && ! in_array($agent['department'], $names, true)) {
                throw new TenantPackException("O agente {$key} vai para «{$agent['department']}», que não está na lista de departamentos.");
            }
        }

        $this->company = $company;
        $this->facts = $this->readFacts($path);
        $this->articles = $this->readArticles($path);
    }

    /**
     * @return list<array{section: CompanySection, content: string, status: string}>
     */
    private function readFacts(string $path): array
    {
        if (! is_file($path.'/facts.json')) {
            return [];
        }

        $rows = json_decode((string) file_get_contents($path.'/facts.json'), true);

        if (! is_array($rows)) {
            throw new TenantPackException('facts.json não é JSON válido: '.json_last_error_msg().'.');
        }

        return array_values(array_map(function ($row) {
            $section = CompanySection::tryFrom((string) ($row['section'] ?? ''));
            $content = trim((string) ($row['content'] ?? ''));
            $status = (string) ($row['status'] ?? CompanyFact::PUBLISHED);

            if ($section === null || $content === '' || ! in_array($status, [CompanyFact::PUBLISHED, CompanyFact::PENDING], true)) {
                throw new TenantPackException('Cada facto precisa de section ('.implode(', ', array_column(CompanySection::cases(), 'value')).'), content e, se tiver, status published ou pending: '.json_encode($row, JSON_UNESCAPED_UNICODE));
            }

            return ['section' => $section, 'content' => $content, 'status' => $status];
        }, $rows));
    }

    /**
     * Markdown files in knowledge/, each with front matter: title, summary,
     * folder ("A/B") and domain (slug, «geral» when absent).
     *
     * @return list<array{title: string, summary: string|null, folder: list<string>, domain: string, content: string}>
     */
    private function readArticles(string $path): array
    {
        $files = glob($path.'/knowledge/*.md') ?: [];
        sort($files);

        return array_map(function (string $file) {
            $text = (string) file_get_contents($file);

            if (! preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $text, $parts)) {
                throw new TenantPackException(basename($file).' não começa com o cabeçalho --- title / summary / folder ---.');
            }

            $meta = [];

            foreach (preg_split('/\R/', $parts[1]) ?: [] as $line) {
                if (preg_match('/^(\w+):\s*(.*)$/', $line, $pair)) {
                    $meta[$pair[1]] = trim($pair[2]);
                }
            }

            if (($meta['title'] ?? '') === '' || trim($parts[2]) === '') {
                throw new TenantPackException(basename($file).' precisa de title no cabeçalho e de texto.');
            }

            return [
                'title' => $meta['title'],
                'summary' => ($meta['summary'] ?? '') !== '' ? $meta['summary'] : null,
                'folder' => array_values(array_filter(array_map('trim', explode('/', $meta['folder'] ?? '')), fn (string $part) => $part !== '')),
                'domain' => ($meta['domain'] ?? '') !== '' ? $meta['domain'] : 'geral',
                'content' => trim($parts[2])."\n",
            ];
        }, $files);
    }

    /**
     * The logo goes to the local disk under one name per tenant, so loading
     * again overwrites it instead of piling up copies.
     */
    private function storeLogo(Tenant $tenant, string $path): ?string
    {
        $file = $this->company['brand']['logo'] ?? null;

        if (! is_string($file)) {
            return null;
        }

        $source = $path.'/'.ltrim($file, '/');
        $target = "brand/{$tenant->id}-pack-logo.".strtolower(pathinfo($source, PATHINFO_EXTENSION));
        Storage::disk('local')->put($target, (string) file_get_contents($source));

        return $target;
    }

    private function brand(Tenant $tenant, ?string $logo): void
    {
        $settings = (array) $tenant->settings;
        $brand = (array) ($settings['brand'] ?? []);
        $old = $brand['logo_path'] ?? null;

        $brand = [
            ...$brand,
            ...array_intersect_key((array) ($this->company['brand'] ?? []), array_flip(['color', 'accent_color', 'font', 'footer'])),
            ...($logo !== null ? ['logo_path' => $logo] : []),
        ];

        if ($logo !== null && is_string($old) && $old !== $logo) {
            Storage::disk('local')->delete($old);
        }

        $tenant->forceFill(['settings' => [...$settings, 'brand' => $brand]])->save();
    }

    /**
     * @return array<string, Department> by name
     */
    private function departments(User $owner): array
    {
        $byName = [];

        foreach ((array) ($this->company['departments'] ?? []) as $row) {
            $name = trim((string) $row['name']);
            $department = Department::query()->where('name', $name)->first()
                ?? Department::query()->where('slug', Str::slug($name))->first();

            if ($department === null) {
                $department = Department::query()->create(['name' => $name, 'slug' => Str::slug($name)]);
                $this->counts['departments']++;
            }

            $byName[$name] = $department;
        }

        foreach ((array) ($this->company['departments'] ?? []) as $row) {
            $parent = isset($row['parent']) ? $byName[$row['parent']]->id : null;
            $department = $byName[trim((string) $row['name'])];

            if ($department->parent_id !== $parent) {
                $department->forceFill(['parent_id' => $parent])->save();
            }
        }

        $home = $this->company['owner_department'] ?? null;

        if (is_string($home) && $owner->department_id === null) {
            $owner->forceFill(['department_id' => $byName[$home]->id])->save();
        }

        return $byName;
    }

    /**
     * Installs the template agents the pack names (an agent that already
     * exists keeps its status) and puts each in its department. Its chefia is
     * the department's manager, or the owner; an agent that reads pay and
     * personal data only reports to someone allowed to see them.
     *
     * @param  array<string, Department>  $departments
     */
    private function agents(User $owner, array $departments, AgentStatus $status): void
    {
        $agents = (array) ($this->company['agents'] ?? []);

        if ($agents === []) {
            return;
        }

        $this->catalog->syncLocal();
        $templates = AgentTemplates::all();

        foreach ($agents as $key => $config) {
            $result = $this->installer->install($templates[$key], $owner, $status);
            $agent = $result['agent'];
            $this->counts[$result['created'] ? 'agents_installed' : 'agents_updated']++;

            $changes = array_filter([
                'description' => $config['description'] ?? null,
                'suggestions' => isset($config['suggestions']) ? array_values((array) $config['suggestions']) : null,
            ], fn ($value) => $value !== null);

            if (isset($config['department'])) {
                $department = $departments[$config['department']];
                $changes['department_id'] = $department->id;
                $changes['reports_to_user_id'] = $this->chefia($agent, $department, $owner)?->id;
            }

            $agent->forceFill($changes)->save();
        }
    }

    private function chefia(Agent $agent, Department $department, User $owner): ?User
    {
        $manager = User::query()->where('department_id', $department->id)->where('is_active', true)
            ->whereIn('role', [Role::Manager, Role::Admin, Role::Owner])->orderBy('id')->first() ?? $owner;

        if (! PeopleData::agentHolds($agent) || PeopleData::mayRead($manager)) {
            return $manager;
        }

        $current = $agent->reportsTo;

        if ($current !== null && PeopleData::mayRead($current)) {
            return $current;
        }

        return User::query()->where('role', Role::Owner)->where('is_active', true)->orderBy('id')->get()
            ->first(fn (User $user) => PeopleData::mayRead($user));
    }

    /**
     * Departments the pack does not list and nothing uses (no people, agents
     * or sub-departments), such as the ones the templates create.
     *
     * @param  array<string, Department>  $keep
     */
    private function removeEmptyDepartments(array $keep): void
    {
        if (! ($this->company['remove_empty_departments'] ?? false)) {
            return;
        }

        $ids = array_map(fn (Department $department) => $department->id, array_values($keep));

        Department::query()->whereNotIn('id', $ids)
            ->whereDoesntHave('users')->whereDoesntHave('children')
            ->whereNotIn('id', Agent::query()->whereNotNull('department_id')->select('department_id'))
            ->get()
            ->each(function (Department $department) {
                $department->delete();
                $this->counts['departments_removed']++;
            });
    }

    private function loadFacts(User $owner): void
    {
        foreach ($this->facts as $fact) {
            $created = CompanyFact::query()->firstOrCreate(['content' => $fact['content']], [
                'section' => $fact['section'],
                'status' => $fact['status'],
                'created_by_user_id' => $owner->id,
                ...($fact['status'] === CompanyFact::PUBLISHED ? ['reviewed_by_user_id' => $owner->id, 'reviewed_at' => now()] : []),
            ])->wasRecentlyCreated;

            $this->counts['facts'] += $created ? 1 : 0;
        }
    }

    /**
     * One article per title: a new title is created, a changed text or
     * summary replaces the one there (and is indexed again), the same text is
     * left alone.
     */
    private function loadArticles(User $owner): void
    {
        if ($this->articles === []) {
            return;
        }

        $this->access->ensureDefaults();

        foreach ($this->articles as $article) {
            $domain = KnowledgeDomain::query()->where('slug', $article['domain'])->first()
                ?? throw new TenantPackException("Não há a área de conhecimento «{$article['domain']}» (artigo «{$article['title']}»).");

            $folder = null;

            foreach ($article['folder'] as $name) {
                $folder = KnowledgeFolder::query()->firstOrCreate(['knowledge_domain_id' => $domain->id, 'parent_id' => $folder?->id, 'name' => $name]);
            }

            $placement = ['summary' => $article['summary'], 'knowledge_domain_id' => $domain->id, 'knowledge_folder_id' => $folder?->id];
            $item = KnowledgeItem::query()->where('title', $article['title'])->first();

            if ($item === null) {
                $this->knowledge->remember(KnowledgeType::Article, $article['title'], $article['content'], $owner, [...$placement, 'visibility' => 'tenant', 'status' => 'published']);
                $this->counts['articles_created']++;

                continue;
            }

            $reindex = $item->content !== $article['content'];
            $item->forceFill([...$placement, 'content' => $article['content']]);

            if (! $item->isDirty()) {
                continue;
            }

            $item->save();
            $this->counts['articles_updated']++;

            if ($reindex) {
                $item->forceFill(['embedding_status' => 'pending'])->save();
                EmbedKnowledgeItem::dispatch($item->tenant_id, $item->id)->onQueue('embeddings');
            }
        }
    }
}
