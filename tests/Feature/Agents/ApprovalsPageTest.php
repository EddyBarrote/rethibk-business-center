<?php

use App\Ai\Tools\GatedTool;
use App\Enums\ApprovalArea;
use App\Enums\ApprovalStatus;
use App\Enums\StepType;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Approval;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// The approvals centre (docs/DECISOES.md, "Página de aprovações").

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->boss, $this->member, $this->owner, $this->mine, $this->theirs] = asTenant($this->tenant, function () {
        $boss = User::factory()->create(['role' => 'member']);
        $member = User::factory()->create(['role' => 'member']);

        return [
            $boss,
            $member,
            User::factory()->owner()->create(),
            Agent::factory()->create(['reports_to_user_id' => $boss->id, 'name' => 'Gestor de Clientes']),
            Agent::factory()->create(['reports_to_user_id' => $member->id, 'name' => 'Analista Financeiro']),
        ];
    });

    $this->approval = fn (Agent $agent, array $attributes = []) => asTenant($this->tenant, fn () => Approval::factory()->create([
        'agent_id' => $agent->id,
        'agent_run_id' => AgentRun::factory()->create(['agent_id' => $agent->id])->id,
        ...$attributes,
    ]));
});

it('opens on what waits on the person, with the other views counted', function () {
    $email = ($this->approval)($this->mine, ['action_type' => 'comms.send_email', 'action_summary' => 'Enviar email à Agro Zambeze', 'payload' => ['to' => 'f@agro.co.mz']]);
    $reviewing = ($this->approval)($this->mine, ['review_stage' => Approval::STAGE_AGENT]);
    $decided = ($this->approval)($this->mine, ['status' => ApprovalStatus::Approved, 'decided_by_user_id' => $this->boss->id, 'decided_at' => now()]);
    ($this->approval)($this->theirs, ['action_type' => 'erp.invoices.issue']);

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, 'approvals'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Approvals/Index')
            ->where('filters.vista', 'por-decidir')
            ->where('approvals.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$email->id])
            ->where('approvals.data.0.area', ApprovalArea::Communication->value)
            ->where('views', fn ($views) => collect($views)->pluck('total', 'key')->all() === [
                'por-decidir' => 1, 'revisao' => 1, 'pendentes' => 2, 'aprovadas' => 1, 'rejeitadas' => 0, 'expiradas' => 0, 'todas' => 3,
            ])
            ->where('stats.waiting', 1)
            ->where('stats.in_review', 1)
            ->where('stats.approved_7d', 1)
            ->where('agents', fn ($agents) => collect($agents)->pluck('name')->all() === ['Gestor de Clientes']));

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, 'approvals?vista=revisao'))
        ->assertInertia(fn ($page) => $page->where('approvals.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$reviewing->id]));

    // Addresses from before keep working.
    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, 'approvals?status=approved'))
        ->assertInertia(fn ($page) => $page->where('filters.vista', 'aprovadas')->where('approvals.data.0.id', $decided->id));
});

it('filters by area, agent and words', function () {
    $email = ($this->approval)($this->mine, ['action_type' => 'comms.send_email', 'action_summary' => 'Enviar email a Agro Zambeze']);
    $invoice = ($this->approval)($this->theirs, ['action_type' => 'erp.invoices.issue', 'action_summary' => 'Emitir factura FT-12']);
    $lead = ($this->approval)($this->mine, ['action_type' => 'erp.leads.create', 'payload' => ['company_name' => 'Cimentos de Moçambique']]);

    $ids = fn (string $query) => $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "approvals?vista=todas&{$query}"))
        ->assertOk()->viewData('page')['props']['approvals']['data'];

    expect(collect($ids('area=financas'))->pluck('id')->all())->toBe([$invoice->id])
        ->and(collect($ids('area=comunicacao'))->pluck('id')->all())->toBe([$email->id])
        ->and(collect($ids("agente={$this->mine->id}"))->pluck('id')->all())->toBe([$lead->id, $email->id])
        ->and(collect($ids('q=agro+zambeze'))->pluck('id')->all())->toBe([$email->id])
        ->and(collect($ids('q=cimentos'))->pluck('id')->all())->toBe([$lead->id])
        ->and(collect($ids('q=analista'))->pluck('id')->all())->toBe([$invoice->id]);

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'approvals?vista=todas'))
        ->assertInertia(fn ($page) => $page->where('areas', fn ($areas) => collect($areas)->pluck('total', 'key')->only(['comunicacao', 'financas', 'comercial'])->all()
            === ['comunicacao' => 1, 'financas' => 1, 'comercial' => 1]));
});

it('reads the area of an action from its type', function () {
    expect(ApprovalArea::of('comms.send_email'))->toBe(ApprovalArea::Communication)
        ->and(ApprovalArea::of('erp.expenses.create'))->toBe(ApprovalArea::Finance)
        ->and(ApprovalArea::of('erp.procurement.create_rfq'))->toBe(ApprovalArea::Procurement)
        ->and(ApprovalArea::of('erp.hr.create_candidate'))->toBe(ApprovalArea::People)
        ->and(ApprovalArea::of('budget.override'))->toBe(ApprovalArea::Platform)
        ->and(ApprovalArea::of('something.new'))->toBe(ApprovalArea::Other);
});

it('shows a person only their agents and what the matrix lets them decide', function () {
    $mine = ($this->approval)($this->mine);
    $theirs = ($this->approval)($this->theirs);

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, 'approvals?vista=todas'))
        ->assertInertia(fn ($page) => $page->where('approvals.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$mine->id]));

    // Someone else's approval does not open in the side panel.
    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "approvals?aprovacao={$theirs->id}"))
        ->assertInertia(fn ($page) => $page->where('open', null));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'approvals'))
        ->assertInertia(fn ($page) => $page->where('stats.waiting', 2));
});

it('decides by the same rule in the list as one by one', function () {
    foreach (['comms.send_email', 'erp.leads.create', 'budget.override', 'agents.set_trust_level'] as $type) {
        ($this->approval)($this->mine, ['action_type' => $type]);
        ($this->approval)($this->theirs, ['action_type' => $type]);
    }

    foreach ([$this->boss, $this->member, $this->owner] as $user) {
        asTenant($this->tenant, function () use ($user) {
            $byPolicy = Approval::query()->with('agent')->get()->filter(fn (Approval $a) => $user->can('decide', $a))->pluck('id')->sort()->values()->all();
            $byQuery = Approval::query()->decidableBy($user)->pluck('id')->sort()->values()->all();

            expect($byQuery)->toBe($byPolicy);
        });
    }
});

it('opens one approval on the side with the conversation and run it came from', function () {
    $task = asTenant($this->tenant, fn () => Task::factory()->create(['user_id' => $this->boss->id, 'title' => 'Proposta para a Agro Zambeze']));
    $run = asTenant($this->tenant, fn () => AgentRun::factory()->create(['agent_id' => $this->mine->id, 'task_id' => $task->id]));
    $approval = ($this->approval)($this->mine, ['agent_run_id' => $run->id]);

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "approvals?vista=todas&aprovacao={$approval->id}"))
        ->assertInertia(fn ($page) => $page
            ->where('open.id', $approval->id)
            ->where('open.run.id', $run->id)
            ->where('open.task.id', $task->id)
            ->where('open.can_decide', true)
            ->where('approvals.data.0.task_id', $task->id));
});

it('shows each email recipient as the ERP contact the agent read in the run, or warns when none carries it', function () {
    $run = asTenant($this->tenant, fn () => AgentRun::factory()->create(['agent_id' => $this->mine->id]));
    $contacts = ['total' => 2, 'items' => [
        ['customer' => 'Loop Experience, Lda', 'firstname' => 'Felicio', 'lastname' => 'Guelume', 'email' => 'Felicio.Guelume@coralflng.com', 'title' => 'Contracts Manager', 'is_primary' => '1'],
        ['customer' => 'Loop Experience, Lda', 'firstname' => 'Eduardo', 'lastname' => 'Barrote', 'email' => 'ebarrote@rethink.co.mz', 'title' => null, 'is_primary' => '0'],
    ]];

    asTenant($this->tenant, fn () => AgentRunStep::factory()->create([
        'agent_run_id' => $run->id,
        'type' => StepType::ToolResult,
        'tool_name' => 'erp.list_contacts',
        'payload' => ['ok' => true, 'content' => json_encode($contacts)],
    ]));

    $approval = ($this->approval)($this->mine, [
        'agent_run_id' => $run->id,
        'action_type' => 'comms.send_email',
        'payload' => ['to' => ['felicio.guelume@coralflng.com', 'alguem@inventado.co.mz'], 'subject' => 'Facturas', 'body' => 'Exmo. Sr. Felício Guelume'],
    ]);

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "approvals?vista=todas&aprovacao={$approval->id}"))
        ->assertInertia(fn ($page) => $page
            ->where('open.recipients.0.found', true)
            ->where('open.recipients.0.name', 'Felicio Guelume')
            ->where('open.recipients.0.customer', 'Loop Experience, Lda')
            ->where('open.recipients.0.title', 'Contracts Manager')
            ->where('open.recipients.0.primary', true)
            ->where('open.recipients.1.email', 'alguem@inventado.co.mz')
            ->where('open.recipients.1.found', false));
});

it('keeps what a tool returned in full in the run trace', function () {
    expect(GatedTool::TRACE_LIMIT)->toBeGreaterThanOrEqual(100_000);
});

it('rejects several with one reason, the absolute ceiling included, and only what the person decides', function () {
    $first = ($this->approval)($this->mine);
    $ceiling = ($this->approval)($this->mine, ['ceiling_reason' => 'Comunicação externa a entidade nova']);
    $notMine = ($this->approval)($this->theirs);

    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, 'approvals/reject'), ['ids' => [$first->id, $ceiling->id, $notMine->id], 'note' => 'Já tratado por telefone.'])
        ->assertSessionHas('success', 'Rejeitadas 2 acções.');

    asTenant($this->tenant, function () use ($first, $ceiling, $notMine) {
        expect($first->fresh()->status)->toBe(ApprovalStatus::Rejected)
            ->and($first->fresh()->decision_note)->toBe('Já tratado por telefone.')
            ->and($ceiling->fresh()->status)->toBe(ApprovalStatus::Rejected)
            ->and($notMine->fresh()->status)->toBe(ApprovalStatus::Pending);
    });

    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, 'approvals/reject'), ['ids' => [$notMine->id]])->assertSessionHasErrors('note');
});

it('opens an approval notification on the approvals page with its details', function () {
    $approval = ($this->approval)($this->mine);
    $id = (string) Str::uuid();

    asTenant($this->tenant, fn () => DatabaseNotification::query()->forceCreate([
        'id' => $id,
        'type' => 'approval',
        'notifiable_type' => $this->boss->getMorphClass(),
        'notifiable_id' => $this->boss->id,
        'data' => ['title' => 'Aprovação pedida', 'url' => '/approvals', 'approval_id' => $approval->id],
    ]));

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "notifications/{$id}"))
        ->assertRedirect("/approvals?aprovacao={$approval->id}");
});
