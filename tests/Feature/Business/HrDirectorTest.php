<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Agents\InstructionComposer;
use App\Ai\Autonomy\SensitiveData;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\RoutineRunner;
use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Enums\AgentStatus;
use App\Enums\AutonomyLevel;
use App\Enums\RunStatus;
use App\Enums\StepType;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\ErpConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\ToolCall;

// The HR Director (docs/DECISOES.md, "Director de Recursos Humanos", 19.11.2026):
// the hr template on a Perfex ERP, bringing an existing agent up to date, and a
// routine run against the fake ERP. The guard on pay and personal data is in
// PeopleDataTest.

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'rethink-test']);

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create(['name' => 'Edy Barrote']);
        $this->member = User::factory()->create(['role' => 'member', 'name' => 'Nádia Tembe']);
    });
});

describe('on the live Perfex ERP', function () {
    beforeEach(function () {
        $this->tools = PERFEX_BASE_TOOLS + PERFEX_HR_TOOLS;
        $this->results = [];
        perfexServer($this->tools, $this->results);
        asTenant($this->tenant, fn () => ErpConnection::factory()->create());
    });

    it('gives a template agent the reads for its contract tools, never the writes', function () {
        $keys = asTenant($this->tenant, function () {
            app(CapabilityCatalog::class)->syncLocal();
            app(CapabilityCatalog::class)->syncErp();

            // This tenant was always on Perfex: the contract keys were never catalogued.
            expect(Capability::query()->where('key', 'like', 'erp.hr.%')->exists())->toBeFalse();

            $result = app(TemplateInstaller::class)->install(AgentTemplates::find('hr'));

            expect($result['missing_capabilities'])->toContain('erp.hr.list_openings', 'erp.hr.update_employee')
                ->not->toContain('erp.hr.attendance_today', 'erp.hr.list_payroll_runs');

            return $result['agent']->capabilities()->pluck('key')->all();
        });

        expect($keys)->toContain('erp.att_today', 'erp.att_who_is_out', 'erp.hr_upcoming', 'erp.pay_list_runs', 'erp.pay_payslip_pdf', 'erp.hr_list_employees', 'erp.list_staff')
            ->not->toContain('erp.hr_update_employee', 'erp.att_decide_leave_request', 'erp.list_customers', 'erp.create_lead');
    });

    it('hands out at a sync only the reads the ERP started listing, never one an admin took away', function () {
        $this->tools = PERFEX_BASE_TOOLS;

        asTenant($this->tenant, function () {
            $catalog = app(CapabilityCatalog::class);
            $catalog->syncLocal();
            $catalog->syncErp();
            $agent = app(TemplateInstaller::class)->install(AgentTemplates::find('hr'))['agent'];
            $keys = fn () => $agent->capabilities()->pluck('key')->all();

            expect($keys())->toContain('erp.list_staff')->not->toContain('erp.att_today');

            // The HR tools are ticked on the token: the next sync hands their reads out, and says so.
            $this->tools = PERFEX_BASE_TOOLS + PERFEX_HR_TOOLS;
            $catalog->syncErp();
            expect($keys())->toContain('erp.att_today', 'erp.pay_get_run')->not->toContain('erp.hr_update_employee')
                ->and(AuditLog::query()->where('action', 'agent.capabilities_granted_by_sync')->sole()->payload['capabilities'])->toContain('erp.att_today');

            // An admin takes one away in Agentes › Editar, which syncs the ones the form keeps (AgentEditor).
            $agent->capabilities()->sync(array_fill_keys($agent->capabilities()->where('key', '!=', 'erp.att_today')->pluck('capabilities.id')->all(), ['enabled' => true]));
            $catalog->syncErp();
            expect($keys())->not->toContain('erp.att_today');

            // A tool the ERP starts listing later still arrives.
            $this->tools['att_month_status'] = true;
            $catalog->syncErp();
            expect($keys())->toContain('erp.att_month_status')->not->toContain('erp.att_today')
                ->and(AuditLog::query()->where('action', 'agent.capabilities_granted_by_sync')->latest('id')->first()->payload['capabilities'])->toBe(['erp.att_month_status']);
        });
    });

    it('never keeps a payslip link it could not attach, in the trace or the audit', function () {
        $this->results['pay_payslip_pdf'] = ['file_name' => 'recibo-2026-10.pdf', 'download_url' => 'https://erp.example.test/api/mcp/files/f_9', 'expires_at' => '2026-11-19T10:00:00+02:00'];

        asTenant($this->tenant, function () {
            app(CapabilityCatalog::class)->syncLocal();
            app(CapabilityCatalog::class)->syncErp();
            $agent = app(TemplateInstaller::class)->install(AgentTemplates::find('hr'))['agent'];

            // Asked by someone who manages people, but outside a conversation: nowhere to attach the PDF.
            $result = throughGate($agent, 'erp.pay_payslip_pdf', ['period' => '2026-10', 'staff_id' => 4], fn (AgentRun $run) => $run->requested_by_user_id = $this->owner->id);
            $trace = AgentRun::query()->latest('id')->first()->steps()->where('type', StepType::ToolResult)->sole()->payload['content'];
            $audit = AuditLog::query()->where('action', 'erp.tool_call')->where('payload->tool', 'pay_payslip_pdf')->sole();

            expect($result)->toContain('recibo-2026-10.pdf')->toContain('O PDF só se anexa numa conversa ou tarefa')->not->toContain('f_9')
                ->and($trace)->not->toContain('f_9')
                ->and(json_encode($audit->payload))->not->toContain('f_9')->toContain('dados de pessoal');
        });
    });

    it('says in the prompt which HR tools the agent has, and which the ERP has but the token does not list yet', function () {
        $this->tools = PERFEX_BASE_TOOLS + ['att_today' => true, 'pay_list_runs' => true];

        $prompt = asTenant($this->tenant, function () {
            app(CapabilityCatalog::class)->syncLocal();
            app(CapabilityCatalog::class)->syncErp();
            $agent = app(TemplateInstaller::class)->install(AgentTemplates::find('hr'))['agent'];
            $agent->capabilities()->detach(Capability::query()->where('key', 'erp.pay_list_runs')->value('id'));

            return app(InstructionComposer::class)->for($agent->fresh());
        });

        expect($prompt)->toContain('- erp.hr.attendance_today: usa erp.att_today.')
            ->toContain('- erp.hr.list_payroll_runs: no ERP é erp.pay_list_runs, erp.pay_get_run, erp.pay_annual_summary, erp.pay_irps_sheet_pdf, que não tens: pede a quem te gere')
            ->toContain('- erp.hr.upcoming_dates: no ERP é erp.hr_upcoming, que não tens: ainda não está ligada a esta plataforma')
            ->toContain('- erp.hr.list_openings: ainda não existe no ERP')
            ->toContain('recibo de vencimento, contrato de trabalho, carta, folha de IRPS ou declaração anual de rendimentos')
            ->toContain('pay_payslip_pdf');
    });

    it('brings the agent of the old template up to date, only when asked, and keeps what the admin set', function () {
        asTenant($this->tenant, function () {
            app(CapabilityCatalog::class)->syncLocal();
            app(CapabilityCatalog::class)->syncErp();

            // The agent as the old template made it, routines switched off, plus the admin's own additions.
            $agent = Agent::factory()->create(['key' => 'hr', 'name' => 'Agente de Recursos Humanos', 'instructions' => 'Prepara a folha com erp.hr.prepare_payroll_draft. Regra da casa: avisar a Dona Ana.',
                'settings' => ['template' => 'hr'], 'autonomy_level' => AutonomyLevel::Suggest, 'status' => AgentStatus::Active]);
            $agent->capabilities()->attach(Capability::query()->where('key', 'erp.list_customers')->value('id'), ['enabled' => true]);
            foreach (['Assiduidade do mês' => '0 8 1 * *', 'Preparar folha de salários' => '0 8 25 * *', 'Férias pendentes' => '0 8 * * 1', 'Aniversários da equipa' => '0 9 * * *'] as $name => $schedule) {
                $agent->routines()->create(['name' => $name, 'prompt' => 'Antiga.', 'schedule' => $schedule, 'is_active' => false]);
            }
        });

        // Without --refresh the command leaves an existing agent alone, as before.
        $this->artisan('agents:install-templates', ['tenant' => 'rethink-test', '--only' => ['hr']])->assertSuccessful();
        expect(asTenant($this->tenant, fn () => Agent::query()->where('key', 'hr')->sole()->name))->toBe('Agente de Recursos Humanos');

        $this->artisan('agents:install-templates', ['tenant' => 'rethink-test', '--refresh' => ['hr']])
            ->expectsOutputToContain('Director de Recursos Humanos actualizado a partir do modelo hr.')
            ->assertSuccessful();

        asTenant($this->tenant, function () {
            $agent = Agent::query()->where('key', 'hr')->sole();
            $template = AgentTemplates::find('hr');
            $routines = $agent->routines()->pluck('is_active', 'name');

            expect($agent)->name->toBe('Director de Recursos Humanos')->instructions->toBe($template->instructions)->personality->toBe($template->personality)
                ->autonomy_level->toBe(AutonomyLevel::Suggest)
                ->and($agent->capabilities()->pluck('key')->all())->toContain('erp.list_customers', 'erp.att_today', 'erp.pay_list_runs', 'reports.draft')->not->toContain('erp.hr_update_employee')
                ->and($routines->all())->toEqualCanonicalizing([
                    'Assiduidade do mês' => false, 'Preparar folha de salários' => false, 'Férias pendentes' => false, 'Aniversários da equipa' => false,
                    'Datas de RH' => false, 'Assiduidade do dia' => false, 'Resumo semanal de assiduidade' => false, 'Conferir a folha de salários' => false,
                ])
                ->and($agent->routines()->where('name', 'Assiduidade do mês')->sole()->prompt)->toContain('erp.hr.attendance_summary')
                ->and($agent->routines()->where('name', 'Aniversários da equipa')->sole()->prompt)->toBe('Antiga.');

            $audit = AuditLog::query()->where('action', 'agent.refreshed_from_template')->sole();
            expect($audit->payload['changed'])->toContain('name', 'instructions')
                // What the admin had written can be put back from the audit.
                ->and($audit->payload['previous'])->toMatchArray(['name' => 'Agente de Recursos Humanos', 'instructions' => 'Prepara a folha com erp.hr.prepare_payroll_draft. Regra da casa: avisar a Dona Ana.'])
                ->and($audit->payload['routines_created'])->toHaveCount(4)
                ->and($audit->payload['routines_updated'])->toBe(['Assiduidade do mês']);
        });
    });
});

it('switches on new routines and off the retired ones when the agent\'s routines are on', function () {
    $this->store = freshFakeErp();

    asTenant($this->tenant, function () {
        $agent = templateAgent('hr');
        AgentRoutine::query()->delete();
        $agent->routines()->create(['name' => 'Preparar folha de salários', 'prompt' => 'Antiga.', 'schedule' => '0 8 25 * *', 'is_active' => true]);
        $agent->routines()->create(['name' => 'Férias pendentes', 'prompt' => 'Antiga.', 'schedule' => '0 8 * * 1', 'is_active' => false]);

        $result = app(TemplateInstaller::class)->refresh(AgentTemplates::find('hr'));

        expect($result['routines_deactivated'])->toBe(['Preparar folha de salários'])
            ->and($agent->routines()->pluck('is_active', 'name')->all())->toMatchArray([
                'Preparar folha de salários' => false, 'Férias pendentes' => false, 'Datas de RH' => true, 'Conferir a folha de salários' => true,
            ])
            ->and(AgentRoutine::query()->count())->toBe(7);
    });

    @unlink($this->store);
});

it('refuses to refresh a template that has no agent in the tenant', function () {
    $this->artisan('agents:install-templates', ['tenant' => 'rethink-test', '--refresh' => ['hr']])
        ->expectsOutputToContain('Não há nenhum agente do modelo hr')
        ->assertFailed();

    $this->artisan('agents:install-templates', ['tenant' => 'rethink-test', '--refresh' => ['rh']])->assertFailed();
    expect(asTenant($this->tenant, fn () => Agent::query()->count()))->toBe(0);
});

describe('with the fake ERP', function () {
    beforeEach(function () {
        $this->store = freshFakeErp();
        // The platform's clock; the fake ERP runs in its own process with the real one, so ERP calls name their dates.
        $this->travelTo(Carbon::parse('2026-11-19 09:30', 'Africa/Maputo'));

        asTenant($this->tenant, function () {
            $this->agent = templateAgent('hr', ['reports_to_user_id' => $this->owner->id]);
        });
    });

    afterEach(fn () => @unlink($this->store));

    it('never updates an HR record without a person, whatever its level', function () {
        asTenant($this->tenant, function () {
            $this->agent->update(['autonomy_level' => AutonomyLevel::ExecuteAndReport]);
            $chat = app(TaskThread::class)->conversation($this->owner, $this->agent);

            $held = throughGate($this->agent->fresh(), 'erp.hr.update_employee', ['employee_id' => 'EMP-0004', 'id_expiry_date' => '2036-11-26'], function (AgentRun $run) use ($chat) {
                $run->task_id = $chat->id;
                $run->requested_by_user_id = $this->owner->id;
            });

            expect($held)->toContain('Acção suspensa e enviada para aprovação humana')
                ->and(Approval::query()->sole()->ceiling_reason)->toBe('Contratação, cessação ou alteração contratual');
        });

        expect(config('autonomy.ceiling'))->toMatchArray([
            'erp.hr.update_employee' => 'Contratação, cessação ou alteração contratual',
            'erp.hr_update_employee' => 'Contratação, cessação ou alteração contratual',
            'erp.att_decide_leave_request' => 'Aprovação no ERP, reservada a pessoas',
            'erp.hr_decide_expense_claim' => 'Aprovação no ERP, reservada a pessoas',
            'erp.hr_mark_expense_claim_paid' => 'Saída de dinheiro ou instrução de pagamento',
        ]);
    });

    it('reports the day\'s absences to its chefia from its routine, worded so nothing is held', function () {
        Queue::fake();

        asTenant($this->tenant, function () {
            $routine = $this->agent->routines()->where('name', 'Assiduidade do dia')->sole();
            app(RoutineRunner::class)->run($routine);
            $run = AgentRun::query()->sole();

            GenericAgent::fake([
                new ToolCall('a1', 'erp_hr_attendance_today', ['date' => '2026-11-18']),
                new ToolCall('a2', 'erp_hr_attendance_today', ['date' => '2026-11-19']),
                new ToolCall('a3', 'erp_hr_list_leave', ['status' => 'approved', 'from' => '2026-11-18', 'to' => '2026-11-19']),
                new ToolCall('a4', 'notify_user', ['title' => 'Assiduidade de 19/11', 'body' => "Abel Cossa: ausente hoje, sem licença aprovada.\nErcília Mabunda: chegou tarde ontem.\nNádia Tembe: de licença."]),
                'Notifiquei a chefia: uma falta sem licença hoje e um atraso ontem.',
            ]);
            (new RunAgent($this->tenant->id, $run->id))->handle(app(AgentRunner::class));

            $run = $run->fresh();
            $results = $run->steps()->where('type', StepType::ToolResult)->pluck('payload')->pluck('content')->implode("\n");

            expect($run->status)->toBe(RunStatus::Completed)
                ->and($run->agent_routine_id)->toBe($routine->id)
                ->and($run->steps()->where('type', StepType::Error)->exists())->toBeFalse()
                ->and($results)->toContain('"employee":"Abel Cossa"')->toContain('"status":"absent"')->toContain('"status":"late"')
                ->and($this->owner->notifications()->where('data->title', 'Assiduidade de 19/11')->exists())->toBeTrue()
                ->and(Approval::query()->exists())->toBeFalse();
        });
    });
});

it('reads an absence report for health and identity data only when it carries them', function () {
    $check = app(SensitiveData::class);

    expect($check->find(['body' => "Abel Cossa: ausente hoje, sem licença aprovada.\nNádia Tembe: de licença."]))->toBe([])
        ->and($check->find(['body' => 'Nádia Tembe está de baixa médica.']))->toBe(['dados de saúde'])
        ->and($check->find(['body' => 'O BI 110700456789E de Abel Cossa expira a 26/11.']))->toBe(['um número de BI']);
});
