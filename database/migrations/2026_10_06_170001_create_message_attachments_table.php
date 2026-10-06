<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files in conversations and task threads (docs/DECISOES.md, "Anexos nas
 * conversas"): sent by a person (uploaded or recorded) or by an agent (a
 * document it generated, a file of the knowledge base).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null while the person is still writing: the file is uploaded first, then sent with the message.
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('task_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            // An agent's file waits on its run until the reply is written.
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('upload'); // upload, recording, generated, knowledge
            $table->string('kind', 20);
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->string('status', 20)->default('uploading'); // uploading, ready
            $table->string('disk');
            $table->string('path');
            $table->longText('extracted_text')->nullable();
            $table->string('text_status', 20)->default('pending'); // pending, done, skipped, failed
            $table->timestamps();

            $table->index(['task_id', 'task_message_id']);
            $table->index(['agent_run_id', 'task_message_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
