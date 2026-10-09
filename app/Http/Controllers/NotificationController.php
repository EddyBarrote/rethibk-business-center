<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\NotificationCategory;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The notifications centre: everything agents, watchers and people wanted a
 * person to know, by kind and by day, with approvals decided right there.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'estado' => ['nullable', Rule::in(['todas', 'por-ler'])],
            'tipo' => ['nullable', Rule::in(array_column(NotificationCategory::cases(), 'value'))],
        ]);
        $unreadOnly = ($filters['estado'] ?? 'todas') === 'por-ler';
        $category = isset($filters['tipo']) ? NotificationCategory::from($filters['tipo']) : null;

        $page = $user->notifications()
            ->when($unreadOnly, fn (Builder $q) => $q->whereNull('read_at'))
            ->when($category, fn (Builder $q) => $q->where('type', $category?->value))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        /** @var Collection<int, DatabaseNotification> $items */
        $items = $page->getCollection();
        $approvals = $this->approvals($items);

        return Inertia::render('Notifications/Index', [
            'notifications' => $page->through(fn (DatabaseNotification $n) => $this->present($n, $user, $approvals)),
            'categories' => $this->categories($user),
            'filters' => ['estado' => $unreadOnly ? 'por-ler' : 'todas', 'tipo' => $category?->value],
            'awaiting_decision' => $this->awaitingDecision($user),
        ]);
    }

    /**
     * The latest few, for the bell in the top bar.
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $items = $user->notifications()->latest()->limit(8)->get();
        $approvals = $this->approvals($items);

        return response()->json([
            'notifications' => $items->map(fn (DatabaseNotification $n) => $this->present($n, $user, $approvals))->values(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marks it read and follows its link.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $notice = $this->user($request)->notifications()->findOrFail($notification);
        $notice->markAsRead();

        $url = $this->destination($notice);

        return redirect(str_starts_with($url, '/') && ! str_starts_with($url, '//') && $this->exists($request, $url) ? $url : '/notifications');
    }

    /**
     * Read: the ones chosen, every one of a kind, or all of them.
     */
    public function read(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['string', 'max:64'],
            'tipo' => ['nullable', Rule::in(array_column(NotificationCategory::cases(), 'value'))],
        ]);

        $count = $this->user($request)->unreadNotifications()
            ->when(isset($data['ids']), fn (Builder $q) => $q->whereKey($data['ids']))
            ->when(isset($data['tipo']), fn (Builder $q) => $q->where('type', $data['tipo']))
            ->update(['read_at' => now()]);

        return back()->with('success', match (true) {
            $count === 0 => 'Já estava tudo lido.',
            $count === 1 => 'Uma notificação marcada como lida.',
            default => "{$count} notificações marcadas como lidas.",
        });
    }

    public function unread(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string', 'max:64'],
        ]);

        $this->user($request)->notifications()->whereKey($data['ids'])->update(['read_at' => null]);

        return back();
    }

    /**
     * @param  array<int, Approval>  $approvals
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $n, User $user, array $approvals): array
    {
        $category = NotificationCategory::tryFrom($n->type) ?? NotificationCategory::infer($n->data);
        $approval = $approvals[(int) ($n->data['approval_id'] ?? 0)] ?? null;

        return [
            'id' => $n->id,
            'category' => $category->value,
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'url' => $n->data['url'] ?? null,
            'from' => $n->data['from'] ?? null,
            'level' => $n->data['level'] ?? 'info',
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
            'approval' => $approval === null ? null : [
                'id' => $approval->id,
                'status' => $approval->status->value,
                'status_label' => $approval->status->label(),
                // Still with the Chief of Staff, or already decided: nothing to do here.
                'can_decide' => $approval->isPending() && $approval->review_stage !== Approval::STAGE_AGENT && $user->can('decide', $approval),
                'decided_by' => $approval->decidedBy->name ?? $approval->decidedByAgent->name ?? null,
            ],
        ];
    }

    /**
     * The approvals the page's notifications are about, in one query.
     *
     * @param  \Illuminate\Support\Collection<int, DatabaseNotification>  $items
     * @return array<int, Approval>
     */
    private function approvals($items): array
    {
        $ids = $items->map(fn (DatabaseNotification $n) => (int) ($n->data['approval_id'] ?? 0))->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Approval::query()
            ->with(['agent', 'decidedBy', 'decidedByAgent'])
            ->whereKey($ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Each kind with how many there are and how many are unread.
     *
     * @return list<array{key: string, label: string, total: int, unread: int}>
     */
    private function categories(User $user): array
    {
        $counts = $user->notifications()->reorder()
            ->selectRaw('type, count(*) as total, sum(case when read_at is null then 1 else 0 end) as unread')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return array_map(fn (NotificationCategory $c) => [
            'key' => $c->value,
            'label' => $c->label(),
            'total' => (int) ($counts[$c->value]->total ?? 0),
            'unread' => (int) ($counts[$c->value]->unread ?? 0),
        ], NotificationCategory::cases());
    }

    /**
     * How many approvals in this person's notifications still wait on a person.
     */
    private function awaitingDecision(User $user): int
    {
        $ids = $user->notifications()->reorder()->where('type', NotificationCategory::Approval->value)->limit(500)->pluck('data')
            ->map(fn ($data) => (int) ((is_array($data) ? $data : (array) json_decode((string) $data, true))['approval_id'] ?? 0))
            ->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return Approval::query()->with('agent')->whereKey($ids)->where('status', ApprovalStatus::Pending)
            ->where(fn (Builder $q) => $q->whereNull('review_stage')->orWhere('review_stage', '!=', Approval::STAGE_AGENT))->get()
            ->filter(fn (Approval $approval) => $user->can('decide', $approval))->count();
    }

    /**
     * Where opening it leads. A run is read in its conversation, with the run
     * open in the side panel; an approval, on the approvals page.
     */
    private function destination(DatabaseNotification $notice): string
    {
        $url = (string) ($notice->data['url'] ?? '/notifications');
        $taskId = $notice->data['task_id'] ?? null;
        $runId = $notice->data['run_id'] ?? null;

        if (preg_match('#^/runs/(\d+)$#', $url, $match) === 1) {
            $runId = (int) $match[1];
            $taskId = AgentRun::query()->whereKey($runId)->value('task_id');
        }

        if ($taskId !== null && (str_starts_with($url, '/runs/') || $url === "/tasks/{$taskId}")) {
            return "/tasks/{$taskId}".($runId !== null ? "?execucao={$runId}" : '');
        }

        return $url;
    }

    /**
     * Older notifications can point at screens that no longer exist (contracts,
     * clients…); those stay on the notifications list instead of a 404.
     */
    private function exists(Request $request, string $url): bool
    {
        try {
            Route::getRoutes()->match(Request::create($request->getSchemeAndHttpHost().$url));

            return true;
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        }
    }
}
