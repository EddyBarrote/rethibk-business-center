<?php

use App\Ai\Initiatives\InitiativeWriter;
use App\Enums\EmailCategory;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentInitiative;
use App\Models\AiCharge;
use App\Models\EmailMessage;
use App\Models\EmailRoute;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Iniciativas dos agentes".

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->other, $this->sales, $this->email, $this->stalled] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create();
        $sales = Agent::factory()->create(['name' => 'Gestor de Clientes', 'reports_to_user_id' => $owner->id]);
        EmailRoute::factory()->create(['category' => EmailCategory::ClientRfq, 'agent_id' => $sales->id]);

        return [
            $owner,
            User::factory()->create(),
            $sales,
            EmailMessage::factory()->create(['classification' => EmailCategory::ClientRfq, 'sent_to_triage_at' => now(), 'received_at' => now()->subHours(2), 'from_name' => 'Carmélia Buque', 'subject' => 'Cotação de credenciação']),
            Task::factory()->create(['title' => 'Seguimento da proposta à AZUL', 'assignee_agent_id' => $sales->id, 'status' => TaskStatus::Blocked]),
        ];
    });

    InitiativeWriter::fake([['initiatives' => [
        ['title' => 'Preparar a cotação de credenciação', 'why' => 'A Carmélia pediu ontem e ainda não tem resposta.', 'plan' => 'Vejo os preços e preparo a proposta para rever.', 'evidence' => ["email:{$this->email->id}"]],
        ['title' => 'Responder ao banco', 'why' => 'Inventado.', 'plan' => 'x', 'evidence' => ['email:999999']],
        ['title' => 'Destravar o seguimento da AZUL', 'why' => 'Está bloqueado.', 'plan' => 'Escrevo o seguimento para rever.', 'evidence' => ["task:{$this->stalled->id}"]],
    ]]]);
});

it('offers work twice a day from what came in and what stalled, never without evidence, charged to the budget', function () {
    $this->artisan('agents:initiatives')->assertSuccessful();

    asTenant($this->tenant, function () {
        $offers = AgentInitiative::query()->orderBy('id')->get();

        expect($offers->pluck('title')->all())->toBe(['Preparar a cotação de credenciação', 'Destravar o seguimento da AZUL'])
            ->and($offers->first()->user_id)->toBe($this->owner->id)
            ->and($offers->first()->evidence)->toBe([['ref' => "email:{$this->email->id}", 'label' => 'Email de Carmélia Buque: «Cotação de credenciação»', 'href' => "/inbox/{$this->email->id}"]])
            ->and(AiCharge::query()->where('purpose', 'agents.heartbeat')->exists())->toBeTrue();
    });

    // The same evidence is not offered twice.
    $this->artisan('agents:initiatives')->assertSuccessful();
    expect(asTenant($this->tenant, fn () => AgentInitiative::query()->count()))->toBe(2);
});

it('starts the work with «Começar» and drops it with «Esconder», only for the person it was offered to', function () {
    $this->artisan('agents:initiatives');
    [$quote, $nudge] = asTenant($this->tenant, fn () => AgentInitiative::query()->orderBy('id')->get()->all());

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))
        ->assertInertia(fn (Assert $page) => $page->has('initiatives', 2)->where('initiatives.1.title', 'Preparar a cotação de credenciação'));

    $this->actingAs($this->other)->post(tenantUrl($this->tenant, "initiatives/{$quote->id}/start"))->assertForbidden();

    $response = $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "initiatives/{$quote->id}/start"));

    asTenant($this->tenant, function () use ($quote, $response) {
        $task = Task::query()->where('title', 'Preparar a cotação de credenciação')->sole();
        $response->assertRedirect("/tasks/{$task->id}");

        expect($task->assignee_agent_id)->toBe($this->sales->id)
            ->and($task->user_id)->toBe($this->owner->id)
            ->and($task->source_id)->toBe($this->email->id)
            ->and($task->messages()->orderBy('id')->pluck('body')->last())->toBe('Vou tratar disso.')
            ->and($quote->fresh()->status)->toBe(AgentInitiative::STARTED)
            ->and($quote->fresh()->task_id)->toBe($task->id);
    });

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "initiatives/{$nudge->id}/hide"))->assertRedirect();
    expect(asTenant($this->tenant, fn () => $nudge->fresh()->status))->toBe(AgentInitiative::HIDDEN);

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant))->assertInertia(fn (Assert $page) => $page->has('initiatives', 0));
});
