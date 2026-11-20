<?php

use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\RunStatus;
use App\Enums\StepType;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Approval;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    [$this->a, $this->b] = Tenant::factory()->count(2)->create();

    [$this->owner, $this->member, $this->boss, $this->agent] = asTenant($this->a, function () {
        $boss = User::factory()->create(['name' => 'Chefe']);

        return [
            User::factory()->owner()->create(),
            User::factory()->create(),
            $boss,
            Agent::factory()->create(['name' => 'Triagem', 'reports_to_user_id' => $boss->id]),
        ];
    });

    [$this->agentB, $this->approvalB, $this->runB] = asTenant($this->b, function () {
        $agent = Agent::factory()->create();
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        return [$agent, Approval::factory()->create(['agent_id' => $agent->id, 'agent_run_id' => $run->id]), $run];
    });
});

it('lists and shows the tenant agents only', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->a, 'agents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Agents/Index')->has('agents', 1)->where('agents.0.name', 'Triagem'));

    $this->actingAs($this->member)->get(tenantUrl($this->a, "agents/{$this->agent->id}"))->assertOk();
    $this->actingAs($this->member)->get(tenantUrl($this->a, "agents/{$this->agentB->id}"))->assertNotFound();
    $this->actingAs($this->member)->get(tenantUrl($this->a, "settings/runs/{$this->runB->id}"))->assertNotFound();
    $this->actingAs($this->owner)->post(tenantUrl($this->a, "approvals/{$this->approvalB->id}/approve"))->assertNotFound();
});

it('queues a run for people who work with the agent, and refuses others', function () {
    Queue::fake();

    $this->actingAs($this->member)->post(tenantUrl($this->a, "agents/{$this->agent->id}/runs"), ['input' => 'Olá'])->assertForbidden();

    $this->actingAs($this->boss)->post(tenantUrl($this->a, "agents/{$this->agent->id}/runs"), ['input' => 'Resume os leads.'])
        ->assertRedirect();

    Queue::assertPushedOn('agents-high', RunAgent::class, fn (RunAgent $job) => $job->tenantId === $this->a->id);
    expect(asTenant($this->a, fn () => AgentRun::query()->sole()->requested_by_user_id))->toBe($this->boss->id);
});

it('lets owners suspend and reactivate, and nobody else', function () {
    $this->actingAs($this->boss)->put(tenantUrl($this->a, "agents/{$this->agent->id}/status"), ['status' => 'suspended'])->assertForbidden();

    $this->actingAs($this->owner)->put(tenantUrl($this->a, "agents/{$this->agent->id}/status"), ['status' => 'suspended', 'reason' => 'Revisão'])->assertRedirect();
    expect(asTenant($this->a, fn () => $this->agent->fresh()->status))->toBe(AgentStatus::Suspended);

    $this->actingAs($this->owner)->put(tenantUrl($this->a, "agents/{$this->agent->id}/status"), ['status' => 'active'])->assertRedirect();
    expect(asTenant($this->a, fn () => $this->agent->fresh()->status))->toBe(AgentStatus::Active);
});

it('gives people access to an agent, at one of two levels, only within the tenant', function () {
    $userB = asTenant($this->b, fn () => User::factory()->create());

    $this->actingAs($this->owner)->put(tenantUrl($this->a, "agents/{$this->agent->id}/assignees"), ['access' => [['user_id' => $userB->id, 'level' => 'chat']]])
        ->assertSessionHasErrors('access.0.user_id');

    $this->actingAs($this->owner)->put(tenantUrl($this->a, "agents/{$this->agent->id}/assignees"), ['access' => [['user_id' => $this->member->id, 'level' => 'chat']]])->assertRedirect();

    asTenant($this->a, function () {
        expect($this->agent->assignees()->pluck('users.id')->all())->toBe([$this->member->id])
            ->and($this->member->can('run', $this->agent))->toBeTrue()
            ->and($this->member->can('requestWork', $this->agent))->toBeFalse();
    });
});

it('lets the right people decide, and only once', function () {
    $approval = asTenant($this->a, fn () => Approval::factory()->create([
        'agent_id' => $this->agent->id,
        'agent_run_id' => AgentRun::factory()->create(['agent_id' => $this->agent->id])->id,
    ]));

    $this->actingAs($this->member)->post(tenantUrl($this->a, "approvals/{$approval->id}/reject"), ['note' => 'Não'])->assertForbidden();
    $this->actingAs($this->boss)->post(tenantUrl($this->a, "approvals/{$approval->id}/reject"))->assertSessionHasErrors('note');
    $this->actingAs($this->boss)->post(tenantUrl($this->a, "approvals/{$approval->id}/reject"), ['note' => 'Ainda não.'])->assertSessionHas('success');
    $this->actingAs($this->owner)->post(tenantUrl($this->a, "approvals/{$approval->id}/approve"))->assertSessionHas('error');

    expect(asTenant($this->a, fn () => $approval->fresh()->status))->toBe(ApprovalStatus::Rejected);
});

it('shows each person only the approvals they answer for', function () {
    asTenant($this->a, fn () => Approval::factory()->create([
        'agent_id' => $this->agent->id,
        'agent_run_id' => AgentRun::factory()->create(['agent_id' => $this->agent->id])->id,
    ]));

    $this->actingAs($this->member)->get(tenantUrl($this->a, 'approvals'))->assertInertia(fn (Assert $page) => $page->has('approvals.data', 0));
    $this->actingAs($this->boss)->get(tenantUrl($this->a, 'approvals'))->assertInertia(fn (Assert $page) => $page->has('approvals.data', 1)->where('approvals.data.0.can_decide', true));
    $this->actingAs($this->owner)->get(tenantUrl($this->a, 'tasks'))->assertInertia(fn (Assert $page) => $page->where('auth.pending_approvals', 1));
});

it('authorises live channels per tenant', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
    app('Illuminate\Broadcasting\BroadcastManager')->purge();
    require base_path('routes/channels.php');

    // A run's channel follows who may open the run (AgentRunPolicy, docs/DECISOES.md "Director de Recursos Humanos"):
    // the person who asked for it, or with «Ler as conversas de todos»; a run for nobody, only the latter.
    [$run, $nobodys] = asTenant($this->a, fn () => [
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'requested_by_user_id' => $this->member->id]),
        AgentRun::factory()->create(['agent_id' => $this->agent->id]),
    ]);
    $auth = fn (User $user, Tenant $tenant, string $channel) => $this->actingAs($user)->post(tenantUrl($tenant, 'broadcasting/auth'), ['socket_id' => '1.1', 'channel_name' => "private-{$channel}"]);

    $auth($this->member, $this->a, "tenant.{$this->a->id}.run.{$run->id}")->assertOk();
    $auth($this->member, $this->a, "tenant.{$this->a->id}.run.{$nobodys->id}")->assertForbidden();
    $auth($this->owner, $this->a, "tenant.{$this->a->id}.run.{$nobodys->id}")->assertOk();
    $auth($this->member, $this->a, "tenant.{$this->b->id}.run.{$this->runB->id}")->assertForbidden();
    $auth($this->member, $this->a, "tenant.{$this->a->id}.run.{$this->runB->id}")->assertForbidden();
    $auth($this->member, $this->a, "tenant.{$this->a->id}.approvals")->assertForbidden();
    $auth($this->owner, $this->a, "tenant.{$this->a->id}.approvals")->assertOk();
    $auth($this->member, $this->a, "tenant.{$this->a->id}.user.{$this->owner->id}")->assertForbidden();
});

it('shows on the agent page what it is doing now, with its conversation and step', function () {
    [$mine, $theirs] = asTenant($this->a, function () {
        $mine = Task::factory()->create(['title' => 'Cobrar a factura da Cimentos', 'user_id' => $this->boss->id, 'assignee_agent_id' => $this->agent->id]);
        $theirs = Task::factory()->chat()->create(['title' => 'Conversa do membro', 'user_id' => $this->member->id, 'chat_key' => $this->member->id.':'.$this->agent->id]);
        $running = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $mine->id, 'status' => RunStatus::Running, 'started_at' => now()]);
        AgentRunStep::factory()->create(['agent_run_id' => $running->id, 'seq' => 1, 'type' => StepType::Reasoning]);
        AgentRunStep::factory()->create(['agent_run_id' => $running->id, 'seq' => 2, 'type' => StepType::ToolCall, 'tool_name' => 'erp.list_invoices']);
        AgentRunStep::factory()->create(['agent_run_id' => $running->id, 'seq' => 3, 'type' => StepType::ToolResult, 'tool_name' => 'erp.list_invoices']);
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $theirs->id, 'status' => RunStatus::Queued]);

        return [$mine, $theirs];
    });

    $this->actingAs($this->boss)->get(tenantUrl($this->a, "agents/{$this->agent->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('now.active', 2)
            ->where('now.last', null)
            ->where('now.active.0.task.id', $mine->id)
            ->where('now.active.0.task.title', 'Cobrar a factura da Cimentos')
            ->where('now.active.0.step.type', 'tool_call')
            ->where('now.active.0.step.tool_name', 'erp.list_invoices')
            // Someone else's conversation is named, never shown.
            ->where('now.active.1.status', 'queued')
            ->where('now.active.1.task.visible', false)
            ->where('now.active.1.task.id', null)
            ->where('now.active.1.task.title', null));

    asTenant($this->a, fn () => AgentRun::query()->update(['status' => RunStatus::Completed, 'finished_at' => now()]));

    $this->actingAs($this->boss)->get(tenantUrl($this->a, "agents/{$this->agent->id}"))
        ->assertInertia(fn (Assert $page) => $page->has('now.active', 0)->where('now.last.task.id', null)->where('now.last.task.visible', false));
});

it('shows in the conversation what the agent is doing elsewhere, and not its own run there', function () {
    [$chat, $work] = asTenant($this->a, function () {
        $chat = Task::factory()->chat()->create(['user_id' => $this->boss->id, 'assignee_agent_id' => $this->agent->id, 'chat_key' => $this->boss->id.':'.$this->agent->id]);
        $work = Task::factory()->create(['title' => 'Seguir a proposta da Cimentos', 'user_id' => $this->boss->id, 'assignee_agent_id' => $this->agent->id]);
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $work->id, 'status' => RunStatus::Running, 'started_at' => now()]);

        return [$chat, $work];
    });

    $this->actingAs($this->boss)->get(tenantUrl($this->a, "tasks/{$chat->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('working', null)
            ->has('agent_elsewhere', 1)
            ->where('agent_elsewhere.0.task.id', $work->id)
            ->where('agent_elsewhere.0.task.title', 'Seguir a proposta da Cimentos')
            ->where('sidebar_agents.0.running', 1));

    // Its run in this very conversation is the "a trabalhar" bubble, not "elsewhere".
    asTenant($this->a, fn () => AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id, 'status' => RunStatus::Queued]));

    $this->actingAs($this->boss)->get(tenantUrl($this->a, "tasks/{$chat->id}"))
        ->assertInertia(fn (Assert $page) => $page->whereNot('working', null)->has('agent_elsewhere', 1));
});

it('keeps in the conversation, with its result, what the agent just finished elsewhere', function () {
    $chat = asTenant($this->a, function () {
        $chat = Task::factory()->chat()->create(['user_id' => $this->boss->id, 'assignee_agent_id' => $this->agent->id, 'chat_key' => $this->boss->id.':'.$this->agent->id]);
        // An email triaged outside any task, as the triage agent does.
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => null, 'status' => RunStatus::Completed, 'output' => ['text' => 'Triagem do email feita.'], 'started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinute()]);
        // Long done, and its own run in this conversation: neither belongs there.
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'status' => RunStatus::Completed, 'finished_at' => now()->subHours(2)]);
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id, 'status' => RunStatus::Completed, 'finished_at' => now()]);

        return $chat;
    });

    // A run for nobody (an email triaged outside any task) opens only with «Ler as conversas de todos»
    // (AgentRunPolicy, docs/DECISOES.md "Director de Recursos Humanos"): the boss sees it finished, not
    // what it wrote; the owner, in their own conversation, sees the result.
    $this->actingAs($this->boss)->get(tenantUrl($this->a, "tasks/{$chat->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->has('agent_elsewhere', 0)
            ->has('agent_elsewhere_done', 1)
            ->where('agent_elsewhere_done.0.status', 'completed')
            ->where('agent_elsewhere_done.0.output', null)
            ->where('agent_elsewhere_done.0.task', null));

    $ownersChat = asTenant($this->a, fn () => Task::factory()->chat()->create(['user_id' => $this->owner->id, 'assignee_agent_id' => $this->agent->id, 'chat_key' => $this->owner->id.':'.$this->agent->id]));
    $this->actingAs($this->owner)->get(tenantUrl($this->a, "tasks/{$ownersChat->id}"))
        ->assertInertia(fn (Assert $page) => expect(collect($page->toArray()['props']['agent_elsewhere_done'])->pluck('output'))->toContain('Triagem do email feita.'));
});

it('puts the agent\'s work elsewhere in its conversation, and opens its steps beside it', function () {
    [$chat, $routineRun, $private] = asTenant($this->a, function () {
        $chat = Task::factory()->chat()->create(['user_id' => $this->boss->id, 'assignee_agent_id' => $this->agent->id, 'chat_key' => $this->boss->id.':'.$this->agent->id, 'created_at' => now()->subDay()]);
        $routineRun = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => null, 'status' => RunStatus::Completed, 'output' => ['text' => 'Cobranças enviadas.'], 'finished_at' => now()]);
        // Someone else's conversation with the agent is theirs: not in the boss's thread.
        $other = Task::factory()->chat()->create(['user_id' => $this->member->id, 'assignee_agent_id' => $this->agent->id, 'chat_key' => $this->member->id.':'.$this->agent->id]);
        $private = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $other->id, 'status' => RunStatus::Completed]);
        // Before the conversation began.
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => null, 'created_at' => now()->subDays(2)]);

        return [$chat, $routineRun, $private];
    });

    // A run for nobody (a routine with no recipient) opens only with «Ler as conversas de todos»
    // (AgentRunPolicy, docs/DECISOES.md "Director de Recursos Humanos"): the boss sees the agent
    // worked, not what it wrote; the owner sees it all.
    $this->actingAs($this->boss)->get(tenantUrl($this->a, "tasks/{$chat->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->has('agent_background', 1)
            ->where('agent_background.0.id', $routineRun->id)
            ->where('agent_background.0.output', null));
    $this->actingAs($this->boss)->get(tenantUrl($this->a, "tasks/{$chat->id}?execucao={$routineRun->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('panel_run', null));

    $ownersChat = asTenant($this->a, fn () => Task::factory()->chat()->create(['user_id' => $this->owner->id, 'assignee_agent_id' => $this->agent->id, 'chat_key' => $this->owner->id.':'.$this->agent->id, 'created_at' => now()->subDay()]));
    $this->actingAs($this->owner)->get(tenantUrl($this->a, "tasks/{$ownersChat->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('agent_background.0.output', 'Cobranças enviadas.'));
    $this->actingAs($this->owner)->get(tenantUrl($this->a, "tasks/{$ownersChat->id}?execucao={$routineRun->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('panel_run.run.id', $routineRun->id));

    $this->actingAs($this->boss)->get(tenantUrl($this->a, "tasks/{$chat->id}?execucao={$private->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('panel_run', null));
});

it('stops showing a run that lost its worker as working, and closes it as failed', function () {
    $chat = asTenant($this->a, function () {
        $chat = Task::factory()->chat()->create(['user_id' => $this->boss->id, 'assignee_agent_id' => $this->agent->id, 'chat_key' => $this->boss->id.':'.$this->agent->id]);
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id, 'status' => RunStatus::Running, 'started_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)]);
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'status' => RunStatus::Queued, 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)]);

        return $chat;
    });

    $this->actingAs($this->boss)->get(tenantUrl($this->a, "tasks/{$chat->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('working', null)->has('agent_elsewhere', 0)->where('sidebar_agents.0.running', 0));

    $this->artisan('agents:close-stale-runs')->assertSuccessful();

    asTenant($this->a, function () use ($chat) {
        expect(AgentRun::query()->pluck('status')->all())->each->toBe(RunStatus::Failed);
        expect($chat->messages()->latest('id')->first()->body)->toContain('parou a meio sem terminar');
    });
});

it('closes the run when its job fails, instead of leaving it running', function () {
    $run = asTenant($this->a, fn () => AgentRun::factory()->create(['agent_id' => $this->agent->id, 'status' => RunStatus::Running, 'started_at' => now()]));

    (new RunAgent($this->a->id, $run->id))->failed(new RuntimeException('killed'));

    asTenant($this->a, fn () => expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe('A execução foi interrompida antes de terminar.'));
});

it('shows on the home screen who is working now and what finished today', function () {
    asTenant($this->a, function () {
        $work = Task::factory()->create(['title' => 'Seguir a proposta da Cimentos', 'user_id' => $this->boss->id, 'assignee_agent_id' => $this->agent->id]);
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $work->id, 'status' => RunStatus::Running, 'started_at' => now()]);
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => null, 'status' => RunStatus::Completed, 'finished_at' => now()->subHour()]);
        // A conversation's answer is in the conversation; yesterday's work is not "today".
        $chat = Task::factory()->chat()->create(['user_id' => $this->boss->id, 'assignee_agent_id' => $this->agent->id, 'chat_key' => $this->boss->id.':'.$this->agent->id]);
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id, 'status' => RunStatus::Completed, 'finished_at' => now()]);
        AgentRun::factory()->create(['agent_id' => $this->agent->id, 'status' => RunStatus::Completed, 'finished_at' => now()->subDays(2)]);
    });

    $this->actingAs($this->boss)->get(tenantUrl($this->a, '/'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('agents_now.active', 1)
            ->where('agents_now.active.0.task.title', 'Seguir a proposta da Cimentos')
            ->has('agents_now.done', 1));
});
