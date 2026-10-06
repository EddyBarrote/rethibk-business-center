<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\AgentRunner;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Department;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// People and agents as colleagues (docs/DECISOES.md, realinhamento L3 to L6).

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->boss, $this->clerk, $this->outsider, $this->agent] = asTenant($this->tenant, function () {
        $finance = Department::factory()->create(['name' => 'Finanças']);
        $boss = User::factory()->create(['name' => 'Chefe', 'role' => 'manager', 'department_id' => $finance->id]);

        return [
            $boss,
            User::factory()->create(['name' => 'Escriturária', 'email' => 'escrita@micomoc.test', 'department_id' => $finance->id]),
            User::factory()->create(['name' => 'Outra área', 'email' => 'fora@micomoc.test']),
            Agent::factory()->create(['key' => 'finance', 'name' => 'Finanças', 'department_id' => $finance->id, 'reports_to_user_id' => $boss->id]),
        ];
    });
});

/** Run a capability as the agent, inside a given task or conversation. */
function runInTask(Agent $agent, Task $task, string $key, array $arguments): mixed
{
    $run = app(AgentRunner::class)->create($agent, 'teste', TriggerType::Manual);
    $run->forceFill(['task_id' => $task->id])->save();

    return app(CapabilityRegistry::class)->find($key)->execute($arguments, new CapabilityContext($agent, $run->fresh()));
}

it('turns a request in a conversation into a task of the agent, with the person, outside the chat', function () {
    asTenant($this->tenant, function () {
        $chat = app(TaskThread::class)->conversation($this->clerk, $this->agent);

        // With only the "conversar" level the agent does not open work for her.
        $this->agent->assignees()->attach($this->clerk->id, ['role' => 'chat']);
        $refused = runInTask($this->agent, $chat, 'tasks.create', ['agent' => 'finance', 'title' => 'x', 'description' => 'y']);
        expect($refused->ok)->toBeFalse()->and($refused->content)->toContain('não pedir-te trabalho');

        $this->agent->assignees()->updateExistingPivot($this->clerk->id, ['role' => 'work']);

        $result = runInTask($this->agent, $chat, 'tasks.create', ['agent' => 'finance', 'title' => 'Reconciliar Setembro', 'description' => 'Pedido na conversa.']);
        $task = Task::query()->where('kind', TaskKind::Task)->sole();

        expect($result->ok)->toBeTrue()
            ->and($task->assignee_agent_id)->toBe($this->agent->id)
            ->and($task->user_id)->toBe($this->clerk->id)
            ->and($task->parent_id)->toBeNull()
            ->and($chat->messages()->latest('id')->value('body'))->toContain($task->identifier());

        Queue::assertPushed(RunAgent::class);
    });
});

it('lets an agent give work to people of its area and to the person it answers to, not to others', function () {
    asTenant($this->tenant, function () {
        $parent = Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'user_id' => $this->boss->id]);

        expect(runInTask($this->agent, $parent, 'tasks.create', ['person' => 'fora@micomoc.test', 'title' => 'x', 'description' => 'y'])->ok)->toBeFalse();

        $result = runInTask($this->agent, $parent, 'tasks.create', ['person' => 'escrita@micomoc.test', 'title' => 'Digitalizar facturas', 'description' => 'As de Setembro.']);
        $task = Task::query()->where('assignee_user_id', $this->clerk->id)->sole();

        expect($result->ok)->toBeTrue()
            ->and($task->assignee_agent_id)->toBeNull()
            ->and($task->parent_id)->toBe($parent->id)
            ->and($this->clerk->notifications()->count())->toBe(1);

        // The person finishes it and the agent that asked is woken with the result.
        app(TaskThread::class)->post($task, $this->clerk, 'Feito, estão na pasta partilhada.');
        app(TaskThread::class)->setStatus($task, TaskStatus::Done, $this->clerk);

        expect($parent->messages()->where('kind', 'report')->value('body'))->toContain('estão na pasta partilhada');
    });

    $task = asTenant($this->tenant, fn () => Task::query()->where('assignee_user_id', $this->clerk->id)->sole());

    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant, 'tasks?view=mine'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tasks', []));
    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant, "tasks/{$task->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('task.assignee_user.name', 'Escriturária'));
});

it('delivers work a person asked for into review, and the person accepts it or sends it back', function () {
    $task = asTenant($this->tenant, fn () => Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'created_by_user_id' => $this->boss->id, 'user_id' => $this->boss->id, 'status' => TaskStatus::InProgress]));

    asTenant($this->tenant, function () use ($task) {
        $result = runInTask($this->agent, $task, 'tasks.update_status', ['status' => 'done', 'note' => 'Mapa pronto.']);

        expect($result->content)->toContain('Em revisão')
            ->and($task->fresh()->status)->toBe(TaskStatus::InReview);
    });

    // Sending it back: a message from the person puts the agent back to work.
    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "tasks/{$task->id}/messages"), ['body' => 'Falta a coluna do IVA.', 'mode' => 'message'])->assertRedirect();
    asTenant($this->tenant, fn () => expect($task->fresh()->status)->toBe(TaskStatus::InProgress));

    $this->actingAs($this->boss)->patch(tenantUrl($this->tenant, "tasks/{$task->id}"), ['status' => 'done'])->assertRedirect();
    asTenant($this->tenant, fn () => expect($task->fresh()->status)->toBe(TaskStatus::Done));
});

it('lets managers give a task to a person directly, and keeps others asking through the conversation', function () {
    $this->actingAs($this->clerk)->post(tenantUrl($this->tenant, 'tasks'), ['kind' => 'task', 'title' => 'Algo'])->assertForbidden();

    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, 'tasks'), ['kind' => 'task', 'title' => 'Arquivar contratos', 'assignee_user_id' => $this->clerk->id])->assertRedirect();

    asTenant($this->tenant, fn () => expect(Task::query()->sole())
        ->assignee_user_id->toBe($this->clerk->id)
        ->and(AgentRun::query()->count())->toBe(0));

    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('work.0.title', 'Arquivar contratos'));
});

it('wakes each agent on a heartbeat for its quietest open task, once', function () {
    [$quiet, $fresh] = asTenant($this->tenant, fn () => [
        Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'status' => TaskStatus::InProgress, 'last_activity_at' => now()->subHours(3)]),
        Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'status' => TaskStatus::Todo, 'last_activity_at' => now()->subMinutes(5)]),
    ]);

    $this->artisan('agents:heartbeat')->assertSuccessful();
    $this->artisan('agents:heartbeat')->assertSuccessful();

    asTenant($this->tenant, function () use ($quiet, $fresh) {
        expect(AgentRun::query()->where('task_id', $quiet->id)->count())->toBe(1)
            ->and(AgentRun::query()->where('task_id', $fresh->id)->count())->toBe(0)
            ->and(AgentRun::query()->sole()->trigger_type)->toBe(TriggerType::Schedule);
    });
});

it('lists an agent its tasks without loading the tenant once per task', function () {
    asTenant($this->tenant, function () {
        Task::factory()->count(3)->create(['assignee_agent_id' => $this->agent->id]);

        $result = runCapability($this->agent, 'tasks.list');

        expect($result->ok)->toBeTrue()
            ->and(json_decode($result->content, true)['mine'])->toHaveCount(3);
    });
});
