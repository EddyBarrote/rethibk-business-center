<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Code\Sandbox;
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
 * Python the agent writes, run on this server in a sandbox, over files of
 * this conversation (docs/DECISOES.md, "Correr código localmente"): for the
 * work files.analyze does not do (crossing files, rules per row, what a
 * skill's script did). No AI cost beyond the agent's own turn.
 */
final class RunCode extends LocalCapability
{
    public function __construct(private readonly Sandbox $sandbox) {}

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
        $seconds = (int) config('agents.code_execution.timeout', 60);

        return 'Corre Python 3 que tu escreves numa caixa isolada, com anexos desta conversa na pasta actual (pelo nome do ficheiro, mostrado no resultado), e devolve o que o código imprimiu. '
            .'Há pandas, numpy, openpyxl e a biblioteca padrão; não há rede nem outros ficheiros; '.$seconds.' s no máximo. '
            .'Usa-o para o que files.analyze não faz: cruzar ficheiros, regras linha a linha, o que o script de uma skill faria. Para somar, contar ou agrupar uma tabela, files.analyze basta. '
            .'Vê antes as colunas (files.analyze sem filtros) e imprime com print() tudo o que precisas: só o que for impresso volta para ti. Se der erro, corrige e volta a correr.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->required()->description('Programa Python completo; imprime os resultados.'),
            'attachment_ids' => $schema->array()->items($schema->integer())->description('Anexos desta conversa a pôr na pasta.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'code' => 'required|string|max:30000',
            'attachment_ids' => 'nullable|array|max:10',
            'attachment_ids.*' => 'integer',
        ])->validate();

        if (! $this->sandbox->available()) {
            return CapabilityResult::error('a execução de código não está instalada neste servidor. Faz as contas com files.analyze ou diz à pessoa que falta configurar (php artisan sandbox:install).');
        }

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

        $limit = (float) config('agents.code_execution.max_mb', 50) * 1024 * 1024;
        if ($files->sum('size_bytes') > $limit) {
            return CapabilityResult::error('Os ficheiros somam '.Number::fileSize($files->sum('size_bytes')).', acima do limite de '.Number::fileSize($limit).'.');
        }

        $paths = [];
        foreach ($files as $file) {
            if ($file->storagePath() === null || ! Storage::disk($file->diskName())->exists((string) $file->storagePath())) {
                return CapabilityResult::error("O ficheiro do {$file->reference()} já não está disponível.");
            }

            $paths[$this->folderName($file->filename, array_keys($paths))] = Storage::disk($file->diskName())->path((string) $file->storagePath());
        }

        try {
            $result = $this->sandbox->run($data['code'], $paths);
        } catch (Throwable $e) {
            return CapabilityResult::error('a execução de código falhou: '.Str::limit($e->getMessage(), 200));
        }

        AuditLog::record($context->agent, 'code.run', [
            'attachments' => $ids, 'exit' => $result['exit'], 'timed_out' => $result['timed_out'], 'code' => Str::limit($data['code'], 2000),
        ], subject: $context->run);

        $names = $paths === [] ? 'nenhum' : collect(array_keys($paths))->map(fn ($n) => "«{$n}»")->implode(', ');
        $status = match (true) {
            $result['timed_out'] => 'Parou: passou do tempo ou da memória. Simplifica ou lê menos de cada vez.',
            $result['exit'] === 0 => 'Terminou sem erros.',
            default => "Terminou com erro (código {$result['exit']}): corrige e volta a correr.",
        };

        return CapabilityResult::text(trim(
            "Ficheiros na pasta: {$names}. {$status}\n"
            ."<saida_codigo>\n".($result['stdout'] !== '' ? $result['stdout'] : '(nada impresso)')."\n</saida_codigo>"
            .($result['stderr'] !== '' ? "\nErros:\n<erros_codigo>\n{$result['stderr']}\n</erros_codigo>" : '')
            .($result['created'] !== [] ? "\nFicheiros que o código criou (não ficam guardados; para entregar um ficheiro usa documents.generate): ".implode(', ', $result['created']).'.' : '')
        ));
    }

    public function summarise(array $arguments): string
    {
        return 'Correr código';
    }

    /**
     * The file's name in the folder: its own, without folders or odd
     * characters, numbered when two are the same.
     *
     * @param  list<string>  $taken
     */
    private function folderName(string $filename, array $taken): string
    {
        $name = trim((string) preg_replace('/[^\w.\- ]+/u', '_', basename($filename)), '. ') ?: 'ficheiro';
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        for ($n = 2; in_array($name, [...$taken, 'main.py'], true); $n++) {
            $name = "{$base} ({$n})".($extension !== '' ? ".{$extension}" : '');
        }

        return $name;
    }
}
