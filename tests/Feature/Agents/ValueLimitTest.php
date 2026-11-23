<?php

use App\Ai\Agents\InstructionComposer;
use App\Ai\Autonomy\ApprovalRules;
use App\Ai\Autonomy\AutonomyGate;
use App\Ai\Autonomy\GateDecision;
use App\Ai\Autonomy\ValueAmount;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Tools\GatedTool;
use App\Enums\ApprovalStatus;
use App\Enums\AutonomyLevel;
use App\Enums\CapabilitySource;
use App\Enums\RuleEffect;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Limite de valor por agente".

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();
});

/** An agent at N4 (so only the limit can hold it), with the value limit given in its settings. */
function limitedAgent(?array $valueLimit = null, AutonomyLevel $level = AutonomyLevel::ExecuteAndReport): Agent
{
    return Agent::factory()->level($level)->create($valueLimit === null ? [] : ['settings' => ['value_limit' => $valueLimit]]);
}

function erpWrite(string $key, AutonomyLevel $risk = AutonomyLevel::ExecuteWithinLimits): Capability
{
    return Capability::query()->where('key', $key)->first()
        ?? Capability::factory()->create(['key' => $key, 'name' => $key, 'is_mutating' => true, 'risk' => $risk]);
}

function valueGate(Agent $agent, string $key, array $arguments): GateDecision
{
    return app(AutonomyGate::class)->evaluate(erpWrite($key), $arguments, new CapabilityContext($agent, AgentRun::factory()->create(['agent_id' => $agent->id])));
}

/** A Perfex purchase order of $quantity × $price MZN. */
function order(float $quantity, float $price, array $extra = []): array
{
    return ['vendor_id' => 7, 'items' => [['name' => 'Cimento', 'quantity' => $quantity, 'unit_price' => $price]], ...$extra];
}

it('reads the amount from totals, lines, discounts, adjustments and shipping', function (array $arguments, ?float $amount) {
    expect(ValueAmount::read($arguments)->amount)->toBe($amount);
})->with([
    'an expense amount' => [['category' => 1, 'amount' => 125000, 'date' => '2026-11-23'], 125000.0],
    'a quote total' => [['rfq_id' => 'RFQ-1', 'supplier_id' => 'SUP-1', 'total' => '80000.50'], 80000.5],
    'Perfex lines, qty × rate' => [['customer_id' => 3, 'items' => [['description' => 'A', 'qty' => 2, 'rate' => 30000], ['description' => 'B', 'rate' => 5000]]], 65000.0],
    'contract lines, quantity × unit_price' => [['account_id' => 'ACC-1', 'lines' => [['description' => 'A', 'quantity' => 3, 'unit_price' => 1000]]], 3000.0],
    'line discount, document discount, adjustment and shipping' => [[
        'items' => [['name' => 'A', 'quantity' => 10, 'unit_price' => 1000, 'discount_percent' => 10]],
        'discount_percent' => 50, 'discount_fixed' => 500, 'adjustment' => 250, 'shipping_fee' => 1000,
    ], 5250.0],
    'the larger of a total and the lines' => [['total' => 100, 'items' => [['description' => 'A', 'qty' => 1, 'rate' => 90000]]], 90000.0],
    'a line without a price' => [['items' => [['item_id' => 4, 'quantity' => 3]]], null],
    'nothing at all' => [['id' => 12], null],
]);

it('reads the currency: meticais by default, a code, or the ERP\'s currency id', function () {
    expect(ValueAmount::read(['amount' => 1])->currency)->toBe('MZN')
        ->and(ValueAmount::read(['amount' => 1, 'currency' => 'mt'])->currency)->toBe('MZN')
        ->and(ValueAmount::read(['amount' => 1, 'currency' => 'usd'])->currency)->toBe('USD')
        ->and(ValueAmount::read(['amount' => 1, 'currency' => 2])->label())->toBe('1 (moeda 2 do ERP)')
        ->and(ValueAmount::read(['id' => 3, 'duedate' => '2026-12-01'])->carriesValue)->toBeFalse()
        ->and(ValueAmount::format(125000))->toBe('125 000')
        ->and(ValueAmount::format(1234.5))->toBe('1 234,50');
});

it('lets an action below the limit through, and holds one above it with the amount and the limit', function () {
    asTenant($this->tenant, function () {
        $agent = limitedAgent(['amount' => 50000]);

        $below = valueGate($agent, 'erp.create_purchase_order', order(10, 4000));
        $equal = valueGate($agent, 'erp.create_purchase_order', order(10, 5000));
        $above = valueGate($agent, 'erp.create_purchase_order', order(25, 5000));

        expect($below->allowed)->toBeTrue()->and($below->valueLimitReason)->toBeNull()
            ->and($equal->allowed)->toBeTrue()
            ->and($above->allowed)->toBeFalse()
            ->and($above->denied)->toBeFalse()
            ->and($above->ceilingReason)->toBeNull()
            ->and($above->valueLimitReason)->toBe('Valor 125 000 MZN acima do limite de 50 000 MZN deste agente')
            ->and($above->reason())->toBe('Limite de valor: Valor 125 000 MZN acima do limite de 50 000 MZN deste agente.');
    });
});

it('changes nothing without a limit, or for a capability that commits no value', function () {
    asTenant($this->tenant, function () {
        $agent = limitedAgent();

        expect(valueGate($agent, 'erp.create_purchase_order', order(1000, 1000))->allowed)->toBeTrue()
            ->and(valueGate($agent, 'erp.create_purchase_orders_from_request', ['id' => 4])->allowed)->toBeTrue()
            ->and(valueGate(limitedAgent(['amount' => 10]), 'erp.create_lead', ['title' => 'Obra', 'estimated_value' => 900000])->allowed)->toBeTrue();
    });
});

it('takes the company default, and the agent\'s own limit or type limit over it', function () {
    config(['business.agent_value_limit_mzn' => 50000]);
    $this->tenant->update(['settings' => ['business' => ['agent_value_limit_mzn' => 60000]]]);

    asTenant($this->tenant->fresh(), function () {
        $company = valueGate(limitedAgent(), 'erp.create_expense', ['category' => 1, 'amount' => 70000, 'date' => '2026-11-23']);
        $ownHigher = valueGate(limitedAgent(['amount' => 100000]), 'erp.create_expense', ['category' => 1, 'amount' => 70000, 'date' => '2026-11-23']);
        $ownNone = valueGate(limitedAgent(['amount' => 0]), 'erp.create_expense', ['category' => 1, 'amount' => 900000, 'date' => '2026-11-23']);
        $typed = limitedAgent(['amount' => 20000, 'types' => ['purchases' => 150000]]);

        expect($company->allowed)->toBeFalse()
            // The tenant's value beats config/business.php.
            ->and($company->valueLimitReason)->toBe('Valor 70 000 MZN acima do limite de 60 000 MZN da empresa')
            ->and($ownHigher->allowed)->toBeTrue()
            ->and($ownNone->allowed)->toBeTrue()
            ->and(valueGate($typed, 'erp.create_purchase_order', order(10, 14000))->allowed)->toBeTrue()
            ->and(valueGate($typed, 'erp.create_purchase_order', order(10, 16000))->valueLimitReason)->toBe('Valor 160 000 MZN acima do limite de compras de 150 000 MZN deste agente')
            ->and(valueGate($typed, 'erp.create_invoice', ['customer_id' => 1, 'items' => [['description' => 'Serviço', 'qty' => 1, 'rate' => 25000]]])->valueLimitReason)
            ->toBe('Valor 25 000 MZN acima do limite de 20 000 MZN deste agente');
    });
});

it('holds a value write whose amount it cannot read, with a reason, when a limit is set', function () {
    asTenant($this->tenant, function () {
        $agent = limitedAgent(['amount' => 50000]);
        $fromRequest = valueGate($agent, 'erp.create_purchase_orders_from_request', ['id' => 4]);
        $catalogPrice = valueGate($agent, 'erp.create_purchase_order', ['vendor_id' => 7, 'items' => [['item_id' => 4, 'quantity' => 3]]]);

        expect($fromRequest->allowed)->toBeFalse()
            ->and($fromRequest->valueLimitReason)->toBe('Valor por determinar (o pedido não traz o total nem o preço de cada linha), com o limite de 50 000 MZN deste agente')
            ->and($catalogPrice->allowed)->toBeFalse()
            ->and($catalogPrice->valueLimitReason)->toStartWith('Valor por determinar');
    });
});

it('counts another currency as above the limit unless the platform has its rate', function () {
    asTenant($this->tenant, function () {
        $agent = limitedAgent(['amount' => 50000]);
        $usd = fn (float $amount) => valueGate($agent, 'erp.create_expense', ['category' => 1, 'amount' => $amount, 'date' => '2026-11-23', 'currency' => 'USD']);
        $erpCurrency = valueGate($agent, 'erp.create_expense', ['category' => 1, 'amount' => 10, 'date' => '2026-11-23', 'currency' => 2]);

        expect($usd(100)->allowed)->toBeFalse()
            ->and($usd(100)->valueLimitReason)->toBe('Valor 100 USD, numa moeda sem câmbio para MZN definido na plataforma: conta como acima do limite de 50 000 MZN deste agente')
            ->and($erpCurrency->valueLimitReason)->toContain('Valor 10 (moeda 2 do ERP), numa moeda sem câmbio');

        config(['autonomy.value_limit_rates' => ['USD' => 63.9]]);

        expect($usd(500)->allowed)->toBeTrue()
            ->and($usd(2000)->valueLimitReason)->toBe('Valor 2 000 USD (127 800 MZN) acima do limite de 50 000 MZN deste agente');
    });
});

it('leaves the absolute ceiling as it was: a person decides, whatever the amount', function () {
    asTenant($this->tenant, function () {
        $agent = limitedAgent(['amount' => 1000000]);
        $payment = valueGate($agent, 'erp.create_payment', ['invoice_id' => 5, 'payment_mode' => 1, 'amount' => 10]);
        $creditNote = valueGate($agent, 'erp.create_credit_note', ['customer_id' => 1, 'items' => [['description' => 'A', 'rate' => 999999999]]]);

        expect($payment->allowed)->toBeFalse()
            ->and($payment->ceilingReason)->toBe('Saída de dinheiro ou instrução de pagamento')
            ->and($payment->valueLimitReason)->toBeNull()
            ->and($payment->reason())->toStartWith('Tecto absoluto')
            ->and($creditNote->ceilingReason)->toBe('Crédito a cliente')
            ->and($creditNote->valueLimitReason)->toBeNull();
    });
});

it('is not lifted by «Permitir sempre», and keeps a «Perguntar sempre» rule on the decision', function () {
    asTenant($this->tenant, function () {
        $agent = limitedAgent(['amount' => 50000], AutonomyLevel::Observe);
        ApprovalRule::factory()->create(['agent_id' => $agent->id, 'capability_key' => 'erp.create_invoice', 'effect' => RuleEffect::Allow]);
        $invoice = fn (float $rate) => ['customer_id' => 1, 'items' => [['description' => 'Serviço', 'qty' => 1, 'rate' => $rate]]];

        $below = valueGate($agent, 'erp.create_invoice', $invoice(40000));
        $above = valueGate($agent, 'erp.create_invoice', $invoice(60000));

        expect($below->allowed)->toBeTrue()->and($below->rule?->effect)->toBe(RuleEffect::Allow)
            ->and($above->allowed)->toBeFalse()
            ->and($above->rule)->toBeNull()
            ->and($above->valueLimitReason)->toBe('Valor 60 000 MZN acima do limite de 50 000 MZN deste agente');

        ApprovalRule::query()->update(['effect' => RuleEffect::Ask]);
        $asked = valueGate($agent, 'erp.create_invoice', $invoice(60000));

        expect($asked->rule?->effect)->toBe(RuleEffect::Ask)->and($asked->reason())->toStartWith('Limite de valor:');
    });
});

it('counts an edit only when it carries a value', function () {
    asTenant($this->tenant, function () {
        $agent = limitedAgent(['amount' => 50000]);

        expect(valueGate($agent, 'erp.update_invoice', ['id' => 3, 'duedate' => '2026-12-31'])->allowed)->toBeTrue()
            ->and(valueGate($agent, 'erp.update_invoice', ['id' => 3, 'items' => [['description' => 'A', 'qty' => 1, 'rate' => 70000]]])->valueLimitReason)
            ->toBe('Valor 70 000 MZN acima do limite de 50 000 MZN deste agente')
            ->and(valueGate($agent, 'erp.update_invoice', ['id' => 3, 'adjustment' => 9000])->valueLimitReason)->toStartWith('Valor por determinar');
    });
});

it('sends the approval straight to people, says why on the card, and keeps it out of «Permitir sempre» and bulk approval', function () {
    $store = freshFakeErp();

    [$owner, $finance] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create();
        templateAgent('chief_of_staff', ['autonomy_level' => AutonomyLevel::ExecuteAndReport, 'reports_to_user_id' => $owner->id]);
        $finance = templateAgent('finance', ['autonomy_level' => AutonomyLevel::ExecuteWithinLimits, 'reports_to_user_id' => $owner->id]);
        $finance->update(['settings' => [...$finance->settings, 'value_limit' => ['amount' => 50000]]]);

        return [$owner, $finance->fresh()];
    });

    asTenant($this->tenant, function () use ($finance) {
        $source = fn (AgentRun $run) => $run->forceFill(['input' => 'Regista as despesas do fornecedor: 20000 MZN e 80000 MZN.']);
        $small = throughGate($finance, 'erp.expenses.create', ['description' => 'Gasóleo', 'amount' => 20000, 'date' => '2026-11-23'], $source);
        $large = throughGate($finance, 'erp.expenses.create', ['description' => 'Gerador', 'amount' => 80000, 'date' => '2026-11-23'], $source);
        $approval = Approval::query()->sole();

        expect($small)->not->toContain('aprovação')
            ->and($large)->toContain('Limite de valor: Valor 80 000 MZN acima do limite de 50 000 MZN deste agente.')
            ->and($approval->value_limit_reason)->toBe('Valor 80 000 MZN acima do limite de 50 000 MZN deste agente')
            ->and($approval->ceiling_reason)->toBeNull()
            // A person decides: the Chief of Staff does not revalidate it, and no standing rule comes out of it.
            ->and($approval->review_stage)->toBe(Approval::STAGE_HUMAN)
            ->and($approval->review_agent_id)->toBeNull()
            ->and(ApprovalRules::canAllowAlways($approval))->toBeFalse()
            ->and(Present::approval($approval, true)['value_limit_reason'])->toBe('Valor 80 000 MZN acima do limite de 50 000 MZN deste agente')
            ->and(AuditLog::query()->where('action', 'erp.expenses.create')->where('result', 'denied')->sole()->payload['reason'])
            ->toBe('Limite de valor: Valor 80 000 MZN acima do limite de 50 000 MZN deste agente.');
    });

    $approval = asTenant($this->tenant, fn () => Approval::query()->sole());

    $this->actingAs($owner)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve?always=1"))->assertForbidden();
    $this->actingAs($owner)->post(tenantUrl($this->tenant, 'approvals/approve'), ['ids' => [$approval->id]])->assertSessionHas('error');
    expect(asTenant($this->tenant, fn () => $approval->fresh()->status))->toBe(ApprovalStatus::Pending);

    $this->actingAs($owner)->get(tenantUrl($this->tenant, 'approvals'))
        ->assertInertia(fn (Assert $page) => $page->where('approvals.data.0.value_limit_reason', 'Valor 80 000 MZN acima do limite de 50 000 MZN deste agente'));

    $this->actingAs($owner)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve"))->assertSessionHas('success');
    expect(asTenant($this->tenant, fn () => $approval->fresh()->status))->toBe(ApprovalStatus::Approved);

    @unlink($store);
});

it('tells the agent its limits in its instructions, and the tool descriptions', function () {
    asTenant($this->tenant, function () {
        $write = fn (string $key) => Capability::factory()->create(['key' => $key, 'name' => $key, 'is_mutating' => true, 'source' => CapabilitySource::Mcp, 'risk' => AutonomyLevel::ExecuteWithinLimits]);
        $agent = limitedAgent(['amount' => 50000, 'types' => ['purchases' => 150000]]);
        $agent->capabilities()->sync([$write('erp.create_purchase_order')->id => ['enabled' => true], $write('erp.create_expense')->id => ['enabled' => true]]);
        $plain = limitedAgent(['amount' => 50000]);

        $prompt = app(InstructionComposer::class)->for($agent->fresh());
        $tool = new GatedTool(Capability::query()->where('key', 'erp.create_purchase_order')->sole(), new CapabilityContext($agent, AgentRun::factory()->create(['agent_id' => $agent->id])));

        expect($prompt)->toContain('Limite de valor: compras: 150 000 MZN; despesas: 50 000 MZN, por documento, sem impostos.')
            ->and($prompt)->toContain('nunca dividas uma compra, factura ou despesa em várias')
            ->and($tool->description())->toEndWith('(Acima de 150 000 MZN sem impostos, ou sem valor indicado, precisa de aprovação humana.)')
            // Without a capability that commits value, nothing to say.
            ->and(app(InstructionComposer::class)->for($plain))->not->toContain('Limite de valor');
    });
});

it('lets who edits the agent set its limits, validated and audited, and nobody else', function () {
    [$owner, $member, $agent] = asTenant($this->tenant, fn () => [
        User::factory()->owner()->create(), User::factory()->create(), Agent::factory()->create(['settings' => ['template' => 'procurement']]),
    ]);

    $this->actingAs($member)->put(tenantUrl($this->tenant, "agents/{$agent->id}/value-limit"), ['amount' => 1])->assertForbidden();

    $this->actingAs($owner)->put(tenantUrl($this->tenant, "agents/{$agent->id}/value-limit"), ['amount' => -5, 'types' => ['purchases' => 1.5, 'payments' => 10]])
        ->assertSessionHasErrors(['amount', 'types.purchases', 'types']);
    $this->actingAs($owner)->put(tenantUrl($this->tenant, "agents/{$agent->id}/value-limit"), ['amount' => 2_000_000_000])->assertSessionHasErrors('amount');

    $this->actingAs($owner)->put(tenantUrl($this->tenant, "agents/{$agent->id}/value-limit"), ['amount' => 50000, 'types' => ['purchases' => 150000, 'sales' => null, 'expenses' => '']])
        ->assertSessionHas('success', 'Limite de valor guardado.');

    asTenant($this->tenant, function () use ($agent, $owner) {
        $log = AuditLog::query()->where('action', 'agent.value_limit_updated')->sole();

        expect($agent->fresh()->settings)->toBe(['template' => 'procurement', 'value_limit' => ['amount' => 50000, 'types' => ['purchases' => 150000]]])
            ->and($log->actor_id)->toBe($owner->id)
            ->and($log->payload['before'])->toBeNull()
            ->and($log->payload['after'])->toBe(['amount' => 50000, 'types' => ['purchases' => 150000]]);
    });

    $this->actingAs($owner)->get(tenantUrl($this->tenant, "agents/{$agent->id}/edit"))
        ->assertInertia(fn (Assert $page) => $page->component('Agents/Form')
            ->where('value_limits.amount', 50000)
            ->where('value_limits.types.purchases', 150000)
            ->where('value_limits.types.sales', null)
            ->where('value_limits.company', 0));

    // Emptied: back to the company's limit, and audited again; the same values twice leave no second line.
    $this->actingAs($owner)->put(tenantUrl($this->tenant, "agents/{$agent->id}/value-limit"), ['amount' => null, 'types' => []])->assertSessionHas('success');
    $this->actingAs($owner)->put(tenantUrl($this->tenant, "agents/{$agent->id}/value-limit"), ['amount' => null])->assertSessionHas('success');

    asTenant($this->tenant, fn () => expect($agent->fresh()->settings)->toBe(['template' => 'procurement'])
        ->and(AuditLog::query()->where('action', 'agent.value_limit_updated')->count())->toBe(2));
});

it('lets the super admin set the company default above the other rules\' range, and audits it', function () {
    $admin = PlatformAdmin::factory()->create();
    $profile = ['name' => $this->tenant->name, 'status' => 'active', 'email_retention_days' => 180];

    $this->actingAs($admin, 'admin')->put(adminUrl("tenants/{$this->tenant->id}"), [...$profile, 'business' => ['agent_value_limit_mzn' => 2_000_000_000]])
        ->assertSessionHasErrors('business.agent_value_limit_mzn');
    $this->actingAs($admin, 'admin')->put(adminUrl("tenants/{$this->tenant->id}"), [...$profile, 'business' => ['agent_value_limit_mzn' => 250000, 'min_margin_pct' => 250000]])
        ->assertSessionHasErrors('business.min_margin_pct');
    $this->actingAs($admin, 'admin')->put(adminUrl("tenants/{$this->tenant->id}"), [...$profile, 'business' => ['agent_value_limit_mzn' => 250000]])
        ->assertSessionHas('success');

    expect($this->tenant->fresh()->settings['business'])->toBe(['agent_value_limit_mzn' => 250000]);

    asTenant($this->tenant->fresh(), function () {
        $log = AuditLog::query()->where('action', 'tenant.updated')->sole();

        expect($log->payload['business'])->toBe(['before' => [], 'after' => ['agent_value_limit_mzn' => 250000]])
            ->and(valueGate(limitedAgent(), 'erp.create_expense', ['category' => 1, 'amount' => 300000, 'date' => '2026-11-23'])->valueLimitReason)
            ->toBe('Valor 300 000 MZN acima do limite de 250 000 MZN da empresa');
    });
});
