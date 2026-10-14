<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The file repository in the knowledge base (docs/DECISOES.md, 14.10.2026):
     * files are read page by page on the queue (the scanned pages by a model),
     * keep their earlier versions, and are indexed with smaller vectors stored
     * as binary. Model calls outside agent runs are charged to the budget.
     */
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->unsignedInteger('pages')->nullable()->after('size_bytes');
            $table->string('reading_status', 20)->nullable()->after('pages'); // pending, reading, done, failed; null for articles
            $table->string('text_source', 20)->nullable()->after('reading_status'); // text, model, mixed, none
            $table->string('reading_note')->nullable()->after('text_source');
            $table->string('thumbnail_path')->nullable()->after('reading_note');
            $table->unsignedSmallInteger('version')->default(1)->after('thumbnail_path');
            $table->char('content_hash', 64)->nullable()->after('version');

            $table->index(['tenant_id', 'content_hash']);
        });

        Schema::create('knowledge_item_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->mediumText('text');
            $table->string('source', 10)->default('text'); // text (pdftotext) or model
            $table->timestamps();

            $table->unique(['knowledge_item_id', 'page']);
        });

        Schema::create('knowledge_item_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('disk');
            $table->string('path');
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('created_by_type');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();

            $table->index(['knowledge_item_id', 'version']);
        });

        Schema::table('knowledge_embeddings', function (Blueprint $table) {
            $table->unsignedInteger('page')->nullable()->after('chunk_index');
            $table->binary('vector')->nullable()->after('embedding');
            $table->json('embedding')->nullable()->change();
        });

        Schema::create('ai_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 40);
            $table->nullableMorphs('subject');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_charges');

        Schema::table('knowledge_embeddings', function (Blueprint $table) {
            $table->dropColumn(['page', 'vector']);
        });

        Schema::dropIfExists('knowledge_item_versions');
        Schema::dropIfExists('knowledge_item_pages');

        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'content_hash']);
            $table->dropColumn(['pages', 'reading_status', 'text_source', 'reading_note', 'thumbnail_path', 'version', 'content_hash']);
        });
    }
};
