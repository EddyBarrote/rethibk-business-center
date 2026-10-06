<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fluxos de trabalho (docs/DECISOES.md, "Fluxos de trabalho"): what happens to
 * a kind of email after triage, as a graph of blocks the platform walks one at
 * a time; the rules that say which agent handles each kind of email; and each
 * run of a flow, step by step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('fallback_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'category']);
        });

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('email_category', 40)->nullable();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('fallback_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('graph');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'email_category']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
            // The graph as it was when the run started: editing the flow never breaks a run in progress.
            $table->json('graph');
            $table->json('state')->nullable();
            $table->string('status', 20)->default('running');
            $table->string('current_node_id', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'workflow_id', 'status']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('kind', 20);
            $table->string('status', 20)->default('active');
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('item', 500)->nullable();
            $table->string('answer', 20)->nullable();
            $table->json('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'workflow_run_id', 'status']);
            $table->index(['tenant_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflows');
        Schema::dropIfExists('email_routes');
    }
};
