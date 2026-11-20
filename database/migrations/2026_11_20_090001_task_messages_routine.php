<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A routine's request in a conversation points back at the routine, so a run
 * that picks it up later, when the agent was busy, is still the routine's
 * (docs/DECISOES.md, "Director de Recursos Humanos").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_messages', function (Blueprint $table) {
            $table->foreignId('agent_routine_id')->nullable()->after('email_message_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_routine_id');
        });
    }
};
