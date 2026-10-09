<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each person has their own memory with each agent (docs/DECISOES.md,
     * 09.10.2026). A fact belongs to the person of the conversation or task it
     * came from; a fact from work with no person (routines, email) stays the
     * agent's own. Existing facts go to the person of their task.
     */
    public function up(): void
    {
        Schema::table('agent_memories', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('agent_id')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'agent_id', 'user_id']);
        });

        DB::table('agent_memories')->where('kind', 'personal')->whereNotNull('about_user_id')->update(['user_id' => DB::raw('about_user_id')]);

        DB::table('agent_memories')
            ->whereNull('user_id')
            ->whereNotNull('task_id')
            ->orderBy('id')
            ->get(['id', 'task_id'])
            ->each(function ($memory) {
                $task = DB::table('tasks')->where('id', $memory->task_id)->first(['user_id', 'created_by_user_id']);
                $person = $task?->user_id ?? $task?->created_by_user_id;

                if ($person !== null) {
                    DB::table('agent_memories')->where('id', $memory->id)->update(['user_id' => $person]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('agent_memories', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'agent_id', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
