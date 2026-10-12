<?php

namespace App\Http\Controllers\Knowledge;

use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Memory\CompanyMemory;
use App\Enums\CompanySection;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanyFact;
use App\Models\KnowledgeDomain;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company memory (docs/DECISOES.md, 12.10.2026): everyone reads the
 * facts they may see; whoever curates the domain writes them and approves
 * what the agents propose.
 */
class CompanyMemoryController extends Controller
{
    public function __construct(
        private readonly CompanyMemory $memory,
        private readonly KnowledgeAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $this->access->ensureDefaults();
        $with = ['domain', 'proposedBy:id,name', 'createdBy:id,name', 'reviewedBy:id,name', 'replaces', 'task:id,title'];

        $proposals = CompanyFact::query()->where('status', CompanyFact::PENDING)->with($with)->latest('id')->get()
            ->filter(fn (CompanyFact $fact) => $this->memory->canCurate($user, $fact->domain))
            ->values();

        return Inertia::render('Knowledge/Company', [
            'sections' => CompanySection::options(),
            'facts' => $this->memory->visibleTo($user)->with($with)->orderBy('id')->get()->map(fn (CompanyFact $f) => $this->row($f, $user))->values(),
            'proposals' => $proposals->map(fn (CompanyFact $f) => $this->row($f, $user))->values(),
            'domains' => KnowledgeDomain::query()->orderBy('position')->orderBy('name')->get()
                ->filter(fn (KnowledgeDomain $d) => $this->memory->canCurate($user, $d))
                ->map(fn (KnowledgeDomain $d) => ['id' => $d->id, 'name' => $d->name, 'color' => $d->color, 'restricted' => $d->isRestricted()])
                ->values(),
            'usage' => $this->memory->usage(),
            'can' => ['write_company' => $user->hasPermission(Permission::ManageKnowledge), 'write' => $user->hasPermission(Permission::ManageKnowledge) || $user->isManager()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $this->validated($request);
        abort_unless($this->memory->canCurate($user, $data['domain']), 403);

        $fact = CompanyFact::query()->create([
            'section' => $data['section'],
            'content' => $data['content'],
            'knowledge_domain_id' => $data['domain']?->id,
            'status' => CompanyFact::PUBLISHED,
            'created_by_user_id' => $user->id,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
        ]);
        AuditLog::record($user, 'company_memory.created', ['section' => $fact->section->value, 'content' => $fact->content], subject: $fact);

        return back()->with('success', 'Facto guardado. Todos os agentes já o sabem.');
    }

    public function update(Request $request, CompanyFact $fact): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(in_array($fact->status, [CompanyFact::PUBLISHED, CompanyFact::PENDING], true) && $this->memory->canCurate($user, $fact->domain), 403);
        $data = $this->validated($request);
        abort_unless($this->memory->canCurate($user, $data['domain']), 403);

        $before = $fact->content;
        $fact->update(['section' => $data['section'], 'content' => $data['content'], 'knowledge_domain_id' => $data['domain']?->id]);
        AuditLog::record($user, 'company_memory.updated', ['section' => $fact->section->value, 'before' => $before, 'content' => $fact->content], subject: $fact);

        return back()->with('success', 'Facto actualizado.');
    }

    public function destroy(Request $request, CompanyFact $fact): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->memory->canCurate($user, $fact->domain), 403);
        AuditLog::record($user, 'company_memory.deleted', ['section' => $fact->section->value, 'content' => $fact->content]);
        $fact->delete();

        return back()->with('success', 'Facto apagado. Os agentes deixam de o saber.');
    }

    public function approve(Request $request, CompanyFact $fact): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($fact->status === CompanyFact::PENDING && $this->memory->canCurate($user, $fact->domain), 403);
        $data = $this->validated($request);
        abort_unless($this->memory->canCurate($user, $data['domain']), 403);

        $this->memory->approve($fact, $user, ['section' => $data['section'], 'content' => $data['content'], 'knowledge_domain_id' => $data['domain']?->id]);

        return back()->with('success', $fact->replaces_id ? 'Aprovado. O facto antigo saiu da memória.' : 'Aprovado. Todos os agentes já o sabem.');
    }

    public function reject(Request $request, CompanyFact $fact): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($fact->status === CompanyFact::PENDING && $this->memory->canCurate($user, $fact->domain), 403);
        $this->memory->reject($fact, $user);

        return back()->with('success', 'Proposta rejeitada.');
    }

    /**
     * @return array{section: CompanySection, content: string, domain: KnowledgeDomain|null}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'section' => ['required', Rule::enum(CompanySection::class)],
            'content' => ['required', 'string', 'max:'.CompanyMemory::MAX_FACT],
            'knowledge_domain_id' => ['nullable', 'integer', TenantRule::exists('knowledge_domains')],
        ]);

        return [
            'section' => CompanySection::from($data['section']),
            'content' => trim($data['content']),
            'domain' => isset($data['knowledge_domain_id']) ? KnowledgeDomain::query()->find($data['knowledge_domain_id']) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CompanyFact $fact, User $user): array
    {
        return [
            'id' => $fact->id,
            'section' => $fact->section->value,
            'content' => $fact->content,
            'status' => $fact->status,
            'domain' => $fact->domain ? ['id' => $fact->domain->id, 'name' => $fact->domain->name, 'color' => $fact->domain->color, 'restricted' => $fact->domain->isRestricted()] : null,
            'proposed_by' => $fact->proposedBy?->name,
            'written_by' => $fact->createdBy?->name,
            'reviewed_by' => $fact->reviewedBy?->name,
            'task' => $fact->task ? ['id' => $fact->task->id, 'title' => $fact->task->title] : null,
            'replaces' => $fact->replaces ? ['id' => $fact->replaces->id, 'content' => $fact->replaces->content] : null,
            'created_at' => $fact->created_at->toIso8601String(),
            'can_edit' => $this->memory->canCurate($user, $fact->domain),
        ];
    }
}
