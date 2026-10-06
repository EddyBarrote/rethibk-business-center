<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People and agents as colleagues (docs/DECISOES.md, realinhamento L3, L4):
 * one org chart where a person can report to a person or an agent, and tasks
 * that a person can own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('reports_to_user_id')->nullable()->after('department_id')->constrained('users')->nullOnDelete();
            $table->foreignId('reports_to_agent_id')->nullable()->after('reports_to_user_id')->constrained('agents')->nullOnDelete();
            $table->string('job_title')->nullable()->after('name');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('assignee_user_id')->nullable()->after('assignee_agent_id')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'assignee_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'assignee_user_id', 'status']);
            $table->dropConstrainedForeignId('assignee_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reports_to_agent_id');
            $table->dropConstrainedForeignId('reports_to_user_id');
            $table->dropColumn('job_title');
        });
    }
};
