<?php

use App\Ai\Agents\InstructionComposer;
use App\Erp\ErpCallResult;
use App\Erp\ErpDialect;
use App\Erp\ErpFiles;
use App\Erp\ErpGateway;
use App\Erp\Perfex\PerfexAdapter;
use App\Erp\Perfex\PerfexToolMap;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Capability;
use App\Models\ErpConnection;
use App\Models\Task;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// The live Rethink ERP is Perfex-based: its tools have Perfex names. These
// tests answer the Perfex reads from canned data, as the server shapes them.

/**
 * @param  array<string, Closure(array<string, mixed>): array<string, mixed>>  $answers
 * @param  list<array{0: string, 1: array<string, mixed>}>  $calls
 */
function perfex(array $answers, array &$calls = []): PerfexAdapter
{
    return new PerfexAdapter(function (string $tool, array $arguments) use ($answers, &$calls): ErpCallResult {
        $calls[] = [$tool, $arguments];

        if (! isset($answers[$tool])) {
            return new ErpCallResult($tool, false, null, "Ferramenta {$tool} não prevista no teste.", 1);
        }

        return new ErpCallResult($tool, true, $answers[$tool]($arguments), '', 1);
    });
}

function page(array $items): array
{
    return ['total' => count($items), 'limit' => 100, 'offset' => 0, 'items' => $items];
}

it('tells the Perfex ERP from the contract by its tool names', function () {
    expect(ErpDialect::fromToolNames(['whoami', 'list_customers', 'get_customer']))->toBe(ErpDialect::Perfex)
        ->and(ErpDialect::fromToolNames(['erp.whoami', 'crm.get_account']))->toBe(ErpDialect::Contract)
        ->and(ErpDialect::fromToolNames([]))->toBe(ErpDialect::Contract);
});

it('reads the dialect from the tools the connection last listed', function () {
    $tenant = Tenant::factory()->create();

    asTenant($tenant, function () {
        $connection = ErpConnection::factory()->create(['capabilities' => [['name' => 'list_customers', 'title' => null, 'description' => null, 'read_only' => true]]]);
        expect(app(ErpGateway::class)->dialect())->toBe(ErpDialect::Perfex);

        $connection->forceFill(['capabilities' => null])->save();
        expect(app(ErpGateway::class)->dialect())->toBe(ErpDialect::Contract);
    });
});

it('builds receivables from open Perfex invoices with days overdue', function () {
    Carbon::setTestNow('2026-10-07');

    $calls = [];
    $adapter = perfex(['list_invoices' => fn (array $a) => page(match ($a['status']) {
        1 => [['id' => '10', 'formatted_number' => 'INV-000010', 'customer_id' => '3', 'customer' => 'Cliente A', 'date' => '2026-10-01', 'duedate' => '2026-10-31', 'total' => '1160.00', 'total_left_to_pay' => '1160.00', 'currency' => 'MZN', 'status' => '1', 'status_label' => 'unpaid']],
        3 => [['id' => '11', 'formatted_number' => 'INV-000011', 'customer_id' => '3', 'customer' => 'Cliente A', 'date' => '2026-08-01', 'duedate' => '2026-09-27', 'total' => '2000.00', 'total_left_to_pay' => '500.00', 'currency' => 'MZN', 'status' => '3', 'status_label' => 'partially_paid']],
        4 => [],
    })], $calls);

    $result = $adapter->call('invoices.list_receivables', ['account_id' => '3']);

    expect($result->ok)->toBeTrue()
        ->and($result->data['total_outstanding'])->toBe(1660.0)
        ->and($result->data['receivables'][1])->toMatchArray(['number' => 'INV-000011', 'account_id' => '3', 'outstanding' => 500.0, 'days_overdue' => 10])
        ->and($result->data['receivables'][0]['days_overdue'])->toBe(0)
        ->and(array_column(array_column($calls, 1), 'customer_id'))->each->toBe(3);
});

it('asks only for overdue invoices when told so', function () {
    $calls = [];
    perfex(['list_invoices' => fn () => page([])], $calls)->call('invoices.list_receivables', ['overdue_only' => true]);

    expect($calls)->toHaveCount(1)->and($calls[0][1]['status'])->toBe(4);
});

it('builds a client sheet from the Perfex customer, contacts, projects and balance', function () {
    $adapter = perfex([
        'get_customer' => fn () => ['userid' => '3', 'company' => 'Cliente A', 'vat' => '400118274', 'phonenumber' => '+258 84', 'city' => 'Maputo', 'active' => '1', 'default_currency' => 'MZN',
            'contacts' => [['id' => '7', 'firstname' => 'Ana', 'lastname' => 'Cossa', 'email' => 'ana@cliente-a.co.mz', 'is_primary' => '1']]],
        'list_projects' => fn () => page([['id' => '5', 'name' => 'Rede', 'customer_id' => '3', 'status' => '2']]),
        'list_invoices' => fn (array $a) => page($a['status'] === 4 ? [['id' => '12', 'customer_id' => '3', 'total' => '300', 'total_left_to_pay' => '300', 'duedate' => '2026-09-01']] : []),
    ]);

    $result = $adapter->call('crm.get_account', ['account_id' => '3']);

    expect($result->ok)->toBeTrue()
        ->and($result->data['account'])->toMatchArray(['id' => '3', 'name' => 'Cliente A', 'nuit' => '400118274', 'email' => 'ana@cliente-a.co.mz'])
        ->and($result->data['contacts'][0]['name'])->toBe('Ana Cossa')
        ->and($result->data['projects'][0])->toMatchArray(['id' => '5', 'status' => 'in_progress'])
        ->and($result->data['balance_due'])->toBe(300.0);
});

it('works out spending and invoicing per project for the margins', function () {
    $adapter = perfex([
        'list_projects' => fn (array $a) => page($a['status'] === 2 ? [['id' => '5', 'name' => 'Rede', 'customer_id' => '3', 'status' => '2']] : []),
        'get_project' => fn () => ['id' => 5, 'project_cost' => '10000.00'],
        'list_expenses' => fn () => page([['amount' => '2500.50'], ['amount' => '1000']]),
        'list_invoices' => fn () => page([['subtotal' => '5000', 'status' => '2'], ['subtotal' => '900', 'status' => '6'], ['subtotal' => '700', 'status' => '5']]),
    ]);

    $result = $adapter->call('projects.list', ['status' => 'in_progress']);

    expect($result->data['projects'][0])->toMatchArray(['id' => '5', 'budget' => 10000.0, 'spent' => 3500.5, 'invoiced' => 5000.0, 'status' => 'in_progress']);
});

it('returns a Perfex error as a failed result, not an exception', function () {
    $result = perfex([])->call('projects.list_by_account', ['account_id' => '3']);

    expect($result->ok)->toBeFalse()->and($result->error())->toContain('list_projects');
});

it('refuses an identifier that is not a Perfex number', function () {
    $result = perfex([])->call('crm.get_account', ['account_id' => 'ACC-0001']);

    expect($result->ok)->toBeFalse()->and($result->error())->toContain('ACC-0001');
});

it('maps every contract tool and names the ones Perfex lacks', function () {
    expect(PerfexToolMap::capabilitiesFor('erp.crm.search_accounts'))->toBe(['erp.list_customers'])
        ->and(PerfexToolMap::capabilitiesFor('erp.erp.whoami'))->toBe(['erp.whoami'])
        ->and(PerfexToolMap::capabilitiesFor('erp.quotes.create'))->toContain('erp.create_estimate')
        ->and(PerfexToolMap::missing())->toContain('hr.prepare_payroll_draft')->not->toContain('crm.get_account');
});

it('tells an agent on a Perfex ERP which tools stand in for those its instructions name', function () {
    $tenant = Tenant::factory()->create();

    $prompt = asTenant($tenant, function () {
        ErpConnection::factory()->create(['capabilities' => [['name' => 'list_customers', 'title' => null, 'description' => null, 'read_only' => true]]]);
        $agent = Agent::factory()->create(['instructions' => 'Procura o cliente com erp.crm.search_accounts e cria a lead com erp.leads.create. Prepara a folha com erp.hr.prepare_payroll_draft.']);
        $agent->capabilities()->attach(Capability::factory()->create(['key' => 'erp.list_customers', 'mcp_tool_name' => 'list_customers'])->id, ['enabled' => true]);

        return app(InstructionComposer::class)->for($agent->fresh());
    });

    expect($prompt)->toContain('## Ferramentas do ERP')
        ->toContain('- erp.crm.search_accounts: usa erp.list_customers.')
        ->toContain('- erp.leads.create: no ERP é erp.create_lead')
        ->toContain('- erp.hr.prepare_payroll_draft: ainda não existe no ERP');
});

it('leaves the prompt alone on the contract ERP', function () {
    $tenant = Tenant::factory()->create();

    $prompt = asTenant($tenant, function () {
        ErpConnection::factory()->local()->create();
        $agent = Agent::factory()->create(['instructions' => 'Procura o cliente com erp.crm.search_accounts.']);

        return app(InstructionComposer::class)->for($agent);
    });

    expect($prompt)->not->toContain('## Ferramentas do ERP');
});

it('refuses the smoke test, which writes, against the live ERP', function () {
    $tenant = Tenant::factory()->create(['slug' => 'perfex-live']);

    asTenant($tenant, fn () => ErpConnection::factory()->create(['capabilities' => [['name' => 'list_customers', 'title' => null, 'description' => null, 'read_only' => true]]]));

    $this->artisan('erp:smoke', ['tenant' => 'perfex-live'])->expectsOutputToContain('ligado ao ERP real')->assertFailed();
});

it('attaches a file the ERP made to the reply, fetched only from the ERP host', function () {
    Http::fake([
        'erp.example.test/api/mcp/files/*' => Http::response('%PDF-1.4 factura', 200, ['Content-Type' => 'application/pdf']),
        '*' => Http::response('nope', 500),
    ]);
    $tenant = Tenant::factory()->create();

    [$attachment, $foreign] = asTenant($tenant, function () {
        ErpConnection::factory()->create(['base_url' => 'https://erp.example.test/api/mcp']);
        $run = AgentRun::factory()->create(['task_id' => Task::factory()->create()->id]);
        $files = app(ErpFiles::class);

        return [
            $files->attach(['file_name' => 'INV-2026067.pdf', 'download_url' => 'https://erp.example.test/api/mcp/files/f_1'], $run),
            $files->attach(['file_name' => 'x.pdf', 'download_url' => 'https://elsewhere.example/files/f_1'], $run),
        ];
    });

    expect($attachment)->not->toBeNull()
        ->and($attachment->filename)->toBe('INV-2026067.pdf')
        ->and($attachment->source)->toBe('erp')
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($foreign)->toBeNull();
    Http::assertSentCount(1);
});
