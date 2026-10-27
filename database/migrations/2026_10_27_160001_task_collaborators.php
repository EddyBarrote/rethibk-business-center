<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who works on a task with its owner (docs/DECISOES.md, "Tarefas: colaborar
 * com agentes e colegas"): an agent or a colleague, with a role, and the
 * sub-task that holds their part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('subtask_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id'], 'task_collab_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_collaborators');
    }
};
