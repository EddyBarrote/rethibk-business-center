<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\ApprovalService;
use App\Ai\Tools\GatedTool;
use App\Enums\AutonomyLevel;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Capability;
use App\Models\Department;
use App\Models\Skill;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request;

// The Chief of Staff prepares new agents and skills (docs/DECISOES.md, «Chief of Staff cria agentes e skills»).

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->ceo, $this->member, $this->chief, $this->finance, $this->chat] = asTenant($this->tenant, function () {
        $ceo = User::factory()->owner()->create(['name' => 'CEO', 'email' => 'ceo@empresa.example']);
        $member = User::factory()->create(['role' => 'member']);
        Department::factory()->create(['name' => 'Jurídico']);
        $chief = templateAgent('chief_of_staff', ['reports_to_user_id' => $ceo->id]);
        $chief->assignees()->attach($member->id, ['role' => 'chat']);

        return [$ceo, $member, $chief, templateAgent('finance'), app(TaskThread::class)->conversation($ceo, $chief)];
    });
});

function chiefCalls(Agent $chief, Task $task, string $key, array $arguments): string
{
    $run = app(AgentRunner::class)->create($chief, 'teste', TriggerType::Manual);
    $run->forceFill(['task_id' => $task->id])->save();

    return (new GatedTool(Capability::query()->where('key', $key)->sole(), new CapabilityContext($chief, $run->fresh())))->handle(new Request($arguments));
}

function contractsAgent(array $overrides = []): array
{
    return [
        'name' => 'Clara',
        'title' => 'Gestora de contratos',
        'department' => 'Jurídico',
        'reports_to' => 'ceo@empresa.example',
        'instructions' => 'Tu acompanhas os contratos: avisas 30 dias antes de cada renovação.',
        'capabilities' => ['contracts.list'],
        ...$overrides,
    ];
}

it('asks for what is missing instead of sending an incomplete agent for approval', function () {
    asTenant($this->tenant, function () {
        $answer = chiefCalls($this->chief, $this->chat, 'agents.propose', ['name' => 'Clara', 'title' => 'Gestora de contratos', 'department' => 'Marketing']);

        expect($answer)->toContain('Proposta não enviada')
            ->toContain('a quem responde')
            ->toContain('não há «Marketing»')->toContain('Jurídico')
            ->toContain('questions.ask')
            ->and(Approval::query()->count())->toBe(0);

        expect(chiefCalls($this->chief, $this->chat, 'agents.propose', contractsAgent(['autonomy_level' => 3])))->toContain('até N1')
            ->and(Approval::query()->count())->toBe(0);
    });
});

it('shows the new agent as a card and creates it only when someone who may create agents confirms', function () {
    $approval = asTenant($this->tenant, function () {
        chiefCalls($this->chief, $this->chat, 'agents.propose', contractsAgent());

        return Approval::query()->sole();
    });

    expect($approval->review_stage)->toBe(Approval::STAGE_HUMAN)
        ->and($approval->action_summary)->toBe('Criar o agente Clara (Gestora de contratos)')
        ->and(asTenant($this->tenant, fn () => Agent::query()->where('name', 'Clara')->exists()))->toBeFalse();

    $this->actingAs($this->ceo)->get(tenantUrl($this->tenant, "tasks/{$this->chat->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('approvals.0.proposal.kind', 'agent')
            ->where('approvals.0.proposal.facts.2', ['label' => 'Área', 'value' => 'Jurídico'])
            ->where('approvals.0.proposal.facts.3', ['label' => 'Responde a', 'value' => 'CEO'])
            ->where('approvals.0.can_decide', true));

    $this->actingAs($this->member)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve"))->assertForbidden();
    $this->actingAs($this->ceo)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve"))->assertRedirect();

    asTenant($this->tenant, function () use ($approval) {
        app(ApprovalService::class)->execute($approval->fresh());

        $clara = Agent::query()->where('name', 'Clara')->sole();

        expect($clara->key)->toBe('clara')
            ->and($clara->autonomy_level)->toBe(AutonomyLevel::Suggest)
            ->and($clara->department->name)->toBe('Jurídico')
            ->and($clara->reports_to_user_id)->toBe($this->ceo->id)
            ->and($clara->created_by_user_id)->toBe($this->ceo->id)
            ->and($clara->capabilities()->pluck('key')->all())->toContain('contracts.list', 'web.search')
            ->and($this->chat->messages()->latest('id')->first()->body)->toContain("[Clara](/agents/{$clara->id})");
    });
});

it('prepares a skill for the agents named and creates it once the catalogue manager confirms', function () {
    $approval = asTenant($this->tenant, function () {
        expect(chiefCalls($this->chief, $this->chat, 'skills.propose', ['name' => 'Cobranças', 'description' => 'Quando um cliente está em atraso.', 'instructions' => '1. Ver a factura.']))
            ->toContain('que agentes a recebem');

        chiefCalls($this->chief, $this->chat, 'skills.propose', [
            'name' => 'Cobranças',
            'description' => 'Quando um cliente está em atraso.',
            'instructions' => "1. Ver a factura.\n2. Escrever ao contacto financeiro.",
            'agents' => ['finance'],
        ]);

        return Approval::query()->sole();
    });

    $this->actingAs($this->member)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve"))->assertForbidden();
    $this->actingAs($this->ceo)->post(tenantUrl($this->tenant, "approvals/{$approval->id}/approve"))->assertRedirect();

    asTenant($this->tenant, function () use ($approval) {
        app(ApprovalService::class)->execute($approval->fresh());

        $skill = Skill::query()->where('name', 'Cobranças')->sole();

        expect($skill->key)->toBe('cobrancas')
            ->and($skill->is_enabled)->toBeTrue()
            ->and($skill->agents()->pluck('agents.id')->all())->toBe([$this->finance->id])
            // Version 1, by the person who confirmed it.
            ->and($skill->versions()->sole()->number)->toBe(1)
            ->and($skill->versions()->sole()->created_by_user_id)->toBe($this->ceo->id);
    });
});

it('gives the Chief of Staff the catalogue and tells it to ask before proposing', function () {
    asTenant($this->tenant, function () {
        $catalog = json_decode(chiefCalls($this->chief, $this->chat, 'agents.catalog', ['search' => 'contrat']), true);

        expect($catalog['areas'])->toContain('Jurídico')
            ->and(collect($catalog['capabilities'])->pluck('key'))->toContain('contracts.list')
            ->and(collect($catalog['agents'])->pluck('key'))->toContain('finance');
    });
});
