<?php

use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentAssignment;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Department;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// "A minha caixa" (docs/DECISOES.md, realinhamento L2).

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();

    [$this->ana, $this->rui, $this->agent] = asTenant($this->tenant, function () {
        $finance = Department::factory()->create(['name' => 'Finanças']);
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'manager', 'department_id' => $finance->id]);
        $rui = User::factory()->create(['name' => 'Rui', 'department_id' => $finance->id]);
        User::factory()->create(['name' => 'Outra área']);
        $agent = Agent::factory()->create(['name' => 'Agente de Finanças', 'reports_to_user_id' => $ana->id]);

        return [$ana, $rui, $agent];
    });
});

it('puts what needs me first, then my work, with my colleagues beside it', function () {
    [$waiting, $review, $mine, $approval] = asTenant($this->tenant, function () {
        $waiting = Task::factory()->create(['title' => 'Qual é o NUIT?', 'status' => TaskStatus::WaitingHuman, 'user_id' => $this->ana->id, 'assignee_agent_id' => $this->agent->id]);
        $review = Task::factory()->create(['title' => 'Fecho de Setembro', 'status' => TaskStatus::InReview, 'created_by_user_id' => $this->ana->id, 'assignee_agent_id' => $this->agent->id]);
        $mine = Task::factory()->create(['title' => 'Factura FT 877', 'status' => TaskStatus::InProgress, 'user_id' => $this->ana->id, 'assignee_agent_id' => $this->agent->id]);
        Task::factory()->create(['title' => 'De outra pessoa', 'user_id' => $this->rui->id]);
        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $mine->id]);
        $approval = Approval::factory()->create(['agent_run_id' => $run->id, 'agent_id' => $this->agent->id]);

        return [$waiting, $review, $mine, $approval];
    });

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')
            ->where('waiting.0.id', $waiting->id)
            ->where('review.0.id', $review->id)
            ->where('approvals.0.id', $approval->id)
            ->where('approvals.0.task_id', $mine->id)
            ->where('work', fn ($rows) => collect($rows)->pluck('id')->all() === [$mine->id])
            ->where('colleagues.people', fn ($rows) => collect($rows)->pluck('name')->all() === ['Rui'])
            ->where('colleagues.agents.0.name', 'Agente de Finanças'));
});

it('shows a person only the agents they can talk to', function () {
    $this->actingAs($this->rui)->get(tenantUrl($this->tenant))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('colleagues.agents', []));

    asTenant($this->tenant, fn () => AgentAssignment::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->rui->id]));

    $this->actingAs($this->rui)->get(tenantUrl($this->tenant))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('colleagues.agents.0.id', $this->agent->id));
});
