<?php

use App\Ai\Agents\GenericAgent;
use App\Enums\EmailCategory;
use App\Insights\SlaMonitor;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\Tenant;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// The scheduled watchers behind E03 to E08: they look for something due,
// tell the right person once and wake the agent that should act.

beforeEach(function () {
    Mail::fake();
    GenericAgent::fake(['Tratado.']);
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create(['slug' => 'micomoc', 'settings' => [
        'tender_sources' => [['name' => 'Portal de concursos', 'url' => 'https://concursos.example.test/lista', 'keywords' => 'concurso, ajuste directo', 'active' => true]],
    ]]);

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        $this->agents = collect(['triage', 'finance', 'procurement', 'client_manager'])->mapWithKeys(fn ($t) => [$t => templateAgent($t)]);
    });
});

afterEach(fn () => @unlink($this->store));

function runsOf(Tenant $tenant, string $template): int
{
    return asTenant($tenant, fn () => AgentRun::query()->whereHas('agent', fn ($q) => $q->where('settings->template', $template))->count());
}

it('scans the tender sources once per link and hands the new ones to triage', function () {
    Http::fake(['concursos.example.test/*' => Http::response(<<<'HTML'
        <ul>
          <li><a href="/c/1">Concurso público n.º 12/2026: reabilitação de escolas em Sofala</a></li>
          <li><a href="https://concursos.example.test/c/2">Ajuste directo para fornecimento de geradores</a></li>
          <li><a href="/sobre">Sobre o portal de compras do Estado</a></li>
        </ul>
        HTML)]);

    $this->artisan('agents:scan-tenders')->expectsOutputToContain('micomoc: 2 concurso(s) novo(s).')->assertSuccessful();
    $this->artisan('agents:scan-tenders')->expectsOutputToContain('micomoc: 0 concurso(s) novo(s).')->assertSuccessful();

    asTenant($this->tenant, fn () => expect(Tender::query()->pluck('url')->all())->toBe(['https://concursos.example.test/c/1', 'https://concursos.example.test/c/2']));
    expect(runsOf($this->tenant, 'triage'))->toBe(1);
});

it('warns once about deadlines coming up', function () {
    asTenant($this->tenant, function () {
        EmailMessage::factory()->create(['deadline_at' => now()->addHours(20), 'subject' => 'Proposta até amanhã']);
        EmailMessage::factory()->create(['deadline_at' => now()->addDays(10)]);
        Tender::factory()->create(['deadline_at' => now()->addHours(30)]);
    });

    $this->artisan('agents:watch-deadlines')->expectsOutputToContain('2 aviso(s) de prazo.')->assertSuccessful();
    $this->artisan('agents:watch-deadlines')->expectsOutputToContain('0 aviso(s) de prazo.')->assertSuccessful();

    asTenant($this->tenant, fn () => expect($this->owner->notifications()->count())->toBe(2));
});

it('wakes the agent that scheduled a follow-up when it falls due', function () {
    asTenant($this->tenant, fn () => FollowUp::factory()->create(['agent_id' => $this->agents['finance']->id, 'due_at' => now()->subMinute(), 'title' => 'Voltar a cobrar a INV-0001']));

    $this->artisan('followups:notify')->expectsOutputToContain('1 seguimento(s) avisado(s).')->assertSuccessful();
    $this->artisan('followups:notify')->expectsOutputToContain('0 seguimento(s) avisado(s).')->assertSuccessful();

    expect(runsOf($this->tenant, 'finance'))->toBe(1);
});

it('flags contracts inside their notice period and sends them to the right agent', function () {
    asTenant($this->tenant, function () {
        Contract::factory()->create(['title' => 'Manutenção do hotel', 'ends_at' => today()->addDays(30), 'owner_user_id' => $this->owner->id]);
        Contract::factory()->create(['party_type' => 'supplier', 'party_ref' => 'SUP-0001', 'party_name' => 'Ferragens da Beira', 'ends_at' => today()->addDays(10), 'notice_days' => 15]);
        Contract::factory()->create(['ends_at' => today()->addYear()]);
    });

    $this->artisan('agents:watch-contracts')->expectsOutputToContain('2 contrato(s) assinalado(s).')->assertSuccessful();
    $this->artisan('agents:watch-contracts')->expectsOutputToContain('0 contrato(s) assinalado(s).')->assertSuccessful();

    expect(runsOf($this->tenant, 'client_manager'))->toBe(1)
        ->and(runsOf($this->tenant, 'procurement'))->toBe(1);
});

it('raises client requests left unanswered past the SLA, using the contract SLA when there is one', function () {
    asTenant($this->tenant, function () {
        Contract::factory()->create(['party_domain' => 'baiaazul.co.mz', 'sla_response_hours' => 4]);
        $request = ['direction' => 'inbound', 'classification' => EmailCategory::ClientRequest->value];
        EmailMessage::factory()->create([...$request, 'from_address' => 'c.nhantumbo@baiaazul.co.mz', 'received_at' => now()->subHours(5)]);
        EmailMessage::factory()->create([...$request, 'from_address' => 'geral@outro.co.mz', 'received_at' => now()->subHours(5)]);
        EmailMessage::factory()->create([...$request, 'from_address' => 'geral@outro.co.mz', 'received_at' => now()->subHours(30)]);

        expect(collect(app(SlaMonitor::class)->pending())->map(fn ($r) => [$r['from'], $r['sla_hours'], $r['breached']])->all())->toBe([
            ['geral@outro.co.mz', 24, true],
            ['c.nhantumbo@baiaazul.co.mz', 4, true],
            ['geral@outro.co.mz', 24, false],
        ]);
    });

    $this->artisan('agents:watch-sla')->expectsOutputToContain('2 pedido(s) fora do SLA.')->assertSuccessful();
    $this->artisan('agents:watch-sla')->expectsOutputToContain('0 pedido(s) fora do SLA.')->assertSuccessful();

    expect(runsOf($this->tenant, 'client_manager'))->toBe(2);
});

it('asks finance to chase receivables and rolls up the day cost', function () {
    $this->artisan('agents:chase-receivables')->expectsOutputToContain('1 pedido(s) ao agente de finanças.')->assertSuccessful();
    $this->artisan('agents:cost-rollup')->assertSuccessful();

    asTenant($this->tenant, fn () => expect(AuditLog::query()->where('action', 'ai.cost_rollup')->sole()->payload['agents'][0]['runs'])->toBe(1));
});

it('exports everything a tenant owns and nothing of another', function () {
    $other = Tenant::factory()->create();
    asTenant($other, fn () => Contract::factory()->create(['title' => 'Contrato alheio']));
    asTenant($this->tenant, fn () => Contract::factory()->create(['title' => 'Contrato nosso']));
    $path = sys_get_temp_dir().'/export-'.uniqid().'.zip';

    $this->artisan('tenant:export', ['tenant' => 'micomoc', '--path' => $path])->assertSuccessful();

    $zip = new ZipArchive;
    $zip->open($path);
    $contracts = json_decode($zip->getFromName('data/contracts.json'), true);
    $zip->close();
    @unlink($path);

    expect(array_column($contracts, 'title'))->toBe(['Contrato nosso']);
});
