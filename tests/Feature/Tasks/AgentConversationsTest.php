<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Conversas a partir dos agentes".

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->clerk, $this->finance, $this->triage] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create(['name' => 'Dona']);
        $clerk = User::factory()->create(['name' => 'Rui']);
        $finance = Agent::factory()->create(['name' => 'Agente de Finanças']);
        $triage = Agent::factory()->create(['name' => 'Agente de Triagem']);
        $finance->assignees()->attach($clerk->id, ['role' => 'chat']);

        return [$owner, $clerk, $finance, $triage];
    });
});

function chatWith(User $user, Agent $agent, string $body = 'Olá'): Task
{
    $threads = app(TaskThread::class);
    $chat = $threads->conversation($user, $agent);
    $threads->post($chat, $user, $body);

    return $chat->fresh();
}

// Coming in now opens the entry screen: tests/Feature/HomeTest.php.

it('lists the agents as conversations, the latest first, with the answers not read yet', function () {
    $chat = asTenant($this->tenant, function () {
        $chat = chatWith($this->owner, $this->triage);
        $chat->forceFill(['chat_read_at' => now()->subMinute()])->save();
        app(TaskThread::class)->post($chat, $this->triage, 'Classifiquei 12 emails.', wake: false, notify: false);
        app(TaskThread::class)->post($chat, $this->triage, 'Três foram para Clientes.', wake: false, notify: false);

        return $chat;
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebar_agents.0.id', $this->triage->id)
            ->where('sidebar_agents.0.chat_id', $chat->id)
            ->where('sidebar_agents.0.unread', 2)
            ->where('sidebar_agents.1.id', $this->finance->id)
            ->where('sidebar_agents.1.chat_id', null)
            ->where('sidebar_agents.1.unread', 0));

    // Opening the conversation reads it.
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sidebar_agents.0.unread', 0));
});

it('lets whoever reads all conversations read them from the agent page, never write in them', function () {
    $chat = asTenant($this->tenant, fn () => chatWith($this->clerk, $this->finance, 'Qual é o saldo do BCI?'));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "agents/{$this->finance->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversations', 1)
            ->where('conversations.0.id', $chat->id)
            ->where('conversations.0.person', 'Rui'));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.reply', false)->where('can.update', false)->where('agent_work', null));
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "tasks/{$chat->id}/messages"), ['body' => 'Olá', 'mode' => 'message'])->assertForbidden();
    $this->actingAs($this->owner)->patch(tenantUrl($this->tenant, "tasks/{$chat->id}"), ['status' => 'done'])->assertForbidden();

    // Reading it does not mark it read for its person.
    expect(asTenant($this->tenant, fn () => $chat->fresh()->chat_read_at))->toBeNull();

    // The person still writes in their own; others without the permission see no list.
    $this->actingAs($this->clerk)->post(tenantUrl($this->tenant, "tasks/{$chat->id}/messages"), ['body' => 'E o do BIM?', 'mode' => 'message'])->assertRedirect();
    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant, "agents/{$this->finance->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('conversations', null));
});

it('shows in the conversation the person\'s tasks with the agent and its approvals', function () {
    [$chat, $task, $approval] = asTenant($this->tenant, function () {
        $chat = chatWith($this->owner, $this->finance);
        $task = Task::factory()->create(['title' => 'Fecho de Setembro', 'user_id' => $this->owner->id, 'assignee_agent_id' => $this->finance->id]);
        Task::factory()->create(['title' => 'De outra pessoa', 'user_id' => $this->clerk->id, 'assignee_agent_id' => $this->finance->id]);
        Task::factory()->create(['title' => 'De outro agente', 'user_id' => $this->owner->id, 'assignee_agent_id' => $this->triage->id]);
        $run = AgentRun::factory()->create(['agent_id' => $this->finance->id, 'task_id' => $task->id]);
        $approval = Approval::factory()->create(['agent_run_id' => $run->id, 'agent_id' => $this->finance->id]);

        return [$chat, $task, $approval];
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('agent_work.tasks', 1)
            ->where('agent_work.tasks.0.id', $task->id)
            ->has('agent_work.approvals', 1)
            ->where('agent_work.approvals.0.id', $approval->id)
            ->where('agent_work.approvals.0.task_id', $task->id));
});
