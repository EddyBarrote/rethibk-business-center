<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Agents\InstructionComposer;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\FactCheck;
use App\Ai\Runs\QuestionService;
use App\Ai\Runs\RunRecorder;
use App\Enums\AuditResult;
use App\Enums\AutonomyLevel;
use App\Enums\RunStatus;
use App\Enums\StepType;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// Perguntar, nunca inventar (docs/DECISOES.md): an agent that has no price,
// term or contact for something asks the person; it never fills in a guess.

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();

    [$this->boss, $this->agent] = asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $boss = User::factory()->owner()->create(['name' => 'Chefe', 'email' => 'chefe@empresa.co.mz']);

        return [$boss, Agent::factory()->level(AutonomyLevel::ExecuteWithinLimits)->create(['key' => 'clientes', 'name' => 'Gestor de Clientes', 'reports_to_user_id' => $boss->id])];
    });
});

function quote(string $content): Closure
{
    return toolCall('d1', 'documents_generate', ['format' => 'pdf', 'title' => 'Proposta POS', 'template' => 'relatorio', 'content' => $content]);
}

function askChat(string $message): AgentRun
{
    $threads = app(TaskThread::class);
    $chat = $threads->conversation(test()->boss, test()->agent);
    $threads->post($chat, test()->boss, $message);

    return app(AgentRunner::class)->run(AgentRun::query()->sole());
}

it('refuses a quote with prices that no source has, and tells the agent to ask', function () {
    GenericAgent::fake([quote("# Proposta\n\n| Item | Valor |\n|---|---|\n| Instalação e formação | 25 000,00 MZN |\n| Licença e suporte | 3 500,00 MZN/mês |"), 'Preciso dos preços.']);

    asTenant($this->tenant, function () {
        $run = askChat('Faz a cotação do software de gestão para a loja de calçados.');
        $error = $run->steps()->where('type', StepType::Error)->sole();

        expect(GeneratedDocument::query()->count())->toBe(0)
            ->and($error->tool_name)->toBe('documents.generate')
            ->and($error->payload['content'])->toContain('25 000,00 MZN')->toContain('3 500,00 MZN')->toContain('questions.ask')
            ->and(AuditLog::query()->where('action', 'documents.generate')->sole()->result)->toBe(AuditResult::Denied);
    });
});

it('lets the quote through with the prices the person gave, and the totals and IVA worked out from them', function () {
    GenericAgent::fake([quote("Instalação: 25 000,00 MZN\nMensalidade: 3 500,00 MZN x 12 meses = 42 000,00 MZN\nTotal do primeiro ano: 67 000,00 MZN\nIVA 16%: 10 720,00 MZN\nTotal com IVA: 77 720,00 MZN\nContacto: chefe@empresa.co.mz"), 'Feito.']);

    asTenant($this->tenant, function () {
        askChat('Faz a cotação: instalação 25.000 MZN e mensalidade 3500 MZN.');

        expect(GeneratedDocument::query()->count())->toBe(1);
    });
});

it('refuses an invented commission, email or phone number', function () {
    asTenant($this->tenant, function () {
        $run = app(AgentRunner::class)->create($this->agent, 'Escreve a proposta ao cliente.', TriggerType::Manual);
        $check = app(FactCheck::class);

        expect($check->ungrounded('Comissão de 5% sobre as vendas.', $run))->toBe(['5%'])
            ->and($check->ungrounded('Escreva para vendas@loja.co.mz ou ligue +258 84 123 4567.', $run))->toBe(['vendas@loja.co.mz', '+258 84 123 4567'])
            ->and($check->ungrounded('Sem valores aqui, só texto. Prazo: 2026-10-30.', $run))->toBe([]);
    });
});

it('refuses questions that offer made-up prices as the answers', function () {
    GenericAgent::fake([
        toolCall('q1', 'questions_ask', ['questions' => [[
            'question' => 'Qual o preço da instalação?', 'header' => 'Instalação',
            'options' => [['label' => '25 000 MZN', 'description' => 'Valor de mercado.'], ['label' => '30 000 MZN', 'description' => '']], 'multi_select' => false,
        ]]]),
        toolCall('q2', 'questions_ask', ['questions' => [[
            'question' => 'Qual o preço da instalação?', 'header' => 'Instalação',
            'options' => [['label' => 'Indico o valor', 'description' => 'Escrevo-o na resposta.'], ['label' => 'Ainda não sei', 'description' => '']], 'multi_select' => false,
        ]]]),
        'Fico à espera do preço.',
    ]);

    asTenant($this->tenant, function () {
        $run = askChat('Faz a cotação do POS.');

        expect($run->steps()->where('type', StepType::Error)->sole()->payload['content'])->toContain('25 000 MZN')->toContain('em aberto')
            ->and(AgentQuestion::query()->sole()->questions[0]['options'][0]['label'])->toBe('Indico o valor');
    });
});

it('counts a total over the records an ERP tool returned as found', function () {
    asTenant($this->tenant, function () {
        $run = app(AgentRunner::class)->create($this->agent, 'Persegue os recebimentos.', TriggerType::Schedule);
        $invoices = ['items' => [['number' => 'INV-1', 'total' => '120000.50'], ['number' => 'INV-2', 'total' => '77964.50'], ['number' => 'INV-3', 'total' => '3100.00']]];
        app(RunRecorder::class)->step($run, StepType::ToolResult, ['ok' => true, 'content' => json_encode($invoices)], 'erp.list_invoices');

        expect(app(FactCheck::class)->ungrounded('Seguimento de 2 facturas no valor total de 197 965,00 MZN.', $run))->toBe([])
            ->and(app(FactCheck::class)->ungrounded('Seguimento no valor total de 199 000,00 MZN.', $run))->toBe(['199 000,00 MZN']);
    });
});

it('takes the answers to its questions as a source when the run resumes', function () {
    GenericAgent::fake([
        toolCall('q1', 'questions_ask', ['questions' => [[
            'question' => 'Qual é o preço da instalação?', 'header' => 'Instalação',
            'options' => [['label' => 'Não sei', 'description' => ''], ['label' => 'Pergunto', 'description' => '']], 'multi_select' => false,
        ]]]),
        'Preciso do preço.',
        quote('Instalação: 18 000,00 MZN'),
        'Feito.',
    ]);

    asTenant($this->tenant, function () {
        $run = askChat('Faz a cotação do POS.');
        expect($run->status)->toBe(RunStatus::AwaitingInput);

        app(QuestionService::class)->answer(AgentQuestion::query()->sole(), $this->boss, [['selected' => [], 'other' => '18 000 MZN']]);
        app(AgentRunner::class)->run($run->refresh());

        expect(GeneratedDocument::query()->count())->toBe(1);
    });
});

it('points out amounts with no source in the reply itself', function () {
    GenericAgent::fake(['Sugiro cobrar 12 000 MZN pela instalação.']);

    asTenant($this->tenant, function () {
        $run = askChat('Quanto cobramos?');

        expect($run->output['text'])->toContain('Nota da plataforma')->toContain('12 000 MZN');
    });
});

it('tells every agent to ask instead of inventing', function () {
    asTenant($this->tenant, fn () => expect(app(InstructionComposer::class)->for($this->agent))
        ->toContain('Nunca inventes nem estimes factos de negócio')
        ->toContain('company.propose_fact'));
});
