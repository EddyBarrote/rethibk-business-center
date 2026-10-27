<?php

use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\Task;
use App\Models\TaskCollaborator;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\QuickDate;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Tarefas: colaborar com agentes e colegas", "Tarefas: criação rápida", "Tarefas: quadro e planear".

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    [$this->owner, $this->member, $this->colleague, $this->agent] = asTenant($this->tenant, fn () => [
        User::factory()->owner()->create(['name' => 'Barrote']),
        User::factory()->create(['name' => 'Naira']),
        User::factory()->create(['name' => 'Kleyton']),
        Agent::factory()->create(['name' => 'Agente de Finanças']),
    ]);
});

it('reads the day out of a one-line task', function () {
    $tuesday = Carbon::parse('2026-10-27');

    expect(QuickDate::parse('Ligar à Mozal sexta', $tuesday))->sequence(fn ($t) => $t->toBe('Ligar à Mozal'), fn ($d) => $d->toDateString()->toBe('2026-10-30'))
        ->and(QuickDate::parse('Enviar proposta amanhã', $tuesday)[1]->toDateString())->toBe('2026-10-28')
        ->and(QuickDate::parse('Rever contrato na terça-feira', $tuesday)[1]->toDateString())->toBe('2026-11-03')
        ->and(QuickDate::parse('Pagar renda dia 5', $tuesday)[1]->toDateString())->toBe('2026-11-05')
        ->and(QuickDate::parse('Fecho de contas até 15/11', $tuesday))->sequence(fn ($t) => $t->toBe('Fecho de contas'), fn ($d) => $d->toDateString()->toBe('2026-11-15'))
        ->and(QuickDate::parse('Organizar arquivo', $tuesday))->toBe(['Organizar arquivo', null]);
});

it('lets anyone write their own tasks in one line', function () {
    $this->travelTo(Carbon::parse('2026-10-27 09:00', config('agents.schedule_timezone')));

    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'tasks'), ['kind' => 'task', 'title' => 'Ligar à Mozal sexta', 'quick' => true])
        ->assertRedirect()->assertSessionHas('success');

    $task = asTenant($this->tenant, fn () => Task::query()->latest('id')->firstOrFail());
    expect($task->title)->toBe('Ligar à Mozal')
        ->and($task->user_id)->toBe($this->member->id)
        ->and($task->due_at->setTimezone(config('agents.schedule_timezone'))->toDateString())->toBe('2026-10-30');

    // Giving a task to someone else from here is still for chefias.
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'tasks'), ['kind' => 'task', 'title' => 'Para o Kleyton', 'assignee_user_id' => $this->colleague->id])->assertForbidden();
});

it('invites an agent and a colleague to a task, each with their part', function () {
    $task = asTenant($this->tenant, fn () => Task::factory()->create(['title' => 'Proposta à Mozal', 'user_id' => $this->owner->id, 'created_by_user_id' => $this->owner->id]));

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "tasks/{$task->id}/collaborators"), ['agent_id' => $this->agent->id, 'role' => 'preparar', 'note' => 'Os preços de 2025'])->assertRedirect();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "tasks/{$task->id}/collaborators"), ['user_id' => $this->colleague->id, 'role' => 'rever'])->assertRedirect();

    [$agentPart, $colleaguePart] = asTenant($this->tenant, fn () => TaskCollaborator::query()->with('subtask')->orderBy('id')->get()->all());
    expect($agentPart->subtask->title)->toBe('Preparar: Proposta à Mozal')
        ->and($agentPart->subtask->parent_id)->toBe($task->id)
        ->and($agentPart->subtask->assignee_agent_id)->toBe($this->agent->id)
        ->and($agentPart->subtask->description)->toContain('Os preços de 2025')
        ->and($colleaguePart->subtask->assignee_user_id)->toBe($this->colleague->id);

    // The colleague now sees the task, and it shows who works on it.
    $this->actingAs($this->colleague)->get(tenantUrl($this->tenant, "tasks/{$task->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.collaborators.0.name', 'Agente de Finanças')
            ->where('task.collaborators.0.role_label', 'Preparar')
            ->where('task.collaborators.1.name', 'Kleyton'));

    // Leaving cancels their part.
    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "tasks/{$task->id}/collaborators/{$colleaguePart->id}"))->assertRedirect();
    expect(asTenant($this->tenant, fn () => $colleaguePart->subtask->fresh()->status))->toBe(TaskStatus::Cancelled)
        ->and(asTenant($this->tenant, fn () => TaskCollaborator::query()->count()))->toBe(1);
    $this->actingAs($this->colleague)->get(tenantUrl($this->tenant, "tasks/{$task->id}"))->assertForbidden();
});

it('shows the board and the week to plan', function () {
    $this->travelTo(Carbon::parse('2026-10-27 09:00', config('agents.schedule_timezone')));
    asTenant($this->tenant, function () {
        Task::factory()->create(['title' => 'Sem data', 'user_id' => $this->member->id, 'due_at' => null]);
        Task::factory()->create(['title' => 'Quinta', 'user_id' => $this->member->id, 'due_at' => Carbon::parse('2026-10-29 18:00', config('agents.schedule_timezone'))]);
        Task::factory()->create(['title' => 'Próxima semana', 'user_id' => $this->member->id, 'due_at' => Carbon::parse('2026-11-04 18:00', config('agents.schedule_timezone'))]);
        Task::factory()->create(['title' => 'Atrasada', 'user_id' => $this->member->id, 'due_at' => Carbon::parse('2026-10-20 18:00', config('agents.schedule_timezone'))]);
        Task::factory()->create(['title' => 'De outra pessoa', 'user_id' => $this->colleague->id, 'created_by_user_id' => $this->colleague->id]);
    });

    $titles = fn (string $query) => collect($this->actingAs($this->member)->get(tenantUrl($this->tenant, "tasks?{$query}"))->viewData('page')['props']['board']['tasks'])->pluck('title')->all();

    expect($titles('modo=planear'))->toEqualCanonicalizing(['Sem data', 'Quinta', 'Atrasada'])
        ->and($titles('modo=planear&semana=2026-11-02'))->toEqualCanonicalizing(['Sem data', 'Próxima semana'])
        ->and($titles('modo=quadro'))->toEqualCanonicalizing(['Sem data', 'Quinta', 'Próxima semana', 'Atrasada']);
});
