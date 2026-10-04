<?php

namespace App\Http\Controllers;

use App\Ai\Skills\Local\SupplierScores;
use App\Console\Concerns\DispatchesRoles;
use App\Enums\PurchaseRequestStatus;
use App\Enums\TriggerType;
use App\Models\AuditLog;
use App\Models\PurchaseRequest;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase requisitions (E06): anyone asks, the procurement agent runs the
 * RFQ, comparison and draft PO, people approve.
 */
class ProcurementController extends Controller
{
    use DispatchesRoles;

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('Procurement/Index', [
            'requests' => $this->visible($user)->with(['requester:id,name', 'department:id,name'])->latest('id')->paginate(25)->withQueryString()
                ->through(fn (PurchaseRequest $r) => $this->present($r)),
            'suppliers' => SupplierScores::scores(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'project_ref' => ['nullable', 'string', 'max:50'],
        ]);

        $purchase = PurchaseRequest::query()->create([...$data, 'requested_by_user_id' => $user->id, 'department_id' => $user->department_id, 'status' => PurchaseRequestStatus::Submitted]);
        AuditLog::record($user, 'procurement.request_created', ['title' => $purchase->title, 'items' => count($data['items'])], subject: $purchase);

        $run = $this->dispatchRole('procurement', "Nova requisição #{$purchase->id} de {$user->name}: «{$purchase->title}». Lê-a com procurement.requests e lança o pedido de cotação.", $purchase, TriggerType::Manual);

        return to_route('procurement.show', $purchase)->with('success', $run ? 'Requisição submetida: o agente de compras já está a tratar.' : 'Requisição submetida. Não há agente de compras activo: fica à espera de uma pessoa.');
    }

    public function show(Request $request, PurchaseRequest $purchase): Response
    {
        abort_unless($this->visible($this->user($request))->whereKey($purchase->id)->exists(), 403);

        return Inertia::render('Procurement/Show', [
            'request' => [...$this->present($purchase->load(['requester:id,name', 'department:id,name'])), 'description' => $purchase->description, 'items' => $purchase->items, 'notes' => $purchase->notes],
            'reports' => Report::query()->whereIn('subject_ref', array_filter([(string) $purchase->id, 'REQ-'.$purchase->id, $purchase->erp_rfq_id]))->latest('id')->get(['id', 'title', 'type', 'created_at']),
            'statuses' => PurchaseRequestStatus::options(),
        ]);
    }

    public function cancel(Request $request, PurchaseRequest $purchase): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($purchase->requested_by_user_id === $user->id || $user->isManager(), 403);

        $purchase->update(['status' => PurchaseRequestStatus::Cancelled]);
        AuditLog::record($user, 'procurement.request_cancelled', ['title' => $purchase->title], subject: $purchase);

        return back()->with('success', 'Requisição cancelada.');
    }

    /**
     * @return Builder<PurchaseRequest>
     */
    private function visible(User $user): Builder
    {
        return PurchaseRequest::query()->when(! $user->isManager(), fn ($q) => $q->where('requested_by_user_id', $user->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PurchaseRequest $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'needed_by' => $r->needed_by?->toDateString(),
            'budget' => $r->budget,
            'project_ref' => $r->project_ref,
            'erp_rfq_id' => $r->erp_rfq_id,
            'erp_po_id' => $r->erp_po_id,
            'requested_by' => $r->requester?->name,
            'department' => $r->department?->name,
            'items_count' => count($r->items),
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}
