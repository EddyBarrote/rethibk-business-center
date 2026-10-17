<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Code\Sandbox;
use App\Ai\Runs\AgentRunner;
use App\Attachments\ModelInput;
use App\Attachments\TableReader;
use App\Enums\AttachmentKind;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\MessageAttachment;
use App\Models\Skill;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
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

// Correr código localmente (docs/DECISOES.md): the agent's Python runs on this
// server, shut in, over files of this conversation. The run itself needs the
// sandbox (php artisan sandbox:install); without it the tool says so.

function runCode(object $test, array $arguments): CapabilityResult
{
    return asTenant($test->tenant, function () use ($test, $arguments) {
        $run = app(AgentRunner::class)->create($test->agent, 'teste', TriggerType::Manual, taskId: $test->task->id);

        return app(CapabilityRegistry::class)->find('code.run')->execute($arguments, new CapabilityContext($test->agent, $run));
    });
}

it('says code cannot run when there is no sandbox, and refuses files of other conversations', function () {
    config(['agents.code_execution.driver' => 'off']);
    $file = tableAttachment($this, 'recargas.csv', RECARGAS);

    expect(runCode($this, ['code' => 'print(1)', 'attachment_ids' => [$file->id]])->content)->toContain('não está instalada')->toContain('files.analyze');

    config(['agents.code_execution.driver' => 'auto']);
    $elsewhere = asTenant($this->tenant, fn () => MessageAttachment::factory()->create(['filename' => 'x.csv', 'task_message_id' => TaskMessage::factory()->create()->id]));
    if (app(Sandbox::class)->available()) {
        expect(runCode($this, ['code' => 'print(1)', 'attachment_ids' => [$elsewhere->id]])->content)->toContain('não estão nesta conversa');
    }
});

it('runs the agent\'s Python over the files, with no network and nothing else of the machine', function () {
    $file = tableAttachment($this, 'recargas.csv', RECARGAS);
    $env = base_path('.env');

    $result = runCode($this, ['attachment_ids' => [$file->id], 'code' => <<<PY
    import pandas as pd, socket, os
    df = pd.read_csv("recargas.csv", thousands=".", decimal=",")
    print("total", df[df["Método de Pagamento"] == "M-Pesa"]["Valor da Recarga"].sum())
    for what, attempt in [("rede", lambda: socket.create_connection(("1.1.1.1", 80), timeout=2)), ("env", lambda: open("{$env}").read()), ("escrever fora", lambda: open("/private/tmp/micomoc-fora.txt", "w"))]:
        try:
            attempt(); print(what, "ABERTO")
        except Exception as e:
            print(what, "bloqueado")
    print(sorted(os.environ))
    open("saida.csv", "w").write("x")
    PY]);

    expect($result->ok)->toBeTrue()
        ->and($result->content)->toContain('Ficheiros na pasta: «recargas.csv». Terminou sem erros.')
        ->toContain('total 1555.5')->toContain('rede bloqueado')->toContain('env bloqueado')->toContain('escrever fora bloqueado')
        ->not->toContain('DB_PASSWORD')->not->toContain('ABERTO')
        ->toContain('Ficheiros que o código criou')->toContain('saida.csv');

    expect(runCode($this, ['code' => 'raise ValueError("coluna errada")'])->content)->toContain('Terminou com erro')->toContain('coluna errada');

    asTenant($this->tenant, fn () => expect(AuditLog::query()->where('action', 'code.run')->count())->toBe(2));
})->skip(fn () => ! app(Sandbox::class)->available(), 'sem caixa para correr código (php artisan sandbox:install)');

it('stops code that runs too long', function () {
    config(['agents.code_execution.timeout' => 2]);

    expect(runCode($this, ['code' => "while True:\n    pass"])->content)->toContain('Parou: passou do tempo');
})->skip(fn () => ! app(Sandbox::class)->available(), 'sem caixa para correr código (php artisan sandbox:install)');
