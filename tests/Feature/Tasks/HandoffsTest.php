<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Enums\ActorType;
use App\Enums\RunStatus;
use App\Enums\TaskMessageKind;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Agents message each other (docs/DECISOES.md, "Mensagens entre agentes").

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->edy, $this->chief, $this->sales, $this->finance] = asTenant($this->tenant, function () {
        $edy = User::factory()->create(['name' => 'Edy']);
        $chief = Agent::factory()->create(['key' => 'chief_of_staff', 'name' => 'Chief of Staff', 'reports_to_user_id' => $edy->id]);

        return [
            $edy,
            $chief,
            Agent::factory()->create(['key' => 'gestor_comercial', 'name' => 'Gestor Comercial', 'reports_to_user_id' => $edy->id, 'reports_to_agent_id' => $chief->id]),
            Agent::factory()->create(['key' => 'finance', 'name' => 'Finanças', 'reports_to_user_id' => $edy->id, 'reports_to_agent_id' => $chief->id]),
        ];
    });
});

/** The run that answers the latest message of a conversation, taken as running. */
function runningIn(Task $task): AgentRun
{
    $run = AgentRun::query()->where('task_id', $task->id)->latest('id')->firstOrFail();
    $run->forceFill(['status' => RunStatus::Running, 'started_at' => now()->subSecond()])->save();

    return $run->fresh();
}

function sendTo(AgentRun $run, string $agent, string $message): mixed
{
    return app(CapabilityRegistry::class)->find('agents.message')->execute(['agent' => $agent, 'message' => $message], new CapabilityContext($run->agent, $run));
}

function finish(AgentRun $run, string $text, RunStatus $status = RunStatus::Completed): void
{
    $run->forceFill(['status' => $status, 'output' => ['text' => $text], 'error' => $status === RunStatus::Failed ? 'O modelo não respondeu.' : null, 'finished_at' => now()])->save();
    app(TaskThread::class)->recordReply($run->fresh());
}

it('passes a request to a colleague, who works on it in the person\'s conversation with it, and brings the answer back', function () {
    [$cosChat, $salesChat, $handoff] = asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $cosChat = $threads->conversation($this->edy, $this->chief);
        $threads->post($cosChat, $this->edy, 'Caça leads agora');
        $cosRun = runningIn($cosChat);

        $result = sendTo($cosRun, 'gestor_comercial', 'Procura leads novas para a Rethink. Exclui a Wedly e os clientes actuais. Não contactes ninguém.');
        $handoff = AgentHandoff::query()->sole();
        $salesChat = Task::query()->where('chat_key', TaskThread::chatKey($this->edy, $this->sales))->sole();

        expect($result->ok)->toBeTrue()
            ->and($result->content)->toContain('ninguém é contactado')
            ->and($handoff->user_id)->toBe($this->edy->id)
            ->and($handoff->from_task_id)->toBe($cosChat->id)
            ->and($handoff->to_task_id)->toBe($salesChat->id)
            ->and($handoff->status)->toBe(AgentHandoff::WORKING);

        // Here, "Mensagem enviada a…"; there, "Mensagem de Chief of Staff", which puts the colleague to work.
        $sent = $cosChat->messages()->where('kind', TaskMessageKind::Handoff)->sole();
        $received = $salesChat->messages()->where('kind', TaskMessageKind::Handoff)->sole();
        $salesRun = AgentRun::query()->where('task_id', $salesChat->id)->sole();

        expect($sent->author_type)->toBe(ActorType::System)
            ->and($received->author_agent_id)->toBe($this->chief->id)
            ->and($salesRun->agent_id)->toBe($this->sales->id)
            ->and($salesRun->input)->toContain('Chief of Staff passou-te um pedido de Edy')
            ->and($salesRun->input)->toContain('Exclui a Wedly')
            ->and($salesRun->input)->toContain('Não contactas ninguém de fora');

        // The colleague answers the person in that conversation; the answer goes back on its own.
        finish(runningIn($salesChat), 'Edy, há um concurso novo: Associação Ungagodoli, Matola. Não contactei ninguém.');

        expect($handoff->refresh()->status)->toBe(AgentHandoff::ANSWERED)
            ->and($salesChat->messages()->where('author_agent_id', $this->sales->id)->sole()->body)->toContain('Ungagodoli')
            ->and($salesChat->messages()->where('kind', TaskMessageKind::Handoff)->where('author_type', ActorType::System)->sole()->body)->toContain('Ungagodoli')
            ->and($cosChat->messages()->where('kind', TaskMessageKind::Handoff)->where('author_agent_id', $this->sales->id)->sole()->body)->toContain('Ungagodoli')
            // Its own "sent back" row does not wake it again.
            ->and(AgentRun::query()->where('task_id', $salesChat->id)->count())->toBe(1);

        // The Chief of Staff was busy confirming the hand-off; when it ends, the answer wakes it to tell Edy.
        finish($cosRun, 'Passei ao Gestor Comercial. Ainda não há resultados.');
        $relay = AgentRun::query()->where('task_id', $cosChat->id)->latest('id')->first();

        expect($relay->id)->not->toBe($cosRun->id)
            ->and($relay->input)->toContain('Gestor Comercial respondeu ao pedido que lhe passaste')
            ->and($relay->input)->toContain('Ungagodoli')
            // What the colleague really passed on comes from the records, not from its words.
            ->and($relay->input)->toContain('Gestor Comercial não passou trabalho a ninguém')
            ->and($relay->input)->toContain('Nunca digas que algo foi encaminhado se não foi');
        Queue::assertPushed(RunAgent::class, 3);

        return [$cosChat, $salesChat, $handoff];
    });

    // Both conversations show the rows, each pointing at the other.
    $this->actingAs($this->edy)->get(tenantUrl($this->tenant, "tasks/{$cosChat->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.1.kind', 'handoff')
            ->where('messages.1.handoff.direction', 'out')
            ->where('messages.1.handoff.role', 'request')
            ->where('messages.1.handoff.agent', 'Gestor Comercial')
            ->where('messages.1.handoff.status', 'answered')
            ->where('messages.1.handoff.other_task_id', $salesChat->id)
            ->where('messages.2.handoff.direction', 'in')
            ->where('messages.2.handoff.role', 'answer'));

    $this->actingAs($this->edy)->get(tenantUrl($this->tenant, "tasks/{$salesChat->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.handoff.direction', 'in')
            ->where('messages.0.handoff.role', 'request')
            ->where('messages.0.handoff.agent', 'Chief of Staff')
            ->where('messages.0.handoff.other_task_id', $cosChat->id));

    // The colleague read only the brief: none of the Chief of Staff's conversation is in its history.
    asTenant($this->tenant, function () use ($salesChat) {
        $history = app(TaskThread::class)->history(AgentRun::query()->where('task_id', $salesChat->id)->sole());

        expect(collect($history)->map(fn ($m) => $m->content)->implode(' '))->not->toContain('Caça leads agora');
    });
});

it('tells the agent that passed the request about the tasks the colleague gave out while answering', function () {
    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $cosChat = $threads->conversation($this->edy, $this->chief);
        $threads->post($cosChat, $this->edy, 'Prepara a resposta ao concurso');
        $cosRun = runningIn($cosChat);
        sendTo($cosRun, 'gestor_comercial', 'Prepara a resposta ao concurso.');
        finish($cosRun, 'Passei ao Gestor Comercial.');

        $salesRun = runningIn(Task::query()->where('chat_key', TaskThread::chatKey($this->edy, $this->sales))->sole());
        Task::factory()->create(['title' => 'Preços dos equipamentos', 'created_by_agent_id' => $this->sales->id, 'assignee_agent_id' => $this->finance->id, 'user_id' => $this->edy->id]);
        finish($salesRun, 'Pedi os preços às Finanças.');

        expect(AgentRun::query()->where('task_id', $cosChat->id)->latest('id')->first()->input)
            ->toContain('Gestor Comercial deu a tarefa «Preços dos equipamentos» a Finanças');
    });
});

it('tells the agent that passed the request when the colleague could not finish', function () {
    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $cosChat = $threads->conversation($this->edy, $this->chief);
        $threads->post($cosChat, $this->edy, 'Vê as facturas por pagar');
        $cosRun = runningIn($cosChat);
        sendTo($cosRun, 'finance', 'Lista as facturas por pagar.');
        finish($cosRun, 'Passei às Finanças.');

        finish(runningIn(Task::query()->where('chat_key', TaskThread::chatKey($this->edy, $this->finance))->sole()), '', RunStatus::Failed);

        expect(AgentHandoff::query()->sole()->status)->toBe(AgentHandoff::FAILED)
            ->and(AgentRun::query()->where('task_id', $cosChat->id)->latest('id')->first()->input)->toContain('Não consegui terminar');
    });
});

it('only passes requests along the chart, to agents the person may talk to, and not back and forth', function () {
    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);

        // A sibling is not below the Gestor Comercial: it goes through the Chief of Staff.
        $salesChat = $threads->conversation($this->edy, $this->sales);
        $threads->post($salesChat, $this->edy, 'Pede as facturas às Finanças');
        $salesRun = runningIn($salesChat);
        expect(sendTo($salesRun, 'finance', 'x')->content)->toContain('passa o pedido ao Chief of Staff');
        finish($salesRun, 'Peça ao Chief of Staff.');

        // Someone who cannot talk to the colleague gets no conversation with it.
        $clerk = User::factory()->create(['name' => 'Ana']);
        $this->chief->assignees()->attach($clerk->id, ['role' => 'work']);
        $clerkChat = $threads->conversation($clerk, $this->chief);
        $threads->post($clerkChat, $clerk, 'Caça leads');
        expect(sendTo(runningIn($clerkChat), 'gestor_comercial', 'x')->content)->toContain('Ana não tem acesso a Gestor Comercial');

        // The colleague does not message back the agent that passed it the request: the answer goes on its own.
        $cosChat = $threads->conversation($this->edy, $this->chief);
        $threads->post($cosChat, $this->edy, 'Caça leads agora');
        sendTo(runningIn($cosChat), 'gestor_comercial', 'Procura leads.');
        $back = sendTo(runningIn(Task::query()->where('chat_key', TaskThread::chatKey($this->edy, $this->sales))->sole()), 'chief_of_staff', 'Já está.');

        // Nor does it pretend to pass a part to a sibling: it says so, and the Chief of Staff routes it.
        $sibling = sendTo(AgentRun::query()->where('task_id', Task::query()->where('chat_key', TaskThread::chatKey($this->edy, $this->sales))->value('id'))->latest('id')->first(), 'finance', 'x');
        expect($sibling->content)->toContain('Não digas que lhe passaste')->and($sibling->content)->toContain('Chief of Staff passa-lha');

        expect($back->ok)->toBeFalse()->and($back->content)->toContain('segue sozinha')
            ->and(AgentHandoff::query()->count())->toBe(1)
            ->and(TaskMessage::query()->where('kind', TaskMessageKind::Handoff)->count())->toBe(2);
    });
});

it('lets the Chief of Staff pass requests and tasks to any agent, also one that answers to a person', function () {
    asTenant($this->tenant, function () {
        // As in the Rethink chart: Finanças answers to a person, not to the Chief of Staff.
        $naira = User::factory()->create(['name' => 'Naira']);
        $this->finance->forceFill(['reports_to_agent_id' => null, 'reports_to_user_id' => $naira->id])->save();
        $this->finance->assignees()->attach($this->edy->id, ['role' => 'work']);

        $threads = app(TaskThread::class);
        $cosChat = $threads->conversation($this->edy, $this->chief);
        $threads->post($cosChat, $this->edy, 'Descarta o relatório BAZA e avisa as Finanças');
        $run = runningIn($cosChat);

        expect(sendTo($run, 'finance', 'O relatório BAZA de Setembro foi descartado.')->ok)->toBeTrue();

        $task = app(CapabilityRegistry::class)->find('tasks.create')->execute(['agent' => 'finance', 'title' => 'Relatório BAZA descartado', 'description' => 'Não usar.'], new CapabilityContext($this->chief, $run));
        expect($task->ok)->toBeTrue();

        // The others still go through the chart.
        $salesChat = $threads->conversation($this->edy, $this->sales);
        $threads->post($salesChat, $this->edy, 'Pede as facturas às Finanças');
        expect(sendTo(runningIn($salesChat), 'finance', 'x')->content)->toContain('passa o pedido ao Chief of Staff');
    });
});
