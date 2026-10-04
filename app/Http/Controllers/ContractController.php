<?php

namespace App\Http\Controllers;

use App\Enums\ContractStatus;
use App\Enums\PartyType;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Client and supplier contracts, watched for expiry (E06) and renewal and
 * SLA (E08).
 */
class ContractController extends Controller
{
    public function index(Request $request): Response
    {
        $this->manager($request);
        $type = $request->query('party_type');

        return Inertia::render('Contracts/Index', [
            'contracts' => Contract::query()->with('owner:id,name')
                ->when($type, fn ($q, $t) => $q->where('party_type', $t))
                ->orderByRaw('ends_at is null')->orderBy('ends_at')
                ->get()
                ->map(fn (Contract $c) => $this->present($c)),
            'filter' => $type,
            ...$this->options(),
        ]);
    }

    public function show(Request $request, Contract $contract): Response
    {
        $this->manager($request);

        return Inertia::render('Contracts/Show', ['contract' => [...$this->present($contract->load('owner:id,name')), 'notes' => $contract->notes], ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->manager($request);
        $contract = Contract::query()->create($this->validated($request));
        AuditLog::record($user, 'contract.created', ['title' => $contract->title, 'party' => $contract->party_name, 'ends_at' => $contract->ends_at?->toDateString()], subject: $contract);

        return to_route('contracts.show', $contract)->with('success', 'Contrato registado.');
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $user = $this->manager($request);
        $data = $this->validated($request);

        if (($data['ends_at'] ?? null) !== $contract->ends_at?->toDateString()) {
            $data['alerted_at'] = null;
        }

        $contract->update($data);
        AuditLog::record($user, 'contract.updated', ['title' => $contract->title, 'status' => $contract->status->value, 'ends_at' => $contract->ends_at?->toDateString()], subject: $contract);

        return back()->with('success', 'Contrato guardado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'party_type' => ['required', Rule::enum(PartyType::class)],
            'party_ref' => ['nullable', 'string', 'max:50'],
            'party_name' => ['required', 'string', 'max:255'],
            'party_domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'title' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'notice_days' => ['required', 'integer', 'between:0,730'],
            'auto_renews' => ['boolean'],
            'sla_response_hours' => ['nullable', 'integer', 'between:1,720'],
            'owner_user_id' => ['nullable', 'integer', TenantRule::exists('users')],
            'status' => ['required', Rule::enum(ContractStatus::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'partyTypes' => PartyType::options(),
            'statuses' => ContractStatus::options(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Contract $c): array
    {
        return [
            'id' => $c->id,
            'party_type' => $c->party_type->value,
            'party_type_label' => $c->party_type->label(),
            'party_ref' => $c->party_ref,
            'party_name' => $c->party_name,
            'party_domain' => $c->party_domain,
            'title' => $c->title,
            'reference' => $c->reference,
            'value' => $c->value,
            'currency' => $c->currency,
            'starts_at' => $c->starts_at?->toDateString(),
            'ends_at' => $c->ends_at?->toDateString(),
            'days_left' => $c->ends_at ? (int) today()->diffInDays($c->ends_at, false) : null,
            'notice_days' => $c->notice_days,
            'auto_renews' => $c->auto_renews,
            'sla_response_hours' => $c->sla_response_hours,
            'owner_user_id' => $c->owner_user_id,
            'owner' => $c->owner?->name,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'in_notice' => $c->ends_at !== null && $c->status === ContractStatus::Active && $c->ends_at->lte(today()->addDays($c->notice_days)),
        ];
    }

    private function manager(Request $request): User
    {
        $user = $this->user($request);
        abort_unless($user->isManager(), 403);

        return $user;
    }
}
