<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Skills\Local\ProjectMargins;
use App\Email\InboundEmailIngestor;
use App\Enums\BankTransactionStatus;
use App\Finance\BankReconciler;
use App\Finance\BankStatementImporter;
use App\Models\AgentRun;
use App\Models\BankStatement;
use App\Models\BankTransaction;
use App\Models\EmailAttachment;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\Data\ToolCall;

// E05 acceptance (section 19): a bank statement comes in, the finance agent
// proposes the matches against the ERP and a person confirms them.

beforeEach(function () {
    Storage::fake((string) config('mail_ingest.disk'));
    Mail::fake();
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create(['settings' => ['mail_domain' => 'agentes.micomoc.test']]);
    $this->csv = file_get_contents(base_path('docs/exemplos/extracto-bci-2026-09.csv'));

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        $this->member = User::factory()->create(['role' => 'member']);
        $this->finance = templateAgent('finance');
    });
});

afterEach(fn () => @unlink($this->store));

function hotelTransfer(): BankTransaction
{
    return BankTransaction::query()->where('description', 'like', 'TRF HOTEL%')->sole();
}

it('reads Portuguese CSV statements and never imports a movement twice', function () {
    asTenant($this->tenant, function () {
        $importer = app(BankStatementImporter::class);
        $first = $importer->importCsv($this->csv, ['account_name' => 'BCI MZN', 'source' => 'upload']);
        $again = $importer->importCsv($this->csv, ['account_name' => 'BCI MZN', 'source' => 'upload']);

        expect($first['imported'])->toBe(5)
            ->and($again)->imported->toBe(0)->skipped->toBe(5)
            ->and($first['statement']->closing_balance)->toBe(4389150.0)
            ->and(BankTransaction::query()->where('description', 'like', 'COMBUSTIVEIS%')->sole()->amount)->toBe(-48600.0)
            ->and(BankTransaction::query()->where('description', 'like', 'TRF HOTEL%')->sole()->amount)->toBe(1160000.0);

        $english = "Date,Description,Amount,Balance\n2026-09-02,Card fee,-120.50,1000.00\n2026-09-03,Deposit,1500,2500.00\n";
        expect($importer->importCsv($english, ['account_name' => 'Millennium USD', 'source' => 'upload'])['imported'])->toBe(2)
            ->and($importer->amount('1.234.567,89'))->toBe(1234567.89)
            ->and($importer->amount('(2.500,00)'))->toBe(-2500.0);

        expect(fn () => $importer->importCsv("nada,a,ver\n1,2,3", ['account_name' => 'X', 'source' => 'upload']))->toThrow(InvalidArgumentException::class);
    });
});

it('offers the ERP invoices a credit can settle, by number or amount', function () {
    asTenant($this->tenant, function () {
        app(BankStatementImporter::class)->importCsv($this->csv, ['account_name' => 'BCI MZN', 'source' => 'upload']);
        $open = collect(app(BankReconciler::class)->openWithCandidates())->keyBy('description');

        expect($open['TRF HOTEL BAIA AZUL FT 2026/118']['candidates'][0])->invoice_id->toBe('INV-0001')->why->toBe('número e montante')
            ->and($open['TRF AGRO ZAMBEZE']['candidates'][0])->invoice_id->toBe('INV-0002')->why->toBe('montante igual ao em dívida')
            ->and($open['COMBUSTIVEIS DO SAVE']['candidates'])->toBe([]);
    });
});

it('imports the statement emailed to the finance mailbox and suggests the matches for a person to confirm', function () {
    GenericAgent::fake([
        toolCall('f1', 'bank_import_statement', fn () => ['attachment_id' => lastId(EmailAttachment::class), 'account_name' => 'BCI conta à ordem MZN', 'bank' => 'BCI']),
        new ToolCall('f2', 'bank_unreconciled', []),
        toolCall('f3', 'bank_suggest_match', fn () => ['transaction_id' => hotelTransfer()->id, 'match_type' => 'invoice', 'match_ref' => 'INV-0001', 'note' => 'Número FT 2026/118 e montante iguais.']),
        'Extracto importado; 1 reconciliação proposta.',
    ]);

    asTenant($this->tenant, function () {
        $mailbox = Mailbox::query()->where('agent_id', $this->finance->id)->sole();
        app(InboundEmailIngestor::class)->ingest($mailbox, mailFixture('bank-statement'));

        expect(EmailAttachment::query()->sole()->filename)->toBe('extracto-bci-2026-09.csv');

        expect(BankStatement::query()->sole())->source->toBe('email')->transaction_count->toBe(5)
            ->and(hotelTransfer())->status->toBe(BankTransactionStatus::Suggested)->match_ref->toBe('INV-0001')
            ->and(AgentRun::query()->latest('id')->first()->status->value)->toBe('completed');
    });

    $owner = asTenant($this->tenant, fn () => $this->owner);

    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, 'finance'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Finance/Index')->where('totals.suggested', 1)->where('transactions.data.0.match_ref', 'INV-0001'));

    $transfer = asTenant($this->tenant, fn () => hotelTransfer()->id);
    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, "finance/transactions/{$transfer}"), ['action' => 'confirm'])->assertRedirect();

    asTenant($this->tenant, fn () => expect(hotelTransfer())
        ->status->toBe(BankTransactionStatus::Reconciled)
        ->reconciled_by_user_id->toBe($this->owner->id));
});

it('lets managers upload a CSV, which wakes the finance agent, and keeps members out', function () {
    GenericAgent::fake(['Vou propor as reconciliações.']);
    [$owner, $member] = asTenant($this->tenant, fn () => [$this->owner, $this->member]);
    $file = UploadedFile::fake()->createWithContent('extracto.csv', $this->csv);

    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, 'finance'))->assertForbidden();
    $this->actingAs($member, 'web')->post(tenantUrl($this->tenant, 'finance/statements'), ['account_name' => 'BCI', 'file' => $file])->assertForbidden();

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'finance/statements'), ['account_name' => 'BCI', 'file' => $file])
        ->assertRedirect()->assertSessionHas('success', '5 movimento(s) importado(s).');

    asTenant($this->tenant, fn () => expect(AgentRun::query()->sole())->agent_id->toBe($this->finance->id));
});

it('flags projects over budget or under the minimum margin, with per-tenant thresholds', function () {
    asTenant($this->tenant, function () {
        $projects = [
            ['id' => 'P1', 'name' => 'Obra boa', 'budget' => 1000, 'spent' => 500, 'invoiced' => 1000],
            ['id' => 'P2', 'name' => 'Obra apertada', 'budget' => 1000, 'spent' => 950, 'invoiced' => 1000],
        ];

        expect(ProjectMargins::analyse($projects)['alerts'])->toBe([
            'Obra apertada: orçamento consumido 95% (alerta a 90%)',
            'Obra apertada: margem sobre facturado 5% (mínimo 15%)',
        ]);

        $this->tenant->update(['settings' => [...$this->tenant->settings, 'business' => ['min_margin_pct' => 60, 'budget_alert_pct' => 99]]]);
        Tenant::current()->refresh();

        expect(ProjectMargins::analyse($projects)['alerts'])->toBe([
            'Obra boa: margem sobre facturado 50% (mínimo 60%)',
            'Obra apertada: margem sobre facturado 5% (mínimo 60%)',
        ]);

        $live = json_decode(runSkill($this->finance, 'finance.project_margins')->content, true);
        expect($live['projects'])->not->toBeEmpty();
    });
});
