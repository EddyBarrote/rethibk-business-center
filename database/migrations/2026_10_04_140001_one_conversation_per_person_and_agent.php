<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One persistent conversation per person and agent, like Grok
     * (docs/DECISOES.md, 04.10.2026). Chats get a key "{user}:{agent}" that is
     * unique per tenant, and existing separate chats are folded into the oldest.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('chat_key')->nullable()->after('kind');
        });

        $groups = DB::table('tasks')
            ->where('kind', 'chat')
            ->whereNotNull('user_id')
            ->whereNotNull('assignee_agent_id')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'user_id', 'assignee_agent_id'])
            ->groupBy(fn ($chat) => "{$chat->tenant_id}|{$chat->user_id}:{$chat->assignee_agent_id}");

        foreach ($groups as $chats) {
            $keep = $chats->first();
            $others = $chats->skip(1)->pluck('id');

            if ($others->isNotEmpty()) {
                DB::table('task_messages')->whereIn('task_id', $others)->update(['task_id' => $keep->id]);
                DB::table('agent_runs')->whereIn('task_id', $others)->update(['task_id' => $keep->id]);
                DB::table('tasks')->whereIn('parent_id', $others)->update(['parent_id' => $keep->id]);
                DB::table('tasks')->whereIn('id', $others)->delete();
            }

            DB::table('tasks')->where('id', $keep->id)->update([
                'chat_key' => "{$keep->user_id}:{$keep->assignee_agent_id}",
                'status' => DB::raw("case when status in ('done', 'cancelled') then 'in_progress' else status end"),
                'last_activity_at' => DB::table('task_messages')->where('task_id', $keep->id)->max('created_at') ?? now(),
            ]);
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->unique(['tenant_id', 'chat_key']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'chat_key']);
            $table->dropColumn('chat_key');
        });
    }
};
