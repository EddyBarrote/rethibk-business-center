<?php

use App\Ai\Runs\RunMetrics;
use App\Enums\RunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Capability;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// Observability per agent (docs/DECISOES.md, "Latência, taxa de erro e
// versões das skills"): runs, failures, error rate, median and p90 duration
// and cost, on Definições › Consumo de IA for a chosen period and on the
// agent's page for the last 30 days, with the way into its run log.

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-23 12:00'));
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        $this->member = User::factory()->create(['role' => 'member']);
        // Sees AI costs but may not manage people.
        $this->finance = User::factory()->create(['role' => 'member', 'name' => 'Carla Finanças', 'permission_overrides' => ['costs.view' => true, 'people.manage' => false]]);
        // Configures agents but does not see AI costs.
        $this->configurer = User::factory()->create(['role' => 'member', 'permission_overrides' => ['agents.manage' => true, 'costs.view' => false]]);

        $this->sales = Agent::factory()->create(['name' => 'Amélia']);
        $this->triage = Agent::factory()->create(['name' => 'Triagem']);

        $at = now()->subDays(2);
        foreach ([1000, 2000, 3000, 4000] as $ms) {
            AgentRun::factory()->for($this->sales)->create(['status' => RunStatus::Completed, 'duration_ms' => $ms, 'cost_usd' => 0.5, 'created_at' => $at]);
        }
        // Closed by StaleRuns: no duration_ms, timed from its timestamps.
        AgentRun::factory()->for($this->sales)->create([
            'status' => RunStatus::Failed, 'duration_ms' => null, 'started_at' => $at, 'finished_at' => $at->copy()->addSeconds(10), 'cost_usd' => 0.25, 'created_at' => $at,
        ]);
        // No outcome yet, or stopped by a person: counted as runs, not in the error rate nor the durations.
        AgentRun::factory()->for($this->sales)->create(['status' => RunStatus::Running, 'duration_ms' => null, 'created_at' => $at]);
        AgentRun::factory()->for($this->sales)->create(['status' => RunStatus::AwaitingApproval, 'duration_ms' => 500, 'created_at' => $at]);
        AgentRun::factory()->for($this->sales)->create(['status' => RunStatus::Cancelled, 'duration_ms' => 99000, 'created_at' => $at]);
        // Last month.
        AgentRun::factory()->for($this->sales)->create(['status' => RunStatus::Failed, 'duration_ms' => 60000, 'cost_usd' => 2, 'created_at' => Carbon::parse('2026-10-28 10:00')]);

        AgentRun::factory()->for($this->triage)->create(['status' => RunStatus::Completed, 'duration_ms' => 800, 'cost_usd' => 0.1, 'created_at' => now()->subDay()]);
    });
});

it('counts runs and failures and works out the error rate and the median and p90 durations', function () {
    asTenant($this->tenant, function () {
        $rows = app(RunMetrics::class)->perAgent(Carbon::parse('2026-11-01'), Carbon::parse('2026-12-01'));

        expect($rows->get($this->sales->id))->toBe([
            'agent_id' => $this->sales->id,
            'runs' => 8,
            'completed' => 4,
            'failed' => 1,
            // 1 failed of 5 finished.
            'error_rate' => 0.2,
            // 1000, 2000, 3000, 4000 and 10 000 ms.
            'p50_ms' => 3000,
            'p90_ms' => 7600,
            'measured' => 5,
            'spent' => 2.25,
        ])->and($rows->get($this->triage->id))->toMatchArray(['runs' => 1, 'failed' => 0, 'error_rate' => 0.0, 'p50_ms' => 800, 'p90_ms' => 800]);

        // The period filter: last month holds only the one failed run.
        expect(app(RunMetrics::class)->forAgent($this->sales->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')))
            ->toMatchArray(['runs' => 1, 'completed' => 0, 'failed' => 1, 'error_rate' => 1.0, 'p50_ms' => 60000, 'p90_ms' => 60000, 'spent' => 2.0])
            // An agent that did not run has zeros and no rate.
            ->and(app(RunMetrics::class)->forAgent($this->triage->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')))
            ->toMatchArray(['runs' => 0, 'error_rate' => null, 'p50_ms' => null, 'p90_ms' => null]);
    });

    expect(RunMetrics::percentile([], 0.5))->toBeNull()
        ->and(RunMetrics::percentile([100, 200, 300, 400], 0.5))->toBe(250)
        ->and(RunMetrics::percentile([100, 200, 300, 400], 0.9))->toBe(370);
});

it('never counts another tenant\'s runs', function () {
    $other = Tenant::factory()->create();
    $theirs = asTenant($other, function () {
        $agent = Agent::factory()->create();
        AgentRun::factory()->for($agent)->count(3)->create(['status' => RunStatus::Failed, 'duration_ms' => 50]);

        return $agent;
    });

    asTenant($this->tenant, function () use ($theirs) {
        $rows = app(RunMetrics::class)->perAgent(now()->subDays(30));

        expect($rows->keys()->sort()->values()->all())->toBe(collect([$this->sales->id, $this->triage->id])->sort()->values()->all())
            ->and(app(RunMetrics::class)->forAgent($theirs->id, now()->subDays(30))['runs'])->toBe(0);
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/usage'))
        ->assertInertia(fn (Assert $page) => $page->where('agents', fn ($agents) => collect($agents)->pluck('id')->doesntContain($theirs->id)));
});

it('shows per agent, for the chosen period, runs, error rate, latency and cost on Consumo de IA', function () {
    $this->tenant->update(['settings' => ['ai_budget' => ['agent_monthly_usd' => 20]]]);

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/usage'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Usage')
            ->where('selected', '2026-11')
            ->where('periods', ['2026-11', '2026-10', '2026-09', '2026-08', '2026-07', '2026-06', '30d'])
            ->has('agents', 2)
            ->where('agents.0.id', $this->sales->id)
            ->where('agents.0.runs', 8)
            ->where('agents.0.failed', 1)
            ->where('agents.0.finished', 5)
            ->where('agents.0.error_rate', 0.2)
            ->where('agents.0.p50_ms', 3000)
            ->where('agents.0.p90_ms', 7600)
            ->where('agents.0.spent', 2.25)
            ->where('agents.0.cap', 20)
            ->where('hidden_spent', 0));

    // Last month: only what ran then, and no monthly cap to compare against.
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/usage?period=2026-10'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected', '2026-10')
            ->has('agents', 1)
            ->where('agents.0', fn ($row) => $row['id'] === $this->sales->id && $row['runs'] === 1 && $row['error_rate'] === 1 && $row['p50_ms'] === 60000 && $row['cap'] === null)
            // The month's budget above the table stays this month's.
            ->where('spent', 2.35));

    // The last 30 days reach back into October.
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/usage?period=30d'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selected', '30d')->where('agents.0.runs', 9)->where('agents.0.failed', 2));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/usage?period=2020-01'))->assertSessionHasErrors('period');
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/usage'))->assertForbidden();
});

it('leaves a confidential agent out for whoever may not manage people, keeping its cost in the totals', function () {
    asTenant($this->tenant, function () {
        $capability = Capability::factory()->create(['key' => 'erp.hr.get_employee']);
        $this->triage->capabilities()->attach($capability->id, ['enabled' => true]);
    });

    $this->actingAs($this->finance)->get(tenantUrl($this->tenant, 'settings/usage'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('agents', 1)->where('agents.0.id', $this->sales->id)->where('hidden_spent', 0.1)->where('spent', 2.35));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/usage'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('agents', 2)->where('hidden_spent', 0));

    // On its own page, no numbers for them either.
    $this->actingAs($this->finance)->get(tenantUrl($this->tenant, "agents/{$this->triage->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('metrics', null));
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "agents/{$this->triage->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('metrics.runs', 1));
});

it('shows the last 30 days on the agent\'s page, the cost only to whoever sees AI costs', function () {
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "agents/{$this->sales->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Agents/Show')
            ->where('metrics', [
                'days' => 30,
                'runs' => 9,
                'failed' => 2,
                'finished' => 6,
                'error_rate' => 0.3333,
                'p50_ms' => 3500,
                'p90_ms' => 35000,
                'spent' => 4.25,
            ]));

    $this->actingAs($this->configurer)->get(tenantUrl($this->tenant, "agents/{$this->sales->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('metrics.runs', 9)->where('metrics.spent', null));

    $this->actingAs($this->member)->get(tenantUrl($this->tenant, "agents/{$this->sales->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('metrics', null));
});

it('opens one agent\'s run log, conversation turns included, still only with the runs the person may open', function () {
    [$chatRun, $mine] = asTenant($this->tenant, function () {
        $chat = Task::factory()->chat()->create(['assignee_agent_id' => $this->sales->id, 'user_id' => $this->owner->id]);

        return [
            AgentRun::factory()->for($this->sales)->create(['task_id' => $chat->id, 'requested_by_user_id' => $this->owner->id]),
            AgentRun::factory()->for($this->sales)->create(['requested_by_user_id' => $this->member->id]),
        ];
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/runs?agent={$this->sales->id}&origin=todas"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Runs/Index')
            ->where('agent', ['id' => $this->sales->id, 'name' => 'Amélia'])
            ->where('filters.agent', $this->sales->id)
            ->where('runs.total', 11)
            ->where('runs.data', fn ($runs) => collect($runs)->pluck('agent.id')->unique()->values()->all() === [$this->sales->id]
                && collect($runs)->pluck('id')->contains($chatRun->id)));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/runs?agent={$this->sales->id}&origin=todas&status=failed"))
        ->assertInertia(fn (Assert $page) => $page->where('runs.total', 2));

    // A member sees only their own.
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, "settings/runs?agent={$this->sales->id}&origin=todas"))
        ->assertInertia(fn (Assert $page) => $page->where('runs.total', 1)->where('runs.data.0.id', $mine->id));
});
