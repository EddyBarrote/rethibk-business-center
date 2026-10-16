<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An email a person brings into a conversation with an agent
 * (docs/DECISOES.md, "Falar com um agente sobre um email").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_messages', function (Blueprint $table) {
            $table->foreignId('email_message_id')->nullable()->after('handoff_id')->constrained('email_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_message_id');
        });
    }
};
