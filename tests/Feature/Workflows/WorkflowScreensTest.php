<?php

use App\Enums\EmailCategory;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowStepStatus;
use App\Models\Agent;
use App\Models\EmailRoute;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Tasks\TaskThread;
use App\Workflows\WorkflowDrafter;
use App\Workflows\WorkflowEngine;
use App\Workflows\WorkflowGraph;
use App\Workflows\WorkflowTemplates;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// The screens of Fluxos de trabalho: the map, the editor, Regras de email and
// a person's decision in a task (docs/DECISOES.md).

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        $this->member = User::factory()->create(['role' => 'member']);
        $this->ana = User::factory()->create(['name' => 'Ana Sitoe', 'role' => 'manager']);
        $this->manager = templateAgent('client_manager', ['reports_to_user_id' => $this->ana->id]);
        templateAgent('procurement');
        [$this->workflow] = app(WorkflowTemplates::class)->install();
    });
});

it('shows the map to everyone, with each kind of email, who handles it and each step checked', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'workflows'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Workflows/Index')
            ->where('can_create', false)
            ->where('categories', fn ($rows) => collect($rows)->firstWhere('value', 'client_rfq')['workflow_id'] === $this->workflow->id
                && collect($rows)->firstWhere('value', 'supplier_quote')['source'] === 'default')
            ->where('workflows.0.name', 'Responder a pedido de cotação')
            ->where('workflows.0.readiness.price.state', 'missing')
            ->where('workflows.0.readiness.send.state', 'person')
            ->where('workflows.0.can_edit', false));
});

it('lets those who give access to the agent draw a flow, and checks it while it is drawn', function () {
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'workflows/new?category=client_request'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Workflows/Edit')->where('initial.email_category', 'client_request')->where('initial.agent_id', $this->manager->id));

    $graph = ['nodes' => [
        ['id' => 'start', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Pedido de cliente']],
        ['id' => 'look', 'type' => 'agent', 'position' => ['x' => 0, 'y' => 100], 'data' => ['label' => 'Ver o cliente', 'capability' => 'erp.crm.get_account', 'selected' => true]],
        ['id' => 'end', 'type' => 'end', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Fim']],
    ], 'edges' => [['id' => 'a', 'source' => 'start', 'target' => 'look'], ['id' => 'b', 'source' => 'look', 'target' => 'end']]];

    $this->actingAs($this->owner)->postJson(tenantUrl($this->tenant, 'workflows/check'), ['agent_id' => $this->manager->id, 'graph' => $graph])->assertOk()
        ->assertJsonPath('readiness.look.state', 'alone')
        ->assertJsonPath('problems', []);

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'workflows'), [
        'name' => 'Responder a pedidos de cliente', 'email_category' => 'client_request', 'agent_id' => $this->manager->id, 'graph' => $graph, 'activate' => true,
    ])->assertRedirect();

    asTenant($this->tenant, function () {
        $workflow = Workflow::query()->where('email_category', EmailCategory::ClientRequest)->sole();

        expect($workflow->status)->toBe(WorkflowStatus::Active)
            ->and($workflow->created_by_user_id)->toBe($this->owner->id)
            // Only what the flow needs is kept from the canvas.
            ->and($workflow->graph['nodes'][1]['data'])->toBe(['label' => 'Ver o cliente', 'capability' => 'erp.crm.get_account']);
    });

    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'workflows'), ['name' => 'X', 'email_category' => 'lead', 'agent_id' => $this->manager->id, 'graph' => $graph])->assertForbidden();
});

it('does not turn on a flow that goes in circles, and pauses the other flow for the same email when one is turned on', function () {
    $looping = ['nodes' => [
        ['id' => 's', 'type' => 'trigger', 'data' => ['label' => 'Email']],
        ['id' => 'a', 'type' => 'agent', 'data' => ['label' => 'A']],
        ['id' => 'b', 'type' => 'agent', 'data' => ['label' => 'B']],
    ], 'edges' => [['source' => 's', 'target' => 'a'], ['source' => 'a', 'target' => 'b'], ['source' => 'b', 'target' => 'a']]];

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'workflows'), ['name' => 'Circular', 'email_category' => 'client_rfq', 'agent_id' => $this->manager->id, 'graph' => $looping, 'activate' => true])
        ->assertSessionHas('success', fn ($message) => str_starts_with($message, 'Não foi activado'));

    $copy = asTenant($this->tenant, fn () => Workflow::query()->create(['name' => 'Outra versão', 'email_category' => EmailCategory::ClientRfq, 'agent_id' => $this->manager->id, 'graph' => $this->workflow->graph]));

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "workflows/{$copy->id}/status"), ['status' => 'active'])->assertSessionHas('success');

    asTenant($this->tenant, function () use ($copy) {
        expect($copy->fresh()->status)->toBe(WorkflowStatus::Active)
            ->and($this->workflow->fresh()->status)->toBe(WorkflowStatus::Paused)
            ->and(Workflow::query()->where('name', 'Circular')->sole()->status)->toBe(WorkflowStatus::Draft);
    });
});

it('keeps the rules of Definições for those who manage agents', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/email-rules'))->assertForbidden();

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/email-rules'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/EmailRules')
            ->where('rules', fn ($rows) => collect($rows)->firstWhere('category', 'client_rfq')['workflow']['id'] === $this->workflow->id));

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, 'settings/email-rules/supplier_quote'), ['mode' => 'none', 'fallback_user_id' => $this->ana->id])->assertSessionHas('success');
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, 'settings/email-rules/lead'), ['mode' => 'agent', 'agent_id' => $this->manager->id])->assertSessionHas('success');

    asTenant($this->tenant, function () {
        expect(EmailRoute::query()->where('category', 'supplier_quote')->sole()->only(['agent_id', 'fallback_user_id']))->toBe(['agent_id' => null, 'fallback_user_id' => $this->ana->id])
            ->and(EmailRoute::query()->where('category', 'lead')->sole()->agent_id)->toBe($this->manager->id);
    });

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, 'settings/email-rules/lead'), ['mode' => 'default'])->assertSessionHas('success');
    expect(asTenant($this->tenant, fn () => EmailRoute::query()->where('category', 'lead')->exists()))->toBeFalse();
});

it('asks the person of an approval step in the task, and goes on with their answer', function () {
    $step = asTenant($this->tenant, function () {
        $workflow = Workflow::query()->create(['name' => 'Aprovar', 'email_category' => EmailCategory::Lead, 'agent_id' => $this->manager->id, 'fallback_user_id' => $this->ana->id, 'status' => 'active', 'graph' => ['nodes' => [
            ['id' => 's', 'type' => 'trigger', 'data' => ['label' => 'Email']],
            ['id' => 'ok', 'type' => 'approval', 'data' => ['label' => 'Aprovar o preço']],
            ['id' => 'e', 'type' => 'end', 'data' => ['label' => 'Fim']],
        ], 'edges' => [['source' => 's', 'target' => 'ok'], ['source' => 'ok', 'target' => 'e', 'sourceHandle' => 'approved']]]]);
        $task = app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Oportunidade', 'status' => TaskStatus::Todo, 'assignee_agent_id' => $this->manager->id, 'user_id' => $this->ana->id], $this->owner, start: false);
        app(WorkflowEngine::class)->start($workflow, $task);

        return WorkflowStep::query()->where('kind', 'approval')->sole();
    });

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "tasks/{$step->task_id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('workflow.decision.question', 'Aprovar o preço')->where('workflow.decision.can_decide', true)->where('workflow.decision.options.0.value', 'approved'));

    $this->actingAs($this->member)->post(tenantUrl($this->tenant, "workflow-steps/{$step->id}/decide"), ['decision' => 'approved'])->assertForbidden();
    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, "workflow-steps/{$step->id}/decide"), ['decision' => 'approved', 'note' => 'Certo.'])->assertSessionHas('success');

    asTenant($this->tenant, function () use ($step) {
        expect($step->fresh()->status)->toBe(WorkflowStepStatus::Done)
            ->and(Task::query()->find($step->run->task_id)->status)->toBe(TaskStatus::InReview);
    });

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, 'tasks/'.asTenant($this->tenant, fn () => $step->run->task_id)))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('workflow.progress.status', 'completed')->where('workflow.progress.steps.0.answer', 'approved'));
});

it('proposes the blocks from a description, keeping only capabilities and agents that exist', function () {
    $procurement = asTenant($this->tenant, fn () => Agent::query()->where('key', 'procurement')->value('key'));

    WorkflowDrafter::fake([[
        'steps' => [
            ['ref' => 'ler', 'type' => 'agent', 'label' => 'Ler o pedido', 'capability' => 'documents.read_attachment', 'next' => 'cliente'],
            ['ref' => 'cliente', 'type' => 'condition', 'label' => 'Já é cliente?', 'yes' => 'cada', 'no' => 'end'],
            ['ref' => 'cada', 'type' => 'loop', 'label' => 'Para cada fornecedor', 'items' => 'fornecedores', 'max' => 50, 'next' => 'aprovar'],
            ['ref' => 'pedir', 'type' => 'handoff', 'label' => 'Pedir cotação', 'agent' => $procurement, 'capability' => 'nao.existe', 'inside' => 'cada', 'next' => 'end'],
            ['ref' => 'aprovar', 'type' => 'approval', 'label' => 'Aprovar o preço', 'approved' => 'end', 'rejected' => 'ler'],
        ],
    ]]);

    $response = $this->actingAs($this->owner)->postJson(tenantUrl($this->tenant, 'workflows/draft'), [
        'description' => 'Quando um cliente pede cotação, ver se já é cliente e pedir preços aos fornecedores.',
        'agent_id' => $this->manager->id,
        'email_category' => 'client_rfq',
    ])->assertOk();

    $graph = new WorkflowGraph($response->json('graph'));

    expect($graph->trigger())->toBe('trigger')
        ->and($graph->next('trigger'))->toBe('ler')
        ->and($graph->next('cliente', 'no'))->toBe('end')
        ->and($graph->parent('pedir'))->toBe('cada')
        ->and($graph->data('pedir', 'capability'))->toBeNull()
        ->and($graph->data('pedir', 'agent_id'))->toBe(asTenant($this->tenant, fn () => Agent::query()->where('key', 'procurement')->value('id')))
        ->and($graph->data('cada', 'max'))->toBe(20)
        // A way out that leaves the loop, or goes back, is dropped or reported, never silently kept as a cycle.
        ->and($graph->next('pedir'))->toBeNull()
        ->and($graph->problems())->toContain('O fluxo volta para trás sozinho. Para repetir passos, use um bloco de ciclo.');
});
