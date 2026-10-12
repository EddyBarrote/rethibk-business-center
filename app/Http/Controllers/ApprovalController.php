<?php

namespace App\Http\Controllers;

use App\Ai\Runs\ApprovalService;
use App\Ai\Runs\RecipientSources;
use App\Enums\ApprovalArea;
use App\Enums\ApprovalStatus;
use App\Enums\Permission;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * The approvals centre (section 12.2): what waits on this person first, then
 * every view of the queue by state, area, agent and words, with the details of
 * one open on the side. Owners and admins see everything; other people see the
 * approvals of agents they answer for and the ones the matrix lets them decide.
 */
class ApprovalController extends Controller
{
    /** How many per page: the first is the default. */
    private const PER_PAGE = [25, 50, 100];

    /** The views of the queue, in the order the page lists them. */
    private const VIEWS = ['por-decidir', 'revisao', 'pendentes', 'aprovadas', 'rejeitadas', 'expiradas', 'todas'];

    /** Addresses from before the views ("?status=pending"). */
    private const LEGACY = ['pending' => 'pendentes', 'approved' => 'aprovadas', 'rejected' => 'rejeitadas', 'expired' => 'expiradas', 'all' => 'todas'];

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        if ($request->filled('status') && ! $request->filled('vista')) {
            $request->merge(['vista' => self::LEGACY[$request->query('status')] ?? null]);
        }

        $filters = $request->validate([
            'vista' => ['nullable', Rule::in(self::VIEWS)],
            'area' => ['nullable', Rule::in(array_column(ApprovalArea::cases(), 'value'))],
            'agente' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
            'aprovacao' => ['nullable', 'integer'],
        ]);
        $view = $filters['vista'] ?? self::VIEWS[0];
        $area = isset($filters['area']) ? ApprovalArea::from($filters['area']) : null;
        $agentId = isset($filters['agente']) ? (int) $filters['agente'] : null;
        $search = trim((string) ($filters['q'] ?? ''));
        $perPage = (int) ($filters['por_pagina'] ?? self::PER_PAGE[0]);

        $page = $this->query($user, $view, $search)
            ->with(Present::APPROVAL_RELATIONS)
            ->when($area, fn (Builder $q) => $area?->scope($q))
            ->when($agentId, fn (Builder $q) => $q->where('agent_id', $agentId))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Approvals/Index', [
            'approvals' => $page->through(fn (Approval $approval) => $this->present($approval, $user)),
            'views' => $this->views($user, $search),
            'areas' => $this->areas($user, $view, $search, $agentId),
            'agents' => $this->agents($user, $view, $search, $area),
            'stats' => fn () => $this->stats($user),
            'open' => fn () => $this->open($user, (int) ($filters['aprovacao'] ?? 0)),
            'filters' => ['vista' => $view, 'area' => $area?->value, 'agente' => $agentId, 'q' => $search, 'por_pagina' => $perPage],
            'per_page_options' => self::PER_PAGE,
        ]);
    }

    public function approve(Request $request, Approval $approval, ApprovalService $approvals): RedirectResponse
    {
        Gate::authorize('decide', $approval);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        try {
            $approvals->approve($approval, $this->user($request), $data['note'] ?? null);
        } catch (LogicException) {
            return back()->with('error', 'Esta acção já tinha sido decidida.');
        }

        return back()->with('success', 'Aprovado. A acção vai ser executada.');
    }

    /**
     * Approves several pending actions at once, each under the same rule as one by one. Actions under the absolute
     * ceiling (payments, external email, contracts…) are never approved in bulk: they are decided one at a time.
     */
    public function approveMany(Request $request, ApprovalService $approvals): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['integer']]);
        $approved = 0;

        foreach (Approval::query()->with('agent')->whereIn('id', $data['ids'])->pending()->whereNull('ceiling_reason')->get() as $approval) {
            if (! $user->can('decide', $approval)) {
                continue;
            }

            try {
                $approvals->approve($approval, $user, null);
                $approved++;
            } catch (LogicException) {
                // Decided by someone else in the meantime.
            }
        }

        return back()->with($approved > 0 ? 'success' : 'error', match ($approved) {
            0 => 'Nenhuma acção aprovada: já estavam decididas ou não lhe cabem.',
            1 => 'Aprovada 1 acção. Vai ser executada.',
            default => "Aprovadas {$approved} acções. Vão ser executadas.",
        });
    }

    /**
     * Rejects several at once with one reason, which every agent reads. Saying no
     * is safe in bulk, so the absolute ceiling does not hold it back.
     */
    public function rejectMany(Request $request, ApprovalService $approvals): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:50'],
            'ids.*' => ['integer'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $rejected = 0;

        foreach (Approval::query()->with(['agent', 'run'])->whereIn('id', $data['ids'])->pending()->get() as $approval) {
            if (! $user->can('decide', $approval)) {
                continue;
            }

            try {
                $approvals->reject($approval, $user, $data['note']);
                $rejected++;
            } catch (LogicException) {
                // Decided by someone else in the meantime.
            }
        }

        return back()->with($rejected > 0 ? 'success' : 'error', match ($rejected) {
            0 => 'Nenhuma acção rejeitada: já estavam decididas ou não lhe cabem.',
            1 => 'Rejeitada 1 acção.',
            default => "Rejeitadas {$rejected} acções.",
        });
    }

    public function reject(Request $request, Approval $approval, ApprovalService $approvals): RedirectResponse
    {
        Gate::authorize('decide', $approval);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        try {
            $approvals->reject($approval, $this->user($request), $data['note']);
        } catch (LogicException) {
            return back()->with('error', 'Esta acção já tinha sido decidida.');
        }

        return back()->with('success', 'Rejeitado.');
    }

    /**
     * What the person sees: everything for those who decide every approval; else
     * their agents' approvals and the ones the matrix lets them decide.
     *
     * @return Builder<Approval>
     */
    private function visible(User $user): Builder
    {
        $query = Approval::query();

        if (! $user->hasPermission(Permission::DecideAllApprovals)) {
            $query->where(fn (Builder $q) => $q->visibleTo($user)->orWhere(fn (Builder $mine) => $mine->decidableBy($user)));
        }

        return $query;
    }

    /**
     * One view of the queue, with the words searched.
     *
     * @return Builder<Approval>
     */
    private function query(User $user, string $view, string $search = ''): Builder
    {
        $query = $this->visible($user);

        match ($view) {
            // Waits on a person and this person may decide it; the Chief of Staff's are in "revisao".
            'por-decidir' => $query->pending()->decidableBy($user)
                ->where(fn (Builder $q) => $q->whereNull('review_stage')->orWhere('review_stage', '!=', Approval::STAGE_AGENT)),
            'revisao' => $query->pending()->where('review_stage', Approval::STAGE_AGENT),
            'pendentes' => $query->pending(),
            'aprovadas' => $query->where('status', ApprovalStatus::Approved),
            'rejeitadas' => $query->where('status', ApprovalStatus::Rejected),
            'expiradas' => $query->where('status', ApprovalStatus::Expired),
            default => null,
        };

        if ($search !== '') {
            $this->search($query, $search);
        }

        return $query;
    }

    /**
     * Every word must appear in what the action says, its arguments, the agent's
     * name or a note, in any case.
     *
     * @param  Builder<Approval>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $words = preg_split('/\s+/u', mb_strtolower(str_replace(['%', '_', '\\'], ' ', $search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_slice($words, 0, 8) as $word) {
            $like = "%{$word}%";
            $query->where(fn (Builder $q) => $q
                ->whereRaw('lower(action_summary) like ?', [$like])
                ->orWhereRaw('lower(action_type) like ?', [$like])
                ->orWhereRaw('lower(cast(payload as char)) like ?', [$like])
                ->orWhereRaw('lower(coalesce(decision_note, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(review_note, \'\')) like ?', [$like])
                ->orWhereHas('agent', fn (Builder $agents) => $agents->whereRaw('lower(name) like ?', [$like])));
        }
    }

    /**
     * Each view with how many it holds.
     *
     * @return list<array{key: string, total: int}>
     */
    private function views(User $user, string $search): array
    {
        return array_map(fn (string $view) => [
            'key' => $view,
            'total' => $this->query($user, $view, $search)->count(),
        ], self::VIEWS);
    }

    /**
     * Each area with how many of the open view are in it.
     *
     * @return list<array{key: string, label: string, total: int}>
     */
    private function areas(User $user, string $view, string $search, ?int $agentId): array
    {
        $counts = array_fill_keys(array_column(ApprovalArea::cases(), 'value'), 0);

        $this->query($user, $view, $search)->reorder()
            ->when($agentId, fn (Builder $q) => $q->where('agent_id', $agentId))
            ->selectRaw('action_type, count(*) as total')
            ->groupBy('action_type')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$counts) {
                $counts[ApprovalArea::of((string) $row->action_type)->value] += (int) $row->total;
            });

        return array_map(fn (ApprovalArea $area) => [
            'key' => $area->value,
            'label' => $area->label(),
            'total' => $counts[$area->value],
        ], ApprovalArea::cases());
    }

    /**
     * The agents that asked for something in the open view, most first.
     *
     * @return list<array{id: int, name: string, total: int}>
     */
    private function agents(User $user, string $view, string $search, ?ApprovalArea $area): array
    {
        $counts = $this->query($user, $view, $search)->reorder()
            ->when($area, fn (Builder $q) => $area?->scope($q))
            ->selectRaw('agent_id, count(*) as total')
            ->groupBy('agent_id')
            ->toBase()
            ->pluck('total', 'agent_id');

        return Agent::query()->whereKey($counts->keys())->orderBy('name')->get(['id', 'name'])
            ->map(fn (Agent $agent) => ['id' => $agent->id, 'name' => $agent->name, 'total' => (int) $counts[$agent->id]])
            ->sortByDesc('total')->values()->all();
    }

    /**
     * The numbers at the top: what waits on this person, how old, what was decided
     * this week and how long people take to decide.
     *
     * @return array<string, mixed>
     */
    private function stats(User $user): array
    {
        $waiting = $this->query($user, 'por-decidir');
        $since = now()->subDays(7);
        $decided = $this->visible($user)->reorder()->where('decided_at', '>=', $since)
            ->selectRaw('status, count(*) as total')->groupBy('status')->toBase()->pluck('total', 'status');

        // Median minutes from the request to a person's decision, over the last 30 days.
        $minutes = $this->visible($user)->reorder()->whereNotNull('decided_by_user_id')->where('decided_at', '>=', now()->subDays(30))
            ->latest('decided_at')->limit(500)->get(['created_at', 'decided_at'])
            ->map(fn (Approval $a) => max(0, (int) round($a->created_at->diffInMinutes($a->decided_at, true))))
            ->sort()->values();

        return [
            'waiting' => (clone $waiting)->count(),
            'ceiling' => (clone $waiting)->whereNotNull('ceiling_reason')->count(),
            'oldest_at' => (clone $waiting)->reorder()->min('created_at') !== null
                ? Carbon::parse((clone $waiting)->reorder()->min('created_at'))->toIso8601String()
                : null,
            'in_review' => $this->query($user, 'revisao')->count(),
            'approved_7d' => (int) ($decided[ApprovalStatus::Approved->value] ?? 0),
            'rejected_7d' => (int) ($decided[ApprovalStatus::Rejected->value] ?? 0),
            'median_minutes' => $minutes->isEmpty() ? null : (int) $minutes[intdiv($minutes->count(), 2)],
        ];
    }

    /**
     * The approval open in the side panel (`?aprovacao=`), with the conversation
     * and run it came from, whether or not it is on this page.
     *
     * @return array<string, mixed>|null
     */
    private function open(User $user, int $id): ?array
    {
        $approval = $id > 0 ? $this->visible($user)->with(Present::APPROVAL_RELATIONS)->find($id) : null;

        if ($approval === null) {
            return null;
        }

        $approval->setRelation('run', AgentRun::query()->with(['agent', 'requestedBy'])->find($approval->agent_run_id));
        $task = $approval->run?->task_id !== null ? Task::query()->with('assigneeAgent:id,name')->find($approval->run->task_id) : null;

        return [
            ...$this->present($approval, $user),
            'run' => $approval->run === null ? null : [
                'id' => $approval->run->id,
                'title' => Present::run($approval->run)['title'],
                'status' => $approval->run->status->value,
                'status_label' => $approval->run->status->label(),
                'requested_by' => $approval->run->requestedBy?->name,
            ],
            'recipients' => app(RecipientSources::class)->forApproval($approval),
            'task' => $task === null || ! $user->can('view', $task) ? null : [
                'id' => $task->id,
                'ref' => $task->identifier(),
                'title' => Present::task($task)['title'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Approval $approval, User $user): array
    {
        return [
            ...Present::approval($approval, $user->can('decide', $approval)),
            'area' => ApprovalArea::of($approval->action_type)->value,
        ];
    }
}
