<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda (docs/DECISOES.md, "Agenda"): a follow-up can belong to a task, so
 * the task and its reminders are one thing; a person can subscribe to their
 * agenda from Google or Outlook with a private link (only its hash is kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token']);
            $table->dropColumn('calendar_token');
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
        });
    }
};
