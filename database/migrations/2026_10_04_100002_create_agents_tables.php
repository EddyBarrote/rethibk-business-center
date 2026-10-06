<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Section 5.2, adapted to generic agents (docs/DECISOES.md): an agent is
     * a configuration, not a PHP class. The super admin defines its
     * personality, instructions, model, autonomy, capabilities and routines.
     */
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('personality')->nullable();
            $table->longText('instructions')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reports_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['draft', 'active', 'suspended'])->default('draft');
            $table->string('suspended_reason')->nullable();
            $table->unsignedTinyInteger('autonomy_level')->default(0);
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->unsignedSmallInteger('max_steps')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('agent_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'user_id']);
        });

        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('source', ['local', 'mcp']);
            $table->string('mcp_tool_name')->nullable();
            $table->json('input_schema')->nullable();
            $table->boolean('is_mutating')->default(false);
            $table->unsignedTinyInteger('risk')->default(0);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('agent_capability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'capability_id']);
        });

        Schema::create('agent_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('prompt');
            $table->string('schedule');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_routines');
        Schema::dropIfExists('agent_capability');
        Schema::dropIfExists('capabilities');
        Schema::dropIfExists('agent_assignments');
        Schema::dropIfExists('agents');
    }
};
