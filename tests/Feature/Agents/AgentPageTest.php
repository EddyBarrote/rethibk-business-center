<?php

use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Models\Agent;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// The agent's page (docs/DECISOES.md, 09.10.2026): one conversation, the person's tasks inside it.

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->ana, $this->joao, $this->agent] = asTenant($this->tenant, function () {
        $ana = User::factory()->create(['name' => 'Ana']);

        return [$ana, User::factory()->create(['name' => 'João']), Agent::factory()->create(['name' => 'Finanças', 'reports_to_user_id' => $ana->id])];
    });
});

it('sends a direct action from the agent\'s page into the person\'s one conversation', function () {
    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"), ['message' => 'Resume os leads.', 'mode' => 'action'])->assertRedirect();
    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"), ['message' => 'Obrigada.'])->assertRedirect();

    asTenant($this->tenant, function () {
        $chat = Task::query()->sole();

        expect($chat->kind)->toBe(TaskKind::Chat)
            ->and($chat->messages()->orderBy('id')->pluck('kind')->all())->toBe([TaskMessageKind::Action, TaskMessageKind::Message]);
    });
});

it('refuses a direct action to someone who may only talk to the agent', function () {
    asTenant($this->tenant, fn () => $this->agent->assignees()->attach($this->joao->id, ['role' => 'chat']));

    $this->actingAs($this->joao)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"), ['message' => 'Paga a factura.', 'mode' => 'action'])->assertForbidden();
    $this->actingAs($this->joao)->post(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"), ['message' => 'Olá'])->assertRedirect();
});

it('lists only the person\'s own tasks with the agent in its Tasks tab', function () {
    asTenant($this->tenant, function () {
        Task::factory()->create(['title' => 'Da Ana', 'assignee_agent_id' => $this->agent->id, 'user_id' => $this->ana->id]);
        Task::factory()->create(['title' => 'Pedida pelo agente', 'created_by_agent_id' => $this->agent->id, 'assignee_user_id' => $this->ana->id]);
        Task::factory()->create(['title' => 'Do João', 'assignee_agent_id' => $this->agent->id, 'user_id' => $this->joao->id]);
        Task::factory()->create(['title' => 'Conversa', 'kind' => TaskKind::Chat, 'assignee_agent_id' => $this->agent->id, 'user_id' => $this->ana->id]);
    });

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 2)
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('title')->sort()->values()->all() === ['Da Ana', 'Pedida pelo agente']));
});
