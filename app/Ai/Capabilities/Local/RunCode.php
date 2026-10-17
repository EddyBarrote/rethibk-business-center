<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Code\CodeRunner;
use App\Models\AuditLog;
use App\Models\MessageAttachment;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;

/**
 * Python in the provider's sandbox, over files of this conversation
 * (docs/DECISOES.md, "Correr código"): for the work files.analyze does not
 * do (crossing two files, rules per row, what a skill's script did). Nothing
 * runs on the platform's server.
 */
final class RunCode extends LocalCapability
{
    public function key(): string
    {
        return 'code.run';
    }

    public function name(): string
    {
        return 'Correr código';
    }

    public function description(): string
    {
        return 'Corre Python numa caixa isolada do fornecedor de IA, com anexos desta conversa (CSV, Excel, texto, PDF), e devolve os resultados, o código e o que ele imprimiu. '
            .'Usa-o para o que files.analyze não faz: cruzar ficheiros, regras linha a linha, o que o script de uma skill faria. Para somar, contar ou agrupar uma tabela, files.analyze é mais barato. '
            .'Em task diz exactamente o que calcular (colunas, filtros, período, fórmulas). Os ficheiros entram inteiros no modelo: custa tokens.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->required()->description('O que calcular, com as regras e os nomes das colunas.'),
            'attachment_ids' => $schema->array()->items($schema->integer())->description('Anexos desta conversa a dar à caixa.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'task' => 'required|string|max:4000',
            'attachment_ids' => 'nullable|array|max:5',
            'attachment_ids.*' => 'integer',
        ])->validate();

        $ids = array_values(array_unique($data['attachment_ids'] ?? []));
        $files = $ids === [] || $context->run->task_id === null ? collect() : MessageAttachment::query()
            ->ready()
            ->whereIn('task_id', app(TaskThread::class)->readableTaskIds($context->run))
            ->whereNotNull('task_message_id')
            ->whereIn('id', $ids)
            ->get();

        if ($files->count() !== count($ids)) {
            return CapabilityResult::error('Anexos que não estão nesta conversa: '.implode(', ', array_diff($ids, $files->pluck('id')->all())).'.');
        }

        $missing = $files->first(fn (MessageAttachment $file) => $file->storagePath() === null || ! Storage::disk($file->diskName())->exists((string) $file->storagePath()));
        if ($missing !== null) {
            return CapabilityResult::error("O ficheiro do {$missing->reference()} já não está disponível.");
        }

        $limit = (float) config('agents.code_execution.max_mb', 5) * 1024 * 1024;
        if ($files->sum('size_bytes') > $limit) {
            return CapabilityResult::error('Os ficheiros somam '.Number::fileSize($files->sum('size_bytes')).', acima do limite de '.Number::fileSize($limit)
                .' por chamada. Para somar, contar ou agrupar tabelas usa files.analyze; senão, manda menos ficheiros.');
        }

        try {
            $result = CodeRunner::run($data['task'], $files->sortBy(fn ($f) => array_search($f->id, $ids, true))->values()->all(), $context->agent);
        } catch (Throwable $e) {
            return CapabilityResult::error('a execução de código falhou: '.Str::limit($e->getMessage(), 200));
        }

        AuditLog::record($context->agent, 'code.run', ['attachments' => $ids, 'runs' => count($result['runs'])], subject: $context->run);

        $runs = collect($result['runs'])->map(fn (array $run, int $i) => '### Execução '.($i + 1).($run['error'] ? ' (erro)' : '')
            ."\n```python\n".Str::limit($run['code'], 3000)."\n```\nSaída:\n```\n{$run['output']}\n```")->implode("\n\n");

        return CapabilityResult::text(
            "<resultado_codigo>\n{$result['text']}\n\n".($runs !== '' ? $runs : 'A caixa não indicou código corrido: não confies nos números sem o código.')."\n</resultado_codigo>\n"
            .'Os números vêm do código acima, sobre os ficheiros desta conversa. Usa-os tal como estão; se o código falhou ou leu menos linhas do que devia, corrige o pedido e volta a correr.'
        );
    }

    public function summarise(array $arguments): string
    {
        return 'Correr código: '.Str::limit((string) ($arguments['task'] ?? ''), 80);
    }
}
