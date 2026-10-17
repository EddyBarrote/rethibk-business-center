<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Enums\ApprovalStatus;
use App\Enums\QuestionStatus;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// «Fechar tarefa» and «Reabrir» on the task's page (docs/DECISOES.md, "Fechar uma tarefa").

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->boss, $this->outsider, $this->agent] = asTenant($this->tenant, function () {
        $boss = User::factory()->create(['name' => 'Chefe', 'role' => 'manager']);

        return [
            $boss,
            User::factory()->create(['name' => 'Outra pessoa']),
            Agent::factory()->create(['name' => 'Gestor de Clientes', 'reports_to_user_id' => $boss->id]),
        ];
    });
});

it('closes a task with who and when, stops the agent work waiting on it, and reopens it', function () {
    [$task, $waiting, $queued, $question, $approval] = asTenant($this->tenant, function () {
        $task = Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'user_id' => $this->boss->id, 'status' => TaskStatus::WaitingHuman]);
        $waiting = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $task->id, 'status' => RunStatus::AwaitingInput]);
        $queued = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $task->id, 'status' => RunStatus::Queued]);
        $question = AgentQuestion::factory()->create(['agent_id' => $this->agent->id, 'agent_run_id' => $waiting->id, 'task_id' => $task->id, 'asked_user_id' => $this->boss->id]);
        $approval = Approval::factory()->create(['agent_run_id' => $queued->id, 'status' => ApprovalStatus::Pending]);

        return [$task, $waiting, $queued, $question, $approval];
    });

    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "tasks/{$task->id}/close"))->assertRedirect();

    asTenant($this->tenant, function () use ($task, $waiting, $queued, $question, $approval) {
        $task->refresh();

        expect($task->status)->toBe(TaskStatus::Done)
            ->and($task->closed_by_user_id)->toBe($this->boss->id)
            ->and($task->completed_at)->not->toBeNull()
            ->and($waiting->fresh()->status)->toBe(RunStatus::Cancelled)
            ->and($queued->fresh()->status)->toBe(RunStatus::Cancelled)
            ->and($question->fresh()->status)->toBe(QuestionStatus::Cancelled)
            ->and($approval->fresh()->status)->toBe(ApprovalStatus::Expired)
            ->and($task->messages()->pluck('body')->implode("\n"))->toContain('Chefe mudou o estado')->toContain('Tarefa fechada.')->toContain('parou');
    });

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "tasks/{$task->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('task.status', 'done')->where('task.closed_by', 'Chefe'));

    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "tasks/{$task->id}/reopen"))->assertRedirect();

    asTenant($this->tenant, function () use ($task) {
        $task->refresh();

        expect($task->status)->toBe(TaskStatus::InProgress)
            ->and($task->closed_by_user_id)->toBeNull()
            ->and($task->completed_at)->toBeNull()
            ->and($task->messages()->pluck('body')->last())->toContain('Tarefa reaberta.');
    });
});

it('does not let an agent reopen a task a person closed', function () {
    $task = asTenant($this->tenant, fn () => Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'user_id' => $this->boss->id]));

    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "tasks/{$task->id}/close"))->assertRedirect();

    asTenant($this->tenant, function () use ($task) {
        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $task->id]);
        $result = app(CapabilityRegistry::class)->find('tasks.update_status')
            ->execute(['status' => 'in_progress'], new CapabilityContext($this->agent, $run));

        expect($result->ok)->toBeFalse()->and($task->fresh()->status)->toBe(TaskStatus::Done);
    });
});

it('only lets people who may change the task close it', function () {
    $task = asTenant($this->tenant, fn () => Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'user_id' => $this->boss->id]));

    $this->actingAs($this->outsider)->post(tenantUrl($this->tenant, "tasks/{$task->id}/close"))->assertForbidden();

    expect(asTenant($this->tenant, fn () => $task->fresh()->status))->not->toBe(TaskStatus::Done);
});
