<?php

use App\Enums\ApprovalStatus;
use App\Enums\EmailCategory;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setTime(8, 0));
    [$this->tenant, $this->other] = Tenant::factory()->count(2)->create();

    asTenant($this->tenant, function () {
        $this->boss = User::factory()->owner()->create(['name' => 'Carlos Tembe']);
        $this->chief = Agent::factory()->create(['key' => 'chief_of_staff', 'name' => 'Chief of Staff', 'reports_to_user_id' => $this->boss->id]);
        $this->triage = Agent::factory()->create(['key' => 'triage', 'name' => 'Agente de Triagem', 'reports_to_agent_id' => $this->chief->id]);
        $this->procurement = Agent::factory()->create(['key' => 'procurement', 'name' => 'Agente de Procurement', 'reports_to_agent_id' => $this->chief->id]);
        $this->clients = Agent::factory()->create(['key' => 'client_manager', 'name' => 'Gestor de Clientes', 'reports_to_agent_id' => $this->chief->id]);

        $email = EmailMessage::factory()->create(['subject' => 'Cotação cimento 40 t', 'from_address' => 'comercial@cimentos.co.mz', 'classification' => EmailCategory::SupplierQuote, 'received_at' => now()]);

        // 08:01 triage hands the email to Procurement.
        $this->travel(1)->minutes();
        $this->root = Task::factory()->create([
            'title' => 'Cotação de fornecedor: Cotação cimento 40 t', 'description' => 'A triagem classificou este email.',
            'assignee_agent_id' => $this->procurement->id, 'user_id' => $this->boss->id, 'created_by_agent_id' => $this->triage->id,
            'status' => TaskStatus::InProgress, 'source_type' => $email->getMorphClass(), 'source_id' => $email->id,
        ]);
        AgentRun::factory()->create(['agent_id' => $this->procurement->id, 'task_id' => $this->root->id, 'duration_ms' => 120_000, 'cost_usd' => 0.04]);

        // 08:40 Procurement decides the client side belongs to the Chief of Staff (its manager).
        $this->travel(39)->minutes();
        $this->up = Task::factory()->create([
            'title' => 'Actualizar a proposta ao cliente', 'description' => 'O preço muda a proposta à Construtora Beira. Isso é do Gestor de Clientes.',
            'assignee_agent_id' => $this->chief->id, 'created_by_agent_id' => $this->procurement->id, 'parent_id' => $this->root->id, 'status' => TaskStatus::InProgress,
        ]);

        // 08:42 the Chief of Staff delegates it down to the client manager.
        $this->travel(2)->minutes();
        $this->down = Task::factory()->create([
            'title' => 'Rever a proposta PRJ-0007', 'description' => 'Actualiza a proposta com o novo custo do cimento.',
            'assignee_agent_id' => $this->clients->id, 'created_by_agent_id' => $this->chief->id, 'parent_id' => $this->up->id, 'status' => TaskStatus::InProgress,
        ]);
        $run = AgentRun::factory()->create(['agent_id' => $this->clients->id, 'task_id' => $this->down->id, 'duration_ms' => 60_000, 'cost_usd' => 0.03]);

        // 09:00 the price proposal hits the ceiling and waits on a person.
        $this->travel(18)->minutes();
        $this->approval = Approval::factory()->create([
            'agent_run_id' => $run->id, 'agent_id' => $this->clients->id, 'action_type' => 'email.send', 'action_summary' => 'Enviar a proposta à Construtora Beira',
            'ceiling_reason' => 'Proposta com preço a um cliente', 'assigned_to_user_id' => $this->boss->id, 'status' => ApprovalStatus::Pending,
        ]);

        $this->travel(30)->minutes();
    });
});

function journeyOf(object $test, Task $task, User $user): array
{
    $journey = null;

    $test->actingAs($user)->get(tenantUrl($test->tenant, "tasks/{$task->id}"))
        ->assertInertia(function (Assert $page) use (&$journey) {
            $page->missing('journey')->reloadOnly('journey', function (Assert $reload) use (&$journey) {
                $journey = $reload->toArray()['props']['journey'];
            });
        });

    return $journey;
}

it('follows a case from the email through every agent to the person deciding', function () {
    $journey = journeyOf($this, $this->down, $this->boss);

    expect($journey['root']['id'])->toBe($this->root->id)
        ->and(array_column($journey['stops'], 'name'))->toBe(['Email', 'Agente de Triagem', 'Agente de Procurement', 'Chief of Staff', 'Gestor de Clientes', 'Carlos Tembe'])
        ->and($journey['now'])->toMatchArray(['type' => 'person', 'name' => 'Carlos Tembe', 'approval_id' => $this->approval->id]);

    expect(array_column($journey['stops'], 'seconds', 'name'))->toMatchArray(['Agente de Triagem' => 60, 'Agente de Procurement' => 39 * 60, 'Chief of Staff' => 120, 'Gestor de Clientes' => 18 * 60, 'Carlos Tembe' => 1800]);

    $hops = collect($journey['hops']);
    $handoffs = $hops->where('type', 'handoff')->values();

    expect($handoffs->pluck('how')->all())->toBe(['triage', 'agent', 'agent'])
        ->and($handoffs[1])->toMatchArray(['from_name' => 'Agente de Procurement', 'to_name' => 'Chief of Staff', 'how_label' => 'Decisão do agente', 'quote' => true])
        ->and($handoffs[1]['reason'])->toContain('Isso é do Gestor de Clientes')
        ->and($hops->firstWhere('type', 'email')['reason'])->toBe('Classificou como «Cotação de fornecedor».')
        ->and($hops->firstWhere('type', 'approval'))->toMatchArray(['to_name' => 'Carlos Tembe', 'how' => 'ceiling', 'seconds' => 1800, 'approval_id' => $this->approval->id])
        ->and($hops->where('is_current', true)->pluck('task.id')->unique()->all())->toBe([$this->down->id]);

    expect($journey['totals'])->toMatchArray([
        'seconds' => 90 * 60,
        'agent_seconds' => 180,
        'waiting_seconds' => 1800,
        'handoffs' => 3,
        'by_agents' => 2,
        'agents' => 4,
        'people' => 1,
        'open' => true,
    ])->and($journey['totals']['cost_usd'])->toBe(0.07);
});

it('shows the delegated result going back up once a sub-task is done', function () {
    asTenant($this->tenant, function () {
        $this->approval->forceFill(['status' => ApprovalStatus::Approved, 'decided_by_user_id' => $this->boss->id, 'decided_at' => now()])->save();
        $this->down->forceFill(['status' => TaskStatus::Done, 'completed_at' => now()])->save();
    });

    $journey = journeyOf($this, $this->root, $this->boss);
    $returned = collect($journey['hops'])->firstWhere('type', 'returned');

    expect($returned)->toMatchArray(['from_name' => 'Gestor de Clientes', 'to_name' => 'Chief of Staff'])
        ->and($journey['now'])->toMatchArray(['type' => 'agent', 'name' => 'Chief of Staff']);
});

it('is offered only for tasks that are part of a path, and loaded on demand', function () {
    asTenant($this->tenant, function () {
        $this->alone = Task::factory()->create(['title' => 'Sozinha', 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->boss->id]);
    });

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "tasks/{$this->alone->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('has_journey', false)->missing('journey'));

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "tasks/{$this->down->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('has_journey', true)->missing('journey'));

    $journey = journeyOf($this, $this->root, $this->boss);
    expect(collect($journey['hops'])->where('type', 'handoff')->pluck('task.id')->filter()->count())->toBe(3);
});
