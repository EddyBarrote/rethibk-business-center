<?php

namespace App\Attachments;

use App\Enums\AttachmentKind;
use App\Models\AgentRun;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the files of conversations (docs/DECISOES.md, "Anexos nas conversas").
 * A person's file is uploaded in parts while they write, checked on its bytes
 * when complete, and sent with the message; an agent's file is copied in and
 * goes with its reply.
 */
final class AttachmentStore
{
    /**
     * Start an upload: the file is checked against the company's limits by its
     * name and size now, and by its content when the last part arrives.
     */
    public function start(User $user, string $filename, int $size, ?string $clientMime, ?Task $task, string $source = 'upload'): MessageAttachment
    {
        $limits = AttachmentLimits::current();
        $filename = $this->cleanName($filename);
        $type = $limits->typeFor($filename);

        if ($type === null) {
            throw ValidationException::withMessages(['file' => "O tipo de «{$filename}» não é aceite. Veja os tipos em Definições › Anexos."]);
        }

        if ($size <= 0 || $size > $limits->maxBytes()) {
            throw ValidationException::withMessages(['file' => "«{$filename}» passa do limite de {$limits->maxMb} MB."]);
        }

        $pending = MessageAttachment::query()->where('uploaded_by_user_id', $user->id)->whereNull('task_message_id')->where('created_at', '>', now()->subHour())->count();

        if ($pending >= $limits->maxFiles * 3) {
            throw ValidationException::withMessages(['file' => 'Demasiados ficheiros por enviar. Envie ou retire os que tem antes de juntar mais.']);
        }

        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $disk = (string) config('attachments.disk');
        $path = $this->newPath($extension);
        Storage::disk($disk)->put($path, '');

        return MessageAttachment::query()->create([
            'task_id' => $task?->id,
            'uploaded_by_user_id' => $user->id,
            'source' => in_array($source, ['upload', 'recording'], true) ? $source : 'upload',
            'kind' => AttachmentKind::fromMime((string) $clientMime, $filename),
            'filename' => $filename,
            'mime_type' => $clientMime ?: 'application/octet-stream',
            'size_bytes' => $size,
            'received_bytes' => 0,
            'status' => MessageAttachment::UPLOADING,
            'disk' => $disk,
            'path' => $path,
        ]);
    }

    /**
     * Add the next part. When the file is complete its content is checked.
     */
    public function append(MessageAttachment $attachment, UploadedFile $chunk, int $offset): MessageAttachment
    {
        if ($attachment->isReady()) {
            return $attachment;
        }

        if ($offset !== $attachment->received_bytes) {
            throw ValidationException::withMessages(['chunk' => "Parte fora de ordem: esperava o byte {$attachment->received_bytes}."]);
        }

        $bytes = (string) $chunk->get();
        $received = $attachment->received_bytes + strlen($bytes);

        if ($received > $attachment->size_bytes) {
            $this->delete($attachment);

            throw ValidationException::withMessages(['chunk' => 'O ficheiro é maior do que o anunciado.']);
        }

        $disk = Storage::disk($attachment->disk);
        $stream = fopen($disk->path($attachment->path), 'ab');

        if ($stream === false) {
            throw ValidationException::withMessages(['chunk' => 'Não foi possível guardar o ficheiro.']);
        }

        fwrite($stream, $bytes);
        fclose($stream);

        $attachment->forceFill(['received_bytes' => $received])->save();

        return $received === $attachment->size_bytes ? $this->finish($attachment) : $attachment;
    }

    /**
     * The person's ready files for a message in this thread.
     *
     * @param  list<int>  $ids
     * @return Collection<int, MessageAttachment>
     */
    public function claim(User $user, array $ids, ?Task $task): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $limits = AttachmentLimits::current();

        if (count($ids) > $limits->maxFiles) {
            throw ValidationException::withMessages(['attachment_ids' => "No máximo {$limits->maxFiles} ficheiros por mensagem."]);
        }

        $attachments = MessageAttachment::query()
            ->whereKey($ids)
            ->where('uploaded_by_user_id', $user->id)
            ->whereNull('task_message_id')
            ->where(fn ($q) => $q->whereNull('task_id')->when($task, fn ($q) => $q->orWhere('task_id', $task->id)))
            ->orderBy('id')
            ->get();

        if ($attachments->count() !== count(array_unique($ids)) || $attachments->contains(fn (MessageAttachment $a) => ! $a->isReady())) {
            throw ValidationException::withMessages(['attachment_ids' => 'Há ficheiros que ainda não acabaram de carregar ou já não existem.']);
        }

        return $attachments;
    }

    /**
     * @param  iterable<MessageAttachment>  $attachments
     */
    public function attachToMessage(iterable $attachments, TaskMessage $message): void
    {
        foreach ($attachments as $attachment) {
            $attachment->forceFill(['task_id' => $message->task_id, 'task_message_id' => $message->id])->save();
        }
    }

    /**
     * A file an agent sends in its reply: copied in, it waits on the run
     * until the reply is written.
     */
    public function fromAgent(AgentRun $run, string $filename, string $sourceDisk, string $sourcePath, string $source, ?string $text = null): ?MessageAttachment
    {
        $from = Storage::disk($sourceDisk);

        if ($run->task_id === null || ! $from->exists($sourcePath)) {
            return null;
        }

        $filename = $this->cleanName($filename);
        $disk = (string) config('attachments.disk');
        $path = $this->newPath(Str::lower(pathinfo($filename, PATHINFO_EXTENSION)));
        $stream = $from->readStream($sourcePath);
        Storage::disk($disk)->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $mime = $this->normaliseMime((string) (Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream'), $filename, null);

        return MessageAttachment::query()->create([
            'task_id' => $run->task_id,
            'agent_id' => $run->agent_id,
            'agent_run_id' => $run->id,
            'source' => $source,
            'kind' => AttachmentKind::fromMime($mime, $filename),
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => (int) Storage::disk($disk)->size($path),
            'received_bytes' => (int) Storage::disk($disk)->size($path),
            'status' => MessageAttachment::READY,
            'disk' => $disk,
            'path' => $path,
            // A generated document keeps its Markdown, which the conversation shows as its preview.
            'extracted_text' => $text,
            'text_status' => $text === null ? 'pending' : 'done',
        ]);
    }

    public function delete(MessageAttachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
    }

    /**
     * Files uploaded and never sent.
     */
    public function prune(): int
    {
        $count = 0;

        MessageAttachment::query()
            ->whereNull('task_message_id')
            ->whereNull('agent_run_id')
            ->where('created_at', '<', now()->subHours((int) config('attachments.orphan_hours', 24)))
            ->each(function (MessageAttachment $attachment) use (&$count): void {
                $this->delete($attachment);
                $count++;
            });

        return $count;
    }

    /**
     * The mime type models know: audio/x-wav is audio/wav, audio/mpeg is
     * audio/mp3, a Word file sniffed as a zip is a Word file.
     */
    public function normaliseMime(string $sniffed, string $filename, ?string $client): string
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $sniffed = Str::lower(trim(explode(';', $sniffed)[0]));

        return match (true) {
            in_array($sniffed, ['audio/x-wav', 'audio/wave', 'audio/vnd.wave'], true) => 'audio/wav',
            in_array($sniffed, ['audio/mpeg', 'audio/mpeg3', 'audio/x-mpeg-3'], true) => 'audio/mp3',
            in_array($sniffed, ['audio/x-flac'], true) => 'audio/flac',
            in_array($sniffed, ['audio/x-aiff'], true) => 'audio/aiff',
            in_array($sniffed, ['audio/x-m4a', 'audio/m4a', 'audio/x-mp4'], true) || ($extension === 'm4a') => 'audio/mp4',
            $sniffed === 'application/ogg' || in_array($extension, ['ogg', 'oga', 'opus'], true) && str_starts_with($sniffed, 'audio/') => 'audio/ogg',
            $sniffed === 'video/webm' && ($extension === 'weba' || str_starts_with((string) $client, 'audio/')) => 'audio/webm',
            $sniffed === 'image/jpg' => 'image/jpeg',
            $extension === 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $extension === 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $extension === 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            $extension === 'csv' => 'text/csv',
            $extension === 'md' => 'text/markdown',
            default => $sniffed ?: 'application/octet-stream',
        };
    }

    private function finish(MessageAttachment $attachment): MessageAttachment
    {
        $disk = Storage::disk($attachment->disk);
        $sniffed = (string) (mime_content_type($disk->path($attachment->path)) ?: 'application/octet-stream');
        $type = AttachmentLimits::current()->typeFor($attachment->filename);

        if ($type === null || ! AttachmentLimits::contentMatches($type, $sniffed)) {
            $name = $attachment->filename;
            $this->delete($attachment);

            throw ValidationException::withMessages(['file' => "O conteúdo de «{$name}» não corresponde ao tipo do ficheiro."]);
        }

        $mime = $this->normaliseMime($sniffed, $attachment->filename, $attachment->mime_type);

        $attachment->forceFill([
            'status' => MessageAttachment::READY,
            'mime_type' => $mime,
            'kind' => AttachmentKind::fromMime($mime, $attachment->filename),
        ])->save();

        return $attachment;
    }

    private function newPath(string $extension): string
    {
        $tenant = Tenant::current()->id ?? 0;

        return "attachments/{$tenant}/".now()->format('Y/m').'/'.Str::uuid().($extension !== '' ? ".{$extension}" : '');
    }

    private function cleanName(string $filename): string
    {
        $filename = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $filename));

        return Str::limit($filename !== '' ? $filename : 'ficheiro', 180, '');
    }
}
