<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sections 5.6 and 5.9. Embeddings are stored as JSON and compared in PHP
     * for now: portable across MySQL 8.4 (CI), MySQL 9 and SQLite (tests).
     * Moving to MySQL 9's VECTOR type is a later, isolated change.
     */
    public function up(): void
    {
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['decision', 'meeting_brief', 'document', 'pattern', 'entity_note']);
            $table->string('title');
            $table->longText('content');
            $table->text('summary')->nullable();
            $table->nullableMorphs('source');
            $table->boolean('is_external')->default(false);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('visibility', ['tenant', 'department', 'agent'])->default('tenant');
            $table->string('created_by_type');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->enum('embedding_status', ['pending', 'done', 'failed', 'skipped'])->default('pending');
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            Schema::table('knowledge_items', fn (Blueprint $table) => $table->fullText(['title', 'content']));
        }

        Schema::create('knowledge_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('chunk_text');
            $table->json('embedding');
            $table->string('model');
            $table->unsignedSmallInteger('dimensions');
            $table->timestamps();

            $table->index(['knowledge_item_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_embeddings');
        Schema::dropIfExists('knowledge_items');
    }
};
