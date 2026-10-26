<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\AgentRunner;
use App\Enums\EmailCategory;
use App\Enums\TriggerType;
use App\Enums\WorkflowStatus;
use App\Models\EmailMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Workflows\WorkflowDrafter;

// The Chief of Staff suggests flows (docs/DECISOES.md, "Chief of Staff sugere fluxos").

beforeEach(function () {
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create(['name' => 'Edy']);
        $this->chief = templateAgent('chief_of_staff', ['reports_to_user_id' => $this->owner->id]);
        $this->finance = templateAgent('finance');
    });
});

afterEach(fn () => @unlink($this->store));

it('shows the Chief of Staff the kinds of email, who handles them and which have a flow', function () {
    asTenant($this->tenant, function () {
        EmailMessage::factory()->count(3)->create(['direction' => 'inbound', 'classification' => EmailCategory::SupplierInvoice]);

        $result = runCapability($this->chief, 'workflows.overview');
        $invoices = collect($result->data['email_kinds'])->firstWhere('email_category', 'supplier_invoice');

        expect($invoices['emails'])->toBe(3)
            ->and($invoices['handled_by'])->toBe($this->finance->name)
            ->and($invoices['active_flow'])->toBeNull()
            ->and(CapabilityRegistry::CHIEF_OF_STAFF)->toContain('workflows.overview', 'workflows.propose');
    });
});

it('saves the suggested flow as a draft that does nothing until a person activates it', function () {
    WorkflowDrafter::fake([[
        'name' => 'Registar facturas de fornecedores',
        'summary' => 'Lê a factura, confere com a encomenda e pede aprovação.',
        'trigger' => 'email',
        'email_category' => 'supplier_invoice',
        'agent' => 'finance',
        'notes' => ['Falta dizer a partir de que valor a factura precisa de aprovação.'],
        'steps' => [['ref' => 'ler', 'type' => 'agent', 'label' => 'Ler a factura', 'next' => 'end']],
    ]]);

    asTenant($this->tenant, function () {
        $result = runCapability($this->chief, 'workflows.propose', [
            'description' => 'Quando chega uma factura de fornecedor, o Agente de Finanças lê-a e confere com a encomenda.',
            'email_category' => 'supplier_invoice',
            'why' => '3 facturas este mês sem fluxo.',
        ]);
        $workflow = Workflow::query()->sole();

        expect($result->ok)->toBeTrue()
            ->and($workflow->status)->toBe(WorkflowStatus::Draft)
            ->and($workflow->agent_id)->toBe($this->finance->id)
            ->and($workflow->email_category)->toBe(EmailCategory::SupplierInvoice)
            ->and($workflow->created_by_user_id)->toBe($this->owner->id)
            ->and($workflow->description)->toContain('Sugerido por Chief of Staff: 3 facturas')
            ->and(Workflow::activeFor(EmailCategory::SupplierInvoice))->toBeNull();

        // Not a second flow for a kind that already has an active one.
        $workflow->forceFill(['status' => WorkflowStatus::Active])->save();
        $again = app(CapabilityRegistry::class)->find('workflows.propose')->precheck(['description' => 'x', 'email_category' => 'supplier_invoice'], new CapabilityContext($this->chief, app(AgentRunner::class)->create($this->chief, 'x', TriggerType::Manual)));

        expect($again)->toContain('Já há um fluxo activo');
    });
});
