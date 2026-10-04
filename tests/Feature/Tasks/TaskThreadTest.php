<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\AgentRunner;
use App\Enums\ActorType;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Goal;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    [$this->a, $this->b] = Tenant::factory()->count(2)->create();

    [$this->owner, $this->member, $this->boss, $this->chief, $this->finance] = asTenant($this->a, function () {
        $boss = User::factory()->create(['name' => 'Chefe']);
        $chief = Agent::factory()->create(['key' => 'chief', 'name' => 'Chief of Staff', 'reports_to_user_id' => $boss->id]);

        return [
            User::factory()->owner()->create(),
            User::factory()->create(),
            $boss,
            $chief,
            Agent::factory()->create(['key' => 'finance', 'name' => 'Finanças', 'reports_to_user_id' => $boss->id, 'reports_to_agent_id' => $chief->id]),
        ];
    });
});

it('opens a chat with an agent and queues it on the first message', function () {
    $this->actingAs($this->boss)->post(tenantUrl($this->a, "agents/{$this->chief->id}/chat"), ['message' => 'Como estão as contas?'])
        ->assertRedirect();

    [$task, $run] = asTenant($this->a, fn () => [Task::query()->sole(), AgentRun::query()->sole()]);

    expect($task->kind)->toBe(TaskKind::Chat)
        ->and($task->user_id)->toBe($this->boss->id)
        ->and($run->task_id)->toBe($task->id)
        ->and($run->input)->toContain('Como estão as contas?');
    Queue::assertPushed(RunAgent::class);
});

it('answers in the thread and keeps the conversation as history', function () {
    GenericAgent::fake(['Olá! As contas estão em dia.', 'Sim, 12 facturas.']);

    asTenant($this->a, function () {
        $threads = app(TaskThread::class);
        $runner = app(AgentRunner::class);
        $task = $threads->open(['kind' => TaskKind::Chat, 'title' => 'Contas', 'status' => TaskStatus::InProgress, 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->boss->id], $this->boss, 'Como estão as contas?');

        $runner->run(AgentRun::query()->sole());
        expect($task->messages()->where('author_type', ActorType::Agent)->sole()->body)->toBe('Olá! As contas estão em dia.');

        $threads->post($task->refresh(), $this->boss, 'Quantas facturas por pagar?');
        $second = AgentRun::query()->latest('id')->first();

        $history = $threads->history($second);
        expect($history)->toHaveCount(2)
            ->and($history[0]->content)->toContain('Como estão as contas?')
            ->and($history[1]->role->value ?? $history[1]->role)->toBe('assistant');

        $runner->run($second);
        expect($task->messages()->where('author_type', ActorType::Agent)->count())->toBe(2);
    });
});

it('runs one agent turn at a time and picks up messages that arrived meanwhile', function () {
    GenericAgent::fake(['Primeira resposta.']);

    asTenant($this->a, function () {
        $threads = app(TaskThread::class);
        $task = $threads->open(['kind' => TaskKind::Chat, 'title' => 'x', 'status' => TaskStatus::InProgress, 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->boss->id], $this->boss, 'Um');
        $first = AgentRun::query()->sole();
        $first->forceFill(['status' => 'running', 'started_at' => now()->subSecond()])->save();

        $threads->post($task->refresh(), $this->boss, 'Dois');
        expect(AgentRun::query()->count())->toBe(1);

        $first->forceFill(['status' => 'completed', 'output' => ['text' => 'Feito.']])->save();
        $threads->recordReply($first);

        expect(AgentRun::query()->count())->toBe(2)
            ->and(AgentRun::query()->latest('id')->first()->input)->toContain('Dois');
    });
});

it('lets an agent delegate down the org chart and wakes it when the work is done', function () {
    asTenant($this->a, function () {
        $threads = app(TaskThread::class);
        $parent = $threads->open(['kind' => TaskKind::Task, 'title' => 'Fecho do mês', 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->boss->id], $this->boss, null, start: false);
        $run = app(AgentRunner::class)->create($this->chief, 'x', TriggerType::Manual, null, null, $parent->id);

        $result = app(CapabilityRegistry::class)->find('tasks.create')
            ->execute(['agent' => 'finance', 'title' => 'Reconciliar banco', 'description' => 'Reconcilia o extracto de Setembro.'], new CapabilityContext($this->chief, $run));

        expect($result->ok)->toBeTrue();
        $child = Task::query()->where('parent_id', $parent->id)->sole();
        expect($child->assignee_agent_id)->toBe($this->finance->id)
            ->and($child->created_by_agent_id)->toBe($this->chief->id);

        // The delegating run is still going when the work comes back: it is woken once it ends.
        $run->forceFill(['status' => 'running', 'started_at' => now()->subMinute()])->save();
        $threads->setStatus($child, TaskStatus::Done, $this->finance, 'Tudo reconciliado.');

        expect($parent->messages()->where('kind', 'report')->exists())->toBeTrue()
            ->and(AgentRun::query()->where('agent_id', $this->chief->id)->count())->toBe(1);

        $run->forceFill(['status' => 'completed', 'output' => ['text' => 'Delegado.']])->save();
        $threads->recordReply($run);

        expect(AgentRun::query()->where('agent_id', $this->chief->id)->count())->toBe(2)
            ->and(AgentRun::query()->latest('id')->first()->input)->toContain('foi concluída');
    });
});

it('refuses delegation to agents outside the chart', function () {
    asTenant($this->a, function () {
        $result = runCapability($this->finance, 'tasks.create', ['agent' => 'finance', 'title' => 'x', 'description' => 'y']);
        expect($result->ok)->toBeFalse();

        $other = Agent::factory()->create(['key' => 'hr', 'reports_to_agent_id' => $this->chief->id]);
        $result = runCapability($this->finance, 'tasks.create', ['agent' => $other->key, 'title' => 'x', 'description' => 'y']);
        expect($result->ok)->toBeFalse()->and($result->content)->toContain('organigrama');
    });
});

it('lets an agent ask a person and wakes it with the answer', function () {
    asTenant($this->a, function () {
        $threads = app(TaskThread::class);
        $task = $threads->open(['kind' => TaskKind::Task, 'title' => 'Proposta', 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->boss->id], $this->boss, null, start: false);
        $run = app(AgentRunner::class)->create($this->chief, 'x', TriggerType::Manual, null, null, $task->id);

        app(CapabilityRegistry::class)->find('tasks.ask_human')
            ->execute(['question' => 'Qual é a margem mínima?'], new CapabilityContext($this->chief, $run));

        expect($task->refresh()->status)->toBe(TaskStatus::WaitingHuman)
            ->and($this->boss->notifications()->count())->toBeGreaterThan(0);

        $run->forceFill(['status' => 'completed'])->save();
        $threads->post($task, $this->boss, '15%.');

        expect($task->refresh()->status)->toBe(TaskStatus::InProgress)
            ->and(AgentRun::query()->latest('id')->first()->input)->toContain('15%');
    });
});

it('opens a new conversation when an agent asks someone outside a task', function () {
    asTenant($this->a, function () {
        runCapability($this->chief, 'tasks.ask_human', ['question' => 'Posso marcar a reunião para sexta?', 'to' => $this->boss->email]);

        $chat = Task::query()->sole();
        expect($chat->kind)->toBe(TaskKind::Chat)
            ->and($chat->status)->toBe(TaskStatus::WaitingHuman)
            ->and($chat->user_id)->toBe($this->boss->id)
            ->and(AgentRun::query()->where('task_id', $chat->id)->exists())->toBeFalse();
    });
});

it('shows tasks only to people involved, and never across tenants', function () {
    $task = asTenant($this->a, fn () => app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Privada', 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->boss->id], $this->boss, null, start: false));
    $userB = asTenant($this->b, fn () => User::factory()->owner()->create());

    $this->actingAs($this->boss)->get(tenantUrl($this->a, "tasks/{$task->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tasks/Show')->where('task.title', 'Privada'));
    $this->actingAs($this->owner)->get(tenantUrl($this->a, "tasks/{$task->id}"))->assertOk();
    $this->actingAs($this->member)->get(tenantUrl($this->a, "tasks/{$task->id}"))->assertForbidden();
    $this->actingAs($userB)->get(tenantUrl($this->b, "tasks/{$task->id}"))->assertNotFound();

    $this->actingAs($this->member)->get(tenantUrl($this->a, 'tasks?view=all'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tasks/Index')->has('tasks', 0));
});

it('posts a direct action and changes status from the console', function () {
    $task = asTenant($this->a, fn () => app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Relatório', 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->boss->id], $this->boss, null, start: false));

    $this->actingAs($this->boss)->post(tenantUrl($this->a, "tasks/{$task->id}/messages"), ['body' => 'Gera o relatório de Setembro.', 'mode' => 'action'])->assertRedirect();
    expect(asTenant($this->a, fn () => AgentRun::query()->latest('id')->first()->input))->toContain('Acção directa');

    $this->actingAs($this->boss)->patch(tenantUrl($this->a, "tasks/{$task->id}"), ['status' => 'done'])->assertRedirect();
    expect(asTenant($this->a, fn () => $task->fresh()->status))->toBe(TaskStatus::Done);
});

it('lists goals with progress and keeps the org chart free of cycles', function () {
    asTenant($this->a, fn () => Goal::factory()->create(['title' => 'Crescer 20%']));

    $this->actingAs($this->member)->get(tenantUrl($this->a, 'goals'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Goals/Index')->where('goals.0.title', 'Crescer 20%'));

    $this->actingAs($this->owner)->put(tenantUrl($this->a, "org/{$this->chief->id}"), ['reports_to_agent_id' => $this->finance->id])
        ->assertSessionHasErrors('reports_to_agent_id');

    $this->actingAs($this->member)->put(tenantUrl($this->a, "org/{$this->finance->id}"), ['reports_to_agent_id' => null])->assertForbidden();

    $this->actingAs($this->owner)->get(tenantUrl($this->a, 'org'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Org/Index')->has('agents', 2));
});

it('puts the Chief of Staff on top of the org chart, whatever is installed first', function () {
    asTenant($this->b, function () {
        $finance = templateAgent('finance');
        $chief = templateAgent('chief_of_staff');
        $triage = templateAgent('triage');

        expect($finance->fresh()->reports_to_agent_id)->toBe($chief->id)
            ->and($triage->fresh()->reports_to_agent_id)->toBe($chief->id)
            ->and($chief->fresh()->reports_to_agent_id)->toBeNull();
    });
});

it('keeps one persistent conversation per person and agent', function () {
    $this->actingAs($this->boss)->post(tenantUrl($this->a, "agents/{$this->chief->id}/chat"), ['message' => 'Olá'])->assertRedirect();
    $this->actingAs($this->boss)->post(tenantUrl($this->a, "agents/{$this->chief->id}/chat"), ['message' => 'Outra coisa'])->assertRedirect();
    $this->actingAs($this->boss)->post(tenantUrl($this->a, 'tasks'), ['kind' => 'chat', 'assignee_agent_id' => $this->chief->id, 'message' => 'E mais esta'])->assertRedirect();

    $chat = asTenant($this->a, fn () => Task::query()->sole());
    expect($chat->chat_key)->toBe("{$this->boss->id}:{$this->chief->id}")
        ->and(asTenant($this->a, fn () => $chat->messages()->count()))->toBe(3);

    $this->actingAs($this->boss)->get(tenantUrl($this->a, "agents/{$this->chief->id}/chat"))->assertRedirect(tenantUrl($this->a, "tasks/{$chat->id}"));

    // Another person gets their own conversation with the same agent.
    $this->actingAs($this->owner)->get(tenantUrl($this->a, "agents/{$this->chief->id}/chat"))->assertRedirect();
    expect(asTenant($this->a, fn () => Task::query()->count()))->toBe(2);

    // An agent asking that person writes in the same conversation.
    asTenant($this->a, fn () => runCapability($this->chief, 'tasks.ask_human', ['question' => 'Confirma a reunião?', 'to' => $this->boss->email]));
    expect(asTenant($this->a, fn () => [$chat->fresh()->status, $chat->messages()->count(), Task::query()->count()]))
        ->toBe([TaskStatus::WaitingHuman, 5, 2]);

    // A conversation keeps its agent.
    $this->actingAs($this->boss)->patch(tenantUrl($this->a, "tasks/{$chat->id}"), ['assignee_agent_id' => $this->finance->id])->assertRedirect();
    expect(asTenant($this->a, fn () => $chat->fresh()->assignee_agent_id))->toBe($this->chief->id);
});
