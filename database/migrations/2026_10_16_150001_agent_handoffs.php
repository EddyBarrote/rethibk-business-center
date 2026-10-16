<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents message each other (docs/DECISOES.md, "Mensagens entre agentes"): an
 * agent passes a person's request to a colleague, who works on it in that
 * person's own conversation with it; its answer goes back to the person and
 * to the agent that passed it. The rows of both conversations point here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('to_agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('from_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('to_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('from_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('answer_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->text('brief');
            $table->text('answer')->nullable();
            $table->string('status', 20)->default('working');
            $table->unsignedTinyInteger('depth')->default(1);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'to_task_id', 'status']);
        });

        Schema::table('task_messages', function (Blueprint $table) {
            $table->foreignId('handoff_id')->nullable()->after('agent_run_id')->constrained('agent_handoffs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handoff_id');
        });

        Schema::dropIfExists('agent_handoffs');
    }
};
