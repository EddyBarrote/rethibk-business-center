<?php

use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\Capability;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Ecrã de entrada e modo lite".

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->clerk, $this->finance, $this->triage] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create(['name' => 'Dona Amélia']);
        $clerk = User::factory()->create(['name' => 'Rui']);
        $finance = Agent::factory()->create(['name' => 'Agente de Finanças']);
        $triage = Agent::factory()->create(['name' => 'Agente de Triagem']);
        $finance->assignees()->attach($clerk->id, ['role' => 'chat']);

        return [$owner, $clerk, $finance, $triage];
    });
});

it('opens the entry screen with the agents the person can talk to, the latest conversation chosen', function () {
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->has('agents', 2)
            ->where('agent_id', $this->finance->id));

    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $threads->post($threads->conversation($this->owner, $this->triage), $this->owner, 'Olá');
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page->where('agent_id', $this->triage->id));

    // Only the agents they may talk to.
    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page->has('agents', 1)->where('agents.0.id', $this->finance->id));

    // Nobody to talk to: the tasks.
    $lonely = asTenant($this->tenant, fn () => User::factory()->create());
    $this->actingAs($lonely)->get(tenantUrl($this->tenant))->assertRedirect(tenantUrl($this->tenant, 'tasks'));
});

it('suggests what the capabilities make possible until someone writes the suggestions', function () {
    asTenant($this->tenant, function () {
        $capability = Capability::factory()->create(['key' => 'finance.month_summary']);
        $this->finance->capabilities()->attach($capability->id, ['enabled' => true]);
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page
            ->where('agents.0.suggestions', ['Resumo financeiro deste mês', 'O que podes fazer por mim?', 'Resume o que fizeste esta semana']));

    $this->finance->forceFill(['suggestions' => ['Quanto facturámos ontem?', '  ']])->save();

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page->where('agents.0.suggestions', ['Quanto facturámos ontem?']));
});

it('saves the suggestions written on the agent, empty ones left out', function () {
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "agents/{$this->finance->id}/edit"))
        ->assertInertia(fn (Assert $page) => $page->where('agent.suggestions', [])->has('agent.suggested'));

    $data = [
        ...$this->finance->only(['key', 'name']),
        'status' => $this->finance->status->value,
        'autonomy_level' => $this->finance->autonomy_level->value,
        'suggestions' => ['Fluxo de caixa', '', null, 'Faturas em atraso'],
    ];

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "agents/{$this->finance->id}"), $data)->assertSessionHasNoErrors();
    expect($this->finance->fresh()->suggestions)->toBe(['Fluxo de caixa', 'Faturas em atraso']);

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "agents/{$this->finance->id}"), [...$data, 'suggestions' => ['', '']]);
    expect($this->finance->fresh()->suggestions)->toBeNull();
});

it('shows the agents waiting on an answer from the person', function () {
    $chat = asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->owner, $this->finance);
        AgentQuestion::factory()->create(['agent_id' => $this->finance->id, 'task_id' => $chat->id, 'asked_user_id' => $this->owner->id]);

        return $chat;
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page
            ->has('questions', 1)
            ->where('questions.0.agent', 'Agente de Finanças')
            ->where('questions.0.href', "/tasks/{$chat->id}"));
});

it('starts in lite for whoever does not administer the company, and remembers the choice', function () {
    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.lite', true));
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.lite', false));

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, 'settings/profile/mode'), ['mode' => 'lite'])->assertRedirect();
    expect($this->owner->fresh()->ui_mode)->toBe('lite');
    $this->actingAs($this->owner->fresh())->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.lite', true));

    $this->actingAs($this->clerk)->put(tenantUrl($this->tenant, 'settings/profile/mode'), ['mode' => 'full']);
    expect($this->clerk->fresh()->prefersLite())->toBeFalse();

    $this->actingAs($this->clerk)->put(tenantUrl($this->tenant, 'settings/profile/mode'), ['mode' => 'admin'])->assertSessionHasErrors('mode');
});
