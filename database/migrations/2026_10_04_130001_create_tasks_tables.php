<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work modelled on Paperclip (docs/DECISOES.md, 04.10.2026): company goals,
     * tasks that are also conversation threads with an agent, the agent org
     * chart, and runs tied to the task they worked on.
     */
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['planned', 'active', 'achieved', 'cancelled'])->default('active');
            $table->foreignId('owner_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->enum('kind', ['task', 'chat'])->default('task');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['todo', 'in_progress', 'waiting_human', 'in_review', 'blocked', 'done', 'cancelled'])->default('todo');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->foreignId('assignee_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            // The person the thread is with: whoever asked, or whom the agent is asking.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'last_activity_at']);
            $table->index(['tenant_id', 'assignee_agent_id', 'status']);
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('task_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->enum('author_type', ['user', 'agent', 'system']);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            // message: conversation; action: a direct order to execute; event: status changes and system notes;
            // report: the result of delegated work, which wakes the delegating agent.
            $table->enum('kind', ['message', 'action', 'event', 'report'])->default('message');
            $table->longText('body');
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['task_id', 'id']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('reports_to_agent_id')->nullable()->after('reports_to_user_id')->constrained('agents')->nullOnDelete();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('agent_id')->constrained()->nullOnDelete();
        });

        // Agents installed from templates before the org chart existed report to their tenant's Chief of Staff.
        foreach (DB::table('agents')->whereJsonContains('settings->template', 'chief_of_staff')->get(['id', 'tenant_id']) as $chief) {
            DB::table('agents')
                ->where('tenant_id', $chief->tenant_id)
                ->where('id', '!=', $chief->id)
                ->whereNull('reports_to_agent_id')
                ->whereNotNull('settings->template')
                ->update(['reports_to_agent_id' => $chief->id]);
        }
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reports_to_agent_id');
        });

        Schema::dropIfExists('task_messages');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('goals');
    }
};
