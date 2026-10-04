<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Agents\ToolResolver;
use App\Ai\Budget\BudgetGuard;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\ApprovalService;
use App\Enums\AgentStatus;
use App\Enums\AutonomyLevel;
use App\Enums\KnowledgeType;
use App\Enums\RunStatus;
use App\Enums\TriggerType;
use App\Jobs\RunAgent;
use App\Mail\AgentMessage;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\BudgetEvent;
use App\Models\Capability;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\ToolCall;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('cuts an agent off when its monthly budget is spent, and warns at 80%', function () {
    $this->tenant->update(['settings' => ['ai_budget' => ['agent_monthly_usd' => 10]]]);

    asTenant($this->tenant->fresh(), function () {
        $agent = Agent::factory()->create();
        AgentRun::factory()->create(['agent_id' => $agent->id, 'cost_usd' => 8.5]);

        $run = app(AgentRunner::class)->create($agent, 'Olá', TriggerType::Manual);
        GenericAgent::fake(['Feito.']);
        app(AgentRunner::class)->run($run);

        expect(BudgetEvent::query()->pluck('threshold')->all())->toBe([80])
            ->and($agent->fresh()->status)->toBe(AgentStatus::Active);

        AgentRun::factory()->create(['agent_id' => $agent->id, 'cost_usd' => 2]);
        $next = app(AgentRunner::class)->create($agent, 'Outra vez', TriggerType::Manual);
        app(AgentRunner::class)->run($next);

        expect($next->fresh()->status)->toBe(RunStatus::Failed)
            ->and($next->fresh()->error)->toContain('orçamento')
            ->and($agent->fresh()->status)->toBe(AgentStatus::Suspended);
    });
});

it('asks for a budget exception when the cap runs out, and resumes the agent once an owner approves', function () {
    $this->tenant->update(['settings' => ['ai_budget' => ['agent_monthly_usd' => 10]]]);
    Queue::fake();

    asTenant($this->tenant->fresh(), function () {
        app(CapabilityCatalog::class)->syncLocal();
        $owner = User::factory()->owner()->create();
        $boss = User::factory()->create();
        $agent = Agent::factory()->create(['reports_to_user_id' => $boss->id]);
        AgentRun::factory()->create(['agent_id' => $agent->id, 'cost_usd' => 10.5]);

        GenericAgent::fake(['Não devia correr.']);
        app(AgentRunner::class)->run(app(AgentRunner::class)->create($agent, 'Olá', TriggerType::Manual));

        $approval = Approval::query()->where('action_type', 'budget.override')->sole();
        expect($agent->fresh()->status)->toBe(AgentStatus::Suspended)
            ->and($approval->payload['extra_usd'])->toBe(5)
            ->and($approval->ceiling_reason)->not->toBeNull()
            ->and($boss->can('decide', $approval))->toBeFalse()
            ->and($owner->can('decide', $approval))->toBeTrue();

        $approvals = app(ApprovalService::class);
        $approvals->approve($approval, $owner);
        $approvals->execute($approval->fresh());

        expect($approval->fresh()->execution_status->value)->toBe('executed')
            ->and($agent->fresh()->status)->toBe(AgentStatus::Active)
            ->and(app(BudgetGuard::class)->agentCap($agent))->toBe(15.0);

        GenericAgent::fake(['Já posso.']);
        $next = app(AgentRunner::class)->create($agent->fresh(), 'Outra vez', TriggerType::Manual);
        app(AgentRunner::class)->run($next);
        expect($next->fresh()->status)->toBe(RunStatus::Completed);
    });
});

it('refuses to run a suspended agent', function () {
    asTenant($this->tenant, function () {
        $agent = Agent::factory()->suspended()->create();
        $run = app(AgentRunner::class)->create($agent, 'Olá', TriggerType::Manual);
        GenericAgent::fake(['Não devia correr.']);

        app(AgentRunner::class)->run($run);

        expect($run->fresh()->status)->toBe(RunStatus::Failed);
        GenericAgent::assertNeverPrompted();
    });
});

it('gives an agent only the capabilities of its own tenant, plus the knowledge base and task tools', function () {
    $other = Tenant::factory()->create();
    asTenant($other, fn () => app(CapabilityCatalog::class)->syncLocal());

    asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->create();
        $agent->capabilities()->attach(Capability::query()->where('key', 'comms.send_email')->value('id'), ['enabled' => true]);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        $names = collect(app(ToolResolver::class)->for(new CapabilityContext($agent, $run)))->map->name()->sort()->values()->all();

        expect($names)->toBe(['comms_send_email', 'knowledge_browse', 'knowledge_read', 'memory_search', 'tasks_ask_human', 'tasks_create', 'tasks_list', 'tasks_update_status']);
    });
});

it('dispatches due routines once per minute, in Maputo time', function () {
    Queue::fake();
    $this->travelTo(now()->setTimezone('Africa/Maputo')->setTime(6, 30)->utc());

    asTenant($this->tenant, function () {
        $agent = Agent::factory()->create();
        AgentRoutine::factory()->create(['agent_id' => $agent->id, 'schedule' => '30 6 * * *', 'prompt' => 'Briefing']);
        AgentRoutine::factory()->create(['agent_id' => $agent->id, 'schedule' => '0 9 * * *']);
    });

    $this->artisan('agents:run-routines')->assertSuccessful();
    $this->artisan('agents:run-routines')->assertSuccessful();

    Queue::assertPushedOn('agents-low', RunAgent::class);
    Queue::assertPushed(RunAgent::class, 1);
    expect(asTenant($this->tenant, fn () => AgentRun::query()->sole()->input))->toBe('Briefing');
});

it('searches memory by keyword without an embeddings provider', function () {
    config(['ai.providers.openai.key' => null]);

    asTenant($this->tenant, function () {
        $kb = app(KnowledgeBase::class);
        $kb->remember(KnowledgeType::Decision, 'Preço do cimento', 'A direcção decidiu congelar o preço do cimento até Dezembro.', null);
        $kb->remember(KnowledgeType::MeetingBrief, 'Reunião de obra', 'Atraso na entrega de varão.', null);

        $hits = $kb->search('cimento');

        expect($hits)->toHaveCount(1)
            ->and($hits->first()['item']->title)->toBe('Preço do cimento');
    });
});

it('sends email from the agent mailbox to a known contact without approval', function () {
    Mail::fake();
    $this->tenant->update(['domain' => 'micomoc.co.mz']);

    asTenant($this->tenant->fresh(), function () {
        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->level(AutonomyLevel::ExecuteWithinLimits)->create();
        $agent->capabilities()->attach(Capability::query()->where('key', 'comms.send_email')->value('id'), ['enabled' => true]);
        Mailbox::factory()->create(['agent_id' => $agent->id, 'address' => 'triagem@micomoc.co.mz']);

        GenericAgent::fake([
            new ToolCall('1', 'comms_send_email', ['to' => ['direccao@micomoc.co.mz'], 'subject' => 'Resumo', 'body' => 'Tudo em ordem.']),
            'Enviado.',
        ]);

        $run = app(AgentRunner::class)->create($agent, 'Envia o resumo à direcção.', TriggerType::Manual);
        app(AgentRunner::class)->run($run);

        expect($run->fresh()->status)->toBe(RunStatus::Completed)
            ->and(AuditLog::query()->where('action', 'comms.send_email')->sole()->payload['arguments']['to'])->toBe(['direccao@micomoc.co.mz']);
    });

    Mail::assertSent(AgentMessage::class, fn (AgentMessage $mail) => $mail->fromAddress === 'triagem@micomoc.co.mz' && $mail->hasTo('direccao@micomoc.co.mz'));
});
