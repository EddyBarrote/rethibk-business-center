<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rotinas (docs/DECISOES.md, "Rotinas e fluxos agendados"): the admin's
 * routines say who gets the result, and a routine can start a flow instead
 * of asking an agent. A flow starts with an email or on a schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_routines', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->change();
            $table->text('prompt')->nullable()->change();
            $table->foreignId('workflow_id')->nullable()->after('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->after('workflow_id')->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->foreignId('last_run_id')->nullable()->after('last_run_at')->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('last_task_id')->nullable()->after('last_run_id')->constrained('tasks')->nullOnDelete();
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->string('trigger', 20)->default('email')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('trigger');
        });

        Schema::table('agent_routines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_task_id');
            $table->dropConstrainedForeignId('last_run_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropConstrainedForeignId('workflow_id');
        });
    }
};
