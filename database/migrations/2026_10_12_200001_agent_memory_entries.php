<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Memory as named entries, like claude.ai (docs/DECISOES.md, "Memória em
     * entradas", 12.10.2026): each row of agent_memories becomes an entry with
     * a section, a title and a one-line description; `content` is its body.
     * Each person also gets their own memory switches.
     *
     * The loose facts of each memory (person and agent) fold into at most two
     * entries: the personal ones into «Preferências», the work ones into
     * «Notas de trabalho». `agents:organise-memory` later splits them into
     * entries by subject.
     */
    public function up(): void
    {
        Schema::table('agent_memories', function (Blueprint $table) {
            $table->string('section', 20)->default('topics')->after('user_id');
            $table->string('title', 160)->nullable()->after('section');
            $table->string('description', 255)->nullable()->after('title');
            $table->index(['tenant_id', 'agent_id', 'user_id', 'section']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('memory_preferences')->nullable()->after('notification_preferences');
        });

        DB::table('agent_memories')
            ->whereNull('title')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($fact) => "{$fact->tenant_id}:{$fact->agent_id}:{$fact->user_id}:{$fact->kind}")
            ->each(function ($facts) {
                $first = $facts->first();
                $personal = $first->kind === 'personal';
                $body = $facts->map(fn ($fact) => '- '.trim($fact->content))->implode("\n");

                DB::table('agent_memories')->where('id', $first->id)->update([
                    'section' => $personal ? 'preferences' : 'topics',
                    'title' => $personal ? 'Preferências' : 'Notas de trabalho',
                    'description' => $personal ? 'O que a pessoa pediu e como gosta de trabalhar, por organizar.' : 'O que o agente aprendeu no trabalho, por organizar.',
                    'content' => $body,
                    'task_id' => $facts->last()->task_id,
                    'updated_at' => $facts->max('updated_at'),
                ]);
                DB::table('agent_memories')->whereIn('id', $facts->skip(1)->pluck('id'))->delete();
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('memory_preferences');
        });

        Schema::table('agent_memories', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'agent_id', 'user_id', 'section']);
            $table->dropColumn(['section', 'title', 'description']);
        });
    }
};
