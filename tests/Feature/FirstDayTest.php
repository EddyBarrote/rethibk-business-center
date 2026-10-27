<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Primeiro dia".

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();
    [$this->owner, $this->chief] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create(['name' => 'Kleyton Macatange', 'job_title' => 'Director Técnico', 'welcomed_at' => null]);

        return [$owner, Agent::factory()->create(['key' => 'chief_of_staff', 'name' => 'Chief of Staff', 'reports_to_user_id' => $owner->id])];
    });
});

it('has the Chief of Staff speak first on the person\'s first day, once', function () {
    $response = $this->actingAs($this->owner)->get(tenantUrl($this->tenant));

    asTenant($this->tenant, function () use ($response) {
        $chat = Task::query()->where('assignee_agent_id', $this->chief->id)->where('user_id', $this->owner->id)->sole();
        $response->assertRedirect("/tasks/{$chat->id}");

        $run = AgentRun::query()->where('task_id', $chat->id)->sole();
        expect($run->input)->toContain('É o primeiro dia de Kleyton Macatange')
            ->and($run->input)->toContain('Cargo: Director Técnico')
            ->and($run->input)->toContain('«Está certo» e «Há algo errado»')
            ->and($chat->messages()->sole()->body)->toBe('Primeiro dia de Kleyton Macatange na plataforma.')
            ->and($this->owner->fresh()->welcomed_at)->not->toBeNull();
    });

    $this->actingAs($this->owner->fresh())->get(tenantUrl($this->tenant))->assertInertia(fn (Assert $page) => $page->component('Home'));
    expect(asTenant($this->tenant, fn () => AgentRun::query()->count()))->toBe(1);
});

it('does not welcome someone who already talks to the agents', function () {
    asTenant($this->tenant, function () {
        $chat = app(TaskThread::class)->conversation($this->owner, $this->chief);
        $chat->messages()->create(['author_type' => 'user', 'author_user_id' => $this->owner->id, 'kind' => 'message', 'body' => 'Olá']);
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))->assertInertia(fn (Assert $page) => $page->component('Home'));
    expect($this->owner->fresh()->welcomed_at)->not->toBeNull();
});
