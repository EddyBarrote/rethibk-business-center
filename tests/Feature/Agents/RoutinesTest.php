<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\RoutineRunner;
use App\Enums\EmailCategory;
use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTrigger;
use App\Jobs\RunAgent;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Support\ScheduleLabel;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Rotinas e fluxos agendados (docs/DECISOES.md): the admin creates routines,
// the result goes to the person it is for, and the admins always hear of it.

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->admin = User::factory()->owner()->create(['name' => 'Carlos Tembe']);
        $this->ana = User::factory()->create(['name' => 'Ana Sitoe', 'role' => 'manager']);
        $this->member = User::factory()->create(['role' => 'member']);
        $this->agent = templateAgent('procurement', ['reports_to_user_id' => $this->ana->id]);
        AgentRoutine::query()->delete(); // the template's own routines
    });
});

it('says schedules in words', function () {
    expect(ScheduleLabel::for('30 8 * * 1-5'))->toBe('Dias úteis às 08:30')
        ->and(ScheduleLabel::for('0 8 * * *'))->toBe('Todos os dias às 08:00')
        ->and(ScheduleLabel::for('0 8 * * 1,3'))->toBe('Às segundas e quartas, às 08:00')
        ->and(ScheduleLabel::for('0 8 3 * *'))->toBe('Dia 3 de cada mês, às 08:00')
        ->and(ScheduleLabel::for('15 * * * *'))->toBe('De hora a hora, aos 15 minutos')
        ->and(ScheduleLabel::for('*/5 8-17 * * *'))->toBe('Personalizado: */5 8-17 * * *');
});

it('lets only admins see and create routines', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'routines'))->assertForbidden();
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'routines'), [])->assertForbidden();

    $this->actingAs($this->admin)->post(tenantUrl($this->tenant, 'routines'), [
        'name' => 'Acompanhar entregas', 'kind' => 'agent', 'agent_id' => $this->agent->id,
        'prompt' => 'Lista as entregas em atraso.', 'schedule' => '30 8 * * 1-5', 'owner_user_id' => $this->ana->id,
    ])->assertRedirect()->assertSessionHas('success', 'Rotina criada: Dias úteis às 08:30.');

    // Several routines in one list (strict mode forbids lazy loading across them).
    asTenant($this->tenant, fn () => AgentRoutine::factory()->create(['agent_id' => $this->agent->id, 'name' => 'Zeta', 'last_run_at' => now()]));

    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'routines'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Routines/Index')
            ->where('routines.0.schedule_label', 'Dias úteis às 08:30')
            ->where('routines.1.name', 'Zeta')
            ->where('routines.0.recipient', 'Ana Sitoe'));

    expect(asTenant($this->tenant, fn () => AgentRoutine::query()->where('name', 'Acompanhar entregas')->sole()->created_by_user_id))->toBe($this->admin->id);
});

it('refuses a person who cannot talk to the agent, and a flow that starts with email', function () {
    $workflow = asTenant($this->tenant, fn () => Workflow::factory()->create(['agent_id' => $this->agent->id]));

    $this->actingAs($this->admin)->post(tenantUrl($this->tenant, 'routines'), [
        'name' => 'X', 'kind' => 'agent', 'agent_id' => $this->agent->id, 'prompt' => 'Y', 'schedule' => '0 8 * * *', 'owner_user_id' => $this->member->id,
    ])->assertSessionHasErrors('owner_user_id');

    $this->actingAs($this->admin)->post(tenantUrl($this->tenant, 'routines'), [
        'name' => 'X', 'kind' => 'workflow', 'workflow_id' => $workflow->id, 'schedule' => 'todos os dias',
    ])->assertSessionHasErrors('schedule');

    $this->actingAs($this->admin)->post(tenantUrl($this->tenant, 'routines'), [
        'name' => 'X', 'kind' => 'workflow', 'workflow_id' => $workflow->id, 'schedule' => '0 8 * * *',
    ])->assertSessionHasErrors('workflow_id');
});

it('asks the agent in the conversation of the person it is for, and tells the admins the result', function () {
    Queue::fake();
    $this->travelTo(now()->setTimezone('Africa/Maputo')->setTime(8, 30)->utc());

    asTenant($this->tenant, fn () => AgentRoutine::factory()->create([
        'agent_id' => $this->agent->id, 'owner_user_id' => $this->ana->id, 'created_by_user_id' => $this->admin->id,
        'name' => 'Acompanhar entregas', 'prompt' => 'Lista as entregas em atraso.', 'schedule' => '30 8 * * 1-5', 'created_at' => now()->subDay(),
    ]));
    $this->travelTo(now()->next('Monday')->setTimezone('Africa/Maputo')->setTime(8, 30)->utc());

    asTenant($this->tenant, fn () => AgentRoutine::factory()->create(['agent_id' => $this->agent->id, 'schedule' => '0 3 * * *', 'name' => 'Outra']));

    $this->artisan('agents:run-routines')->assertSuccessful();
    $this->artisan('agents:run-routines')->assertSuccessful();

    asTenant($this->tenant, function () {
        $routine = AgentRoutine::query()->where('name', 'Acompanhar entregas')->sole();
        $chat = Task::query()->where('kind', TaskKind::Chat)->sole();
        $run = AgentRun::query()->sole();

        expect($chat->user_id)->toBe($this->ana->id)
            ->and($chat->messages()->sole()->kind)->toBe(TaskMessageKind::Message)
            ->and($chat->messages()->sole()->body)->toContain('Rotina «Acompanhar entregas» (Dias úteis às 08:30)')
            ->and($run->task_id)->toBe($chat->id)
            ->and($routine->last_run_id)->toBe($run->id)
            ->and($routine->last_task_id)->toBe($chat->id);

        GenericAgent::fake(['Há duas entregas em atraso.']);
        (new RunAgent($this->tenant->id, $run->id))->handle(app(AgentRunner::class));

        expect($chat->messages()->where('author_type', 'agent')->sole()->body)->toBe('Há duas entregas em atraso.')
            ->and($this->ana->notifications()->sole()->data['title'])->toBe("{$this->agent->name} respondeu")
            ->and($this->admin->notifications()->sole()->data['title'])->toBe("Rotina «Acompanhar entregas»: {$this->agent->name} respondeu")
            ->and($this->admin->notifications()->sole()->data['body'])->toContain('na conversa de Ana Sitoe');
    });
});

it('catches up once on a routine missed while the machine slept, but not an old one', function () {
    $routine = asTenant($this->tenant, fn () => AgentRoutine::factory()->make([
        'agent_id' => $this->agent->id, 'schedule' => '0 8 * * *', 'created_at' => now()->subDays(3), 'last_run_at' => now()->subDays(2),
    ]));
    $at = fn (int $h, int $m = 0) => now()->setTimezone('Africa/Maputo')->setTime($h, $m)->utc();

    expect($routine->dueAt($at(9, 40))?->eq($at(8)))->toBeTrue()
        ->and($routine->dueAt($at(21, 0)))->toBeNull()
        ->and($routine->dueAt($at(7, 59)))->toBeNull();

    $routine->last_run_at = $at(9, 40);
    expect($routine->dueAt($at(9, 41)))->toBeNull();
});

it('lets a flow start on a schedule, and the routine opens its task with the person', function () {
    Queue::fake();
    $graph = ['nodes' => [
        ['id' => 'start', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Por agenda']],
        ['id' => 'look', 'type' => 'agent', 'position' => ['x' => 0, 'y' => 100], 'data' => ['label' => 'Ver entregas', 'instruction' => 'Vê as entregas em atraso.']],
        ['id' => 'end', 'type' => 'end', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Fim']],
    ], 'edges' => [['id' => 'a', 'source' => 'start', 'target' => 'look'], ['id' => 'b', 'source' => 'look', 'target' => 'end']]];

    $this->actingAs($this->admin)->post(tenantUrl($this->tenant, 'workflows'), [
        'name' => 'Entregas da semana', 'trigger' => 'schedule', 'agent_id' => $this->agent->id, 'graph' => $graph, 'activate' => true,
    ])->assertRedirect()->assertSessionHas('success', 'Fluxo activo, mas ainda sem horário: crie a rotina que o começa em Rotinas.');

    $workflow = asTenant($this->tenant, fn () => Workflow::query()->sole());
    expect($workflow->trigger)->toBe(WorkflowTrigger::Schedule)
        ->and($workflow->email_category)->toBeNull()
        ->and($workflow->status)->toBe(WorkflowStatus::Active);

    $this->actingAs($this->admin)->post(tenantUrl($this->tenant, 'routines'), [
        'name' => 'Entregas da semana', 'kind' => 'workflow', 'workflow_id' => $workflow->id, 'schedule' => '0 8 * * 1', 'owner_user_id' => $this->ana->id,
    ])->assertRedirect();

    $routine = asTenant($this->tenant, fn () => AgentRoutine::query()->sole());
    $this->actingAs($this->admin)->post(tenantUrl($this->tenant, "routines/{$routine->id}/run"))->assertSessionHas('success');

    asTenant($this->tenant, function () use ($workflow) {
        $task = Task::query()->where('kind', TaskKind::Task)->sole();

        expect($task->user_id)->toBe($this->ana->id)
            ->and($task->assignee_agent_id)->toBe($this->agent->id)
            ->and(WorkflowRun::query()->sole()->workflow_id)->toBe($workflow->id)
            ->and(AgentRoutine::query()->sole()->last_task_id)->toBe($task->id)
            ->and($this->admin->notifications()->sole()->data['title'])->toBe("Rotina «Entregas da semana» abriu {$task->identifier()}")
            ->and(Workflow::activeFor(EmailCategory::ClientRfq))->toBeNull();
    });

    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, "workflows/{$workflow->id}/edit"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('initial.trigger', 'schedule')->where('routines.0.schedule_label', 'Às segundas, às 08:00'));
});

it('does not run a routine whose flow is paused', function () {
    $result = asTenant($this->tenant, function () {
        $workflow = Workflow::factory()->create(['agent_id' => $this->agent->id, 'trigger' => WorkflowTrigger::Schedule, 'email_category' => null, 'status' => WorkflowStatus::Paused]);

        return app(RoutineRunner::class)->run(AgentRoutine::factory()->create(['agent_id' => null, 'workflow_id' => $workflow->id]));
    });

    expect($result['ok'])->toBeFalse()
        ->and(asTenant($this->tenant, fn () => Task::query()->count()))->toBe(0);
});
