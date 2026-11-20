<?php

use App\Access\AgentAccess;
use App\Ai\Agents\AgentEditor;
use App\Ai\Autonomy\PeopleData;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Memory\MemoryConsolidator;
use App\Ai\Runs\AgentNow;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\ApprovalService;
use App\Ai\Runs\QuestionService;
use App\Ai\Runs\RoutineRunner;
use App\Enums\RuleEffect;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TriggerType;
use App\Events\AgentRunFinished;
use App\Events\ApprovalRequested;
use App\Models\Agent;
use App\Models\AgentHandoff;
use App\Models\AgentMemory;
use App\Models\AgentQuestion;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Capability;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\KnowledgeItem;
use App\Models\MessageAttachment;
use App\Models\Report;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\Handoffs;
use App\Tasks\TaskThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// A confidential agent (docs/DECISOES.md, "Director de Recursos Humanos",
// 20.11.2026): one that holds a people-data capability exists only for people
// with «Gerir pessoas e papéis», and everything it produces stays among them,
// whatever else a person may read. The owner keeps everything.

/** Run a capability as the agent in a run someone asked for. */
function asked(Agent $agent, string $key, array $arguments, ?User $by, ?Task $task = null): CapabilityResult
{
    $run = app(AgentRunner::class)->create($agent, 'teste', TriggerType::Manual, $by, taskId: $task?->id);

    return app(CapabilityRegistry::class)->find($key)->execute($arguments, new CapabilityContext($agent, $run));
}

beforeEach(function () {
    Queue::fake();
    $this->store = freshFakeErp();
    $this->travelTo(Carbon::parse('2026-11-19 09:30', 'Africa/Maputo'));
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create(['name' => 'Edy Barrote']);
        $this->member = User::factory()->create(['role' => 'member', 'name' => 'Nádia Tembe']);
        // Reads every conversation, decides every approval and reads every report, but may not manage people.
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Carlos Tembe', 'permission_overrides' => ['people.manage' => false]]);
        // Manages people.
        $this->rosa = User::factory()->create(['role' => 'admin', 'name' => 'Rosa Machava']);
        $this->director = templateAgent('hr', ['reports_to_user_id' => $this->owner->id]);
        $this->chief = templateAgent('chief_of_staff', ['reports_to_user_id' => $this->owner->id]);
    });
});

afterEach(fn () => @unlink($this->store));

it('is talked to only by people who manage people, checked when they talk', function () {
    asTenant($this->tenant, function () {
        // Access given before the rule, and the roles that talk to every agent.
        $this->director->assignees()->attach($this->member->id, ['role' => AgentAccess::WORK]);

        expect($this->member->can('run', $this->director))->toBeFalse()
            ->and($this->member->can('requestWork', $this->director))->toBeFalse()
            ->and($this->admin->can('run', $this->director))->toBeFalse()
            ->and($this->admin->can('run', $this->chief))->toBeTrue()
            ->and($this->owner->can('run', $this->director))->toBeTrue()
            ->and($this->rosa->can('requestWork', $this->director))->toBeTrue();

        $this->rosa->forceFill(['permission_overrides' => ['people.manage' => false]])->save();
        expect($this->rosa->fresh()->can('run', $this->director))->toBeFalse();
    });
});

it('refuses a chefia or someone to talk to it without the permission, and keeps an admin without it from opening its past', function () {
    $form = fn () => asTenant($this->tenant, fn () => [
        ...collect(app(AgentEditor::class)->present($this->director->fresh()))->except(['id', 'avatar_url', 'suggested', 'suggestions'])->all(),
        'capabilities' => $this->director->capabilities()->pluck('capabilities.id')->all(),
        'skills' => [],
    ]);
    $update = fn (User $user, array $data) => $this->actingAs($user)->put(tenantUrl($this->tenant, "agents/{$this->director->id}"), $data);

    $update($this->owner, [...$form(), 'reports_to_user_id' => $this->member->id])
        ->assertSessionHasErrors(['reports_to_user_id' => 'Director de Recursos Humanos lê salários e dados de pessoal: a chefia e quem fala com ele têm de ter a permissão «Gerir pessoas e papéis», e Nádia Tembe não a tem.']);
    $update($this->owner, [...$form(), 'reports_to_user_id' => $this->rosa->id])->assertSessionHasNoErrors();

    $access = fn (User $user, array $rows) => $this->actingAs($user)->put(tenantUrl($this->tenant, "agents/{$this->director->id}/assignees"), ['access' => $rows]);
    $access($this->owner, [['user_id' => $this->member->id, 'level' => AgentAccess::CHAT]])->assertSessionHasErrors('access');
    $access($this->owner, [['user_id' => $this->rosa->id, 'level' => AgentAccess::WORK]])->assertSessionHasNoErrors();
    expect(asTenant($this->tenant, fn () => $this->director->assignees()->pluck('users.id')->all()))->toBe([$this->rosa->id]);

    // Taking the pay reads away would open what it already did to everyone: only someone with the permission does it.
    $withoutPayData = fn () => [...$form(), 'capabilities' => asTenant($this->tenant, fn () => $this->director->capabilities()->get()->reject(fn (Capability $c) => PeopleData::isPeopleData($c->key))->modelKeys())];
    $update($this->admin, $withoutPayData())->assertSessionHasErrors('capabilities');
    expect(asTenant($this->tenant, fn () => PeopleData::agentHolds($this->director)))->toBeTrue();
});

it('lets only people who manage people decide its approvals, and names the action without its arguments', function () {
    Queue::fake();
    $approval = asTenant($this->tenant, function () {
        // Someone given access before the rule, at the chat level.
        $this->director->assignees()->attach($this->member->id, ['role' => AgentAccess::CHAT]);
        $chat = app(TaskThread::class)->conversation($this->owner, $this->director);
        $run = app(AgentRunner::class)->create($this->director, 'Cessa o contrato do Abel.', TriggerType::Manual, $this->owner, taskId: $chat->id);

        expect(gateInRun($run, 'erp.hr.update_employee', ['employee_id' => 'EMP-0004', 'employment_status' => 'terminated', 'termination_reason' => 'Furto no armazém', 'notes' => 'Processo disciplinar 12/2026']))
            ->toContain('Acção suspensa');

        return Approval::query()->sole();
    });

    $name = asTenant($this->tenant, fn () => Capability::query()->where('key', 'erp.hr.update_employee')->value('name'));
    expect($approval->action_summary)->toBe($name);

    asTenant($this->tenant, function () use ($approval) {
        expect($this->member->can('decide', $approval))->toBeFalse()
            ->and($this->admin->can('decide', $approval))->toBeFalse()
            ->and($this->owner->can('decide', $approval))->toBeTrue()
            ->and($this->rosa->can('decide', $approval))->toBeTrue()
            ->and(Approval::query()->decidableBy($this->member)->exists())->toBeFalse()
            ->and(Approval::query()->decidableBy($this->admin)->exists())->toBeFalse()
            ->and(Approval::query()->visibleTo($this->admin)->exists())->toBeFalse()
            ->and(Approval::query()->decidableBy($this->rosa)->exists())->toBeTrue()
            // Its deciders are told; nobody else, and never the arguments.
            ->and($this->member->notifications()->count())->toBe(0)
            ->and($this->admin->notifications()->count())->toBe(0)
            ->and($this->owner->notifications()->sole()->data['body'])->not->toContain('Furto');

        // Nor the Chief of Staff's overview for someone who may not manage people.
        $overview = fn (User $by) => asked($this->chief, 'platform.overview', ['hours' => 24], $by)->data['approvals_pending'];
        expect($overview($this->admin))->toBe([])
            ->and($overview($this->owner))->toHaveCount(1)
            ->and($overview($this->owner)[0]['action'])->toBe($approval->action_summary);

        // The live event goes to its deciders only, not to the channel of everyone who decides every approval.
        expect(collect((new ApprovalRequested($approval, [$this->owner->id]))->broadcastOn())->map->name->all())->toBe(["private-tenant.{$this->tenant->id}.user.{$this->owner->id}"]);
    });

    $this->actingAs($this->admin)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve"))->assertForbidden();
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve"))->assertForbidden();
});

it('sends its approvals straight to people, never to the Chief of Staff, and keeps the requester through the approval', function () {
    asTenant($this->tenant, function () {
        ApprovalRule::query()->create(['agent_id' => $this->director->id, 'capability_key' => 'erp.hr.list_payroll_runs', 'effect' => RuleEffect::Ask]);
        $run = app(AgentRunner::class)->create($this->director, 'A folha de Outubro.', TriggerType::Manual, $this->owner);

        expect(gateInRun($run, 'erp.hr.list_payroll_runs', ['period' => '2026-10']))->toContain('Acção suspensa');
        $approval = Approval::query()->sole();
        expect($approval->review_stage)->toBe(Approval::STAGE_HUMAN)
            ->and(Task::query()->where('assignee_agent_id', $this->chief->id)->exists())->toBeFalse();

        // Rosa decides it; the run still works for the owner, who asked.
        $approval = app(ApprovalService::class)->execute(app(ApprovalService::class)->approve($approval, $this->rosa)->fresh());
        expect($approval->execution_status->value)->toBe('executed')
            ->and($run->fresh()->requested_by_user_id)->toBe($this->owner->id)
            ->and($approval->decided_by_user_id)->toBe($this->rosa->id);
    });
});

it('keeps the requester when a paused run resumes on the answers', function () {
    asTenant($this->tenant, function () {
        $chat = app(TaskThread::class)->conversation($this->owner, $this->director);
        $run = app(AgentRunner::class)->create($this->director, 'Confere a folha.', TriggerType::Manual, $this->owner, taskId: $chat->id);
        $question = app(QuestionService::class)->ask([[
            'question' => 'De que mês?', 'header' => 'Mês', 'multi_select' => false,
            'options' => [['label' => 'Outubro', 'description' => 'O último fechado.'], ['label' => 'Setembro', 'description' => 'O anterior.']],
        ]], new CapabilityContext($this->director, $run));
        expect($question)->toBeInstanceOf(AgentQuestion::class);
        $run->forceFill(['status' => RunStatus::AwaitingInput])->save();

        app(QuestionService::class)->answer($question, $this->owner, [['selected' => ['Outubro']]]);

        expect($run->fresh())->status->toBe(RunStatus::Queued)->requested_by_user_id->toBe($this->owner->id)
            ->and(gateInRun($run->fresh(), 'erp.hr.list_payroll_runs', ['period' => '2026-10']))->toContain('"employer_cost"');
    });
});

describe('hand-offs', function () {
    it('carry the requester only when they are the person of the conversation, also for a request that waited', function () {
        asTenant($this->tenant, function () {
            $handoffs = app(Handoffs::class);
            // The owner writes in a task that is Rosa's: the request is the owner's, the conversations it reaches are Rosa's.
            $task = app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Plano de férias', 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->rosa->id], $this->owner, start: false);
            $run = app(AgentRunner::class)->create($this->chief, 'Pergunta ao Director.', TriggerType::Manual, $this->owner, taskId: $task->id);

            expect($handoffs->send($run, $this->chief, $this->director, 'Que férias tem a equipa em Dezembro?'))->toBeInstanceOf(AgentHandoff::class);
            $there = AgentRun::query()->where('agent_id', $this->director->id)->sole();
            expect($there->task->user_id)->toBe($this->rosa->id)
                ->and($there->requested_by_user_id)->toBeNull()
                ->and(gateInRun($there, 'erp.hr.list_payroll_runs', ['period' => '2026-10']))->toStartWith('Recusado:');

            // Back in Rosa's task, the Chief of Staff works for nobody either.
            $there->forceFill(['status' => RunStatus::Completed, 'output' => ['text' => 'Ninguém de férias.']])->save();
            $run->forceFill(['status' => RunStatus::Completed])->save();
            $handoffs->settle($there->fresh());
            expect(AgentRun::query()->where('agent_id', $this->chief->id)->latest('id')->first()->requested_by_user_id)->toBeNull();

            // The Director busy in Rosa's conversation: the request waits, and is picked up for nobody.
            $conversation = app(TaskThread::class)->conversation($this->rosa, $this->director);
            $busy = AgentRun::factory()->create(['agent_id' => $this->director->id, 'task_id' => $conversation->id, 'status' => RunStatus::Running, 'started_at' => now()->subMinute()]);
            $other = app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Contratos', 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->rosa->id], $this->owner, start: false);
            $asking = app(AgentRunner::class)->create($this->chief, 'Pergunta ao Director.', TriggerType::Manual, $this->owner, taskId: $other->id);
            $handoffs->send($asking, $this->chief, $this->director, 'Que contratos acabam em Dezembro?');

            $busy->forceFill(['status' => RunStatus::Completed, 'output' => ['text' => 'Feito.']])->save();
            app(TaskThread::class)->recordReply($busy->fresh());
            expect(AgentRun::query()->where('task_id', $conversation->id)->whereKeyNot($busy->id)->latest('id')->first())->not->toBeNull()
                ->requested_by_user_id->toBeNull();
        });
    });

    it('never reach or leave it for someone who may not manage people', function () {
        asTenant($this->tenant, function () {
            $handoffs = app(Handoffs::class);
            $task = app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Férias', 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->member->id], $this->owner, start: false);
            $run = app(AgentRunner::class)->create($this->chief, 'Pergunta ao Director.', TriggerType::Manual, $this->owner, taskId: $task->id);

            expect($handoffs->send($run, $this->chief, $this->director, 'Quantos dias tem a Nádia?'))->toBeString()->toContain('Director de Recursos Humanos lê salários');

            $own = app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Proposta', 'assignee_agent_id' => $this->director->id, 'user_id' => $this->member->id], $this->owner, start: false);
            $from = app(AgentRunner::class)->create($this->director, 'Passa ao Chief.', TriggerType::Manual, $this->owner, taskId: $own->id);
            expect($handoffs->send($from, $this->director, $this->chief, 'Prepara a agenda.'))->toBeString()->toContain('Director de Recursos Humanos lê salários')
                ->and(AgentHandoff::query()->exists())->toBeFalse();
        });
    });

    it('started by a routine stay refused the pay data', function () {
        asTenant($this->tenant, function () {
            $routine = AgentRoutine::factory()->create(['agent_id' => $this->chief->id, 'owner_user_id' => $this->owner->id, 'prompt' => 'Pergunta ao Director pela folha.']);
            app(RoutineRunner::class)->run($routine);
            $run = AgentRun::query()->where('agent_routine_id', $routine->id)->sole();

            expect($run->requested_by_user_id)->toBeNull();
            app(Handoffs::class)->send($run, $this->chief, $this->director, 'Quanto custou a folha de Outubro?');
            $there = AgentRun::query()->where('agent_id', $this->director->id)->sole();

            expect($there->requested_by_user_id)->toBeNull()
                ->and(gateInRun($there, 'erp.hr.list_payroll_runs', ['period' => '2026-10']))->toStartWith('Recusado:');
        });
    });

    it('bring back only where its answer is, which stays in its conversation', function () {
        asTenant($this->tenant, function () {
            $handoffs = app(Handoffs::class);
            $chat = app(TaskThread::class)->conversation($this->owner, $this->chief);
            $run = app(AgentRunner::class)->create($this->chief, 'Pergunta ao Director.', TriggerType::Manual, $this->owner, taskId: $chat->id);
            $handoffs->send($run, $this->chief, $this->director, 'Quanto ganha a Nádia?');
            $there = AgentRun::query()->where('agent_id', $this->director->id)->sole();

            $there->forceFill(['status' => RunStatus::Completed, 'output' => ['text' => 'A Nádia ganha 45 000 MT brutos.']])->save();
            $run->forceFill(['status' => RunStatus::Completed])->save();
            $handoffs->settle($there->fresh());

            // The owner's conversation with the Chief of Staff, which an admin may read, never gets the figure.
            expect($chat->messages()->pluck('body')->implode("\n"))->not->toContain('45 000')->toContain('respondeu na conversa com Edy Barrote');
        });
    });
});

it('never runs its routine for nobody, tells admins without the permission only that it ran, and keeps no shared memory', function () {
    asTenant($this->tenant, function () {
        // Nobody to send it to: no owner, creator or chefia.
        $this->director->update(['reports_to_user_id' => null]);
        $nobody = AgentRoutine::factory()->create(['agent_id' => $this->director->id, 'owner_user_id' => null, 'created_by_user_id' => null, 'prompt' => 'Lê a folha.']);
        expect(app(RoutineRunner::class)->run($nobody))->ok->toBeFalse()
            ->and(AgentRun::query()->where('agent_id', $this->director->id)->exists())->toBeFalse();
        // Nor for a member.
        $forMember = AgentRoutine::factory()->create(['agent_id' => $this->director->id, 'owner_user_id' => $this->member->id, 'prompt' => 'Lê a folha.']);
        expect(app(RoutineRunner::class)->run($forMember)['message'])->toContain('Gerir pessoas e papéis');
        $this->director->update(['reports_to_user_id' => $this->owner->id]);

        // For the owner it runs; when it answers, the admins hear of it, and only Rosa reads what it said.
        $routine = $this->director->routines()->where('name', 'Conferir a folha de salários')->sole();
        app(RoutineRunner::class)->run($routine);
        $run = AgentRun::query()->where('agent_routine_id', $routine->id)->sole();
        $note = $run->task->messages()->where('agent_routine_id', $routine->id)->sole();
        expect($run->trigger_source_id)->toBe($note->id);

        $run->forceFill(['status' => RunStatus::Completed, 'output' => ['text' => 'Folha de Outubro: Nádia Tembe com 45 000 MT de outros rendimentos.']])->save();
        app(RoutineRunner::class)->delivered($run->fresh());

        expect($this->admin->notifications()->sole()->data)->body->toBe('')->title->toContain('Conferir a folha de salários')
            ->and($this->rosa->notifications()->sole()->data['body'])->toContain('45 000');

        // Its work with nobody never becomes memory every conversation reads, nor a knowledge base article.
        AgentRun::factory()->create(['agent_id' => $this->director->id, 'trigger_type' => 'schedule', 'output' => ['text' => 'A Nádia ganha 45 000 MT.']]);
        AgentRun::factory()->create(['agent_id' => $this->chief->id, 'trigger_type' => 'schedule', 'output' => ['text' => 'Enviei o resumo.']]);
        $memory = app(MemoryConsolidator::class);
        expect($memory->workRuns($this->director)->exists())->toBeFalse()
            ->and($memory->workRuns($this->chief)->exists())->toBeTrue();

        AgentMemory::factory()->create(['agent_id' => $this->director->id, 'user_id' => null, 'title' => 'Folha', 'content' => 'A Nádia ganha 45 000 MT.']);
        $memory->publish($this->director);
        expect(KnowledgeItem::query()->where('content', 'like', '%45 000%')->exists())->toBeFalse();
    });
});

it('keeps its conversations, tasks, files, runs and live work from people who may not manage people', function () {
    [$chat, $task, $file, $run] = asTenant($this->tenant, function () {
        $chat = app(TaskThread::class)->conversation($this->owner, $this->director);
        $message = app(TaskThread::class)->post($chat, $this->owner, 'O recibo da Nádia, por favor.', wake: false);
        $file = MessageAttachment::factory()->create(['task_id' => $chat->id, 'task_message_id' => $message->id, 'filename' => 'recibo.pdf']);
        $run = AgentRun::factory()->create(['agent_id' => $this->director->id, 'task_id' => $chat->id, 'requested_by_user_id' => $this->owner->id, 'status' => RunStatus::Running, 'started_at' => now()]);
        // A task it gave a member is its work too.
        $task = app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Recolher o BI da Nádia', 'assignee_user_id' => $this->member->id], $this->director, start: false);

        return [$chat, $task, $file, $run];
    });

    foreach ([$this->admin, $this->member] as $user) {
        $this->actingAs($user)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertForbidden();
        $this->actingAs($user)->get(tenantUrl($this->tenant, "tasks/{$task->id}"))->assertForbidden();
        $this->actingAs($user)->get(tenantUrl($this->tenant, "files/{$file->id}"))->assertForbidden();
        $this->actingAs($user)->get(tenantUrl($this->tenant, "settings/runs/{$run->id}"))->assertForbidden();
        // The dashboard's live work and recent runs, and the home screen's agents at work.
        $this->actingAs($user)->get(tenantUrl($this->tenant, 'painel'))->assertInertia(fn (Assert $page) => $page->has('live', 0)->has('runs', 0));
        asTenant($this->tenant, fn () => expect(app(AgentNow::class)->overview(Agent::query()->get(), $user)['active'])->toBe([])
            ->and(app(AgentNow::class)->for($this->director, $user)['active'])->toBe([])
            ->and(Task::query()->visibleTo($user)->whereKey([$chat->id, $task->id])->exists())->toBeFalse());
    }

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertOk();
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'painel'))->assertInertia(fn (Assert $page) => $page->where('live.0.id', $run->id));
    expect(asTenant($this->tenant, fn () => app(AgentNow::class)->overview(Agent::query()->get(), $this->owner)['active'][0]['id']))->toBe($run->id);
    // The member is not told of a task it cannot open.
    expect(asTenant($this->tenant, fn () => $this->member->notifications()->count()))->toBe(0);

    // The tenant-wide agents channel says that it ended, not why it failed.
    asTenant($this->tenant, function () use ($run) {
        $run->forceFill(['status' => RunStatus::Failed, 'error' => 'O ERP não encontrou o contrato de Nádia Tembe.'])->save();
        $other = AgentRun::factory()->create(['agent_id' => $this->chief->id, 'status' => RunStatus::Failed, 'error' => 'Sem resposta do ERP.']);
        expect((new AgentRunFinished($run->fresh()))->broadcastWith()['error'])->toBeNull()
            ->and((new AgentRunFinished($other))->broadcastWith()['error'])->toBe('Sem resposta do ERP.');
    });
});

it('sends notifications, documents, reminders and tasks only to people who manage people', function () {
    asTenant($this->tenant, function () {
        // A task for someone in its own area who may not manage people; and one for it from a member's conversation.
        $this->member->forceFill(['department_id' => $this->director->department_id])->save();
        $given = asked($this->director, 'tasks.create', ['person' => $this->member->email, 'title' => 'Recolher o BI', 'description' => 'O BI da Nádia expira.'], $this->owner);
        $fromMember = asked($this->chief, 'tasks.create', ['agent' => 'hr', 'title' => 'Salário', 'description' => 'Quanto ganho?'], $this->member, app(TaskThread::class)->conversation($this->member, $this->chief));
        expect($given->ok)->toBeFalse()->and($given->content)->toContain('não envio a Nádia Tembe')
            ->and($fromMember->ok)->toBeFalse()->and($fromMember->content)->toContain('que Nádia Tembe não tem')
            ->and(Task::query()->where('kind', TaskKind::Task)->exists())->toBeFalse();

        $notify = asked($this->director, 'notify.user', ['to' => $this->member->email, 'title' => 'Folha', 'body' => 'A Nádia ganha 45 000 MT.'], $this->owner);
        expect($notify->ok)->toBeFalse()->and($notify->content)->toContain('Gerir pessoas e papéis')
            ->and($this->member->notifications()->count())->toBe(0)
            ->and(asked($this->director, 'notify.user', ['to' => $this->rosa->email, 'title' => 'Folha', 'body' => 'Conferida.'], $this->owner)->ok)->toBeTrue();

        // The chefia lost the permission: no document for it.
        $this->director->forceFill(['reports_to_user_id' => $this->admin->id])->save();
        $draft = asked($this->director->fresh(), 'reports.draft', ['type' => 'payroll_check', 'title' => 'Folha de Outubro', 'content' => 'Nádia: 45 000 MT.', 'notify' => 'chefia'], $this->owner);
        expect($draft->ok)->toBeFalse()->and($draft->content)->toContain('Carlos Tembe')
            ->and(Report::query()->exists())->toBeFalse()
            ->and($this->admin->notifications()->count())->toBe(0);

        $reminder = asked($this->director->fresh(), 'followups.schedule', ['title' => 'Renovar o contrato da Nádia', 'due' => '2026-12-01 09:00', 'for' => $this->member->email], $this->owner);
        expect($reminder->ok)->toBeFalse()->and(FollowUp::query()->exists())->toBeFalse();
        $this->director->forceFill(['reports_to_user_id' => $this->owner->id])->save();
    });

    // A document with no one to notify: for people who manage people only, whoever else reads every report.
    $report = asTenant($this->tenant, function () {
        $this->manager = User::factory()->create(['role' => 'manager', 'department_id' => $this->director->department_id]);
        expect(asked($this->director->fresh(), 'reports.draft', ['type' => 'payroll_check', 'title' => 'Folha de Outubro', 'content' => 'Nádia: 45 000 MT.'], $this->owner)->ok)->toBeTrue();

        return Report::query()->sole();
    });

    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, "reports/{$report->id}"))->assertForbidden();
    $this->actingAs($this->manager)->get(tenantUrl($this->tenant, "reports/{$report->id}"))->assertForbidden();
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "reports/{$report->id}"))->assertOk();
    $this->actingAs($this->rosa)->get(tenantUrl($this->tenant, "reports/{$report->id}"))->assertOk();
    expect(asTenant($this->tenant, fn () => asked($this->chief, 'platform.overview', ['hours' => 24], $this->admin)->data['reports_to_review']))->toBe([]);
});

it('writes nothing for everyone to read: no knowledge base article, no company decision', function () {
    asTenant($this->tenant, function () {
        foreach (['knowledge.save' => ['title' => 'Processos disciplinares', 'content' => 'A Nádia Tembe está num processo disciplinar.'], 'memory.remember_decision' => ['title' => 'Cessação', 'content' => 'O contrato do Abel Cossa não se renova.']] as $key => $arguments) {
            $run = app(AgentRunner::class)->create($this->director, 'teste', TriggerType::Manual, $this->owner);
            expect(gateInRun($run, $key, $arguments))->toStartWith('Recusado:')->toContain('base de conhecimento');
        }

        expect(KnowledgeItem::query()->exists())->toBeFalse();
    });
});

describe('searching conversations', function () {
    beforeEach(function () {
        asTenant($this->tenant, function () {
            $chat = app(TaskThread::class)->conversation($this->owner, $this->director);
            app(TaskThread::class)->post($chat, $this->owner, 'Qual é o salário da Nádia?', wake: false);
            // May ask for conversation reports, but may not manage people.
            $this->admin->forceFill(['permission_overrides' => ['people.manage' => false, 'conversations.report' => true]])->save();
        });
    });

    it('asks only for whoever asked for the run, not whoever a task is with', function () {
        asTenant($this->tenant, function () {
            $email = EmailMessage::factory()->create(['subject' => 'Pedido']);
            $task = app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Pedido por email', 'assignee_agent_id' => $this->chief->id, 'user_id' => $this->owner->id,
                'source_type' => $email->getMorphClass(), 'source_id' => $email->id], Agent::factory()->create(['name' => 'Triagem']), start: false);

            $byNobody = asked($this->chief, 'conversations.search', ['query' => 'salário'], null, $task);
            expect($byNobody->ok)->toBeFalse()->and($byNobody->content)->toContain('só pesquiso conversas');
        });
    });

    it('leaves the Director\'s threads out for someone who may not manage people', function () {
        asTenant($this->tenant, function () {
            expect(asked($this->chief, 'conversations.search', ['query' => 'salário'], $this->admin)->data['matches'])->toBe([])
                ->and(asked($this->chief, 'conversations.search', ['query' => 'salário'], $this->owner)->data['matches'])->toHaveCount(1);
        });
    });
});
