<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Knowledge base (docs/CONHECIMENTO.md): information domains with access by
     * department, folders inside them, files kept next to their text, a review
     * state for what agents write, and the documents agents generate.
     */
    public function up(): void
    {
        Schema::create('knowledge_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->string('color', 16)->nullable();
            // Null: every person and agent of the tenant. A list: only those
            // departments (owners and admins always see every domain).
            $table->json('department_ids')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('knowledge_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('knowledge_folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['tenant_id', 'knowledge_domain_id', 'parent_id']);
        });

        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->string('type', 32)->change();
            $table->foreignId('knowledge_domain_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->foreignId('knowledge_folder_id')->nullable()->after('knowledge_domain_id')->constrained()->nullOnDelete();
            $table->string('status', 16)->default('published')->after('content');
            $table->string('disk')->nullable()->after('status');
            $table->string('path')->nullable()->after('disk');
            $table->string('filename')->nullable()->after('path');
            $table->string('mime_type')->nullable()->after('filename');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('format', 8);
            $table->string('template', 32)->default('documento');
            $table->longText('source');
            $table->string('disk');
            $table->string('path');
            $table->string('filename');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('knowledge_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');

        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('knowledge_domain_id');
            $table->dropConstrainedForeignId('knowledge_folder_id');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn(['status', 'disk', 'path', 'filename', 'mime_type', 'size_bytes', 'reviewed_at']);
        });

        Schema::dropIfExists('knowledge_folders');
        Schema::dropIfExists('knowledge_domains');
    }
};
