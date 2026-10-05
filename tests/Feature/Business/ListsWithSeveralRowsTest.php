<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Briefing;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\GeneratedDocument;
use App\Models\Report;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

// Lazy loading only throws when a model came out of a query with more than one
// row, so a screen tested with a single row can still break on real data. These
// render every list and detail page with several rows in it.

beforeEach(function () {
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        templateAgent('triage');
        templateAgent('chief_of_staff');
        Briefing::factory()->count(2)->create(['for_user_id' => $this->owner->id]);
        Report::factory()->count(2)->create(['reviewed_by_user_id' => $this->owner->id, 'reviewed_at' => now()]);
        $thread = EmailThread::factory()->create();
        EmailMessage::factory()->count(2)->create(['thread_id' => $thread->id]);
        AgentRun::factory()->count(2)->create();
        Approval::factory()->count(2)->create();
        GeneratedDocument::factory()->count(2)->create();
    });
});

afterEach(fn () => @unlink($this->store));

it('renders lists and details with several rows', function (string $path) {
    $owner = asTenant($this->tenant, fn () => $this->owner);
    $path = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) asTenant($this->tenant, fn () => lastId('App\\Models\\'.$m[1])), $path);

    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, $path))->assertOk();
})->with([
    '/',
    'runs',
    'approvals',
    'inbox',
    'inbox/{EmailMessage}',
    'briefings',
    'reports',
    'reports/{Report}',
    'documents',
    'notifications',
    'tasks',
]);

it('opens a notification at its screen, or on the list when that screen no longer exists', function () {
    $owner = asTenant($this->tenant, fn () => $this->owner);
    $owner->notifications()->create(['id' => (string) str()->uuid(), 'type' => 'test', 'data' => ['title' => 'Antiga', 'body' => '', 'url' => '/contracts/1']]);
    $owner->notifications()->create(['id' => (string) str()->uuid(), 'type' => 'test', 'data' => ['title' => 'Actual', 'body' => '', 'url' => '/approvals']]);
    [$old, $current] = [$owner->notifications()->where('data->title', 'Antiga')->value('id'), $owner->notifications()->where('data->title', 'Actual')->value('id')];

    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, "notifications/{$old}"))->assertRedirect('/notifications');
    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, "notifications/{$current}"))->assertRedirect('/approvals');
});

it('keeps chat turns out of the work list and groups them under their conversation', function () {
    $owner = asTenant($this->tenant, fn () => $this->owner);
    [$chat, $task] = asTenant($this->tenant, function () use ($owner) {
        $agent = Agent::query()->first();
        $chat = Task::factory()->create(['kind' => 'chat', 'assignee_agent_id' => $agent->id, 'user_id' => $owner->id, 'title' => 'Hello']);
        $task = Task::factory()->create(['kind' => 'task', 'assignee_agent_id' => $agent->id]);
        AgentRun::factory()->count(3)->create(['agent_id' => $agent->id, 'task_id' => $chat->id]);
        AgentRun::factory()->create(['agent_id' => $agent->id, 'task_id' => $task->id]);

        return [$chat, $task];
    });
    $work = asTenant($this->tenant, fn () => AgentRun::query()->count()) - 3;

    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, 'runs'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('runs.total', $work)->where('conversations', null));

    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, 'runs?origin=conversa'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 1)->where('conversations.data.0.turns', 3)->where('conversations.data.0.task_id', $chat->id));

    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, 'runs?origin=tarefa'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('runs.total', 1));
});

it('counts the task tabs with the same rule as the list, leaving out conversations nobody wrote in', function () {
    $owner = asTenant($this->tenant, fn () => $this->owner);
    asTenant($this->tenant, function () use ($owner) {
        $agent = Agent::query()->first();
        Task::factory()->create(['kind' => 'chat', 'assignee_agent_id' => $agent->id, 'user_id' => $owner->id, 'status' => 'in_progress']);
        Task::factory()->create(['kind' => 'task', 'assignee_agent_id' => $agent->id, 'user_id' => $owner->id, 'status' => 'in_progress']);
    });

    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, 'tasks'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.mine', fn ($count) => $count === count($page->toArray()['props']['tasks'])));
});
