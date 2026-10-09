<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the person last read their conversation with an agent, so the sidebar
 * can count the answers they have not seen (docs/DECISOES.md, "Conversas a
 * partir dos agentes"). Existing conversations start as read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('chat_read_at')->nullable()->after('last_activity_at');
        });

        DB::table('tasks')->whereNotNull('chat_key')->update(['chat_read_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('chat_read_at');
        });
    }
};
