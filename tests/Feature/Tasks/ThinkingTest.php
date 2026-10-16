<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\ToolActivity;
use App\Enums\StepType;
use App\Events\TaskReplyStreaming;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->boss, $this->agent] = asTenant($this->tenant, fn () => [
        $boss = User::factory()->create(['name' => 'Chefe']),
        Agent::factory()->create(['name' => 'Gestor de Clientes', 'reports_to_user_id' => $boss->id]),
    ]);
});

it('asks Gemini for a summary of its reasoning', function () {
    asTenant($this->tenant, function () {
        $generic = new GenericAgent($this->agent, AgentRun::factory()->create(['agent_id' => $this->agent->id]), '', []);

        expect($generic->providerOptions(Lab::Gemini))->toBe(['thinking_summaries' => 'auto'])
            ->and($generic->providerOptions('anthropic'))->toBe([]);

        config(['agents.thought_summaries' => false]);
        expect($generic->providerOptions(Lab::Gemini))->toBe([]);
    });
});

it('streams the reasoning while the agent thinks and keeps it on the run', function () {
    Event::fake([TaskReplyStreaming::class]);
    GenericAgent::fake([AgentResponse::fakeWithReasoning('Primeiro vejo as facturas em aberto.', 'Não há facturas em atraso.')]);

    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->boss, $this->agent);
        $threads->post($chat, $this->boss, 'Há facturas em atraso?');

        $run = app(AgentRunner::class)->run(AgentRun::query()->sole());

        Event::assertDispatched(TaskReplyStreaming::class, fn (TaskReplyStreaming $event) => $event->thinking && $event->text === '');
        Event::assertDispatched(TaskReplyStreaming::class, fn (TaskReplyStreaming $event) => str_contains($event->reasoning, 'facturas em aberto') && $event->text === '');
        Event::assertDispatched(TaskReplyStreaming::class, fn (TaskReplyStreaming $event) => ! $event->thinking
            && str_contains($event->text, 'Não há facturas')
            && $event->broadcastWith()['reasoning'] === 'Primeiro vejo as facturas em aberto.');

        $step = AgentRunStep::query()->where('agent_run_id', $run->id)->where('type', StepType::Reasoning)->sole();
        expect($step->payload['text'])->toBe('Primeiro vejo as facturas em aberto.')
            ->and($step->duration_ms)->toBeInt();
    });
});

it('shows what each turn thought in the conversation', function () {
    $chat = asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->boss, $this->agent);
        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id]);
        AgentRunStep::query()->create(['agent_run_id' => $run->id, 'seq' => 1, 'type' => StepType::Reasoning, 'payload' => ['text' => 'Ver o cliente.'], 'duration_ms' => 4000]);
        AgentRunStep::query()->create(['agent_run_id' => $run->id, 'seq' => 2, 'type' => StepType::Reasoning, 'payload' => ['text' => 'Depois a proposta.'], 'duration_ms' => 8000]);

        return $chat;
    });

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('thinking', fn ($thinking) => collect($thinking)->first() == ['text' => "Ver o cliente.\n\nDepois a proposta.", 'ms' => 12000]));
});

it('says what the agent is doing in plain Portuguese', function (string $label, string $phrase) {
    expect(ToolActivity::phrase($label))->toBe($phrase);
})->with([
    ['Pesquisar na internet', 'A pesquisar na internet'],
    ['Ler página web', 'A ler página web'],
    ['Redigir resposta', 'A redigir resposta'],
    ['Contratos', 'A consultar contratos'],
    ['Ficha de cliente', 'A consultar ficha de cliente'],
    ['', 'A usar uma ferramenta'],
]);
