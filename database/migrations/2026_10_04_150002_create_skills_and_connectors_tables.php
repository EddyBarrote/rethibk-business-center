<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Skills (instruction packages), connectors (MCP servers and HTTP actions
     * that become capabilities) and the agent's face (docs/CAPACIDADES.md).
     * Both exist globally (platform_*, created by the super admin) and per
     * tenant (created by the company's admins).
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('personality');
            $table->foreignId('created_by_user_id')->nullable()->after('created_by_admin_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('platform_connectors', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('kind', ['mcp', 'http']);
            $table->string('url', 2048);
            $table->text('secret')->nullable();
            $table->string('http_method', 10)->nullable();
            $table->json('input_schema')->nullable();
            $table->boolean('is_mutating')->default(false);
            $table->json('tools')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('kind', ['mcp', 'http']);
            $table->string('url', 2048);
            $table->text('secret')->nullable();
            $table->string('http_method', 10)->nullable();
            $table->json('input_schema')->nullable();
            $table->boolean('is_mutating')->default(false);
            $table->json('tools')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::table('capabilities', function (Blueprint $table) {
            $table->string('source', 20)->change();
            $table->string('scope', 10)->default('global')->after('source');
            $table->boolean('is_enabled')->default(true)->after('is_available');
            $table->foreignId('platform_connector_id')->nullable()->after('mcp_tool_name')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->after('platform_connector_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('platform_skills', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description');
            $table->longText('instructions');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('platform_skill_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_skill_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->longText('content')->nullable();
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_skill_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->longText('instructions')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('skill_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->longText('content')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_skill', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['agent_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_skill');
        Schema::dropIfExists('skill_files');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('platform_skill_files');
        Schema::dropIfExists('platform_skills');

        Schema::table('capabilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('connector_id');
            $table->dropConstrainedForeignId('platform_connector_id');
            $table->dropColumn(['scope', 'is_enabled']);
        });

        Schema::dropIfExists('connectors');
        Schema::dropIfExists('platform_connectors');

        Schema::table('agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('avatar_path');
        });
    }
};
