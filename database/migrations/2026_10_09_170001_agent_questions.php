<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perguntas dos agentes (docs/DECISOES.md, "Perguntas dos agentes"): like
 * Claude's AskUserQuestion, an agent asks one to four multiple-choice
 * questions and its run pauses until a person answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->enum('status', ['queued', 'running', 'awaiting_approval', 'awaiting_input', 'completed', 'failed', 'cancelled'])->default('queued')->change();
        });

        Schema::create('agent_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'task_id', 'status']);
            $table->index(['agent_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_questions');

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->enum('status', ['queued', 'running', 'awaiting_approval', 'completed', 'failed', 'cancelled'])->default('queued')->change();
        });
    }
};
