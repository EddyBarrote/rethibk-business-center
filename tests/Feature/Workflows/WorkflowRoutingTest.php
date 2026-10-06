<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\Local\SendEmail;
use App\Ai\Runs\AgentRunner;
use App\Email\InboundEmailIngestor;
use App\Enums\AutonomyLevel;
use App\Enums\EmailCategory;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Enums\WorkflowRunStatus;
use App\Models\AgentRun;
use App\Models\EmailMessage;
use App\Models\EmailRoute;
use App\Models\Mailbox;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Workflows\WorkflowGraph;
use App\Workflows\WorkflowReadiness;
use App\Workflows\WorkflowTemplates;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

// Who handles each kind of email after triage, and what each block of a flow
// will do with what the agents have (docs/DECISOES.md, "Fluxos de trabalho").

beforeEach(function () {
    Storage::fake((string) config('mail_ingest.disk'));
    Mail::fake();
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create(['settings' => ['mail_domain' => 'agentes.micomoc.test']]);

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        $this->ana = User::factory()->create(['name' => 'Ana Sitoe', 'role' => 'manager']);
        $this->triage = templateAgent('triage');
        $this->manager = templateAgent('client_manager', ['reports_to_user_id' => $this->ana->id]);
        $this->mailbox = Mailbox::query()->where('agent_id', $this->triage->id)->sole();
    });
});

afterEach(fn () => @unlink($this->store));

function lastInbound(): int
{
    return (int) EmailMessage::query()->where('direction', 'inbound')->max('id');
}

it('starts the active flow for a client\'s request for a quotation, with the flow\'s agent', function () {
    GenericAgent::fake([
        toolCall('t1', 'email_classify', fn () => ['email_id' => lastInbound(), 'category' => 'client_rfq', 'confidence' => 0.93, 'priority' => 'high', 'summary' => 'A Cimentos do Púnguè pede cotação para reabilitar o cais.']),
        toolCall('m1', 'workflow_complete_step', ['outcome' => 'done', 'summary' => 'Li o pedido: 400 m² de cais.']),
        'Pedido lido.',
        'Passado ao Gestor de Clientes.',
    ]);

    asTenant($this->tenant, function () {
        Workflow::query()->create([
            'name' => 'Responder a pedido de cotação',
            'email_category' => EmailCategory::ClientRfq,
            'agent_id' => $this->manager->id,
            'status' => 'active',
            'graph' => ['nodes' => [
                ['id' => 's', 'type' => 'trigger', 'data' => ['label' => 'Email']],
                ['id' => 'read', 'type' => 'agent', 'data' => ['label' => 'Ler o pedido', 'capability' => 'documents.read_attachment']],
                ['id' => 'e', 'type' => 'end', 'data' => ['label' => 'Fim']],
            ], 'edges' => [['source' => 's', 'target' => 'read'], ['source' => 'read', 'target' => 'e']]],
        ]);

        $email = app(InboundEmailIngestor::class)->ingest($this->mailbox, mailFixture('lead'));
        $task = Task::query()->sole();
        $run = WorkflowRun::query()->sole();
        $managerRun = AgentRun::query()->where('agent_id', $this->manager->id)->sole();

        expect($task->assignee_agent_id)->toBe($this->manager->id)
            ->and($task->user_id)->toBe($this->ana->id)
            ->and($run->email_message_id)->toBe($email->id)
            ->and($run->status)->toBe(WorkflowRunStatus::Completed)
            ->and($managerRun->input)->toContain('Fluxo «Responder a pedido de cotação», passo «Ler o pedido»')
            ->and($managerRun->input)->toContain("email_id {$email->id}")
            ->and($task->status)->toBe(TaskStatus::InReview);
    });
});

it('follows the rule in Definições, and keeps the email with triage when the rule names no agent', function () {
    GenericAgent::fake([
        toolCall('t1', 'email_classify', fn () => ['email_id' => lastInbound(), 'category' => 'client_request', 'confidence' => 0.9, 'priority' => 'normal', 'summary' => 'Avaria no ar condicionado.']),
        'Classificado.',
    ]);

    asTenant($this->tenant, function () {
        EmailRoute::query()->create(['category' => EmailCategory::ClientRequest, 'agent_id' => null, 'fallback_user_id' => $this->ana->id]);

        app(InboundEmailIngestor::class)->ingest($this->mailbox, mailFixture('client-request'));

        expect(Task::query()->count())->toBe(0)
            ->and(EmailMessage::query()->find(lastInbound())->hasFlag('handed_off'))->toBeFalse();
    });
});

it('says what each block will do with what the agents have', function () {
    asTenant($this->tenant, function () {
        $graph = new WorkflowGraph(['nodes' => [
            ['id' => 'read', 'type' => 'agent', 'data' => ['label' => 'Procurar', 'capability' => 'erp.crm.search_accounts']],
            ['id' => 'lead', 'type' => 'agent', 'data' => ['label' => 'Registar', 'capability' => 'erp.leads.create']],
            ['id' => 'send', 'type' => 'agent', 'data' => ['label' => 'Enviar a proposta ao cliente', 'capability' => 'comms.send_email']],
            ['id' => 'rfq', 'type' => 'agent', 'data' => ['label' => 'Pedir preços', 'capability' => 'erp.procurement.create_rfq']],
            ['id' => 'quote', 'type' => 'agent', 'data' => ['label' => 'Orçamentar', 'capability' => 'erp.quotes.create']],
            ['id' => 'skill', 'type' => 'agent', 'data' => ['label' => 'Orçamentar', 'skill' => 'orcamentacao']],
            ['id' => 'ok', 'type' => 'approval', 'data' => ['label' => 'Aprovar']],
            ['id' => 'loop', 'type' => 'loop', 'data' => ['label' => 'Para cada']],
        ], 'edges' => []]);

        $check = fn () => collect(app(WorkflowReadiness::class)->check($graph, $this->manager->fresh()))->map(fn ($row) => $row['state'])->all();

        // N2 and no Chief of Staff: a risk-3 action goes to a person.
        expect($check())->toBe(['read' => 'alone', 'lead' => 'person', 'send' => 'person', 'rfq' => 'missing', 'quote' => 'missing', 'skill' => 'missing', 'ok' => 'person', 'loop' => 'flow']);

        templateAgent('chief_of_staff');
        expect($check()['lead'])->toBe('chief');

        $this->manager->update(['autonomy_level' => AutonomyLevel::ExecuteWithinLimits]);
        expect($check()['lead'])->toBe('alone')
            ->and($check()['send'])->toBe('person');
    });
});

it('installs the example flow for a client\'s request for a quotation, valid and checked against the agents', function () {
    asTenant($this->tenant, function () {
        templateAgent('procurement');

        [$workflow] = app(WorkflowTemplates::class)->install();
        $graph = new WorkflowGraph($workflow->graph);
        $states = collect(app(WorkflowReadiness::class)->check($graph, $this->manager))->map(fn ($row) => $row['state']);

        expect($graph->problems())->toBe([])
            ->and($workflow->agent_id)->toBe($this->manager->id)
            ->and($workflow->fallback_user_id)->toBe($this->ana->id)
            ->and(Workflow::activeFor(EmailCategory::ClientRfq)?->is($workflow))->toBeTrue()
            ->and($states['read'])->toBe('alone')
            ->and($states['price'])->toBe('missing')
            ->and($states['send'])->toBe('person')
            ->and($states['ask_supplier'])->not->toBe('missing')
            ->and(app(WorkflowTemplates::class)->install())->toBe([]);
    });
});

it('sends a price proposal to a known client only with a person\'s decision', function () {
    asTenant($this->tenant, function () {
        $run = app(AgentRunner::class)->create($this->manager, 'teste', TriggerType::Manual);
        $context = new CapabilityContext($this->manager, $run);
        $send = app(SendEmail::class);
        $internal = ['to' => [$this->ana->email], 'subject' => 'Proposta', 'body' => 'Total: 1 200 000 MZN.'];

        expect(SendEmail::isPriceProposal('Proposta para o cais da Beira. Valor total: 3.500.000,00 MZN'))->toBeTrue()
            ->and(SendEmail::isPriceProposal('Segue a acta da reunião de ontem.'))->toBeFalse()
            ->and(SendEmail::isPriceProposal('Envio a proposta técnica revista.'))->toBeFalse()
            ->and($send->ceilingReason($internal, $context))->toBeNull();
    });
});
