<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Agents\ToolResolver;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Runs\AgentRunner;
use App\Enums\ActorType;
use App\Enums\QuestionStatus;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Perguntas dos agentes (docs/DECISOES.md): like Claude's AskUserQuestion, the
// agent asks multiple-choice questions, its run pauses, and the answer resumes it.

function askFormat(bool $multi = false): Closure
{
    return toolCall('q1', 'questions_ask', [
        'questions' => [
            [
                'question' => 'Em que formato quer o relatório?',
                'header' => 'Formato',
                'options' => [
                    ['label' => 'PDF', 'description' => 'Para enviar por email.'],
                    ['label' => 'Word', 'description' => 'Para editar antes de enviar.'],
                    ['label' => 'Outra', 'description' => 'O modelo não deve oferecer esta.'],
                ],
                'multi_select' => $multi,
            ],
            [
                'question' => 'Que secções incluir?',
                'header' => 'Secções',
                'options' => [
                    ['label' => 'Vendas', 'description' => ''],
                    ['label' => 'Custos', 'description' => ''],
                ],
                'multi_select' => true,
            ],
        ],
    ]);
}

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->boss, $this->agent] = asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $boss = User::factory()->owner()->create(['name' => 'Chefe']);

        return [$boss, Agent::factory()->create(['key' => 'finance', 'name' => 'Finanças', 'reports_to_user_id' => $boss->id])];
    });
});

it('pauses a chat run on the questions, shows the card on the reply and resumes the same run with the answers', function () {
    GenericAgent::fake([askFormat(), 'Preciso de duas escolhas suas.', 'Relatório em PDF com Vendas e Custos, a seguir.']);

    $chat = asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->boss, $this->agent);
        $threads->post($chat, $this->boss, 'Faz-me o relatório do mês.');
        app(AgentRunner::class)->run(AgentRun::query()->sole());

        return $chat->refresh();
    });

    [$run, $question] = asTenant($this->tenant, fn () => [AgentRun::query()->sole(), AgentQuestion::query()->sole()]);
    $reply = asTenant($this->tenant, fn () => $chat->messages()->where('author_type', ActorType::Agent)->sole());

    expect($run->status)->toBe(RunStatus::AwaitingInput)
        ->and($run->finished_at)->toBeNull()
        ->and($chat->status)->toBe(TaskStatus::WaitingHuman)
        ->and($question->task_message_id)->toBe($reply->id)
        ->and($question->asked_user_id)->toBe($this->boss->id)
        ->and(array_column($question->questions[0]['options'], 'label'))->toBe(['PDF', 'Word'])
        // Someone chatting sees the card appear: no notification.
        ->and(asTenant($this->tenant, fn () => $this->boss->notifications()->where('type', 'waiting')->count()))->toBe(0);

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.1.question.id', $question->id)
            ->where('messages.1.question.status', 'pending')
            ->where('messages.1.question.can_answer', true));

    $this->actingAs($this->boss)->post(tenantUrl($this->tenant, "questions/{$question->id}/answer"), [
        'answers' => [['selected' => ['PDF']], ['selected' => ['Vendas', 'Custos']]],
    ])->assertRedirect();

    Queue::assertPushed(RunAgent::class, fn (RunAgent $job) => $job->runId === $run->id);

    asTenant($this->tenant, function () use ($run, $question, $chat) {
        expect($run->refresh()->status)->toBe(RunStatus::Queued)
            ->and($chat->refresh()->status)->toBe(TaskStatus::InProgress)
            ->and($question->refresh()->status)->toBe(QuestionStatus::Answered)
            ->and($question->answers[1]['selected'])->toBe(['Vendas', 'Custos']);

        app(AgentRunner::class)->run($run->refresh());

        expect($run->refresh()->status)->toBe(RunStatus::Completed)
            ->and($run->output['text'])->toContain('PDF')
            ->and($question->refresh()->resumed_at)->not->toBeNull()
            ->and(AgentRun::query()->count())->toBe(1)
            ->and($chat->messages()->where('author_type', ActorType::Agent)->pluck('body')->all())
            ->toBe(['Preciso de duas escolhas suas.', 'Relatório em PDF com Vendas e Custos, a seguir.']);
    });

    GenericAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Respostas às tuas perguntas') && str_contains($prompt->prompt, 'Vendas, Custos'));
});

it('takes a message written in the conversation as the answer and resumes the paused run', function () {
    GenericAgent::fake([askFormat(), 'Fico à espera.', 'Combinado.']);

    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->boss, $this->agent);
        $threads->post($chat, $this->boss, 'Relatório, por favor.');
        $run = AgentRun::query()->sole();
        app(AgentRunner::class)->run($run);

        $threads->post($chat->refresh(), $this->boss, 'Excel, só com vendas.');

        $question = AgentQuestion::query()->sole();
        expect($question->status)->toBe(QuestionStatus::Answered)
            ->and($question->answers[0]['other'])->toBe('Excel, só com vendas.')
            ->and(AgentRun::query()->count())->toBe(1)
            ->and($run->refresh()->status)->toBe(RunStatus::Queued);
    });
});

it('asks the person by notification when the agent runs outside a live chat, and the task waits on them', function () {
    GenericAgent::fake([askFormat(), 'Fiz perguntas ao Chefe.']);

    asTenant($this->tenant, function () {
        // A routine-like run: no task, nobody watching.
        $run = app(AgentRunner::class)->create($this->agent, 'Prepara o relatório semanal.', TriggerType::Schedule);
        app(AgentRunner::class)->run($run);

        $chat = Task::query()->where('chat_key', TaskThread::chatKey($this->boss, $this->agent))->sole();
        $question = AgentQuestion::query()->sole();

        expect($run->refresh()->status)->toBe(RunStatus::AwaitingInput)
            ->and($run->task_id)->toBe($chat->id)
            ->and($chat->status)->toBe(TaskStatus::WaitingHuman)
            ->and($question->task_message_id)->not->toBeNull()
            ->and($this->boss->notifications()->where('type', 'waiting')->count())->toBe(1)
            ->and($this->boss->notifications()->where('type', 'conversation')->count())->toBe(0);
    });

    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, 'tasks?vista=waiting'))->assertOk();
    expect(asTenant($this->tenant, fn () => Task::query()->where('user_id', $this->boss->id)->where('status', TaskStatus::WaitingHuman)->count()))->toBe(1);
});

it('refuses other tools once the agent has asked, and a second set of questions in the same run', function () {
    GenericAgent::fake([askFormat(), askFormat(), toolCall('t1', 'tasks_list', []), 'À espera.']);

    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->boss, $this->agent);
        $threads->post($chat, $this->boss, 'Relatório.');
        app(AgentRunner::class)->run(AgentRun::query()->sole());

        expect(AgentQuestion::query()->count())->toBe(1)
            ->and(AgentRun::query()->sole()->steps()->where('type', 'error')->count())->toBeGreaterThanOrEqual(1);
    });
});

it('validates the answers: one choice for single-select questions, and every question answered', function () {
    $question = asTenant($this->tenant, function () {
        $chat = app(TaskThread::class)->conversation($this->boss, $this->agent);
        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id, 'status' => RunStatus::AwaitingInput]);

        return AgentQuestion::factory()->create(['agent_run_id' => $run->id, 'agent_id' => $this->agent->id, 'task_id' => $chat->id, 'asked_user_id' => $this->boss->id]);
    });

    $url = tenantUrl($this->tenant, "questions/{$question->id}/answer");

    $this->actingAs($this->boss)->post($url, ['answers' => [['selected' => ['PDF', 'Word']]]])->assertSessionHasErrors('answers.0');
    $this->actingAs($this->boss)->post($url, ['answers' => [['selected' => [], 'other' => '']]])->assertSessionHasErrors('answers.0');

    $stranger = asTenant($this->tenant, fn () => User::factory()->create());
    $this->actingAs($stranger)->post($url, ['answers' => [['selected' => ['PDF']]]])->assertForbidden();

    $this->actingAs($this->boss)->post($url, ['answers' => [['selected' => [], 'other' => 'Em Excel']]])->assertSessionHasNoErrors();
    $this->actingAs($this->boss)->post($url, ['answers' => [['selected' => ['PDF']]]])->assertSessionHasErrors('answers');
});

it('gives every agent the questions tool', function () {
    asTenant($this->tenant, function () {
        $run = app(AgentRunner::class)->create($this->agent, 'olá', TriggerType::Manual);
        $names = collect(app(ToolResolver::class)->for(new CapabilityContext($this->agent, $run)))->map->name()->all();

        expect($names)->toContain('questions_ask');
    });
});
