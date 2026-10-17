<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who closed a task (docs/DECISOES.md, "Fechar uma tarefa"): the person or the
 * agent; when is `completed_at`. Cleared when the task is reopened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('closed_by_user_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_agent_id')->nullable()->after('closed_by_user_id')->constrained('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_agent_id');
            $table->dropConstrainedForeignId('closed_by_user_id');
        });
    }
};
