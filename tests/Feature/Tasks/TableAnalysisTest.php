<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Code\CodeRunner;
use App\Ai\Runs\AgentRunner;
use App\Attachments\ModelInput;
use App\Attachments\TableReader;
use App\Enums\AttachmentKind;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AiCharge;
use App\Models\AuditLog;
use App\Models\MessageAttachment;
use App\Models\Skill;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Contas sobre tabelas anexadas (docs/DECISOES.md): the platform goes through
// every row of a CSV or Excel attachment, so totals never come from the parts
// the agent happened to read.

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    [$this->agent, $this->task] = asTenant($this->tenant, fn () => [Agent::factory()->create(), Task::factory()->create()]);
});

function tableAttachment(object $test, string $filename, string $bytes, string $mime = 'text/csv'): MessageAttachment
{
    return asTenant($test->tenant, function () use ($test, $filename, $bytes, $mime) {
        $path = 'attachments/test/'.$filename;
        Storage::disk('local')->put($path, $bytes);
        $message = TaskMessage::factory()->create(['task_id' => $test->task->id]);

        return MessageAttachment::factory()->create([
            'task_id' => $test->task->id, 'task_message_id' => $message->id, 'kind' => AttachmentKind::Text,
            'filename' => $filename, 'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'received_bytes' => strlen($bytes), 'path' => $path,
        ]);
    });
}

function analyze(object $test, array $arguments): string
{
    return asTenant($test->tenant, function () use ($test, $arguments) {
        $run = app(AgentRunner::class)->create($test->agent, 'teste', TriggerType::Manual, taskId: $test->task->id);
        $result = app(CapabilityRegistry::class)->find('files.analyze')->execute($arguments, new CapabilityContext($test->agent, $run));

        return $result->content;
    });
}

const RECARGAS = <<<'CSV'
"ID da Recarga","Data e Hora","Valor da Recarga",Origem,"Método de Pagamento","Status do Pagamento"
R1,"30/09/2026 23:45","20,00","Próprio (App)",M-Pesa,Concluído
R2,"30/09/2026 10:00","1.000,00",Assistente,M-Pesa,Concluído
R3,"01/09/2026 08:00","150,00",Assistente,POS,
R4,"31/08/2026 08:00","500,00",Assistente,M-Pesa,Concluído
R5,"02/09/2026 09:00","35,50","Próprio (App)",M-Pesa,Falhado
CSV;

it('profiles a table, then counts, filters by date and adds up every row', function () {
    $file = tableAttachment($this, 'recargas.csv', RECARGAS);

    expect(analyze($this, ['attachment_id' => $file->id]))
        ->toContain('5 linhas de dados')
        ->toContain('Método de Pagamento: 5 preenchidas · valores: «M-Pesa» (4), «POS» (1)')
        ->toContain('Valor da Recarga: 5 preenchidas')->toContain('numérica');

    $september = analyze($this, [
        'attachment_id' => $file->id,
        'date_column' => 'data e hora', 'from' => '2026-09-01', 'to' => '2026-09-30',
        'where' => [['column' => 'Status do Pagamento', 'op' => '!=', 'value' => 'falhado']],
        'group_by' => ['metodo de pagamento'], 'sum' => ['Valor da Recarga'],
    ]);

    expect($september)
        ->toContain('Linhas que contam: 3 (depois dos filtros) · datas de 2026-09-01 a 2026-09-30')
        ->toContain('Soma de «Valor da Recarga»: 1 170,00')
        ->toContain('| M-Pesa | 2 | 1 020,00 |')
        ->toContain('| POS | 1 | 150,00 |');

    expect(analyze($this, ['attachment_id' => $file->id, 'date_column' => 'Data e Hora', 'group_by_day' => true, 'sum' => ['Valor da Recarga']]))
        ->toContain('| 2026-08-31 | 1 | 500,00 |');
});

it('names the columns when one does not exist, and refuses files that are not tables', function () {
    $file = tableAttachment($this, 'recargas.csv', RECARGAS);

    expect(analyze($this, ['attachment_id' => $file->id, 'sum' => ['Montante']]))
        ->toContain('Colunas que não existem: Montante')->toContain('Valor da Recarga');

    $pdf = asTenant($this->tenant, fn () => MessageAttachment::factory()->create(['task_id' => $this->task->id, 'task_message_id' => TaskMessage::factory()->create(['task_id' => $this->task->id])->id]));
    expect(analyze($this, ['attachment_id' => $pdf->id]))->toContain('não é uma tabela');

    // Only files of this conversation.
    $elsewhere = asTenant($this->tenant, fn () => MessageAttachment::factory()->create(['filename' => 'x.csv', 'mime_type' => 'text/csv', 'task_message_id' => TaskMessage::factory()->create()->id]));
    expect(analyze($this, ['attachment_id' => $elsewhere->id]))->toContain('Anexo não encontrado');
});

it('reads Excel sheets below a title, with numbers and dates as the spreadsheet keeps them', function () {
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet()->setTitle('Fecho');
    $sheet->fromArray([
        ['Fecho das assistentes — Setembro'],
        [],
        ['DATA', 'ASSISTENTE', 'CARTÕES VENDIDOS', 'VALOR'],
        [46296, 'Rosalia', 12, 12.5],
        [46297, 'Tomas', 3, 1200.25],
        [46297, 'Rosalia', 5, 0.5],
    ]);
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    (new Xlsx($book))->save($path);
    $file = tableAttachment($this, 'fecho.xlsx', (string) file_get_contents($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect(analyze($this, ['attachment_id' => $file->id, 'group_by' => ['assistente'], 'sum' => ['cartoes vendidos', 'valor']]))
        ->toContain('folhas: Fecho')->toContain('cabeçalho na linha 3')
        ->toContain('| Rosalia | 2 | 17,00 | 13,00 |')
        ->toContain('| Tomas | 1 | 3,00 | 1 200,25 |');

    expect(analyze($this, ['attachment_id' => $file->id, 'date_column' => 'DATA', 'from' => '2026-10-02', 'sum' => ['valor']]))
        ->toContain('Linhas que contam: 2');
});

it('reads numbers and dates as people write them', function (string $value, ?float $number) {
    expect(TableReader::number($value))->toBe($number);
})->with([
    ['20,00', 20.0], ['1.000,00', 1000.0], ['1,000.50', 1000.5], ['1 000,50', 1000.5], ['150 MZN', 150.0],
    ['(30,00)', -30.0], ['-12.5', -12.5], ['0.500', 0.5], ['1.234.567', 1234567.0], ['', null], ['abc', null],
]);

it('tells the agent to use files.analyze for tables, in the prompt, in files.read and when a skill talks of scripts', function () {
    $big = tableAttachment($this, 'grande.csv', "a,b\n".str_repeat("x,1\n", 10000));

    $text = app(ModelInput::class)->build('gemini', [$big])['text'];
    expect($text)->toContain('usa files.analyze com o anexo #'.$big->id)->not->toContain('lê o resto com files.read');

    $read = asTenant($this->tenant, function () use ($big) {
        $run = app(AgentRunner::class)->create($this->agent, 'teste', TriggerType::Manual, taskId: $this->task->id);

        return app(CapabilityRegistry::class)->find('files.read')->execute(['attachment_id' => $big->id], new CapabilityContext($this->agent, $run))->content;
    });
    expect($read)->toContain('É uma tabela: para contas usa files.analyze');

    asTenant($this->tenant, function () {
        $skill = Skill::factory()->create(['key' => 'fecho', 'instructions' => "Corre:\n```bash\npython scripts/gerar.py --csv X\n```", 'all_agents' => true]);
        $plain = Skill::factory()->create(['key' => 'carta', 'instructions' => 'Escreve uma carta.', 'all_agents' => true]);

        expect(runCapability($this->agent, 'skills.load', ['skill' => $skill->key])->content)->toContain('## Scripts')->toContain('files.analyze')
            ->and(runCapability($this->agent, 'skills.load', ['skill' => $plain->key])->content)->not->toContain('## Scripts');
    });
});

// Correr código (docs/DECISOES.md): Python in the provider's sandbox, over files of this conversation.

function runCode(object $test, array $arguments): CapabilityResult
{
    return asTenant($test->tenant, function () use ($test, $arguments) {
        $run = app(AgentRunner::class)->create($test->agent, 'teste', TriggerType::Manual, taskId: $test->task->id);

        return app(CapabilityRegistry::class)->find('code.run')->execute($arguments, new CapabilityContext($test->agent, $run));
    });
}

it('runs code over the files of the conversation and returns the code and what it printed, charged to the budget', function () {
    $file = tableAttachment($this, 'recargas.csv', RECARGAS);
    CodeRunner::fake([new TextResponse('M-Pesa: 1 555,50 em 4 linhas.', new TextUsage(5000, 800), new Meta('gemini', 'gemini-3.6-flash'))]);

    $result = runCode($this, ['task' => 'Soma o M-Pesa', 'attachment_ids' => [$file->id]]);

    expect($result->ok)->toBeTrue()
        ->and($result->content)->toContain('M-Pesa: 1 555,50 em 4 linhas.')->toContain('não confies nos números sem o código');

    // The code and what it printed, from the provider's steps.
    $step = new Step('', [], [], FinishReason::Stop, new TextUsage, new Meta('gemini', 'gemini-3.6-flash'), '', [], [
        new ProviderToolCall('c1', 'code_execution', ['id' => 'c1', 'type' => 'code_execution_call', 'arguments' => ['language' => 'python', 'code' => 'print(1555.5)']]),
        new ProviderToolCall('', 'code_execution', ['call_id' => 'c1', 'type' => 'code_execution_result', 'result' => "1555.5\n", 'is_error' => false]),
    ]);
    expect(CodeRunner::runs([$step]))->toBe([['code' => 'print(1555.5)', 'output' => "1555.5\n", 'error' => false]]);

    CodeRunner::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'Soma o M-Pesa' && $prompt->attachments->count() === 1
        && str_contains($prompt->agent->instructions(), 'input_file_0: recargas.csv'));

    asTenant($this->tenant, fn () => expect(AiCharge::query()->where('purpose', 'code.run')->sole()->input_tokens)->toBe(5000)
        ->and(AuditLog::query()->where('action', 'code.run')->exists())->toBeTrue());
});

it('refuses files of other conversations and files above the size limit', function () {
    CodeRunner::fake(['nada']);
    $elsewhere = asTenant($this->tenant, fn () => MessageAttachment::factory()->create(['filename' => 'x.csv', 'task_message_id' => TaskMessage::factory()->create()->id]));
    expect(runCode($this, ['task' => 'x', 'attachment_ids' => [$elsewhere->id]])->content)->toContain('não estão nesta conversa');

    config(['agents.code_execution.max_mb' => 0.0001]);
    $file = tableAttachment($this, 'recargas.csv', RECARGAS);
    expect(runCode($this, ['task' => 'x', 'attachment_ids' => [$file->id]])->content)->toContain('acima do limite')->toContain('files.analyze');

    CodeRunner::assertNeverPrompted();
});

it('gives the sandbox each sheet of a spreadsheet as CSV', function () {
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Fecho')->fromArray([['ASSISTENTE', 'VENDIDOS'], ['Rosalia', 3]]);
    $book->createSheet()->setTitle('Notas')->fromArray([['nota'], ['ok']]);
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    (new Xlsx($book))->save($path);
    $file = tableAttachment($this, 'fecho.xlsx', (string) file_get_contents($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    CodeRunner::fake(['3 cartões']);

    runCode($this, ['task' => 'Conta', 'attachment_ids' => [$file->id]]);

    CodeRunner::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 2
        && str_contains($prompt->agent->instructions(), 'input_file_1: fecho.xlsx — folha Notas.csv'));
});
