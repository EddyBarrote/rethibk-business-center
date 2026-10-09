<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->member, $this->agent] = asTenant($this->tenant, fn () => [
        User::factory()->owner()->create(['name' => 'Dona']),
        User::factory()->create(['name' => 'Marta']),
        Agent::factory()->create(['name' => 'Finanças']),
    ]);
});

it('filters, searches, counts every view and pages the list', function () {
    asTenant($this->tenant, function () {
        Task::factory()->count(23)->create(['user_id' => $this->owner->id]);
        Task::factory()->create(['title' => 'Proposta para a EDM', 'user_id' => $this->owner->id, 'priority' => TaskPriority::Urgent, 'assignee_agent_id' => $this->agent->id]);
        Task::factory()->create(['title' => 'Relatório atrasado', 'user_id' => $this->owner->id, 'due_at' => now()->subDays(2)]);
        Task::factory()->create(['title' => 'Responder ao cliente', 'user_id' => $this->owner->id, 'status' => TaskStatus::WaitingHuman]);
        Task::factory()->create(['title' => 'Já feita', 'user_id' => $this->owner->id, 'status' => TaskStatus::Done]);
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tasks/Index')
            ->has('tasks.data', 20)
            ->where('tasks.total', 26)
            ->where('counts.mine', 26)
            ->where('counts.waiting', 1)
            ->where('counts.closed', 1)
            ->where('status_counts.todo', 25)
            ->where('waiting_total', 1)
            ->where('overdue_total', 1)
            // Grouped by status: what waits for a person comes first.
            ->where('tasks.data.0.title', 'Responder ao cliente'));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?por_pagina=50'))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 26));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?q=proposta+edm'))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1)->where('tasks.data.0.title', 'Proposta para a EDM')->where('counts.closed', 0));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?q=finanças'))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "tasks?responsavel=agent:{$this->agent->id}&prioridade=urgent"))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1)->where('filters.responsavel', "agent:{$this->agent->id}"));

    // Older links from an agent's page still work.
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "tasks?agent={$this->agent->id}"))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?prazo=atrasadas'))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1)->where('tasks.data.0.title', 'Relatório atrasado'));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?view=closed'))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1)->where('tasks.data.0.status', 'done'));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?agrupar=prioridade&ordenar=prazo'))
        ->assertInertia(fn (Assert $page) => $page->where('tasks.data.0.title', 'Proposta para a EDM'));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?estado=nada'))->assertSessionHasErrors('estado');
});

it('sends the old conversations view to the conversations, which live under the agents', function () {
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?view=chats'))->assertRedirectToRoute('home');
});

it('changes the status and priority of several tasks at once, only where allowed', function () {
    [$mine, $other] = asTenant($this->tenant, fn () => [
        Task::factory()->count(2)->create(['user_id' => $this->member->id]),
        Task::factory()->create(['user_id' => $this->owner->id]),
    ]);

    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'tasks/bulk'), [
        'ids' => [...$mine->modelKeys(), $other->id],
        'status' => 'done',
        'priority' => 'high',
    ])->assertRedirect()->assertSessionHas('success', '2 tarefas actualizadas.');

    asTenant($this->tenant, function () use ($mine, $other) {
        foreach ($mine as $task) {
            expect($task->fresh()->status)->toBe(TaskStatus::Done)->and($task->fresh()->priority)->toBe(TaskPriority::High);
        }
        expect($other->fresh()->status)->toBe(TaskStatus::Todo);
    });

    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'tasks/bulk'), ['ids' => $mine->modelKeys()])->assertSessionHasErrors(['status', 'priority']);
});
