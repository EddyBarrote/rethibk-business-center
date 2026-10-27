<?php

use App\Enums\ProjectStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Canais por projecto".

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();
    [$this->owner, $this->member, $this->agent] = asTenant($this->tenant, fn () => [
        User::factory()->owner()->create(),
        User::factory()->create(),
        Agent::factory()->create(['name' => 'Gestor de Clientes']),
    ]);
});

it('gives a project an icon and a colour that its tasks wear', function () {
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'projects'), [
        'name' => 'Festival da Reactive', 'description' => 'Bilheteira e cashless do festival.', 'status' => 'active', 'color' => 'purple', 'icon' => 'ticket',
    ])->assertRedirect();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'projects'), ['name' => 'X', 'status' => 'active', 'color' => 'gold'])->assertSessionHasErrors('color');

    $project = asTenant($this->tenant, fn () => Project::query()->sole());
    expect([$project->color, $project->icon])->toBe(['purple', 'ticket']);

    asTenant($this->tenant, fn () => Task::factory()->create(['title' => 'Montar as bilheteiras', 'project_id' => $project->id, 'user_id' => $this->owner->id]));
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks?modo=lista'))
        ->assertInertia(fn (Assert $page) => $page->where('tasks.data.0.project.color', 'purple'));
});

it('opens the channel once, where the project\'s agent introduces itself and suggests where to start', function () {
    $project = asTenant($this->tenant, fn () => Project::factory()->create(['name' => 'Festival da Reactive', 'description' => 'Bilheteira e cashless.', 'status' => ProjectStatus::Active, 'lead_agent_id' => $this->agent->id]));

    $this->actingAs($this->member)->post(tenantUrl($this->tenant, "projects/{$project->id}/channel"))->assertForbidden();

    $response = $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "projects/{$project->id}/channel"));

    asTenant($this->tenant, function () use ($project, $response) {
        $channel = Task::query()->where('title', 'Canal: Festival da Reactive')->sole();
        $response->assertRedirect("/tasks/{$channel->id}");

        expect($project->fresh()->channel_task_id)->toBe($channel->id)
            ->and($channel->assignee_agent_id)->toBe($this->agent->id)
            ->and($channel->project_id)->toBe($project->id)
            ->and($channel->description)->toContain('sugere três coisas concretas para começar')
            ->and(AgentRun::query()->where('task_id', $channel->id)->count())->toBe(1);
    });

    // A second time it opens the same channel.
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, "projects/{$project->id}/channel"))->assertRedirect();
    expect(asTenant($this->tenant, fn () => Task::query()->where('project_id', $project->id)->count()))->toBe(1);
});
