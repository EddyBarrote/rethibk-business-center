<?php

use App\Ai\Agents\GenericAgent;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowStepStatus;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Tasks\TaskThread;
use App\Workflows\WorkflowEngine;
use App\Workflows\WorkflowGraph;

// Fluxos de trabalho (docs/DECISOES.md): the platform walks the flow one block
// at a time; the agent ends each step with workflow.complete_step.

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        $this->ana = User::factory()->create(['name' => 'Ana Sitoe', 'role' => 'manager']);
        $this->manager = templateAgent('client_manager', ['reports_to_user_id' => $this->ana->id]);
    });
});

/**
 * A flow from a compact description: nodes as [id, type, data, parent?], edges as [source, target, handle?].
 *
 * @param  list<array{0: string, 1: string, 2?: array<string, mixed>, 3?: string}>  $nodes
 * @param  list<array{0: string, 1: string, 2?: string}>  $edges
 */
function flow(array $nodes, array $edges, array $attributes = []): Workflow
{
    return Workflow::query()->create([
        'name' => 'Fluxo de teste',
        'email_category' => 'client_rfq',
        'agent_id' => test()->manager->id,
        'fallback_user_id' => test()->ana->id,
        'status' => WorkflowStatus::Active,
        'graph' => [
            'nodes' => array_map(fn (array $n) => array_filter(['id' => $n[0], 'type' => $n[1], 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => $n[0], ...($n[2] ?? [])], 'parentId' => $n[3] ?? null], fn ($v) => $v !== null), $nodes),
            'edges' => array_map(fn (array $e) => array_filter(['id' => "{$e[0]}-{$e[1]}", 'source' => $e[0], 'target' => $e[1], 'sourceHandle' => $e[2] ?? null], fn ($v) => $v !== null), $edges),
        ],
        ...$attributes,
    ]);
}

function flowTask(): Task
{
    return app(TaskThread::class)->open([
        'kind' => TaskKind::Task,
        'title' => 'Pedido de cotação de cliente: Reabilitação do cais',
        'status' => TaskStatus::Todo,
        'assignee_agent_id' => test()->manager->id,
        'user_id' => test()->ana->id,
    ], test()->owner, start: false);
}

function completeStep(string $id, array $arguments): Closure
{
    return toolCall($id, 'workflow_complete_step', ['outcome' => 'done', 'summary' => 'Feito.', ...$arguments]);
}

it('walks steps and conditions with the agent, waits for a person\'s approval, and hands the task back for review at the end', function () {
    GenericAgent::fake([
        completeStep('c1', ['summary' => 'Procurei a Cimentos do Púnguè: não existe.']),
        completeStep('c2', ['answer' => 'no', 'summary' => 'Não há conta no ERP.']),
        'Passou à aprovação da Ana.',
        completeStep('c3', ['summary' => 'Proposta preparada.']),
        'Fluxo terminado.',
    ]);

    asTenant($this->tenant, function () {
        $workflow = flow(
            [['start', 'trigger'], ['search', 'agent', ['capability' => 'erp.crm.search_accounts']], ['known', 'condition'], ['approve', 'approval'], ['draft', 'agent'], ['end', 'end']],
            [['start', 'search'], ['search', 'known'], ['known', 'draft', 'yes'], ['known', 'approve', 'no'], ['approve', 'draft', 'approved'], ['draft', 'end']],
        );
        $task = flowTask();

        $run = app(WorkflowEngine::class)->start($workflow, $task);

        $approval = WorkflowStep::query()->where('kind', 'approval')->sole();
        expect($run->status)->toBe(WorkflowRunStatus::Waiting)
            ->and($approval->status)->toBe(WorkflowStepStatus::Waiting)
            ->and($approval->task->assignee_user_id)->toBe($this->ana->id)
            ->and($approval->task->parent_id)->toBe($task->id)
            ->and(WorkflowStep::query()->where('kind', 'condition')->sole()->answer)->toBe('no');

        app(WorkflowEngine::class)->decide($approval, 'approved', $this->ana, 'Preço certo.');

        expect($run->refresh()->status)->toBe(WorkflowRunStatus::Completed)
            ->and($task->refresh()->status)->toBe(TaskStatus::InReview)
            ->and($approval->refresh()->answer)->toBe('approved')
            ->and(WorkflowStep::query()->where('node_id', 'draft')->sole()->status)->toBe(WorkflowStepStatus::Done);
    });
});

it('goes through a loop item by item, handing each one to another agent in a sub-task', function () {
    GenericAgent::fake([
        // On the sync queue each sub-task's run happens inside the call that opened it.
        completeStep('c1', ['items' => ['Cimentos de Moçambique', 'Ferragens do Maputo'], 'summary' => 'Dois fornecedores.']),
        toolCall('p1', 'tasks_update_status', ['status' => 'done', 'note' => 'Pedido enviado à Cimentos de Moçambique.']),
        toolCall('p2', 'tasks_update_status', ['status' => 'done', 'note' => 'Pedido enviado à Ferragens do Maputo.']),
        'Feito.',
        'Feito.',
        'A pedir cotações.',
    ]);

    asTenant($this->tenant, function () {
        $procurement = templateAgent('procurement');
        $workflow = flow(
            [['start', 'trigger'], ['each', 'loop', ['items' => 'os fornecedores', 'max' => 3]], ['ask', 'handoff', ['agent_id' => $procurement->id, 'capability' => 'erp.procurement.list_suppliers'], 'each'], ['end', 'end']],
            [['start', 'each'], ['each', 'end']],
        );

        $run = app(WorkflowEngine::class)->start($workflow, $task = flowTask());

        $handoffs = WorkflowStep::query()->with('task')->where('kind', 'handoff')->orderBy('id')->get();

        expect($run->refresh()->status)->toBe(WorkflowRunStatus::Completed)
            ->and($handoffs->pluck('item')->all())->toBe(['Cimentos de Moçambique', 'Ferragens do Maputo'])
            ->and($handoffs->every(fn ($step) => $step->task->assignee_agent_id === $procurement->id && $step->task->status === TaskStatus::Done))->toBeTrue()
            ->and($task->refresh()->status)->toBe(TaskStatus::InReview)
            ->and($run->state['loops'] ?? [])->toBe([]);
    });
});

it('sends a step nobody can do straight to the fallback person, and carries on once they do it', function () {
    GenericAgent::fake([]);

    asTenant($this->tenant, function () {
        $workflow = flow(
            [['start', 'trigger'], ['price', 'agent', ['capability' => 'erp.quotes.create']], ['end', 'end']],
            [['start', 'price'], ['price', 'end']],
        );

        $run = app(WorkflowEngine::class)->start($workflow, $task = flowTask());
        $fallback = WorkflowStep::query()->where('kind', 'fallback')->sole();

        expect($run->status)->toBe(WorkflowRunStatus::Blocked)
            ->and(WorkflowStep::query()->where('kind', 'agent')->sole()->status)->toBe(WorkflowStepStatus::Blocked)
            ->and($fallback->task->assignee_user_id)->toBe($this->ana->id)
            ->and($fallback->task->description)->toContain('erp.quotes.create');

        app(TaskThread::class)->setStatus($fallback->task, TaskStatus::Done, $this->ana, 'Orçamento feito à mão.');

        expect($run->refresh()->status)->toBe(WorkflowRunStatus::Completed)
            ->and($task->refresh()->status)->toBe(TaskStatus::InReview);
    });
});

it('reminds an agent that stopped without ending its step, then gives the step to a person', function () {
    GenericAgent::fake(['Vou ver.', 'Ainda a ver.', 'Não sei.']);

    asTenant($this->tenant, function () {
        $workflow = flow([['start', 'trigger'], ['think', 'agent'], ['end', 'end']], [['start', 'think'], ['think', 'end']]);

        $run = app(WorkflowEngine::class)->start($workflow, flowTask());

        $step = WorkflowStep::query()->where('kind', 'agent')->sole();

        expect($step->status)->toBe(WorkflowStepStatus::Blocked)
            ->and($step->attempts)->toBe(WorkflowEngine::REMINDERS)
            ->and($run->refresh()->status)->toBe(WorkflowRunStatus::Blocked)
            ->and(WorkflowStep::query()->where('kind', 'fallback')->sole()->task->assignee_user_id)->toBe($this->ana->id);
    });
});

it('repeats a body until the agent says it is enough, inside one run', function () {
    GenericAgent::fake([
        completeStep('c1', ['summary' => 'Primeira cotação recebida.']),
        completeStep('c2', ['answer' => 'no', 'summary' => 'Só uma.']),
        completeStep('c3', ['summary' => 'Segunda cotação recebida.']),
        completeStep('c4', ['answer' => 'yes', 'summary' => 'Já há duas.']),
        'Pronto.',
    ]);

    asTenant($this->tenant, function () {
        $workflow = flow(
            [['start', 'trigger'], ['again', 'repeat', ['until' => 'Já há duas cotações', 'max' => 4]], ['collect', 'agent', [], 'again'], ['end', 'end']],
            [['start', 'again'], ['again', 'end']],
        );

        $run = app(WorkflowEngine::class)->start($workflow, flowTask());

        expect($run->refresh()->status)->toBe(WorkflowRunStatus::Completed)
            ->and(WorkflowStep::query()->where('kind', 'agent')->pluck('item')->all())->toBe(['volta 1', 'volta 2'])
            ->and(WorkflowStep::query()->where('kind', 'until')->pluck('answer')->all())->toBe(['no', 'yes']);
    });
});

it('waits, then goes on', function () {
    GenericAgent::fake([completeStep('c1', ['summary' => 'Cotações comparadas.']), 'Pronto.']);

    asTenant($this->tenant, function () {
        $workflow = flow([['start', 'trigger'], ['wait', 'wait', ['hours' => 2]], ['compare', 'agent'], ['end', 'end']], [['start', 'wait'], ['wait', 'compare'], ['compare', 'end']]);

        $run = app(WorkflowEngine::class)->start($workflow, flowTask());

        expect($run->refresh()->status)->toBe(WorkflowRunStatus::Completed)
            ->and(WorkflowStep::query()->where('kind', 'wait')->sole()->status)->toBe(WorkflowStepStatus::Done);
    });
});

it('stops the flow when the task is cancelled', function () {
    GenericAgent::fake([]);

    asTenant($this->tenant, function () {
        $workflow = flow([['start', 'trigger'], ['approve', 'approval'], ['end', 'end']], [['start', 'approve'], ['approve', 'end', 'approved']]);

        $run = app(WorkflowEngine::class)->start($workflow, $task = flowTask());
        app(TaskThread::class)->setStatus($task, TaskStatus::Cancelled, $this->owner);

        expect($run->refresh()->status)->toBe(WorkflowRunStatus::Cancelled)
            ->and(WorkflowStep::query()->sole()->status)->toBe(WorkflowStepStatus::Cancelled);
    });
});

it('tells what is wrong with a graph', function () {
    $graph = new WorkflowGraph([
        'nodes' => [
            ['id' => 'a', 'type' => 'agent', 'data' => ['label' => 'A']],
            ['id' => 'b', 'type' => 'condition', 'data' => ['label' => 'B']],
            ['id' => 'l', 'type' => 'loop', 'data' => ['label' => 'Ciclo']],
            ['id' => 'x', 'type' => 'magic', 'data' => ['label' => 'X']],
        ],
        'edges' => [
            ['source' => 'a', 'target' => 'b'],
            ['source' => 'b', 'target' => 'a', 'sourceHandle' => 'yes'],
            ['source' => 'b', 'target' => 'a', 'sourceHandle' => 'maybe'],
        ],
    ]);

    expect($graph->problems())->toContain(
        'O fluxo precisa de exactamente um gatilho.',
        'O bloco «X» é de um tipo desconhecido.',
        'O ciclo «Ciclo» não tem nenhum bloco dentro.',
        '«B» tem uma saída que não existe.',
        'O fluxo volta para trás sozinho. Para repetir passos, use um bloco de ciclo.',
    );
});
