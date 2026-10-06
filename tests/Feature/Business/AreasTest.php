<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Capabilities\Local\CompareQuotes;
use App\Ai\Capabilities\Local\MatchCandidate;
use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Clients\ClientSheetBuilder;
use App\Email\InboundEmailIngestor;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\Contract;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\PlatformAdmin;
use App\Models\SupplierRating;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

// E06 (procurement), E07 (HR) and E08 (client manager) acceptance, plus the
// agent templates the super admin installs for a new tenant.

beforeEach(function () {
    Storage::fake((string) config('mail_ingest.disk'));
    Mail::fake();
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create(['settings' => ['mail_domain' => 'agentes.micomoc.test']]);

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        $this->member = User::factory()->create(['role' => 'member']);
    });
});

afterEach(fn () => @unlink($this->store));

it('installs the six agent templates once, each with its mailbox, from the super admin console', function () {
    $this->actingAs(PlatformAdmin::factory()->create(), 'admin')
        ->post(adminUrl("tenants/{$this->tenant->id}/agents/templates"))
        ->assertRedirect()->assertSessionHas('success');

    asTenant($this->tenant, function () {
        app(TemplateInstaller::class)->install(AgentTemplates::find('finance'));

        expect(Agent::query()->pluck('settings')->pluck('template')->sort()->values()->all())->toBe(['chief_of_staff', 'client_manager', 'finance', 'hr', 'procurement', 'triage'])
            ->and(Agent::query()->where('key', 'triage')->sole()->autonomy_level)->toBe(AutonomyLevel::from(AgentTemplates::find('triage')->autonomy->value))
            ->and(Mailbox::query()->pluck('address')->sort()->values()->all())->toBe([
                'clientes@agentes.micomoc.test', 'compras@agentes.micomoc.test', 'direccao@agentes.micomoc.test',
                'financas@agentes.micomoc.test', 'rh@agentes.micomoc.test', 'triagem@agentes.micomoc.test',
            ])
            ->and(Agent::query()->where('key', 'finance')->sole()->capabilities()->pluck('key')->all())->toContain('bank.import_statement', 'finance.project_margins');
    });
});

it('weighs supplier history when comparing quotes', function () {
    asTenant($this->tenant, function () {
        $quotes = [
            ['supplier_id' => 'SUP-0001', 'supplier_name' => 'Barato', 'total' => 100000, 'delivery_days' => 10],
            ['supplier_id' => 'SUP-0002', 'supplier_name' => 'Fiável', 'total' => 104000, 'delivery_days' => 10],
        ];

        expect(CompareQuotes::rank($quotes)['recommended']['supplier_id'])->toBe('SUP-0001');

        SupplierRating::factory()->create(['supplier_ref' => 'SUP-0001', 'on_time' => 1, 'quality' => 2, 'price' => 3]);
        SupplierRating::factory()->create(['supplier_ref' => 'SUP-0002', 'on_time' => 5, 'quality' => 5, 'price' => 4]);

        $ranked = CompareQuotes::rank($quotes);
        expect($ranked['recommended']['supplier_id'])->toBe('SUP-0002')
            ->and($ranked['recommended']['why'])->toContain('4% acima da mais barata');
    });
});

it('scores a CV received by email against the opening requirements', function () {
    asTenant($this->tenant, function () {
        $hr = templateAgent('hr');
        $mailbox = Mailbox::query()->where('agent_id', $hr->id)->sole();
        GenericAgent::fake(['Lido.']);
        $email = app(InboundEmailIngestor::class)->ingest($mailbox, mailFixture('job-application'));

        $result = json_decode(runCapability($hr, 'hr.match_candidate', [
            'email_id' => $email->id,
            'requirements' => ['Curso técnico de electromecânica', 'Experiência em geradores', 'Carta de condução', 'Inglês', 'Certificação em soldadura'],
        ])->content, true);

        expect($result['missing'])->toBe(['Certificação em soldadura'])
            ->and($result['score'])->toBeGreaterThanOrEqual(70);

        expect(MatchCandidate::match(['Python'], 'Experiência em Java')['score'])->toBe(0);
    });
});

it('builds the client sheet from the ERP and the platform', function () {
    asTenant($this->tenant, function () {
        Contract::factory()->create(['party_ref' => 'ACC-0002', 'title' => 'Manutenção do hotel']);
        EmailMessage::factory()->create(['from_address' => 'c.nhantumbo@baiaazul.co.mz', 'subject' => 'Avaria no gerador', 'classification' => 'client_request']);
        EmailMessage::factory()->create(['from_address' => 'alguem@outra.co.mz']);

        $sheet = app(ClientSheetBuilder::class)->build('ACC-0002');

        expect($sheet['account']['name'])->toBe('Hotel Baía Azul, SA')
            ->and(array_column($sheet['receivables'], 'id'))->toContain('INV-0001')
            ->and(array_column($sheet['contracts'], 'title'))->toBe(['Manutenção do hotel'])
            ->and(array_column($sheet['recent_emails'], 'subject'))->toBe(['Avaria no gerador'])
            ->and($sheet['projects'])->not->toBeEmpty();
    });

});
