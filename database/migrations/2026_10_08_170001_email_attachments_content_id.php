<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZBateson\MailMimeParser\Message;

/**
 * The Content-ID of each file of an email, so the images the email embeds
 * (cid:) show in its body (docs/DECISOES.md, "Email: imagens carregam sozinhas").
 * The emails already read get it from their stored .eml.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_attachments', function (Blueprint $table) {
            $table->string('content_id', 255)->nullable()->after('mime_type');
        });

        $messages = DB::table('email_messages')->where('html_body', 'like', '%cid:%')->whereNotNull('raw_path')->get(['id', 'raw_path']);

        foreach ($messages as $message) {
            $files = DB::table('email_attachments')->where('email_message_id', $message->id)->whereNotNull('path')->get(['id', 'disk', 'path']);
            $disk = Storage::disk($files->first()->disk ?? (string) config('mail_ingest.disk'));

            if ($files->isEmpty() || ! $disk->exists($message->raw_path)) {
                continue;
            }

            $parts = Message::from((string) $disk->get($message->raw_path), false)->getAllAttachmentParts();

            foreach ($files as $file) {
                // Stored as "{index}-{name}", in the order of the parts.
                $index = (int) strtok(basename((string) $file->path), '-');
                $id = trim((string) ($parts[$index] ?? null)?->getContentId(), '<> ');

                if ($id !== '') {
                    DB::table('email_attachments')->where('id', $file->id)->update(['content_id' => mb_substr($id, 0, 255)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('email_attachments', function (Blueprint $table) {
            $table->dropColumn('content_id');
        });
    }
};
