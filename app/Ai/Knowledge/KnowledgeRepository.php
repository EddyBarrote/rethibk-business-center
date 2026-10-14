<?php

namespace App\Ai\Knowledge;

use App\Attachments\StoredFile;
use App\Models\Agent;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts files the platform already keeps into the repository (docs/DECISOES.md,
 * 14.10.2026): an attachment of a conversation or of an email, saved by a
 * person or an agent. The same file twice is found by its bytes (sha256).
 */
final class KnowledgeRepository
{
    public function __construct(
        private readonly KnowledgeBase $knowledge,
        private readonly KnowledgeAccess $access,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  title, domain, folder, status
     */
    public function fromStoredFile(StoredFile&Model $file, User|Agent $author, array $attributes = []): ?KnowledgeItem
    {
        $disk = Storage::disk($file->diskName());
        $path = $file->storagePath();

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        $title = trim((string) ($attributes['title'] ?? '')) ?: (pathinfo($file->fileName(), PATHINFO_FILENAME) ?: $file->fileName());
        unset($attributes['title']);

        return $this->withLocal($file, fn (string $local) => $this->knowledge->rememberFile($local, $file->fileName(), $file->mimeType(), Str::limit($title, 250, ''), $author, [
            'is_external' => $file->isExternal(),
            'source_type' => $file->getMorphClass(),
            'source_id' => $file->getKey(),
            ...$attributes,
        ]));
    }

    /**
     * The item that already has this file, among those the person or agent can see.
     */
    public function duplicateOf(StoredFile|string $file, User|Agent $viewer): ?KnowledgeItem
    {
        $hash = is_string($file) ? $file : $this->withLocal($file, fn (string $local) => hash_file('sha256', $local) ?: null);

        return $hash === null ? null : $this->access->items($viewer)->where('content_hash', $hash)->first();
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T|null
     */
    private function withLocal(StoredFile $file, callable $callback): mixed
    {
        $path = $file->storagePath();

        if ($path === null) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'repo');
        $from = Storage::disk($file->diskName())->readStream($path);
        $to = fopen($tmp, 'wb');

        if (! is_resource($from) || $to === false) {
            @unlink($tmp);

            return null;
        }

        stream_copy_to_stream($from, $to);
        fclose($from);
        fclose($to);

        try {
            return $callback($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
