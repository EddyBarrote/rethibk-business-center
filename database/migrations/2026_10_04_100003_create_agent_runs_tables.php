<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sections 5.3 and 5.4 (runs, steps, approvals) plus the budget events
     * of section 14.3.
     */
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id')->nullable();
            $table->enum('trigger_type', ['email', 'schedule', 'manual', 'agent', 'webhook']);
            $table->nullableMorphs('trigger_source');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['queued', 'running', 'awaiting_approval', 'completed', 'failed', 'cancelled'])->default('queued');
            $table->longText('input');
            $table->json('output')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'agent_id', 'status', 'created_at']);
        });

        Schema::create('agent_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->enum('type', ['message', 'reasoning', 'tool_call', 'tool_result', 'approval', 'error']);
            $table->string('tool_name')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agent_run_id', 'seq']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capability_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type');
            $table->string('action_summary');
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('required_level');
            $table->unsignedTinyInteger('agent_level');
            $table->string('ceiling_reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->enum('execution_status', ['not_executed', 'executed', 'failed'])->default('not_executed');
            $table->json('execution_result')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('budget_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('scope', ['tenant', 'agent', 'run']);
            $table->string('subject_key');
            $table->char('period', 7);
            $table->unsignedTinyInteger('threshold');
            $table->decimal('spent_usd', 12, 6);
            $table->decimal('cap_usd', 12, 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'subject_key', 'period', 'threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_events');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('agent_run_steps');
        Schema::dropIfExists('agent_runs');
    }
};
