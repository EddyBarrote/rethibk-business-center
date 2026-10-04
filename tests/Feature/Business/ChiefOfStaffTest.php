<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Agents\InstructionComposer;
use App\Ai\Knowledge\KnowledgeBase;
use App\Enums\KnowledgeType;
use App\Enums\RunStatus;
use App\Mail\BriefingMail;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Briefing;
use App\Models\Contract;
use App\Models\Department;
use App\Models\EmailMessage;
use App\Models\PurchaseRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Responses\Data\ToolCall;

// E04 acceptance (section 19): at 06:30 on a working day the briefing reaches
// its reader and opens in the console with links to what needs a decision.

beforeEach(function () {
    Mail::fake();
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $general = Department::factory()->create(['name' => 'Direcção-Geral', 'slug' => 'direccao-geral']);
        $this->director = User::factory()->owner()->create(['department_id' => $general->id, 'email' => 'dg@micomoc.test']);
        $this->cos = templateAgent('chief_of_staff');
    });
});

afterEach(fn () => @unlink($this->store));

it('is scheduled at 06:30 on working days and weekly on Mondays', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'agents:daily-briefing'));
    $daily = $events->first(fn ($e) => ! str_contains((string) $e->command, '--weekly'));
    $weekly = $events->first(fn ($e) => str_contains((string) $e->command, '--weekly'));

    expect($daily->expression)->toBe('30 6 * * 1-5')
        ->and($daily->timezone)->toBe('Africa/Maputo')
        ->and($weekly->expression)->toBe('0 7 * * 1');
});

it('writes the daily briefing, delivers it by email and console, and links the decisions', function () {
    asTenant($this->tenant, fn () => Contract::factory()->create(['title' => 'Manutenção do hotel', 'ends_at' => today()->addDays(20)]));

    GenericAgent::fake([
        new ToolCall('c1', 'platform_overview', ['hours' => 24]),
        new ToolCall('c2', 'platform_detect_issues', []),
        new ToolCall('c3', 'briefings_publish', [
            'type' => 'daily',
            'title' => 'Briefing de '.today()->format('d/m/Y'),
            'content' => "## Precisa de decisão hoje\n- Renovar o contrato de manutenção do hotel (termina em 20 dias).\n\n## Números\n| Indicador | Valor |\n|---|---|\n| Em atraso | 2.334.400 MT |",
            'highlights' => ['Contrato do hotel termina em 20 dias.'],
            'decisions_pending' => [['title' => 'Renovar o contrato de manutenção do hotel', 'link' => '/contracts/1']],
        ]),
        'Briefing publicado.',
    ]);

    $this->artisan('agents:daily-briefing')->expectsOutputToContain('1 briefing(s) pedido(s)')->assertSuccessful();

    asTenant($this->tenant, function () {
        $briefing = Briefing::query()->sole();

        expect(AgentRun::query()->sole()->status)->toBe(RunStatus::Completed)
            ->and($briefing->for_user_id)->toBe($this->director->id)
            ->and($briefing->decisions_pending[0]['link'])->toBe('/contracts/1')
            ->and($briefing->delivered_at)->not->toBeNull()
            ->and($this->director->notifications()->sole()->data['url'])->toBe("/briefings/{$briefing->id}");
    });

    Mail::assertSent(BriefingMail::class, fn (BriefingMail $mail) => $mail->hasTo('dg@micomoc.test') && str_contains($mail->render(), '/contracts/1'));

    $director = asTenant($this->tenant, fn () => $this->director);

    $this->actingAs($director, 'web')->get(tenantUrl($this->tenant, '/'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->where('briefing.decisions_pending.0.link', '/contracts/1')->has('issues'));

    $briefing = asTenant($this->tenant, fn () => lastId(Briefing::class));
    $this->actingAs($director, 'web')->get(tenantUrl($this->tenant, "briefings/{$briefing}"))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Briefings/Show')->where('briefing.title', 'Briefing de '.today()->format('d/m/Y')));

    expect(asTenant($this->tenant, fn () => Briefing::query()->sole()->read_at))->not->toBeNull();
});

it('finds blockers and inconsistencies across areas', function () {
    asTenant($this->tenant, function () {
        EmailMessage::factory()->create(['classification' => 'lead', 'status' => 'processed', 'erp_lead_id' => null, 'subject' => 'Proposta sem lead']);
        PurchaseRequest::factory()->create(['status' => 'ordered', 'erp_po_id' => null, 'title' => 'Cimento']);
        Approval::factory()->create(['created_at' => now()->subDays(2), 'action_summary' => 'Enviar email ao cliente']);

        $issues = collect(json_decode(runSkill($this->cos, 'platform.detect_issues')->content, true)['issues']);

        expect($issues->pluck('area')->all())->toContain('Comercial', 'Compras', 'Aprovações')
            ->and($issues->firstWhere('area', 'Comercial')['link'])->toStartWith('/inbox/');
    });
});

it('gives every agent the decisions recorded with RememberDecision', function () {
    asTenant($this->tenant, function () {
        app(KnowledgeBase::class)->remember(KnowledgeType::Decision, 'Não aceitar obras abaixo de 15% de margem', 'Decidido pela Direcção-Geral em 04/10/2026.', $this->director);
        $finance = templateAgent('finance');

        expect(app(InstructionComposer::class)->for($finance))
            ->toContain('## Decisões em vigor')
            ->toContain('Não aceitar obras abaixo de 15% de margem');
    });
});
