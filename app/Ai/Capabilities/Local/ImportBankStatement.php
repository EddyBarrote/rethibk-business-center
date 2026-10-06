<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Finance\BankStatementImporter;
use App\Models\BankStatement;
use App\Models\EmailAttachment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records a bank statement received by email (E05): a CSV attachment is
 * read directly; for a PDF or spreadsheet the agent reads it with
 * documents.read_attachment and passes the lines.
 */
final class ImportBankStatement extends LocalCapability
{
    public function __construct(private readonly BankStatementImporter $importer) {}

    public function key(): string
    {
        return 'bank.import_statement';
    }

    public function name(): string
    {
        return 'Importar extracto bancário';
    }

    public function description(): string
    {
        return 'Regista os movimentos de um extracto bancário recebido por email. CSV: basta o anexo. PDF ou Excel: lê o anexo e envia as linhas (data, descrição, montante com sinal, referência). Movimentos já importados são ignorados.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'attachment_id' => $schema->integer()->required(),
            'account_name' => $schema->string()->description('Ex.: "BCI conta à ordem MZN".')->required(),
            'bank' => $schema->string(),
            'lines' => $schema->array()->items($schema->object([
                'date' => $schema->string()->description('AAAA-MM-DD')->required(),
                'description' => $schema->string()->required(),
                'amount' => $schema->number()->description('Positivo para entradas, negativo para saídas.')->required(),
                'reference' => $schema->string(),
                'balance' => $schema->number(),
            ]))->description('Só para extractos que não são CSV.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'attachment_id' => 'required|integer',
            'account_name' => 'required|string|max:255',
            'bank' => 'nullable|string|max:255',
            'lines' => 'nullable|array|max:2000',
            'lines.*.date' => 'required|string',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.amount' => 'required|numeric',
            'lines.*.reference' => 'nullable|string|max:255',
            'lines.*.balance' => 'nullable|numeric',
        ])->validate();

        $attachment = EmailAttachment::query()->find($data['attachment_id']);

        if ($attachment === null) {
            return CapabilityResult::error('anexo não encontrado.');
        }

        $meta = [
            'account_name' => $data['account_name'],
            'bank' => $data['bank'] ?? null,
            'source' => 'email',
            'original_name' => $attachment->filename,
            'path' => $attachment->path,
            'email_attachment_id' => $attachment->id,
        ];

        try {
            $result = filled($data['lines'] ?? null)
                ? $this->importer->importLines($data['lines'], $meta)
                : $this->importCsv($attachment, $meta);
        } catch (InvalidArgumentException $e) {
            return CapabilityResult::error($e->getMessage());
        }

        return CapabilityResult::data([
            'statement_id' => $result['statement']->id,
            'imported' => $result['imported'],
            'skipped_duplicates' => $result['skipped'],
            'next' => 'usa bank.unreconciled para propor as reconciliações',
        ]);
    }

    /**
     * @param  array{account_name: string, bank: string|null, source: string, original_name: string, path: string|null, email_attachment_id: int}  $meta
     * @return array{statement: BankStatement, imported: int, skipped: int}
     */
    private function importCsv(EmailAttachment $attachment, array $meta): array
    {
        $isCsv = Str::endsWith(Str::lower($attachment->filename), ['.csv', '.txt']) || in_array($attachment->mime_type, ['text/csv', 'text/plain', 'application/csv'], true);

        if (! $isCsv || $attachment->path === null) {
            throw new InvalidArgumentException('o anexo não é CSV: lê-o com documents.read_attachment e envia as linhas em "lines".');
        }

        return $this->importer->importCsv((string) Storage::disk($attachment->disk ?: (string) config('mail_ingest.disk'))->get($attachment->path), $meta);
    }
}
