<?php

use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Tarefas pelo que precisam de si".

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->agent] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create();

        return [$owner, Agent::factory()->create(['name' => 'Gestor de Clientes', 'reports_to_user_id' => $owner->id])];
    });

    $this->task = fn (string $title, TaskStatus $status, array $more = []) => asTenant($this->tenant, fn () => Task::factory()->create([
        'title' => $title, 'status' => $status, 'user_id' => $this->owner->id, 'assignee_agent_id' => $this->agent->id, ...$more,
    ]));
});

it('opens Tarefas on Foco, with each task once under what it needs from the person', function () {
    $asking = ($this->task)('Confirmar o preço', TaskStatus::WaitingHuman);
    asTenant($this->tenant, fn () => $asking->messages()->create(['author_type' => 'agent', 'author_agent_id' => $this->agent->id, 'kind' => 'message', 'body' => 'Qual é o **desconto** para a Olankha?']));
    $failed = ($this->task)('Cotação de cimento', TaskStatus::InProgress);
    asTenant($this->tenant, fn () => AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $failed->id, 'status' => RunStatus::Failed, 'error' => 'O ERP não respondeu.']));
    ($this->task)('Relatório de vendas', TaskStatus::InReview);
    ($this->task)('Seguimento da AZUL', TaskStatus::Todo);
    ($this->task)('Contrato da SIR', TaskStatus::Blocked);
    ($this->task)('Fecho de Setembro', TaskStatus::Done, ['completed_at' => now()->subDay()]);

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'foco')
            ->where('focus.sections.needs.0.title', 'Confirmar o preço')
            ->where('focus.sections.needs.0.next', 'Qual é o desconto para a Olankha?')
            ->where('focus.sections.needs.0.can_reply', true)
            ->where('focus.sections.failed.0.title', 'Cotação de cimento')
            ->where('focus.sections.failed.0.next', 'Não conseguiu: O ERP não respondeu.')
            ->where('focus.sections.failed.0.can_retry', true)
            ->has('focus.sections.working', 1)
            ->where('focus.sections.working.0.title', 'Seguimento da AZUL')
            ->where('focus.sections.review.0.title', 'Relatório de vendas')
            ->where('focus.sections.info.0.title', 'Contrato da SIR')
            ->where('focus.sections.done.0.title', 'Fecho de Setembro'));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?modo=lista'))->assertInertia(fn (Assert $page) => $page->where('focus', null));
});

it('tries a failed task again', function () {
    $failed = ($this->task)('Cotação de cimento', TaskStatus::InProgress);
    asTenant($this->tenant, fn () => AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $failed->id, 'status' => RunStatus::Failed]));

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "tasks/{$failed->id}/retry"))->assertSessionHas('success', 'O agente vai tentar de novo.');

    expect(asTenant($this->tenant, fn () => AgentRun::query()->where('task_id', $failed->id)->latest('id')->first()->status))->toBe(RunStatus::Queued);
});
