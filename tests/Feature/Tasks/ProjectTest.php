<?php

use App\Enums\TaskStatus;
use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Goal → project → tasks (docs/DECISOES.md, realinhamento L12).

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    [$this->manager, $this->member, $this->goal] = asTenant($this->tenant, fn () => [
        User::factory()->create(['role' => 'manager']),
        User::factory()->create(['role' => 'member']),
        Goal::factory()->create(['title' => 'Entregar a ala norte']),
    ]);
});

it('lets managers create projects under a goal and shows their progress to everyone', function () {
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'projects'), ['name' => 'Obra', 'status' => 'active'])->assertForbidden();

    $this->actingAs($this->manager)->post(tenantUrl($this->tenant, 'projects'), [
        'name' => 'Obra da ala norte', 'status' => 'active', 'goal_id' => $this->goal->id, 'lead_user_id' => $this->manager->id,
    ])->assertRedirect();

    $project = asTenant($this->tenant, function () {
        $project = Project::query()->sole();
        Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Done]);
        Task::factory()->create(['project_id' => $project->id, 'user_id' => $this->manager->id]);

        return $project;
    });

    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'projects'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Projects/Index')
            ->where('projects.0.goal.title', 'Entregar a ala norte')
            ->where('projects.0.lead', $this->manager->name)
            ->where('projects.0.tasks_done', 1)
            ->where('projects.0.tasks_total', 2)
            ->where('can_manage', false));

    $this->actingAs($this->manager)->get(tenantUrl($this->tenant, "tasks?view=all&project={$project->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 1));
});

it('puts a task in a project and takes the goal from it', function () {
    $project = asTenant($this->tenant, fn () => Project::factory()->create(['goal_id' => $this->goal->id]));

    $this->actingAs($this->manager)->post(tenantUrl($this->tenant, 'tasks'), [
        'kind' => 'task', 'title' => 'Encomendar varão', 'project_id' => $project->id,
    ])->assertRedirect();

    asTenant($this->tenant, fn () => expect(Task::query()->sole())
        ->project_id->toBe($project->id)
        ->goal_id->toBe($this->goal->id));
});
