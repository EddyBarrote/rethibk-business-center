<?php

use App\Access\AgentAccess;
use App\Ai\Agents\GenericAgent;
use App\Ai\Autonomy\PeopleData;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\ApprovalService;
use App\Ai\Runs\RoutineRunner;
use App\Enums\AuditResult;
use App\Enums\RuleEffect;
use App\Enums\RunStatus;
use App\Enums\StepType;
use App\Enums\TaskKind;
use App\Enums\TriggerType;
use App\Events\AgentRunStepAdded;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\Handoffs;
use App\Tasks\TaskThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\Data\ToolCall;

// Pay and personal data (docs/DECISOES.md, "Director de Recursos Humanos"): only
// a person who asked and may manage people, or the agent's routine for such a
// person, reads them; run traces show them only to such people.

beforeEach(function () {
    Queue::fake();
    $this->store = freshFakeErp();
    // The platform's clock; the fake ERP runs in its own process with the real one, so ERP calls name their dates.
    $this->travelTo(Carbon::parse('2026-11-19 09:30', 'Africa/Maputo'));
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create(['name' => 'Edy Barrote']);
        $this->member = User::factory()->create(['role' => 'member', 'name' => 'Nádia Tembe']);
        // Reads everyone's conversations and runs, but may not manage people.
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Carlos Tembe', 'permission_overrides' => ['people.manage' => false]]);
        $this->director = templateAgent('hr', ['reports_to_user_id' => $this->owner->id]);
        $this->chief = templateAgent('chief_of_staff', ['reports_to_user_id' => $this->owner->id]);
    });
});

afterEach(fn () => @unlink($this->store));

describe('who a run works for', function () {
    it('refuses the Director a task an email opened, though the task is with someone who manages people', function () {
        asTenant($this->tenant, function () {
            $email = EmailMessage::factory()->create(['subject' => 'Candidatura a técnico']);
            $task = app(TaskThread::class)->open([
                'kind' => TaskKind::Task, 'title' => 'Candidatura: técnico', 'assignee_agent_id' => $this->director->id,
                'user_id' => $this->owner->id, 'source_type' => $email->getMorphClass(), 'source_id' => $email->id,
            ], Agent::factory()->create(['name' => 'Triagem']));
            $run = AgentRun::query()->where('task_id', $task->id)->sole();

            expect($run->requested_by_user_id)->toBeNull()
                ->and(gateInRun($run, 'erp.hr.get_employee', ['employee_id' => 'EMP-0004']))->toStartWith('Recusado:')->toContain('ninguém com essa permissão pediu');
        });
    });

    it('refuses it a task another agent gave it from the owner\'s conversation, and the heartbeat\'s nudge', function () {
        asTenant($this->tenant, function () {
            $chat = app(TaskThread::class)->conversation($this->owner, $this->chief);
            $chiefRun = app(AgentRunner::class)->create($this->chief, 'Pede a folha ao Director.', TriggerType::Manual, $this->owner, taskId: $chat->id);

            $result = app(CapabilityRegistry::class)->find('tasks.create')->execute(['agent' => 'hr', 'title' => 'Folha de Outubro', 'description' => 'Confere a folha de Outubro.'], new CapabilityContext($this->chief, $chiefRun));
            expect($result->ok)->toBeTrue();

            $task = Task::query()->where('assignee_agent_id', $this->director->id)->sole();
            $run = AgentRun::query()->where('task_id', $task->id)->sole();
            expect($task->user_id)->toBe($this->owner->id)
                ->and(gateInRun($run, 'erp.hr.list_payroll_runs', ['period' => '2026-10']))->toStartWith('Recusado:');

            $run->forceFill(['status' => RunStatus::Completed])->save();
            $nudged = app(TaskThread::class)->nudge($task);
            expect(gateInRun($nudged, 'erp.hr.list_payroll_runs', ['period' => '2026-10']))->toStartWith('Recusado:');
        });
    });

    it('carries the person through a hand-off: allowed for someone who manages people, and back again; never for a member', function () {
        asTenant($this->tenant, function () {
            $handoffs = app(Handoffs::class);
            $from = function (User $person) {
                $chat = app(TaskThread::class)->conversation($person, $this->chief);

                return app(AgentRunner::class)->create($this->chief, 'Pergunta ao Director.', TriggerType::Manual, $person, taskId: $chat->id);
            };

            $byOwner = $from($this->owner);
            $handoffs->send($byOwner, $this->chief, $this->director, 'Quanto custou a folha de Outubro?');
            $there = AgentRun::query()->where('agent_id', $this->director->id)->latest('id')->first();

            expect($there->requested_by_user_id)->toBe($this->owner->id)
                ->and(gateInRun($there, 'erp.hr.list_payroll_runs', ['period' => '2026-10']))->toContain('"employer_cost"');

            // The answer goes back and wakes the Chief of Staff for the same person.
            $there->forceFill(['status' => RunStatus::Completed, 'output' => ['text' => 'A folha de Outubro foi aprovada.']])->save();
            $byOwner->forceFill(['status' => RunStatus::Completed])->save();
            $handoffs->settle($there->fresh());
            expect(AgentRun::query()->where('agent_id', $this->chief->id)->latest('id')->first()->requested_by_user_id)->toBe($this->owner->id);

            // A member never reaches the Director, not even through a colleague (the confidential agent rule).
            foreach ([$this->chief, $this->director] as $agent) {
                $agent->assignees()->attach($this->member->id, ['role' => AgentAccess::CHAT]);
            }
            $runs = AgentRun::query()->where('agent_id', $this->director->id)->count();

            expect($handoffs->send($from($this->member), $this->chief, $this->director, 'Quanto ganha o Abel?'))->toBeString()->toContain('Nádia Tembe não tem')
                ->and(AgentRun::query()->where('agent_id', $this->director->id)->count())->toBe($runs);

            // The second layer still holds for a run that has a member as its requester.
            $there = app(AgentRunner::class)->create($this->director, 'Quanto ganha o Abel?', TriggerType::Manual, $this->member);
            expect(gateInRun($there, 'erp.hr.get_employee', ['employee_id' => 'EMP-0004']))->toStartWith('Recusado:')->toContain('Nádia Tembe não tem a permissão');
        });
    });

    it('refuses someone who had the permission but is no longer active', function () {
        asTenant($this->tenant, function () {
            $gone = User::factory()->owner()->create(['is_active' => false]);
            $run = app(AgentRunner::class)->create($this->director, 'teste', TriggerType::Manual, $gone);

            expect(gateInRun($run, 'erp.hr.get_employee', ['employee_id' => 'EMP-0004']))->toStartWith('Recusado:');
        });
    });
});

describe('routines', function () {
    it('lets a routine read only when its result goes to someone who manages people, never to nobody', function () {
        asTenant($this->tenant, function () {
            $routineFor = fn (?User $person) => AgentRoutine::factory()->create(['agent_id' => $this->director->id, 'owner_user_id' => $person?->id, 'created_by_user_id' => null]);
            $inRoutine = fn (AgentRoutine $routine) => fn (AgentRun $run) => $run->agent_routine_id = $routine->id;

            expect(throughGate($this->director, 'erp.hr.list_payroll_runs', ['period' => '2026-10'], $inRoutine($routineFor($this->owner))))->toContain('"employer_cost"')
                ->and(throughGate($this->director, 'erp.hr.list_payroll_runs', ['period' => '2026-10'], $inRoutine($routineFor($this->member))))->toStartWith('Recusado:');

            // Nobody to send it to (no owner, creator or chefia): the result would go to the run list and every admin.
            $this->director->update(['reports_to_user_id' => null]);
            expect(throughGate($this->director->fresh(), 'erp.hr.list_payroll_runs', ['period' => '2026-10'], $inRoutine($routineFor(null))))->toStartWith('Recusado:');

            $other = AgentRoutine::factory()->create(['agent_id' => $this->chief->id]);
            expect(throughGate($this->director, 'erp.hr.list_payroll_runs', [], $inRoutine($other)))->toStartWith('Recusado:');
        });
    });

    it('lets only people who manage people create, change or run a routine of an agent that reads people data; any admin pauses it', function () {
        $routine = asTenant($this->tenant, fn () => $this->director->routines()->where('name', 'Assiduidade do dia')->sole());
        $form = ['name' => 'Folha', 'kind' => 'agent', 'agent_id' => $this->director->id, 'prompt' => 'Lê a folha.', 'schedule' => '0 9 25 * *'];

        $this->actingAs($this->admin)->post(tenantUrl($this->tenant, 'settings/routines'), $form)->assertForbidden();
        $this->actingAs($this->admin)->put(tenantUrl($this->tenant, "settings/routines/{$routine->id}"), [...$form, 'is_active' => true])->assertForbidden();
        $this->actingAs($this->admin)->post(tenantUrl($this->tenant, "settings/routines/{$routine->id}/run"))->assertForbidden();

        // Pausing: only the switch changes, whatever else the form says.
        $this->actingAs($this->admin)->put(tenantUrl($this->tenant, "settings/routines/{$routine->id}"), [...$form, 'is_active' => false])->assertRedirect()->assertSessionHas('success');
        expect($routine->fresh())->is_active->toBeFalse()->name->toBe('Assiduidade do dia')->schedule->toBe('30 9 * * 1-5');
        $this->actingAs($this->admin)->put(tenantUrl($this->tenant, "settings/routines/{$routine->id}"), [...$form, 'is_active' => false])->assertForbidden();

        // Another agent's routines are theirs to manage as before.
        $this->actingAs($this->admin)->post(tenantUrl($this->tenant, 'settings/routines'), [...$form, 'agent_id' => $this->chief->id])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'settings/routines'), $form)->assertRedirect()->assertSessionHas('success');
        $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "settings/routines/{$routine->id}/run"))->assertRedirect()->assertSessionHas('success');
    });

    it('keeps the routine on the run that picks its request up when the agent was busy', function () {
        asTenant($this->tenant, function () {
            $threads = app(TaskThread::class);
            $chat = $threads->conversation($this->owner, $this->director);
            $busy = AgentRun::factory()->create(['agent_id' => $this->director->id, 'task_id' => $chat->id, 'status' => RunStatus::Running, 'started_at' => now()->subMinute()]);
            $routine = $this->director->routines()->where('name', 'Conferir a folha de salários')->sole();

            expect(app(RoutineRunner::class)->run($routine)['message'])->toContain('está a meio de outra resposta');

            $busy->forceFill(['status' => RunStatus::Completed, 'output' => ['text' => 'Feito.']])->save();
            $threads->recordReply($busy->fresh());

            $next = AgentRun::query()->whereKeyNot($busy->id)->where('task_id', $chat->id)->sole();
            expect($next->agent_routine_id)->toBe($routine->id)
                ->and($routine->fresh()->last_run_id)->toBe($next->id)
                ->and(gateInRun($next, 'erp.hr.list_payroll_runs', ['period' => '2026-10']))->toContain('"employer_cost"');
        });
    });
});

it('covers every payroll and Rethink HR tool of the live ERP but a short open list, and the contract by name', function () {
    $restricted = fn (string $key) => PeopleData::isPeopleData($key);

    expect(collect([
        'erp.pay_list_runs', 'erp.pay_my_payslips', 'erp.pay_some_future_tool', 'erp.hr_list_employees', 'erp.hr_get_employee', 'erp.hr_upcoming',
        'erp.hr_get_contract_pdf', 'erp.hr_list_documents', 'erp.hr_list_disciplinary_cases', 'erp.hr_update_employee', 'erp.hr_some_future_tool',
        'erp.hr.get_employee', 'erp.hr.people_indicators', 'erp.hr.upcoming_dates', 'erp.hr.list_payroll_runs', 'erp.hr.get_payslip', 'erp.hr.update_employee',
    ])->reject($restricted)->all())->toBe([])
        ->and(collect([
            'erp.hr_org_chart', 'erp.hr_reference_data', 'erp.hr_leave_balances', 'erp.att_today', 'erp.att_list_leave_requests', 'erp.list_staff', 'erp.list_departments',
            'erp.hr.list_employees', 'erp.hr.attendance_today', 'erp.hr.attendance_summary', 'erp.hr.list_leave', 'erp.payments.create',
        ])->filter($restricted)->all())->toBe([]);
});

describe('run traces', function () {
    beforeEach(function () {
        asTenant($this->tenant, function () {
            $routine = $this->director->routines()->where('name', 'Conferir a folha de salários')->sole();
            app(RoutineRunner::class)->run($routine);
            $this->run = AgentRun::query()->sole();

            GenericAgent::fake([
                new ToolCall('p1', 'erp_hr_list_payroll_runs', ['period' => '2026-10']),
                'A folha de Outubro tem 5 pessoas; Nádia Tembe tem outros rendimentos sem horas extraordinárias que os expliquem.',
            ]);
            (new RunAgent($this->tenant->id, $this->run->id))->handle(app(AgentRunner::class));
            $this->run = $this->run->fresh();
            $this->chat = $this->run->task;
        });
    });

    it('shows the Director\'s run only to people who manage people, and its pay results only to them once it no longer reads them', function () {
        $show = fn (User $user) => $this->actingAs($user)->get(tenantUrl($this->tenant, "settings/runs/{$this->run->id}"));
        $result = fn (Assert $page) => collect($page->toArray()['props']['steps'])->firstWhere('type', 'tool_result')['payload'];

        $show($this->owner)->assertOk()->assertInertia(fn (Assert $page) => expect($result($page)['content'])->toContain('"employer_cost"'));
        // Reading every conversation does not reach a confidential agent's (docs/DECISOES.md, "Director de Recursos Humanos").
        $show($this->admin)->assertForbidden();
        $show($this->member)->assertForbidden();

        $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/runs'))->assertInertia(fn (Assert $page) => $page->has('runs.data', 0));
        $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/runs?origin=conversa'))->assertInertia(fn (Assert $page) => $page->has('conversations.data', 0));
        $this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'settings/runs?origin=conversa'))->assertInertia(fn (Assert $page) => $page->has('conversations.data', 0));
        $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/runs?origin=conversa'))->assertInertia(fn (Assert $page) => $page->has('conversations.data', 1));

        // Nor on the agent's page: no runs, no work in progress, no other people's conversations.
        $this->actingAs($this->admin)->get(tenantUrl($this->tenant, "agents/{$this->director->id}"))->assertInertia(fn (Assert $page) => $page->has('runs', 0)
            ->where('conversations', null)->where('now.last', null)->where('can.run', false));
        $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "agents/{$this->director->id}"))->assertInertia(fn (Assert $page) => $page->where('runs.0.id', $this->run->id));

        // The conversation itself, and so its side panel, attachments and reasoning.
        $panel = fn (User $user) => $this->actingAs($user)->get(tenantUrl($this->tenant, "tasks/{$this->chat->id}?execucao={$this->run->id}"));
        $panel($this->admin)->assertForbidden();
        $panel($this->owner)->assertInertia(fn (Assert $page) => expect(collect($page->toArray()['props']['panel_run']['steps'])->firstWhere('type', 'tool_result')['payload']['content'])->toContain('"employer_cost"'));

        // The second layer: once the agent no longer reads pay data, its old runs open again, without the results.
        asTenant($this->tenant, fn () => $this->director->capabilities()->detach(Capability::query()->get()->filter(fn (Capability $c) => PeopleData::isPeopleData($c->key))->modelKeys()));
        $show($this->admin)->assertOk()->assertInertia(fn (Assert $page) => expect($result($page))->content->toBe(PeopleData::WITHHELD)->withheld->toBeTrue());
        $panel($this->admin)->assertInertia(fn (Assert $page) => expect(collect($page->toArray()['props']['panel_run']['steps'])->firstWhere('type', 'tool_result')['payload']['content'])->toBe(PeopleData::WITHHELD));
    });

    it('never broadcasts the result, authorises the live channel like the trace, and keeps it out of the audit', function () {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        app('Illuminate\Broadcasting\BroadcastManager')->purge();
        require base_path('routes/channels.php');

        asTenant($this->tenant, function () {
            $step = $this->run->steps()->where('type', StepType::ToolResult)->sole();
            expect((new AgentRunStepAdded($step))->broadcastWith()['step']['payload']['content'])->toBe(PeopleData::WITHHELD)
                ->and($step->payload['content'])->toContain('"employer_cost"');

            $audit = AuditLog::query()->where('action', 'erp.tool_call')->where('payload->tool', 'hr.list_payroll_runs')->sole();
            expect($audit->result)->toBe(AuditResult::Ok)->and($audit->payload['result'])->toMatchArray(['withheld' => 'dados de pessoal'])
                ->and(json_encode($audit->payload))->not->toContain('employer_cost');
        });

        $auth = fn (User $user, string $channel) => $this->actingAs($user)->post(tenantUrl($this->tenant, 'broadcasting/auth'), ['socket_id' => '1.1', 'channel_name' => "private-tenant.{$this->tenant->id}.{$channel}"]);

        // The run's channel and the conversation's (where the reply streams), like the trace.
        foreach (["run.{$this->run->id}", "task.{$this->chat->id}"] as $channel) {
            $auth($this->member, $channel)->assertForbidden();
            $auth($this->admin, $channel)->assertForbidden();
            $auth($this->owner, $channel)->assertOk();
        }
    });
});

it('keeps a people-data result out of the approval card when an admin made the read wait for a person', function () {
    asTenant($this->tenant, function () {
        ApprovalRule::query()->create(['agent_id' => $this->director->id, 'capability_key' => 'erp.hr.get_payslip', 'effect' => RuleEffect::Ask]);
        $run = app(AgentRunner::class)->create($this->director, 'teste', TriggerType::Manual, $this->owner);

        expect(gateInRun($run, 'erp.hr.get_payslip', ['period' => '2026-10', 'employee_id' => 'EMP-0004']))->toContain('Acção suspensa');

        $approval = app(ApprovalService::class)->approve(Approval::query()->sole(), $this->owner);
        $approval = app(ApprovalService::class)->execute($approval->fresh());

        expect($approval->execution_result)->toMatchArray(['ok' => true, 'content' => PeopleData::WITHHELD, 'data' => null]);
    });
});
