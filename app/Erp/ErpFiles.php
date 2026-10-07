<?php

namespace App\Erp;

use App\Attachments\AttachmentStore;
use App\Enums\AuditResult;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\ErpConnection;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Some ERP tools (download_invoice_pdf, export_list_to_excel, …) answer with a
 * short-lived download_url instead of the file. In a conversation the
 * platform fetches that file once and attaches it to the agent's reply, so
 * the person gets the ERP's own document and no public link is passed on.
 * Only links on the host of the tenant's ERP connection are fetched, without
 * following redirects.
 */
final class ErpFiles
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly AttachmentStore $attachments) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function attach(?array $data, AgentRun $run): ?MessageAttachment
    {
        $url = $data['download_url'] ?? null;
        $connection = ErpConnection::query()->currentTenant()->first();

        if (! is_string($url) || $run->task_id === null || $connection?->base_url === null
            || Str::lower((string) parse_url($url, PHP_URL_HOST)) !== Str::lower((string) parse_url($connection->base_url, PHP_URL_HOST))) {
            return null;
        }

        $filename = is_string($data['file_name'] ?? null) && $data['file_name'] !== '' ? $data['file_name'] : 'documento-erp';
        $path = 'erp-files/'.Str::uuid().'-'.Str::slug(pathinfo($filename, PATHINFO_FILENAME)).'.'.(pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin');

        try {
            $response = Http::timeout(30)->withOptions(['allow_redirects' => false])->get($url);

            if (! $response->successful()) {
                throw new RuntimeException("O ERP respondeu {$response->status()}.");
            }

            if (strlen($response->body()) > self::MAX_BYTES) {
                throw new RuntimeException('Ficheiro do ERP demasiado grande.');
            }

            Storage::disk('local')->put($path, $response->body());
            $attachment = $this->attachments->fromAgent($run, $filename, 'local', $path, 'erp');
        } catch (Throwable $e) {
            AuditLog::record($run->agent, 'erp.file_fetch', ['agent_run_id' => $run->id, 'file' => $filename, 'error' => Str::limit($e->getMessage(), 300)], AuditResult::Error, $connection);

            return null;
        } finally {
            Storage::disk('local')->delete($path);
        }

        AuditLog::record($run->agent, 'erp.file_fetch', ['agent_run_id' => $run->id, 'file' => $filename, 'bytes' => $attachment?->size_bytes], AuditResult::Ok, $connection);

        return $attachment;
    }
}
