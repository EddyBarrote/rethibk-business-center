<?php

namespace App\Console\Commands\Tenants;

use App\Concerns\BelongsToTenant;
use App\Console\Concerns\InteractsWithTenant;
use App\Models\AuditLog;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Everything a tenant owns, as one zip (contractual export): a JSON file per
 * table and the stored files (raw email, attachments, uploads). Secrets are
 * hidden attributes, so they never leave.
 */
#[Signature('tenant:export {tenant : Slug do tenant} {--path= : Ficheiro de destino}')]
#[Description('Exporta todos os dados de um tenant para um zip (JSON por tabela e ficheiros)')]
class ExportTenant extends Command
{
    use InteractsWithTenant;

    public function handle(): int
    {
        return $this->asTenant(function (Tenant $tenant): int {
            $path = (string) ($this->option('path') ?: storage_path('app/exports/'.$tenant->slug.'-'.now()->format('Ymd-His').'.zip'));
            @mkdir(dirname($path), 0775, true);

            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Não foi possível criar {$path}.");
            }

            $zip->addFromString('tenant.json', (string) json_encode($tenant->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $counts = [];

            foreach (self::tenantModels() as $class) {
                $rows = [];
                $class::query()->orderBy('id')->chunk(500, function ($models) use (&$rows) {
                    foreach ($models as $model) {
                        $rows[] = $model->toArray();
                    }
                });

                $table = (new $class)->getTable();
                $counts[$table] = count($rows);
                $zip->addFromString("data/{$table}.json", (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $files = $this->addFiles($zip);
            $zip->close();

            AuditLog::record(null, 'tenant.exported', ['tables' => $counts, 'files' => $files]);

            $this->table(['Tabela', 'Registos'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());
            $this->components->info("{$files} ficheiro(s). Exportação em {$path}");

            return self::SUCCESS;
        });
    }

    /**
     * @return list<class-string<Model>>
     */
    public static function tenantModels(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (class_exists($class) && in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        return $models;
    }

    private function addFiles(ZipArchive $zip): int
    {
        $disk = Storage::disk((string) config('mail_ingest.disk'));
        $count = 0;

        $paths = EmailMessage::query()->whereNotNull('raw_path')->pluck('raw_path')
            ->merge(EmailAttachment::query()->whereNotNull('path')->pluck('path'));

        foreach ($paths as $path) {
            if ($disk->exists((string) $path)) {
                $zip->addFromString('files/'.$path, (string) $disk->get((string) $path));
                $count++;
            }
        }

        return $count;
    }
}
