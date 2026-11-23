<?php

use App\Audit\AuditPresenter;
use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Models\Agent;
use App\Models\AgentCapability;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// Definições › Auditoria (docs/DECISOES.md, "Auditoria pesquisável e
// exportável"): the company's whole trail, filtered and exported as CSV, for
// whoever administers the company; the details of entries with pay and
// personal data only for whoever may also manage people.

/** The CSV of a streamed export, as lines of cells, without the byte order mark. */
function auditCsv(TestResponse $response): array
{
    $body = $response->streamedContent();
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");

    $lines = array_values(array_filter(explode("\r\n", substr($body, 3)), fn (string $line) => $line !== ''));

    return array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), $lines);
}

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-11-23 10:00', 'Africa/Maputo'));
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create(['name' => 'Edy Barrote']);
        $this->member = User::factory()->create(['role' => 'member', 'name' => 'Nádia Tembe']);
        // Administers the company, may not manage people.
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Carlos Tembe', 'permission_overrides' => ['people.manage' => false]]);
        // Administers the company and manages people.
        $this->rosa = User::factory()->create(['role' => 'admin', 'name' => 'Rosa Machava']);

        $this->sales = Agent::factory()->create(['name' => 'Gestor de Clientes']);
        // Holds a people-data capability: a confidential agent.
        $this->director = Agent::factory()->create(['name' => 'Director de RH']);
        $payslips = Capability::factory()->create(['key' => 'erp.hr_get_employee', 'name' => 'Ficha do funcionário', 'mcp_tool_name' => 'hr_get_employee']);
        AgentCapability::factory()->create(['agent_id' => $this->director->id, 'capability_id' => $payslips->id]);
    });
});

it('lists the company\'s whole trail, newest first and in Portuguese, to whoever administers the company', function () {
    $task = asTenant($this->tenant, function () {
        $task = Task::factory()->create(['title' => 'Cotação para a Mozal']);
        AuditLog::record($this->owner, 'task.created', ['task_id' => $task->id], subject: $task);
        AuditLog::record($this->sales, 'erp.tool_call', ['tool' => 'list_customers', 'arguments' => ['q' => 'Mozal']]);
        AuditLog::record(null, 'ai.cost_rollup', ['agents' => 2], AuditResult::Error);

        return $task;
    });

    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'settings/audit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Audit')
            ->where('entries.total', 3)
            ->where('entries.data.0.action', 'ai.cost_rollup')
            ->where('entries.data.0.action_label', 'Custos de IA somados')
            ->where('entries.data.0.actor.name', 'Sistema')
            ->where('entries.data.0.result', 'error')
            ->where('entries.data.0.result_label', 'Erro')
            ->where('entries.data.1.action_label', 'Chamada ao ERP')
            ->where('entries.data.1.actor.name', 'Gestor de Clientes')
            ->where('entries.data.1.actor.type_label', 'Agente')
            ->where('entries.data.1.details.arguments.q', 'Mozal')
            ->where('entries.data.1.withheld', false)
            ->where('entries.data.2.action_label', 'Tarefa criada')
            ->where('entries.data.2.actor.name', 'Edy Barrote')
            ->where('entries.data.2.subject.type_label', 'Tarefa')
            ->where('entries.data.2.subject.name', 'Cotação para a Mozal')
            ->where('entries.data.2.subject.url', "/tasks/{$task->id}")
            ->where('reads_confidential', false)
            ->has('options.actions', 3)
            ->where('options.subjects.0.value', 'task'));
});

it('keeps the trail and its export from whoever does not administer the company', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/audit'))->assertForbidden();
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/audit/export'))->assertForbidden();

    // Managing people alone is not administering the company.
    $this->member->forceFill(['permission_overrides' => ['people.manage' => true]])->save();
    $this->actingAs($this->member->fresh())->get(tenantUrl($this->tenant, 'settings/audit'))->assertForbidden();

    expect(asTenant($this->tenant, fn () => AuditLog::query()->where('action', 'audit.exported')->exists()))->toBeFalse();
});

it('filters by period, person or agent, action or family, result, kind of object and words', function () {
    asTenant($this->tenant, function () {
        $task = Task::factory()->create();
        // Days are the company's (Maputo, UTC+2): 21.11 at 01:00 there is still the 20th in UTC.
        AuditLog::factory()->create(['action' => 'erp.tool_call', 'actor_type' => ActorType::Agent, 'actor_id' => $this->sales->id, 'payload' => ['tool' => 'list_rfqs', 'error' => 'Unknown column'], 'result' => AuditResult::Error, 'created_at' => Carbon::parse('2026-11-20 23:30', 'Africa/Maputo')->utc()]);
        AuditLog::factory()->create(['action' => 'erp.connection_test', 'actor_type' => ActorType::User, 'actor_id' => $this->owner->id, 'created_at' => Carbon::parse('2026-11-21 01:00', 'Africa/Maputo')->utc()]);
        AuditLog::factory()->create(['action' => 'task.created', 'actor_type' => ActorType::User, 'actor_id' => $this->owner->id, 'subject_type' => Task::class, 'subject_id' => $task->id, 'payload' => ['kind' => 'task', 'note' => 'Requisição da Cimpor'], 'created_at' => Carbon::parse('2026-11-22 09:00', 'Africa/Maputo')->utc()]);
        AuditLog::factory()->create(['action' => 'agent.suspended', 'actor_type' => ActorType::User, 'actor_id' => $this->rosa->id, 'subject_type' => Agent::class, 'subject_id' => $this->sales->id, 'payload' => ['reason' => 'Orçamento esgotado'], 'result' => AuditResult::Denied, 'created_at' => Carbon::parse('2026-11-22 10:00', 'Africa/Maputo')->utc()]);
    });

    $actions = fn (string $query) => collect($this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit?'.$query))->assertOk()
        ->viewData('page')['props']['entries']['data'])->pluck('action')->all();

    expect($actions('from=2026-11-21'))->toBe(['agent.suspended', 'task.created', 'erp.connection_test'])
        ->and($actions('to=2026-11-20'))->toBe(['erp.tool_call'])
        ->and($actions('from=2026-11-21&to=2026-11-21'))->toBe(['erp.connection_test'])
        ->and($actions("actor=agent:{$this->sales->id}"))->toBe(['erp.tool_call'])
        ->and($actions("actor=user:{$this->owner->id}"))->toBe(['task.created', 'erp.connection_test'])
        ->and($actions('actor=system'))->toBe([])
        ->and($actions('action=erp.connection_test'))->toBe(['erp.connection_test'])
        ->and($actions('action=erp.'))->toBe(['erp.connection_test', 'erp.tool_call'])
        ->and($actions('action=erp.*'))->toBe(['erp.connection_test', 'erp.tool_call'])
        // Without the dot it is one exact action.
        ->and($actions('action=erp'))->toBe([])
        ->and($actions('result=error'))->toBe(['erp.tool_call'])
        ->and($actions('result=denied'))->toBe(['agent.suspended'])
        ->and($actions('subject=task'))->toBe(['task.created'])
        ->and($actions('subject=agent'))->toBe(['agent.suspended'])
        // Words in the details, with or without accents, in any case, and in the action's name.
        ->and($actions('q=cimpor'))->toBe(['task.created'])
        ->and($actions('q=orçamento'))->toBe(['agent.suspended'])
        ->and($actions('q=list_rfqs'))->toBe(['erp.tool_call'])
        ->and($actions('q=suspenso'))->toBe(['agent.suspended'])
        ->and($actions('action=erp.&result=error&from=2026-11-20'))->toBe(['erp.tool_call']);

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit?action=erp.&result=error'))
        ->assertInertia(fn (Assert $page) => $page->where('filters.action', 'erp.')->where('filters.result', 'error')->where('entries.total', 1));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit?actor=everyone'))->assertSessionHasErrors('actor');
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit?from=21-11-2026'))->assertSessionHasErrors('from');
});

it('keeps each company to its own trail, on the page and in the export', function () {
    $other = Tenant::factory()->create();
    $stranger = asTenant($other, function () {
        $stranger = User::factory()->owner()->create(['name' => 'Outra Empresa']);
        AuditLog::record($stranger, 'settings.brand_updated', ['secret' => 'da outra empresa']);

        return $stranger;
    });
    asTenant($this->tenant, fn () => AuditLog::record($this->owner, 'settings.brand_updated', ['color' => '#0052CC']));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.actor.name', 'Edy Barrote'));
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/audit?actor=user:{$stranger->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0));
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit?q=outra'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0));

    $csv = auditCsv($this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit/export')));
    expect($csv)->toHaveCount(2)
        ->and(implode(' ', $csv[1]))->not->toContain('da outra empresa');

    // And the other company sees only its own.
    $this->actingAs($stranger)->get(tenantUrl($other, 'settings/audit'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.actor.name', 'Outra Empresa'));
});

it('shows what a confidential agent did, or was the object of, without its details to whoever may not manage people', function () {
    $task = asTenant($this->tenant, function () {
        $task = Task::factory()->create(['title' => 'Processo disciplinar do João', 'assignee_agent_id' => $this->director->id]);
        $approval = Approval::factory()->create(['agent_id' => $this->director->id, 'action_type' => 'erp.hr_update_employee']);

        // The agent's own calls.
        AuditLog::record($this->director, 'erp.tool_call', ['tool' => 'hr_list_staff', 'result' => ['salario_base' => 85000]]);
        // A person acting on its task, and on its approval.
        AuditLog::record($this->rosa, 'task.status', ['status' => 'done', 'note' => 'salário revisto'], subject: $task);
        AuditLog::record($this->owner, 'approval.approved', ['approval_id' => $approval->id, 'action' => 'erp.hr_update_employee', 'note' => 'aumento de 10%'], subject: $approval);
        // A people-data tool, whoever called it.
        AuditLog::record(null, 'erp.tool_call', ['tool' => 'hr_get_employee', 'arguments' => ['employee_id' => 7]]);
        // About the agent itself.
        AuditLog::record($this->owner, 'agent.updated', ['changes' => ['instructions']], subject: $this->director);
        // Nothing to do with it.
        AuditLog::record($this->sales, 'erp.tool_call', ['tool' => 'list_customers', 'arguments' => ['q' => 'Mozal']]);

        return $task;
    });

    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'settings/audit'))->assertOk()
        ->assertDontSee('85000')->assertDontSee('salário revisto')->assertDontSee('aumento de 10%')->assertDontSee('Processo disciplinar')
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 6)
            ->where('reads_confidential', false)
            ->where('entries.data.0.action', 'erp.tool_call')
            ->where('entries.data.0.withheld', false)
            ->where('entries.data.0.details.arguments.q', 'Mozal')
            // The agent keeps its name; the edit itself is hidden.
            ->where('entries.data.1.withheld', true)
            ->where('entries.data.1.details', null)
            ->where('entries.data.1.subject.name', 'Director de RH')
            ->where('entries.data.1.subject.url', "/agents/{$this->director->id}")
            ->where('entries.data.2.withheld', true)
            ->where('entries.data.2.action_label', 'Chamada ao ERP')
            ->where('entries.data.3.withheld', true)
            ->where('entries.data.3.actor.name', 'Edy Barrote')
            ->where('entries.data.3.action_label', 'Acção aprovada')
            // A confidential task keeps its kind and number, not its title or its link.
            ->where('entries.data.4.withheld', true)
            ->where('entries.data.4.subject.type_label', 'Tarefa')
            ->where('entries.data.4.subject.id', $task->id)
            ->where('entries.data.4.subject.name', null)
            ->where('entries.data.4.subject.url', null)
            ->where('entries.data.5.withheld', true)
            ->where('entries.data.5.actor.name', 'Director de RH')
            ->where('entries.data.5.result', 'ok'));

    // Whoever manages people reads it all; the owner always does.
    foreach ([$this->rosa, $this->owner] as $reader) {
        $this->actingAs($reader)->get(tenantUrl($this->tenant, 'settings/audit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reads_confidential', true)
                ->where('entries.data.4.withheld', false)
                ->where('entries.data.4.subject.name', 'Processo disciplinar do João')
                ->where('entries.data.4.details.note', 'salário revisto')
                ->where('entries.data.5.details.result.salario_base', 85000));
    }

    // Checked every time: without the capability the agent is no longer confidential.
    asTenant($this->tenant, fn () => AgentCapability::query()->where('agent_id', $this->director->id)->delete());
    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'settings/audit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.4.withheld', false)
            // A people-data tool stays hidden whoever called it.
            ->where('entries.data.2.withheld', true));
});

it('does not find words that are only in details a person may not read', function () {
    asTenant($this->tenant, function () {
        AuditLog::record($this->director, 'erp.tool_call', ['tool' => 'hr_list_staff', 'result' => ['nota' => 'Salário de Novembro']]);
        AuditLog::record($this->sales, 'erp.tool_call', ['tool' => 'list_customers', 'arguments' => ['q' => 'Novembro']]);
    });

    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'settings/audit?q=salário'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0));
    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'settings/audit?q=novembro'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.actor.name', 'Gestor de Clientes'));
    // The action stays searchable: the row itself is not hidden.
    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, "settings/audit?q=erp.tool&actor=agent:{$this->director->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));

    $this->actingAs($this->rosa)->get(tenantUrl($this->tenant, 'settings/audit?q=salário'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.actor.name', 'Director de RH'));
});

it('exports exactly the filtered entries as a CSV that Excel opens, newest first', function () {
    asTenant($this->tenant, function () {
        AuditLog::factory()->create(['action' => 'erp.tool_call', 'actor_type' => ActorType::Agent, 'actor_id' => $this->sales->id, 'payload' => ['tool' => 'list_rfqs', 'error' => 'Unknown column'], 'result' => AuditResult::Error, 'created_at' => Carbon::parse('2026-11-21 08:15:30', 'Africa/Maputo')->utc()]);
        AuditLog::factory()->create(['action' => 'erp.connection_test', 'actor_type' => ActorType::User, 'actor_id' => $this->owner->id, 'created_at' => Carbon::parse('2026-11-22 08:00', 'Africa/Maputo')->utc()]);
        // Text that a spreadsheet would run as a formula.
        AuditLog::factory()->create(['action' => 'knowledge.created', 'actor_type' => ActorType::User, 'actor_id' => $this->owner->id, 'payload' => ['title' => 'Preços de cimento'], 'created_at' => Carbon::parse('2026-11-22 09:00', 'Africa/Maputo')->utc()]);
        AuditLog::factory()->create(['action' => '=cmd|calc', 'payload' => [], 'created_at' => Carbon::parse('2026-11-22 09:30', 'Africa/Maputo')->utc()]);
    });

    $response = $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit/export?action=erp.'));
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('auditoria-acme-2026-11-23-')->toContain('.csv');

    $csv = auditCsv($response);

    expect($csv[0])->toBe(AuditPresenter::CSV_HEADERS)
        ->and($csv)->toHaveCount(3)
        ->and(array_column($csv, 4))->toBe(['Código da acção', 'erp.connection_test', 'erp.tool_call'])
        ->and($csv[2])->toBe([
            '2026-11-21 08:15:30', 'Agente', 'Gestor de Clientes', 'Chamada ao ERP', 'erp.tool_call', 'Erro', '', '', '', '',
            '{"tool":"list_rfqs","error":"Unknown column"}',
        ])
        ->and($csv[1][1])->toBe('Pessoa')
        ->and($csv[1][2])->toBe('Edy Barrote');

    // Accents survive (UTF-8), and a cell that starts like a formula does not run.
    // (Up to yesterday: today's export is in the trail too.)
    $all = auditCsv($this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit/export?to=2026-11-22')));
    expect($all)->toHaveCount(5)
        ->and($all[1][4])->toBe("'=cmd|calc")
        ->and($all[2][10])->toBe('{"title":"Preços de cimento"}');
});

it('records each export with its filters and how many entries it carried', function () {
    asTenant($this->tenant, function () {
        AuditLog::record($this->sales, 'erp.tool_call', ['tool' => 'list_customers'], AuditResult::Error);
        AuditLog::record($this->sales, 'erp.tool_call', ['tool' => 'list_rfqs']);
    });

    $csv = auditCsv($this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit/export?action=erp.tool_call&result=error')));

    $export = asTenant($this->tenant, fn () => AuditLog::query()->where('action', 'audit.exported')->sole());
    expect($csv)->toHaveCount(2)
        ->and($export->actor_type)->toBe(ActorType::User)
        ->and($export->actor_id)->toBe($this->owner->id)
        ->and($export->payload)->toBe(['filters' => ['action' => 'erp.tool_call', 'result' => 'error'], 'count' => 1]);

    // The next export carries the first one, which is in the trail like everything else.
    $again = auditCsv($this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit/export?action=audit.exported')));
    expect($again)->toHaveCount(2)
        ->and($again[1][3])->toBe('Auditoria exportada')
        ->and(asTenant($this->tenant, fn () => AuditLog::query()->where('action', 'audit.exported')->orderByDesc('id')->first()->payload['count']))->toBe(1);
});

it('withholds the same details in the export', function () {
    asTenant($this->tenant, function () {
        AuditLog::record($this->director, 'erp.tool_call', ['tool' => 'hr_list_staff', 'result' => ['salario_base' => 85000]]);
        AuditLog::record($this->sales, 'erp.tool_call', ['tool' => 'list_customers']);
    });

    $hidden = auditCsv($this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'settings/audit/export')));
    expect($hidden)->toHaveCount(3)
        ->and($hidden[2][2])->toBe('Director de RH')
        ->and($hidden[2][10])->toBe(AuditPresenter::WITHHELD)
        ->and($hidden[1][10])->toBe('{"tool":"list_customers"}')
        ->and(implode(';', array_merge(...$hidden)))->not->toContain('85000');

    $shown = auditCsv($this->actingAs($this->rosa)->get(tenantUrl($this->tenant, "settings/audit/export?actor=agent:{$this->director->id}")));
    expect($shown)->toHaveCount(2)
        ->and($shown[1][10])->toContain('85000');
});

it('never changes the trail it shows', function () {
    $log = asTenant($this->tenant, fn () => AuditLog::record($this->owner, 'settings.brand_updated', ['color' => '#0052CC']));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit'))->assertOk();
    auditCsv($this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/audit/export')));

    expect(asTenant($this->tenant, fn () => AuditLog::query()->whereKey($log->id)->sole()->payload))->toBe(['color' => '#0052CC'])
        ->and(asTenant($this->tenant, fn () => AuditLog::query()->orderBy('id')->pluck('action')->all()))->toBe(['settings.brand_updated', 'audit.exported']);
});

it('withholds the error of a people-data call on the ERP activity too', function () {
    asTenant($this->tenant, function () {
        AuditLog::record($this->director, 'erp.tool_call', ['tool' => 'hr_get_employee', 'error' => 'Funcionário 7 (salário 85000) bloqueado'], AuditResult::Error);
        AuditLog::record($this->sales, 'erp.tool_call', ['tool' => 'list_rfqs', 'error' => 'Unknown column'], AuditResult::Error);
    });

    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'settings/integrations/erp/activity'))
        ->assertDontSee('85000')
        ->assertInertia(fn (Assert $page) => $page->where('calls.data.0.error', 'Unknown column')->where('calls.data.1.error', null)->where('calls.data.1.result', 'error'));
    $this->actingAs($this->rosa)->get(tenantUrl($this->tenant, 'settings/integrations/erp/activity'))
        ->assertInertia(fn (Assert $page) => $page->where('calls.data.1.error', 'Funcionário 7 (salário 85000) bloqueado'));
});
